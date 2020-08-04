<?php
/**
 * MediaWiki Wikilog extension
 * Copyright © 2008-2010 Juliano F. Ravasi
 * http://www.mediawiki.org/wiki/Extension:Wikilog
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * @file
 * @ingroup Extensions
 * @author Juliano F. Ravasi < dev juliano info >
 */

if ( !defined( 'MEDIAWIKI' ) )
	die();

/**
 * WikilogComments special page handler class.
 * Uses WikilogCommentsPage to format the list.
 */
class SpecialWikilogComments
	extends SpecialPage
{
	function __construct() {
		parent::__construct( 'WikilogComments' );
	}

	protected function getGroupName() {
		return 'changes';
	}

	function execute( $par ) {
		global $wgTitle;
		$page = WikilogCommentsPage::createInstance( $wgTitle );
		$page->view();
	}

	/**
	 * Returns the name used as page title in the special page itself,
	 * and also the name that will be listed in Special:Specialpages.
	 */
	public function getDescription() {
		return wfMessage( 'wikilog-title-comments-all' )->text();
	}
}

/**
 * Wikilog comments namespace handler class.
 *
 * Displays a threaded discussion about a page, replacing the mess that is
 * the usual wiki talk pages. This allows a simpler and faster interface for
 * commenting on pages, more like how traditional blogs work. It also allows
 * other interesting things that are difficult or impossible with usual talk
 * pages, like counting the number of comments for each page and generation
 * of syndication feeds with comments for pages or even groups of pages.
 */
class WikilogCommentsPage
	extends Article
	implements WikilogCustomAction
{
	protected $mSkin;				///< Skin used when rendering the page.
	protected $mFormatter;			///< Comment formatter.
	protected $mFormOptions;		///< Post comment form fields.
	protected $mUserCanPost;		///< User is allowed to post.
	protected $mUserCanModerate;	///< User is allowed to moderate.
	protected $mPostedComment;		///< Posted comment, from HTTP post data.
	protected $mCaptchaForm;		///< Captcha form fields, when saving comment.

	public    $mSingleComment;		///< Used when viewing a single comment.

	var $mWikilogInfo, $mSubject, $mSubjectUser;
	var $mCommentPagerType;

	/**
	 * Constructor.
	 *
	 * @param $title Title of the page.
	 */
	static function createInstance( Title $title ) {
		global $wgUser, $wgRequest;

		$subject = $subjectUser = $singleComment = $switchSubject = NULL;
		if ( $title->getNamespace() != NS_SPECIAL ) {
			if ( !Wikilog::nsHasComments( $title ) ) {
				return NULL;
			}
			// We do not print anything from subject page, but its ID is required to correctly post comments
			// So disable permission check for the time
			if ( defined( 'HACL_HALOACL_VERSION' ) ) {
				$hacl = haclfDisableTitlePatch();
			}
			$subject = $title->getSubjectPage();
			if ( defined( 'HACL_HALOACL_VERSION' ) ) {
				haclfRestoreTitlePatch( $hacl );
			}
			$singleComment = WikilogComment::newFromPageID( $title->getArticleId() );
			if ( $singleComment ) {
				$switchSubject = $subject;
				$subject = $singleComment->mSubject;
			}
			// Our DB structure does not allow to post comments for non-existing subject pages,
			// so we either disallow such comments or auto-create the subject page.
			// We only auto-create non-existing User:* pages for registered users,
			// and add them to the corresponding user's watchlist.
			if ( $subject->getNamespace() == NS_USER ) {
				$subjectUser = User::newFromName( $subject->getText() );
				if ( !$subjectUser || !$subjectUser->getId() ) {
					return NULL;
				}
			} elseif ( !$subject->exists() ) {
				return NULL;
			}
		}

		$self = new WikilogCommentsPage( $title );
		if ( class_exists( 'Wikilog' ) ) {
			$self->mWikilogInfo = Wikilog::getWikilogInfo( $title );
		}

		// Check if user can post.
		$self->mUserCanPost = !$wgUser->isBlocked() && ( $wgUser->isAllowed( 'wl-postcomment' ) ||
			( $wgUser->isAllowed( 'edit' ) && $wgUser->isAllowed( 'createtalk' ) ) );
		$self->mUserCanModerate = $wgUser->isAllowed( 'wl-moderation' );

		// Prepare the skin and the comment formatter.
		$self->mSkin = RequestContext::getMain()->getSkin();
		$self->mFormatter = new WikilogCommentFormatter( $self->mSkin, $self->mUserCanPost );

		// Form options.
		$self->mFormOptions = new FormOptions();
		$self->mFormOptions->add( 'wlAnonName', '' );
		$self->mFormOptions->add( 'wlComment', '' );
		$self->mFormOptions->fetchValuesFromRequest( $wgRequest,
			array( 'wlAnonName', 'wlComment' ) );

		// This flags if we are viewing a single comment (subpage).
		$self->mSingleComment = $singleComment;
		$self->mSubject = $subject;
		$self->mSubjectUser = $subjectUser;

		// Set WikilogCommentPager type
		if ( $wgRequest->getVal( 'comment_pager_type' ) !== null ) {
			WikilogCommentPagerSwitcher::setType( $switchSubject, $wgRequest->getVal( 'comment_pager_type' ) );
		}

		// Refresh cache after switching
		WikilogCommentPagerSwitcher::checkType( $switchSubject );
		$self->mCommentPagerType = WikilogCommentPagerSwitcher::getType( $switchSubject );
		if ( $self->mCommentPagerType === 'list' ) {
			$self->mFormatter->mWithParent = true;
		}

		return $self;
	}

	/**
	 * Should the resulting page include comments to subpages?
	 * If yes, you can't comment to the page itself.
	 * By default - only for Wikilog blogs.
	 */
	public function includeSubpageComments() {
		if ( class_exists( 'Wikilog' ) && $this->mWikilogInfo ) {
			return $this->mWikilogInfo->isMain();
		}
		return false;
	}

	/**
	 * Override getRobotPolicy()
	 */
	public function getRobotPolicy( $action, $pOutput = false ) {
		if ( $this->mSingleComment ) {
			// Do not index individual comment pages
			return array( 'index' => 'noindex', 'follow' => 'nofollow' );
		}
		return array( 'index' => 'index', 'follow' => 'follow' );
	}

	/**
	 * Just show the comments without other page details
	 */
	public function outputComments() {
		$this->viewComments( $this->getQuery() );
	}

	/**
	 * Get the comment query object
	 */
	public function getQuery() {
		$query = new WikilogCommentQuery( $this->mSubject );
		if ( $this->includeSubpageComments() ) {
			$query->setIncludeSubpageComments( true );
		}
		return $query;
	}

	/**
	 * Handler for action=view requests.
	 */
	public function view() {
		global $wgRequest, $wgOut;

		if ( $wgRequest->getVal( 'diff' ) ) {
			# Ignore comments if diffing.
			return parent::view();
		}

		# Create our query object.
		$query = $this->getQuery();

		if ( ( $feedFormat = $wgRequest->getVal( 'feed' ) ) ) {
			# RSS or Atom feed requested. Ignore all other options.
			global $wgWikilogNumComments;
			$query->setModStatus( WikilogCommentQuery::MS_ACCEPTED );
			$feed = new WikilogCommentFeed( $this->mTitle, $feedFormat, $query,
				$wgRequest->getInt( 'limit', $wgWikilogNumComments ) );
			return $feed->execute();
		}

		if ( $this->mSingleComment ) {
			$name = $this->mSubject->getSubpageText();

			# Single comment view, show comment followed by its replies.
			$old = $this->mFormatter->mWithParent;
			$this->mFormatter->mWithParent = true;
			$params = $this->mFormatter->getCommentMsgParams( $this->mSingleComment );
			$this->mFormatter->mWithParent = $old;

			# Display the comment header and other status messages.
			$wgOut->addHtml( $this->mFormatter->formatCommentHeader( $this->mSingleComment, $params ) );

			# Display talk page contents.
			parent::view();

			# Display the comment footer.
			$wgOut->addHtml( $this->mFormatter->formatCommentFooter( $this->mSingleComment, $params ) );
		} else {
			# Normal page view, show talk page contents followed by comments.
			parent::view();

			if ( $this->mSubject ) {
				$name = $this->mSubject->getPrefixedText();
				$wgOut->setPageTitle( wfMessage( 'wikilog-title-comments', $name )->text() );
			} else {
				$name = '';
				$wgOut->setPageTitle( wfMessage( 'wikilog-title-comments-all' )->text() );
			}
		}

		# Add a backlink to the original article.
		if ( $name !== '' ) {
			$link = Linker::link( $this->mSubject, Sanitizer::escapeHtmlAllowEntities( $name ) );
			$wgOut->setSubtitle( wfMessage( 'wikilog-backlink', $link )->text() );
		}

		# Retrieve comments (or replies) from database and display them.
		$this->viewComments( $query );

		# Add feed links.
		$wgOut->setSyndicated();
	}

	/**
	 * Wikilog comments view. Retrieve comments from database and display
	 * them in threads.
	 */
	protected function viewComments( WikilogCommentQuery $query ) {
		global $wgOut, $wgRequest, $wgUser, $wgRequest, $wgScript;

		# Prepare query and pager objects.
		$replyTo = $wgRequest->getInt( 'wlParent' );
		$pagerClass = $this->mCommentPagerType == 'list' ? 'WikilogCommentListPager' : 'WikilogCommentThreadPager';
		$pager = new $pagerClass( $query, $this->mFormatter );

		# Different behavior when displaying a single comment.
		if ( $this->mSingleComment ) {
			$query->setThread( $this->mSingleComment->mThread );
			$headerMsg = 'wikilog-replies';
		} else {
			$headerMsg = 'wikilog-comments';
		}

		# Insert reply comment into the thread when replying to a comment.
		if ( $this->mUserCanPost && $replyTo ) {
			$pager->setReplyTrigger( $replyTo, array( $this, 'getPostCommentForm' ) );
		}

		# Enclose all comments or replies in a div.
		$wgOut->addHtml( Xml::openElement( 'div', array( 'class' => 'wl-comments' ) ) );

		# Switch pager
		$type = $this->mCommentPagerType;
		$msg = wfMessage( $type != 'thread' ? 'wikilog-ptswitcher-thread' : 'wikilog-ptswitcher-list' )->text();
		$url = $wgScript . '?' . http_build_query( [ 'comment_pager_type' => $type != 'thread' ? 'thread' : 'list' ] + $_GET );
		$link = Xml::tags( 'a', array( 'href' => $url ),  $msg );
		$pagerType = Xml::tags(
			'span', array( 'style' => 'float: right; font-size: 70%' ), '[ '. $link . ' ]'
		);

		# Comments/Replies header.
		$header = Xml::tags( 'h2', array( 'id' => 'wl-comments-header' ),
			$pagerType . wfMessage( $headerMsg )->parse()
		);
		$wgOut->addHtml( $header );

		# Display comments/replies.
		$wgOut->addHtml( $pager->getBody() . $pager->getNavigationBar() );

		# Display subscribe/unsubscribe link.
		if ( $wgUser->getId() && !$this->mSingleComment ) {
			$wgOut->addHtml( $this->getSubscribeLink() );
		}

		# Display "post new comment" form, if appropriate.
		if ( $this->mUserCanPost ) {
			$wgOut->addHtml( $this->getPostCommentForm( $this->mSingleComment ) );
		} elseif ( $wgUser->isAnon() ) {
			$wgOut->addWikiMsg( 'wikilog-login-to-comment' );
		}

		# Close div.
		$wgOut->addHtml( Xml::closeElement( 'div' ) );
	}

	/**
	 * Handler for action=wikilog requests.
	 * Enabled via WikilogHooks::UnknownAction() hook handler.
	 */
	public function wikilog() {
		global $wgOut, $wgUser, $wgRequest;

		if ( $wgRequest->getBool( 'wlActionSubscribe' ) ) {
			// Subscribe/unsubscribe to new comments
			$id = $this->mSubject->getArticleId();
			$s = $this->subscribe( $id ) ? 'yes' : 'no';
			$wgOut->setPageTitle( wfMessage( "wikilog-subscribed-title-$s" )->text() );
			$wgOut->addWikiText( wfMessage( "wikilog-subscribed-text-$s", $this->mSubject->getPrefixedText() )->plain() );
			return;
		}

		# Forbid commenting to articles which include subpage comments
		# in display to make the discussion less messy
		if ( $this->includeSubpageComments() ) {
			$wgOut->showErrorPage( 'wikilog-error', 'wikilog-no-such-article' );
			return;
		}

		# Initialize a session, when an anonymous post a comment...
		if ( session_id() == '' ) {
			wfSetupSession();
		}

		if ( $wgRequest->wasPosted() ) {
			# HTTP post: either comment preview or submission.
			$this->mPostedComment = $this->getPostedComment();
			if ( $this->mPostedComment ) {
				$submit = $wgRequest->getBool( 'wlActionCommentSubmit' );
				$preview = $wgRequest->getBool( 'wlActionCommentPreview' );
				if ( $submit ) {
					if ( !$wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) ) ) {
						$wgOut->wrapWikiMsg( "<div class='error'>\n$1</div>", 'wikilog-sessionfailure' );
						$preview = true;
					} elseif ( !$this->mUserCanPost ) {
						$wgOut->permissionRequired( 'wl-postcomment' );
						$preview = true;
					} else {
						return $this->postComment( $this->mPostedComment );
					}
				}
				if ( $preview ) {
					return $this->view();
				}
			}
		} else {
			# Comment moderation, actions performed to single-comment pages.
			if ( $this->mSingleComment ) {
				# Check permissions.
				$title = $this->mSingleComment->getCommentArticleTitle();
				$permerrors = $title->getUserPermissionsErrors( 'wl-moderation', $wgUser );
				if ( count( $permerrors ) > 0 ) {
					$wgOut->showPermissionsErrorPage( $permerrors );
					return;
				}
				if ( !$wgUser->matchEditToken( $wgRequest->getVal( 'wpEditToken' ) ) ) {
					$wgOut->showErrorPage( 'wikilog-error', 'sessionfailure' );
					return;
				}

				$approval = $wgRequest->getVal( 'wlActionCommentApprove' );

				# Approve or reject a pending comment.
				if ( $approval ) {
					return $this->setCommentApproval( $this->mSingleComment, $approval );
				}
			}
		}

		$wgOut->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
	}

	/**
	 * Override Article::hasViewableContent() so that it doesn't return 404
	 * if the item page exists.
	 */
	public function hasViewableContent() {
		return parent::hasViewableContent() || $this->mSubject->exists();
	}

	/**
	 * Generates and returns a subscribe/unsubscribe link.
	 */
	public function getSubscribeLink() {
		global $wgScript, $wgUser, $wgWikilogNamespaces;
		if ( !$wgUser->getId() || !$this->mSubject ) {
			return '';
		}
		$all = false;
		$subjId = $this->mSubject->getArticleId();
		$one = $this->isSubscribed( $subjId );
		if ( $this->includeSubpageComments() ) {
			$msg = $one ? 'wikilog-do-unsubscribe-all' : 'wikilog-do-subscribe-all';
		} elseif ( !$this->mSingleComment ) {
			$dbr = wfGetDB( DB_REPLICA );
			// Is it the user talk page? If yes, he can't unsubscribe.
			if ( $this->mSubject->getNamespace() == NS_USER &&
				$this->mSubject->getText() == $wgUser->getName() ) {
				return wfMessage( 'wikilog-subscribed-usertalk' )->plain();
			}
			// Is the user author? If yes, he can't unsubscribe.
			$isa = $dbr->selectField( 'wikilog_authors', '1', array(
				'wla_page' => $subjId,
				'wla_author' => $wgUser->getId(),
			), __METHOD__ );
			if ( $isa ) {
				return wfMessage( 'wikilog-subscribed-as-author' )->plain();
			}
			// Is the user subscribed globally to comments to ALL Wikilog posts?
			// This can be overridden by individual subscription settings (below)
			$globalAll = $wgUser->getOption( 'wl-subscribetoall', 0 ) &&
				in_array( $this->mSubject->getNamespace(), $wgWikilogNamespaces );
			// Is the user subscribed/unsubscribed to/from all entries of the wikilog?
			// (or to/from discussion of all subpages of a root page)
			list( $parent ) = explode( '/', $this->mSubject->getText() );
			$parent = Title::makeTitle( $this->mSubject->getNamespace(), $parent );
			$all = $this->isSubscribed( $parent->getArticleId() );
			if ( $globalAll && $all === NULL && $one === NULL ) {
				// User is subscribed globally and didn't care about blog or post explicitly
				$one = true;
				$msg = 'wikilog-do-unsubscribe-global';
			} elseif ( $one === NULL && $all ) {
				// User is subcribed to the blog and didn't care about the post explicitly
				$one = true;
				$msg = 'wikilog-do-unsubscribe-one';
			} else {
				$msg = $one ? 'wikilog-do-unsubscribe' : 'wikilog-do-subscribe';
			}
		} else {
			return '';
		}
		return wfMessage( $msg,
			// FIXME use title->getFullUrl( ??? )
			$wgScript.'?'.http_build_query( array(
				'title' => $this->getTitle()->getPrefixedText(),
				'action' => 'wikilog',
				'wlActionSubscribe' => 1,
				'wl-subscribe' => $one ? 0 : 1,
			) )
		)->plain();
	}

	/**
	 * Returns:
	 * 1 if current user is subcribed to comments to page with ID=$itemid
	 * 0 if unsubscribed
	 * NULL if the user didn't care
	 */
	public function isSubscribed( $itemid ) {
		global $wgUser;
		$dbr = wfGetDB( DB_REPLICA );
		$r = $dbr->selectField( 'wikilog_subscriptions', 'ws_yes', array( 'ws_page' => $itemid, 'ws_user' => $wgUser->getID() ), __METHOD__ );
		if ( $r === false ) {
			$r = NULL;
		}
		return $r;
	}

	/**
	 * Generates and returns a "post new comment" form for the user to fill in
	 * and submit.
	 *
	 * @param $parent If provided, generates a "post reply" form to reply to
	 *   the given comment.
	 */
	public function getPostCommentForm( $parent = null, $inline_reply = false ) {
		global $wgUser, $wgTitle, $wgRequest;
		global $wgWikilogModerateAnonymous;

		if ( $this->includeSubpageComments() && !$parent || !$this->mSubject ) {
			return '';
		}

		$comment = $this->mPostedComment;
		$opts = $this->mFormOptions;

		$preview = '';
		$pid = $parent ? $parent->mID : null;
		if ( $comment && $comment->mParent == $pid ) {
			$check = $this->validateComment( $comment );
			if ( $check ) {
				$preview = Xml::wrapClass( wfMessage( $check )->text(), 'mw-warning', 'div' );
			} else {
				$preview = $this->mFormatter->formatComment( $this->mPostedComment );
			}
			$header = wfMessage( 'wikilog-form-preview' )->escaped();
			$preview = "<b>{$header}</b>{$preview}<hr/>";
		}

		$targetTitle = $parent ? $parent->mCommentTitle : $this->getTitle();
		$form =
			Html::hidden( 'action', 'wikilog' ) .
			Html::hidden( 'wpEditToken', $wgUser->getEditToken() ) .
			( $parent ? Html::hidden( 'wlParent', $parent->mID ) : '' );

		$fields = array();

		if ( $wgUser->isLoggedIn() ) {
			$fields[] = array(
				wfMessage( 'wikilog-form-name' )->text(),
				$this->mSkin->userLink( $wgUser->getId(), $wgUser->getName() )
			);
		} else {
			$loginTitle = SpecialPage::getTitleFor( 'Userlogin' );
			$loginLink = Linker::link( $loginTitle,
				wfMessage( 'loginreqlink' )->escaped(), array(),
				array( 'returnto' => $wgTitle->getPrefixedUrl() )
			);
			$message = wfMessage( 'wikilog-posting-anonymously', $loginLink )->text();
			$fields[] = array(
				Xml::label( wfMessage( 'wikilog-form-name' )->text(), 'wl-name' ),
				Xml::input( 'wlAnonName', 25, $opts->consumeValue( 'wlAnonName' ),
					array( 'id' => 'wl-name', 'maxlength' => 255 ) ) .
					"<p>{$message}</p>"
			);
		}

		$autofocus = $parent ? array( 'autofocus' => 'autofocus' ) : array();
		$fields[] = array(
			Xml::label( wfMessage( 'wikilog-form-comment' )->text(), 'wl-comment' ),
			Xml::textarea( 'wlComment', $opts->consumeValue( 'wlComment' ),
				40, 5, array( 'id' => 'wl-comment' ) + $autofocus )
		);

		if ( $this->mCaptchaForm ) {
			$fields[] = array( '', $this->mCaptchaForm );
		}

		if ( $wgWikilogModerateAnonymous && $wgUser->isAnon() ) {
			$fields[] = array( '', wfMessage( 'wikilog-anonymous-moderated' )->text() );
		}

		if ( $wgUser->getID() && $this->mSubject ) {
			$itemid = $parent ? $parent->mPost : $this->mSubject->getArticleID();
			$subscribed = $this->isSubscribed( $itemid );
			if ( $subscribed === NULL ) {
				$subscribed = true;
			}
			$subscribe_html = ' &nbsp; ' . Xml::checkLabel( wfMessage( 'wikilog-subscribe' )->text(), 'wl-subscribe', 'wl-subscribe', $subscribed );
		} else {
			$subscribe_html = '';
		}

		$fields[] = array( '',
			Xml::submitbutton( wfMessage( 'wikilog-submit' )->text(), array( 'name' => 'wlActionCommentSubmit' ) ) . WL_NBSP .
			Xml::submitbutton( wfMessage( 'wikilog-preview' )->text(), array( 'name' => 'wlActionCommentPreview' ) ) .
			$subscribe_html
		);

		$form .= WikilogUtils::buildForm( $fields );

		foreach ( $opts->getUnconsumedValues() as $key => $value ) {
			$form .= Html::hidden( $key, $value );
		}

		$form = Xml::tags( 'form', array(
			'action' => $targetTitle->getLocalUrl()."#wl-comment-form",
			'method' => 'post',
		), $form );

		$msgid = ( $parent ? 'wikilog-post-reply' : 'wikilog-post-comment' );
		return Xml::fieldset( wfMessage( $msgid )->text(), $preview . $form,
			array( 'id' => ( $inline_reply ? 'wl-comment-form-reply' : 'wl-comment-form' ) ) ) . "\n";
	}

	protected function setCommentApproval( $comment, $approval ) {
		global $wgOut, $wgUser;

		# Check if comment is really awaiting moderation.
		if ( $comment->mStatus != WikilogComment::S_PENDING ) {
			$wgOut->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
			return;
		}

		$log = new LogPage( 'wikilog' );
		$title = $comment->getCommentArticleTitle();

		if ( $approval == 'approve' ) {
			$comment->mStatus = WikilogComment::S_OK;
			$comment->saveComment();
			$log->addEntry( 'c-approv', $title, '' );
			$wgOut->redirect( $this->mTalkTitle->getFullUrl() );
		} elseif ( $approval == 'reject' ) {
			$reason = wfMessage( 'wikilog-log-cmt-rejdel', $comment->mUserText )->inContentLanguage()->text();
			$id = $title->getArticleID( Title::GAID_FOR_UPDATE );
			if ( $this->doDeleteArticle( $reason, false, $id ) ) {
				$comment->deleteComment();
				$log->addEntry( 'c-reject', $title, '' );
				$wgOut->redirect( $this->mTalkTitle->getFullUrl() );
			} else {
				$wgOut->showFatalError( wfMessage( 'cannotdelete' )->parse() );
				$wgOut->addHTML( Xml::element( 'h2', null, LogPage::logName( 'delete' ) ) );
				LogEventsList::showLogExtract( $wgOut, 'delete', $this->mTitle->getPrefixedText() );
			}
		} else {
			$wgOut->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
		}
	}

	/**
	 * Subscribes/unsubscribes current user to/from comments to some post
	 */
	protected function subscribe( $page_id ) {
		global $wgUser, $wgRequest;
		if ( $wgUser->getID() ) {
			$subscribe = $wgRequest->getBool( 'wl-subscribe' ) ? 1 : 0;
			$dbw = wfGetDB( DB_MASTER );
			$dbw->replace( 'wikilog_subscriptions', array( array( 'ws_page', 'ws_user' ) ), array(
				'ws_page' => $page_id,
				'ws_user' => $wgUser->getID(),
				'ws_yes'  => $subscribe,
				'ws_date' => $dbw->timestamp(),
			), __METHOD__ );
			return $subscribe;
		}
		return NULL;
	}

	/**
	 * Validates and saves a new comment. Redirects back to the comments page.
	 * @param $comment Posted comment.
	 */
	protected function postComment( WikilogComment &$comment ) {
		global $wgOut, $wgUser;
		global $wgWikilogModerateAnonymous;

		$check = $this->validateComment( $comment );

		if ( $check !== false ) {
			return $this->view();
		}

		# Check through captcha.
		if ( !WlCaptcha::confirmEdit( $this->getTitle(), $comment->getText() ) ) {
			$this->mCaptchaForm = WlCaptcha::getCaptchaForm();
			$wgOut->setPageTitle( $this->mTitle->getPrefixedText() );
			$wgOut->setRobotPolicy( 'noindex,nofollow' );
			$wgOut->addHtml( $this->getPostCommentForm( $comment->mParent ) );
			return;
		}

		# Limit rate of comments.
		if ( $wgUser->pingLimiter() ) {
			$wgOut->rateLimited();
			return;
		}

		# Set pending state if moderated.
		if ( $comment->mUserID == 0 && $wgWikilogModerateAnonymous ) {
			$comment->mStatus = WikilogComment::S_PENDING;
		}

		if ( !$this->mSubject->getArticleID() || !$this->exists() ) {
			$user = User::newFromName( wfMessage( 'wikilog-auto' )->inContentLanguage()->text(), false );
			if ( !$this->exists() ) {
				// Initialize a blank talk page.
				$this->doEdit(
					wfMessage( 'wikilog-newtalk-text' )->inContentLanguage()->text(),
					wfMessage( 'wikilog-newtalk-summary' )->inContentLanguage()->text(),
					EDIT_NEW | EDIT_SUPPRESS_RC, false, $user
				);
			}
			if ( !$this->mSubject->getArticleID() ) {
				// Initialize a blank subject page.
				$page = new WikiPage( $this->mSubject );
				$page->doEdit(
					wfMessage( 'wikilog-newtalk-text' )->inContentLanguage()->text(),
					wfMessage( 'wikilog-newtalk-summary' )->inContentLanguage()->text(),
					EDIT_NEW | EDIT_SUPPRESS_RC, false, $user
				);
				if ( $this->mSubjectUser ) {
					// Add newly created user talk page to user's watchlist
					$w = WatchedItem::fromUserTitle( $this->mSubjectUser, $this->mSubject );
					$w->addWatch();
				}
			}
		}

		$comment->saveComment();

		$this->subscribe( $comment->mPost );

		$dest = $this->getTitle();
		$dest->setFragment( "#c{$comment->mID}" );
		$wgOut->redirect( $dest->getFullUrl() );
	}

	/**
	 * Returns a new non-validated WikilogComment object with the contents
	 * posted using the post comment form. The result should be validated
	 * using validateComment() before using.
	 */
	protected function getPostedComment() {
		global $wgUser, $wgRequest;

		$parent = $wgRequest->getIntOrNull( 'wlParent' );
		$anonname = trim( $wgRequest->getText( 'wlAnonName' ) );
		$text = trim( $wgRequest->getText( 'wlComment' ) );

		$comment = WikilogComment::newFromText( $this->mSubject, $text, $parent );
		$comment->setUser( $wgUser );
		if ( $wgUser->isAnon() ) {
			$comment->setAnon( $anonname );
		}
		return $comment;
	}

	/**
	 * Checks if the given comment is valid for posting.
	 * @param $comment Comment to validate.
	 * @return False if comment is valid, error message identifier otherwise.
	 */
	protected static function validateComment( WikilogComment &$comment ) {
		global $wgWikilogMaxCommentSize;

		$length = strlen( $comment->mText );

		if ( $length == 0 ) {
			return 'wikilog-comment-is-empty';
		}
		if ( $length > $wgWikilogMaxCommentSize ) {
			return 'wikilog-comment-too-long';
		}

		if ( $comment->mUserID == 0 ) {
			$anonname = User::getCanonicalName( $comment->mAnonName, 'usable' );
			if ( !$anonname ) {
				return 'wikilog-comment-invalid-name';
			}
			$comment->setAnon( $anonname );
		}

		return false;
	}
}
