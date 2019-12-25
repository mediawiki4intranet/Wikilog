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
 * Wikilog article comment database entry.
 */
class WikilogComment
{
	/**
	 * Comment statuses.
	 */
	const S_OK				= 'OK';			///< Comment is published.
	const S_PENDING			= 'PENDING';	///< Comment is pending moderation.
	const S_DELETED			= 'DELETED';	///< Comment was removed.

	/**
	 * Mapping of comment statuses to readable messages. System messages are
	 * "wikilog-comment-{$statusMap[$status]}", except when false (for S_OK).
	 */
	public static $statusMap = array(
		self::S_OK				=> false,
		self::S_PENDING			=> 'pending',
		self::S_DELETED			=> 'deleted',
	);

	static $saveInProgress = array();

	/**
	 * Title this comment is associated to.
	 */
	public  $mSubject		= null;

	/**
	 * General data about the comment.
	 */
	public  $mID			= null;		///< Comment ID.
	public  $mPost			= null;		///< Post ID.
	public  $mParent		= null;		///< Parent comment ID.
	public  $mParentObj		= null;		///< Parent comment object.
	public  $mThread		= null;		///< Comment thread.
	public  $mUserID		= null;		///< Comment author user id.
	public  $mUserText		= null;		///< Comment author user name.
	public  $mAnonName		= null;		///< Comment anonymous author name.
	public  $mStatus		= null;		///< Comment status.
	public  $mTimestamp		= null;		///< Date the comment was published.
	public  $mUpdated		= null;		///< Date the comment was last updated.
	public  $mCommentPage	= null;		///< Comment page id.
	public  $mCommentTitle	= null;		///< Comment page title.
	public  $mCommentRev	= null;		///< Comment revision id.
	public  $mText			= null;		///< Comment text.
	public  $mVisited		= null;		///< Is comment already visited by current user after last change?

	/**
	 * Whether the text was changed, and thus a database update is required.
	 */
	private $mTextChanged	= false;

	/**
	 * Constructor.
	 */
	public function __construct( Title $subject ) {
		$this->mSubject = $subject;
	}

	/**
	 * Returns the comment id.
	 */
	public function getID() {
		return $this->mID;
	}

	/**
	 * Set the author of the comment to the given (authenticated) user.
	 *
	 * This function can also be used when $user->getId() == 0
	 * (i.e. anonymous). In this case, a call to $this->setAnon() should
	 * follow, in order to set the anonymous name.
	 */
	public function setUser( $user ) {
		$this->mUserID = $user->getId();
		$this->mUserText = $user->getName();
		$this->mAnonName = null;
	}

	/**
	 * Set the anonymous (i.e. not logged in) author name.
	 */
	public function setAnon( $name ) {
		$this->mAnonName = $name;
	}

	/**
	 * Returns the wikitext of the comment.
	 */
	public function getText() {
		return $this->mText;
	}

	/**
	 * Returns the subject title of this comment.
	 */
	public function getSubject() {
		return $this->mSubject;
	}

	/**
	 * Get the parent comment object
	 */
	public function getParentObj() {
		if ( !$this->mParent ) {
			return NULL;
		}
		if ( !$this->mParentObj ) {
			$this->mParentObj = WikilogComment::newFromID( $this->mParent );
		}
		if ( !$this->mParentObj || $this->mParentObj->mThread === '' ) {
			throw new MWException( 'Invalid parent history.' );
		}
		return $this->mParentObj;
	}

	/**
	 * Changes the wikitext of the comment.
	 */
	public function setText( $text ) {
		$this->mText = $text;
		$this->mTextChanged = true;
	}

	/**
	 * Returns whether the comment is visible (not pending or deleted).
	 */
	public function isVisible() {
		return $this->mStatus == self::S_OK;
	}

	/**
	 * Returns whether the comment text is changed (DB update required).
	 */
	public function isTextChanged() {
		return $this->mTextChanged;
	}

	/**
	 * Load current revision of comment wikitext.
	 */
	public function loadText() {
		$dbr = wfGetDB( DB_REPLICA );
		$rev = Revision::loadFromId( $dbr, $this->mCommentRev );
		if ( $rev ) {
			$this->mText = $rev->getText();
			$this->mTextChanged = false;
		}
	}

	/**
	 * Saves comment data in the database.
	 */
	public function saveComment() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		$this->mPost = $this->mSubject->getArticleId();

		$data = array(
			'wlc_parent'    => $this->mParent,
			'wlc_post'      => $this->mPost,
			'wlc_user'      => $this->mUserID,
			'wlc_user_text' => $this->mUserText,
			'wlc_anon_name' => $this->mAnonName,
			'wlc_status'    => $this->mStatus,
			'wlc_timestamp' => $dbw->timestamp( $this->mTimestamp ),
			'wlc_updated'   => $dbw->timestamp( $this->mUpdated ),
			'wlc_comment_page' => $this->mCommentPage,
		);

		$delayed = array();

		# Main update.
		$emailnotify = false;
		$forCreation = false;
		if ( $this->mID ) {
			$dbw->update( 'wikilog_comments', $data,
				array( 'wlc_id' => $this->mID ), __METHOD__ );
		} else {
			$cid = $dbw->nextSequenceValue( 'wikilog_comments_wlc_id_seq' );
			$data = array( 'wlc_id' => $cid ) + $data;
			$dbw->insert( 'wikilog_comments', $data, __METHOD__ );
			$this->mID = $dbw->insertId();

			# Now that we have an ID, we can generate the thread.
			if ( $this->mParent ) {
				$p = $this->getParentObj();
				# We anyway rely on that newer comments have greater IDs, so this difference is OK
				$this->mThread = $p->mThread . WikilogUtils::encodeVarint( $this->mID - $p->mID );
			} else {
				$this->mThread = WikilogUtils::encodeVarint( $this->mID );
			}
			$delayed['wlc_thread'] = $this->mThread;
			$emailnotify = true;
			$forCreation = !$this->mCommentTitle && !$this->mCommentPage;
		}

		# Save article with comment text.
		$this->mCommentTitle = $this->getCommentArticleTitle( $forCreation );
		if ( $this->mTextChanged ) {
			$parent = $this->getParentObj();
			$parent = $parent ? $parent->mCommentTitle->getText() : '';
			# Append comment metadata
			$metadata = "\n{{wl-comment: $parent";
			if ( $this->mAnonName ) {
				$metadata .= " | " . htmlspecialchars( str_replace( '}}', '', $this->mAnonName ) ) . " ";
			}
			$metadata .= " }}";
			$this->mText = preg_replace( '/\{\{\s*#wl-comment\s*:[^\}]*\}\}\s*$/is', '', $this->mText );
			$this->mText .= $metadata;

			# Prevent WikilogHooks from generating a second WikilogComment object
			self::$saveInProgress[$this->mCommentTitle->getPrefixedText()] = true;
			$art = new Article( $this->mCommentTitle );
			$art->doEdit( $this->mText, $this->getAutoSummary() );
			$this->mTextChanged = false;
			unset( self::$saveInProgress[$this->mCommentTitle->getPrefixedText()] );

			$this->mCommentPage = $art->getID();
			$delayed['wlc_comment_page'] = $this->mCommentPage;
		}

		# Delayed updates.
		if ( !empty( $delayed ) ) {
			$dbw->update( 'wikilog_comments', $delayed,
				array( 'wlc_id' => $this->mID ), __METHOD__ );
		}

		$this->updateTalkInfo();

		# Mark comment posted/edited by a user already read by him
		if ( $this->mUserID ) {
			WikilogUtils::updateLastVisit( $this->mCommentTitle, $this->mTimestamp, $this->mUserID );
		}

		# Commit
		$dbw->commit();

		$this->invalidateCache();

		# Notify item and parent comment authors about new comment
		if ( $emailnotify ) {
			$this->sendCommentEmails();
		}
	}

	/**
	 * Notify about new comment by email
	 */
	public function sendCommentEmails() {
		global $wgParser, $wgPasswordSender, $wgWikilogNamespaces, $wgTitle;
		if ( $wgTitle->getNamespace() == NS_SPECIAL ) {
			$alias = SpecialPageFactory::resolveAlias( $wgTitle->getText() );
			if ( $alias[0] == 'Import' ) {
				// Suppress notifications during Import
				// FIXME This should be probably done better but WikiImporter has no appropriate hooks...
				return;
			}
		}
		/* Message arguments:
		 * $1 = full page name of comment page
		 * $2 = name of the user who posted the new comment
		 * $3 = full URL to article item
		 * $4 = item talk page anchor for the new comment
		 * $5 (optional) = full page name of parent comment page
		 * $6 (optional) = name of the user who posted the parent comment
		 */
		$args = array(
			$this->mCommentTitle->getPrefixedText(),
			$this->mUserText,
			$this->mSubject->getFullURL(),
			'c' . $this->mID,
			'',
			'',
		);
		if ( $this->mParentObj ) {
			$args[4] = $this->mParentObj->mCommentTitle->getPrefixedText();
			$args[5] = $this->mParentObj->mUserText;
		}
		// Get user IDs for notification
		$dbr = wfGetDB( DB_REPLICA );
		$id = $this->mSubject->getArticleId();
		$parent = Title::makeTitle( $this->mSubject->getNamespace(), $this->mSubject->getBaseText() );
		$wlid = $parent->getArticleId();
		$s = $dbr->tableName( 'wikilog_subscriptions' );
		$u = $dbr->tableName( 'user' );
		$up = $dbr->tableName( 'user_properties' );
		$w = $dbr->tableName( 'watchlist' );
		// $to_ids = array( <userid> => array( 'email' => <string>, 'can_unsubscribe' => <boolean> ), ... )
		$to_ids = array();
		$email_auth = "AND user_email!='' AND user_email_authenticated IS NOT NULL";
		$result = $dbr->query(
			// Notify users subscribed to this post
			"SELECT ws_user user_id, user_email, 1 can_unsubscribe FROM $s".
			" INNER JOIN $u ON user_id=ws_user $email_auth".
			" WHERE ws_page=$id AND ws_yes=1".
			// Notify users subscribed to the blog and not unsubscribed from this post
			" UNION ALL".
			" SELECT s1.ws_user user_id, user_email, 1 can_unsubscribe FROM $s s1".
			" INNER JOIN $u ON user_id=s1.ws_user $email_auth".
			" LEFT JOIN $s s2 ON s2.ws_user=s1.ws_user AND s2.ws_page=$id AND s2.ws_yes=0".
			" WHERE s1.ws_page=$wlid AND s1.ws_yes=1 AND s2.ws_user IS NULL".
			// Notify users subscribed to talk via watchlist and not unsubscribed from the post
			" UNION ALL".
			" SELECT wl_user user_id, user_email, 1 can_unsubscribe FROM $w".
			" INNER JOIN $u ON user_id=wl_user $email_auth".
			" LEFT JOIN $s s2 ON s2.ws_user=wl_user AND s2.ws_page=$id AND s2.ws_yes=0".
			" WHERE wl_namespace=".MWNamespace::getTalk( $this->mSubject->getNamespace() ).
			" AND wl_title=".$dbr->addQuotes( $this->mSubject->getDBkey() )." AND s2.ws_user IS NULL".
			// Notify users subscribed to all blogs via user preference
			// and not unsubscribed from this post and not unsubscribed from this blog,
			// but only for comments to Wikilog posts (not to the ordinary pages)
			// FIXME: untie from Wikilog
			( !empty( $wgWikilogNamespaces ) && in_array( $this->mSubject->getNamespace(), $wgWikilogNamespaces )
				? " UNION ALL".
				" SELECT up_user user_id, user_email, 1 can_unsubscribe FROM $up".
				" INNER JOIN $u ON user_id=up_user $email_auth".
				" LEFT JOIN $s ON ws_user=up_user AND ws_page IN ($id, $wlid) AND ws_yes=0".
				" WHERE up_property='wl-subscribetoall' AND up_value='1' AND ws_user IS NULL"
				: ''
			).
			// Always notify post author(s), and they cannot unsubscribe (0 means that)
			" UNION ALL".
			" SELECT wla_author user_id, user_email, 0 can_unsubscribe FROM ".$dbr->tableName( 'wikilog_authors' ).
			" INNER JOIN $u ON user_id=wla_author $email_auth".
			" WHERE wla_page=$id".
			// Always notify parent comment author
			( !$this->mParentObj ? "" :
				" UNION ALL SELECT user_id, user_email, 0 can_unsubscribe FROM $u".
				" WHERE user_id=".$this->mParentObj->mUserID." $email_auth" ).
			// Always notify users about comments to their talk page
			( $this->mSubject->getNamespace() != NS_USER ? "" :
				" UNION ALL SELECT user_id, user_email, 0 can_unsubscribe FROM $u".
				" WHERE user_name=".$dbr->addQuotes( $this->mSubject->getText() )." $email_auth" ),
			__METHOD__
		);
		foreach ( $result as $u ) {
			// "Cannot unsubscribe" overrides "can unsubscribe"
			if ( !isset( $to_ids[$u->user_id] ) || !$u->can_unsubscribe ) {
				$to_ids[$u->user_id] = $u;
			}
		}
		$dbr->freeResult( $result );
		// Build message subject, body and unsubscribe link
		$saveExpUrls = WikilogParser::expandLocalUrls();
		$popt = new ParserOptions( User::newFromId( $this->mUserID ) );
		$subject = $wgParser->parse( wfMessage( 'wikilog-comment-email-subject', $args )->plain(),
			$this->mSubject, $popt, false, false );
		$subject = 'Re: ' . strip_tags( $subject->getText() );
		$body = $wgParser->parse( wfMessage( 'wikilog-comment-email-body', $args )->plain(),
			$this->mSubject, $popt, true, false );
		$body = $body->getText();
		WikilogParser::expandLocalUrls( $saveExpUrls );
		// Unsubscribe link is appended to e-mails of users that can unsubscribe
		global $wgServer, $wgScript;
		$unsubscribe = wfMessage(
			'wikilog-comment-email-unsubscribe',
			$this->mSubject->getSubpageText(),
			$wgServer.$wgScript.'?'.http_build_query( array(
				'title' => $this->mSubject->getTalkPage()->getPrefixedText(),
				'action' => 'wikilog',
				'wlActionSubscribe' => 1,
				'wl-subscribe' => 0,
			) )
		)->plain();
		// Build e-mail lists (with unsubscribe link, without unsubscribe link)
		// TODO: Send e-mail to user in his own language?
		$to_with = array();
		$to_without = array();
		foreach ( $to_ids as $id => $to ) {
			// Do not send user his own comments
			if ( $id != $this->mUserID ) {
				$email = new MailAddress( $to->user_email );
				if ( $to->can_unsubscribe ) {
					$to_with[] = $email;
				} else {
					$to_without[] = $email;
				}
			}
		}

		$serverName = substr( $wgServer, strpos( $wgServer, '//' ) + 2 );
		$aid = $this->mSubject->getSubjectPage()->getArticleID();
		$headers = array(
			'In-Reply-To' => '<wikilog-' . $aid . '@' . $serverName . '>',
			'References' => '<wikilog-' . $aid . '@' . $serverName . '>',
		);

		// Send e-mails using $wgPasswordSender as from address
		$from = new MailAddress( $wgPasswordSender, 'Wikilog' );
		if ( $to_with ) {
			WikilogUtils::sendHtmlMail( $to_with, $from, $subject, $body . $unsubscribe, $headers );
		}
		if ( $to_without ) {
			WikilogUtils::sendHtmlMail( $to_without, $from, $subject, $body, $headers );
		}
	}

	/**
	 * Updates talk info for this comment
	 */
	protected function updateTalkInfo() {
		# Loose coupling with other Wikilog code
		global $wgWikilogNamespaces;
		$isWikilogPost = isset( $wgWikilogNamespaces ) && in_array( $this->mSubject->getNamespace(), $wgWikilogNamespaces );

		# Update number of comments
		WikilogUtils::updateTalkInfo( $this->mPost, $isWikilogPost );
	}

	/**
	 * Deletes comment data from the database.
	 */
	public function deleteComment() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		$dbw->delete( 'wikilog_comments', array( 'wlc_id' => $this->mID ), __METHOD__ );

		$this->updateTalkInfo();

		$dbw->commit();

		$this->invalidateCache();
		$this->mID = null;
	}

	/**
	 * Invalidate some caches.
	 */
	public function invalidateCache() {
		$this->mCommentTitle->invalidateCache();
		$this->mSubject->invalidateCache();
		$this->mSubject->getTalkPage()->invalidateCache();

		$base = $this->mSubject->getBaseText();
		if ( $base ) {
			$base = Title::makeTitle( $this->mSubject->getNamespace(), $base );
			$base->invalidateCache();
		}
	}

	/**
	 * Returns comment article title.
	 *
	 * @param $forCreation If true, a new non-colliding title will be generated
	 */
	public function getCommentArticleTitle( $forCreation = false ) {
		if ( $this->mCommentTitle ) {
			return $this->mCommentTitle;
		} elseif ( $this->mCommentPage ) {
			return Title::newFromID( $this->mCommentPage, Title::GAID_FOR_UPDATE );
		} else {
			$it = $this->mSubject;
			$title = Title::makeTitle(
				MWNamespace::getTalk( $it->getNamespace() ),
				$it->getText() . '/c' . self::padID( $this->mID )
			);
			if ( $forCreation && $title->exists() ) {
				// Collision! Are there imported comments?
				// Generate another title.
				$dbw = wfGetDB( DB_MASTER );
				$max = $dbw->selectField( 'page', 'MAX( page_title )', array(
					'page_namespace' => $title->getNamespace(),
					'page_title ' . $dbw->buildLike( $title->getDBkey().'-', $dbw->anyString() )
				), __METHOD__ );
				if ( !$max ) {
					$max = $title->getDBkey().'-1';
				} else {
					$max = preg_replace_callback( '/(\D)(\d*)$/s', function($m) { return $m[1].($m[2]+1); }, $max);
				}
				$title = Title::makeTitle( $title->getNamespace(), $max );
			}
			return $title;
		}
	}

	/**
	 * Returns automatic summary (for recent changes) for the posted comment.
	 */
	public function getAutoSummary() {
		global $wgContLang;
		$user = $this->mUserID ? $this->mUserText : $this->mAnonName;
		$summ = $wgContLang->truncate( str_replace( "\n", ' ', $this->mText ),
			max( 0, 200 - strlen( wfMessage( 'wikilog-comment-autosumm' )->inContentLanguage()->text() ) ),
			'...' );
		return wfMessage( 'wikilog-comment-autosumm', $user, $summ )->inContentLanguage()->text();
	}

	/**
	 * Formats the id of a comment as a string, padding it with zeros if
	 * necessary.
	 */
	public static function padID( $id ) {
		return str_pad( intval( $id ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Creates a new comment object from a database row.
	 * @param $row Row from database.
	 * @return New WikilogComment object.
	 */
	public static function newFromRow( $row, $subjectTitle = NULL ) {
		if ( !$row ) {
			return false;
		}
		if ( !$subjectTitle ) {
			$subjectTitle = Title::newFromRow( $row );
		}
		// FIXME remove usage of global $wgUser
		global $wgUser;
		$comment = new WikilogComment( $subjectTitle );
		$comment->mID           = intval( $row->wlc_id );
		$comment->mParent       = $row->wlc_parent;
		$comment->mThread       = $row->wlc_thread;
		$comment->mPost         = intval( $row->wlc_post );
		$comment->mUserID       = intval( $row->wlc_user );
		$comment->mUserText     = strval( $row->wlc_user_text );
		$comment->mAnonName     = strval( $row->wlc_anon_name );
		$comment->mStatus       = strval( $row->wlc_status );
		$comment->mTimestamp    = wfTimestamp( TS_MW, $row->wlc_timestamp );
		$comment->mUpdated      = wfTimestamp( TS_MW, $row->wlc_updated );
		$comment->mCommentPage  = $row->wlc_comment_page;
		$comment->mVisited      = ( $wgUser->getID() ? $row->wlc_status != 'OK'
			|| $row->wlc_last_visit && $row->wlc_last_visit >= $row->wlc_updated : true );

		# This information may not be available for deleted comments.
		if ( $row->wlc_page_title && $row->wlc_page_latest ) {
			$comment->mCommentTitle = Title::newFromRow( (object)array(
				'page_id'           => $row->wlc_comment_page,
				'page_namespace'    => $row->wlc_page_namespace,
				'page_title'        => $row->wlc_page_title,
				'page_len'          => $row->wlc_page_len,
				'page_is_redirect'  => $row->wlc_page_is_redirect,
				'page_latest'       => $row->wlc_page_latest,
			) );
			$comment->mCommentRev = $row->wlc_page_latest;
		}
		return $comment;
	}

	/**
	 * Creates a new comment object for a new comment, given the text and
	 * the parent comment.
	 * @param $page Subject page title this comment is for.
	 * @param $text Comment wikitext as a string.
	 * @param $parent Parent comment id.
	 * @return New WikilogComment object.
	 */
	public static function newFromText( Title $subject, $text, $parent = null ) {
		$ts = wfTimestamp( TS_MW );
		$comment = new WikilogComment( $subject );
		$comment->mParent    = $parent;
		$comment->mStatus    = self::S_OK;
		$comment->mTimestamp = $ts;
		$comment->mUpdated   = $ts;
		$comment->setText( $text );
		return $comment;
	}

	/**
	 * Creates a comment object for a previously created page.
	 * The resulting object won't try to overwrite that page during save.
	 *
	 * @param Title $title Comment page title
	 * @param string|Title $parent Parent comment title
	 * @param string|NULL $anonName Name of anonymous user who did post that comment
	 */
	public static function newFromCreatedPage( Title $title, $parent, $anonName ) {
		$subject = Title::makeTitle( MWNamespace::getSubject( $title->getNamespace() ), $title->getBaseText() );
		if ( $parent && !$parent instanceof Title ) {
			$parentTitle = Title::newFromText( $parent, $title->getNamespace() );
		} else {
			$parentTitle = $parent;
		}
		if ( $parentTitle && $parentTitle->getArticleId() ) {
			$parentComment = WikilogComment::newFromPageId( $parentTitle->getArticleId() );
			$parentCommentId = $parentComment->getId();
		} else {
			$parentCommentId = NULL;
		}
		$oldRev = WikilogUtils::getOldestRevision( $title->getArticleId() );
		$comment = new WikilogComment( $subject );
		$comment->mParent = $parentCommentId;
		$comment->mStatus = self::S_OK;
		$comment->mUserID = $oldRev->getUser( Revision::RAW );
		$comment->mUserText = $oldRev->getUserText( Revision::RAW );
		if ( !$comment->mUserID ) {
			$comment->mAnonName = $anonName;
		}
		$comment->mTimestamp = $oldRev->getTimestamp();
		$comment->mUpdated = wfTimestamp( TS_MW );
		$comment->mCommentPageTitle = $title;
		$comment->mCommentPage = $title->getArticleId();
		return $comment;
	}

	/**
	 * Creates a new comment object from an existing comment id.
	 * Data is fetched from the database.
	 * @param $item Wikilog article item.
	 * @param $id Comment id.
	 * @return New WikilogComment object, or NULL if comment doesn't exist.
	 */
	public static function newFromID( $id ) {
		$dbr = wfGetDB( DB_REPLICA );
		$row = self::loadFromID( $dbr, $id );
		return self::newFromRow( $row );
	}

	/**
	 * Creates a new comment object from an existing comment page id.
	 * Data is fetched from the database.
	 * @param $pageid Comment page id.
	 * @return New WikilogComment object, or NULL if comment doesn't exist.
	 */
	public static function newFromPageID( $pageid ) {
		$dbr = wfGetDB( DB_REPLICA );
		$row = self::loadFromPageID( $dbr, $pageid );
		return self::newFromRow( $row );
	}

	/**
	 * Load information about a comment from the database given a set of
	 * conditions.
	 * @param $dbr Database connection object.
	 * @param $conds Conditions.
	 * @return Database row, or false.
	 */
	private static function loadFromConds( $dbr, $conds ) {
		$tables = self::selectTables();
		$fields = self::selectFields();
		$row = $dbr->selectRow(
			$tables['tables'],
			$fields,
			$conds,
			__METHOD__,
			array(),
			$tables['join_conds']
		);
		return $row;
	}

	/**
	 * Load information about a comment from the database given a set a
	 * comment id.
	 * @param $dbr Database connection object.
	 * @param $id Comment id.
	 * @return Database row, or false.
	 */
	private static function loadFromID( $dbr, $id ) {
		return self::loadFromConds( $dbr, array( 'wlc_id' => $id ) );
	}

	/**
	 * Load information about a comment from the database given a set of
	 * conditions.
	 * @param $dbr Database connection object.
	 * @param $pageid Comment page id.
	 * @return Database row, or false.
	 */
	private static function loadFromPageID( $dbr, $pageid ) {
		return self::loadFromConds( $dbr, array( 'wlc_comment_page' => $pageid ) );
	}

	/**
	 * Fetch all comments given a set of conditions.
	 * @param $dbr Database connection object.
	 * @param $conds Query conditions.
	 * @param $options Query options.
	 * @return Database query result object.
	 */
	private static function fetchFromConds( $dbr, $conds, $options = array() ) {
		$tables = self::selectTables();
		$fields = self::selectFields();
		$result = $dbr->select(
			$tables['tables'],
			$fields,
			$conds,
			__METHOD__,
			$options,
			$tables['join_conds']
		);
		return $result;
	}

	/**
	 * Return the list of database tables required to create a new instance
	 * of WikilogComment.
	 */
	public static function selectTables() {
		// FIXME remove usage of global $wgUser
		global $wgUser;
		$r = array(
			'tables' => array(
				'wikilog_comments',
				'p' => 'page',
				'c' => 'page',
			),
			'join_conds' => array(
				'p' => array( 'JOIN', 'p.page_id = wlc_post' ),
				'c' => array( 'LEFT JOIN', 'c.page_id = wlc_comment_page' ),
			)
		);
		if ( $wgUser->getId() ) {
			$r['tables']['cv'] = 'page_last_visit';
			$r['join_conds']['cv'] =
				array( 'LEFT JOIN', array( 'cv.pv_page = wlc_comment_page', 'cv.pv_user' => $wgUser->getId() ) );
		}
		return $r;
	}

	/**
	 * Return the list of post fields required to create a new instance of
	 * WikilogComment.
	 */
	public static function selectFields() {
		// FIXME remove usage of global $wgUser
		global $wgUser;
		return array(
			'wlc_id',
			'wlc_parent',
			'wlc_thread',
			'wlc_post',
			'wlc_user',
			'wlc_user_text',
			'wlc_anon_name',
			'wlc_status',
			'wlc_timestamp',
			'wlc_updated',
			'wlc_comment_page',
			'p.page_id',
			'p.page_namespace',
			'p.page_title',
			'p.page_len',
			'p.page_is_redirect',
			'p.page_latest',
			'c.page_namespace AS wlc_page_namespace',
			'c.page_title AS wlc_page_title',
			'c.page_len AS wlc_page_len',
			'c.page_is_redirect AS wlc_page_is_redirect',
			'c.page_latest AS wlc_page_latest',
			$wgUser->getId() ? 'cv.pv_date AS wlc_last_visit' : 'NULL AS wlc_last_visit',
		);
	}
}

/**
 * Comment formatter.
 * @since Wikilog v1.1.0.
 */
class WikilogCommentFormatter
{
	protected $mSkin;               ///< Skin used when rendering comments.
	protected $mAllowReplies;       ///< Whether to show reply links.
	protected $mAllowModeration;    ///< User is allowed to moderate.
	protected $mPermalinkTitle;     ///< Optional title used for permalinks.

	/// Whether to show the item title.
	protected $mShowItem = false;

	/// Comment stack for thread formatting.
	protected $mThreadStack = array();
	protected $mThreadRoot = array();

	/// Whether to include link to parent in comment info
	var $mWithParent = false;

	/**
	 * Constructor.
	 *
	 * @param $title Title of the page.
	 * @param $wi WikilogInfo object with information about the wikilog and
	 *   the item.
	 */
	public function __construct( $skin = false, $allowReplies = false ) {
		global $wgUser;
		$this->mSkin = $skin ? $skin : RequestContext::getMain()->getSkin();
		$this->mAllowReplies = $allowReplies;
		$this->mAllowModeration = $wgUser->isAllowed( 'wl-moderation' );
	}

	/**
	 * Set page title used for permanent links. If not set, permalinks point
	 * to their own comment page.
	 *
	 * @param $title Title object to use for permalinks.
	 */
	public function setPermalinkTitle( $title = null ) {
		return wfSetVar( $this->mPermalinkTitle, $title );
	}

	/**
	 * Set whether the item the comment is about is to be printed.
	 */
	public function setShowItem( $value = true ) {
		return wfSetVar( $this->mShowItem, $value );
	}

	/**
	 * Format a single comment in HTML.
	 *
	 * @param $comment Comment to be formatted.
	 * @param $highlight Whether the comment should be highlighted.
	 * @return Generated HTML.
	 */
	public function formatComment( $comment, $highlight = false ) {
		global $wgUser, $wgOut;

		$hidden = WikilogComment::$statusMap[ $comment->mStatus ];

		# div class.
		$divclass = array( 'wl-comment' );
		if ( !$comment->isVisible() ) {
			$divclass[] = "wl-comment-{$hidden}";
		}
		if ( $comment->mUserID ) {
			$divclass[] = 'wl-comment-by-user';
		} else {
			$divclass[] = 'wl-comment-by-anon';
		}

		# If user is has moderator privileges and the comment is pending
		# approval, highlight it.
		$highlight = !$comment->mVisited || $this->mAllowModeration && $comment->mStatus == WikilogComment::S_PENDING;

		if ( !$comment->isVisible() && !$this->mAllowModeration ) {
			# Placeholder.
			$status = wfMessage( "wikilog-comment-{$hidden}" )->text();
			$html = WikilogUtils::wrapDiv( 'wl-comment-placeholder', $status );
		} else {
			# The comment.
			$params = $this->getCommentMsgParams( $comment );
			$html = $this->formatCommentHeader( $comment, $params );

			if ( 0 && $comment->mID && $comment->mCommentRev ) {
				list( $article, $parserOutput ) = WikilogUtils::parsedArticle( $comment->mCommentTitle );
				$text = $parserOutput->getText();
			} else {
				// FIXME do not reuse wgParser
				global $wgParser, $wgUser, $wgTitle;
				$text = $comment->getText();
				$text = $wgParser->parse( $text, $wgTitle, ParserOptions::newFromUser( $wgUser ) );
				$text = $text->getText();
			}

			if ( $text ) {
				$html .= WikilogUtils::wrapDiv( 'wl-comment-text', $text );
			}

			$html .= $this->formatCommentFooter( $comment, $params );
			$html .= $this->getCommentToolLinks( $comment );
		}

		# Update last visit
		WikilogUtils::updateLastVisit( $comment->mCommentPage );

		# Enclose everything in a div.
		if ( $highlight ) {
			$divclass[] = 'wl-comment-highlight';
		}
		$anchor = $comment->mID ? Xml::tags( 'a', array('name' => "id" . $comment->mID), '' ) : '';
		return Xml::tags( 'div', array(
			'class' => implode( ' ', $divclass ),
			'id' => ( $comment->mID ? "c{$comment->mID}" : 'cpreview' )
		), $anchor . $html );
	}

	/**
	 * Format and return the header of a comment. This processes the
	 * 'wikilog-comment-header' system message with the given parameters,
	 * possibly adds some status messages (for pending or deleted posts),
	 * and returns the result.
	 *
	 * @param $comment Comment.
	 * @param $params Message parameters, from getCommentMsgParams().
	 * @return HTML-formatted comment header.
	 */
	public function formatCommentHeader( $comment, $params ) {
		$status = "";
		if ( !$comment->isVisible() ) {
			# If comment is not visible to non-moderators, make note of it.
			$hidden = WikilogComment::$statusMap[ $comment->mStatus ];
			$status = WikilogUtils::wrapDiv( 'wl-comment-status', wfMessage( "wikilog-comment-{$hidden}" )->text() );
		}

		$header = wfMessage( 'wikilog-comment-header' )->inContentLanguage()->rawParams( $params )->text();
		if ( $header ) {
			$header = WikilogUtils::wrapDiv( 'wl-comment-header', $header );
		}

		return $status . $header;
	}

	/**
	 * Format and return the footer of a comment. This processes the
	 * 'wikilog-comment-footer' system message with the given parameters
	 * and returns the result.
	 *
	 * @param $comment Comment.
	 * @param $params Message parameters, from getCommentMsgParams().
	 * @return HTML-formatted comment footer.
	 */
	public function formatCommentFooter( $comment, $params ) {
		$footer = wfMessage( 'wikilog-comment-footer' )->inContentLanguage()->rawParams( $params )->text();
		if ( $footer ) {
			return WikilogUtils::wrapDiv( 'wl-comment-footer', $footer );
		} else {
			return "";
		}
	}

	/**
	 * Returns an array with common header and footer system message
	 * parameters that are used in 'wikilog-comment-header' and
	 * 'wikilog-comment-footer'.
	 *
	 * Note: *Content* language should be used for everything but final
	 * strings (like tooltips). These messages are intended to be customized
	 * by the wiki admin, and we don't want to require changing it for the
	 * 300+ languages suported by MediaWiki.
	 *
	 * Parameters should be HTML-formated. They are substituded using
	 * Message::rawParams().
	 *
	 * @param $comment Comment.
	 * @return Array with message parameters.
	 */
	public function getCommentMsgParams( $comment ) {
		global $wgLang;

		if ( $comment->mUserID ) {
			$authorPlain = htmlspecialchars( $comment->mUserText );
			$authorFmt = WikilogUtils::authorSig( $comment->mUserText, true );
		} else {
			$authorPlain = htmlspecialchars( $comment->mAnonName );
			$authorFmt = wfMessage( 'wikilog-comment-anonsig',
				Xml::wrapClass( $this->mSkin->userLink( $comment->mUserID, $comment->mUserText ), 'wl-comment-author' ),
				$this->mSkin->userTalkLink( $comment->mUserID, $comment->mUserText ),
				htmlspecialchars( $comment->mAnonName )
			)->inContentLanguage()->text();
		}

		list( $date, $time, $tz ) = WikilogUtils::getLocalDateTime( $comment->mTimestamp );
		$permalink = $this->getCommentPermalink( $comment, $date, $time, $tz );

		$extra = array();
		if ( $this->mShowItem ) {
			# Display item title.
			$extra[] = wfMessage( 'wikilog-comment-note-item',
				Linker::link( $comment->mSubject, $comment->mSubject->getSubpageText() )
			)->inContentLanguage()->text();
		}
		if ( $comment->mID && $comment->mCommentTitle &&
				$comment->mCommentTitle->exists() )
		{
			if ( $comment->mUpdated != $comment->mTimestamp ) {
				# Comment was edited.
				list( $updDate, $updTime, $updTz ) = WikilogUtils::getLocalDateTime( $comment->mUpdated );
				$extra[] = Linker::link( $comment->mCommentTitle,
					wfMessage( 'wikilog-comment-note-edited', $updDate, $updTime, $updTz )->inContentLanguage()->text(),
					array( 'title' => wfMessage( 'wikilog-comment-history' )->text() ),
					array( 'action' => 'history' ), 'known'
				);
			}
		}
		if ( $this->mWithParent &&
				$comment->mParent && $comment->isVisible() &&
				$comment->getParentObj()->mCommentTitle->exists()
			) {
			$parent = $comment->getParentObj();
			$link = Linker::link( $parent->mCommentTitle,
				wfMessage( 'wikilog-ptswitcher-comment-label' )->text(),
				array( 'title' => wfMessage( 'wikilog-ptswitcher-to-comment' )->text() ),
				array( 'section' => false ),
				'known'
			);
			list( $pd, $pt, $ptz ) = WikilogUtils::getLocalDateTime( $parent->mUpdated );
			if ( $parent->mUserID ) {
				$parentSig = WikilogUtils::authorSig( $parent->mUserText, true );
			} else {
				$parentSig = wfMessage( 'wikilog-comment-anonsig', '', '', htmlspecialchars( $parent->mAnonName ) )->inContentLanguage()->text();
			}
			$extra[] = wfMessage( 'wikilog-ptswitcher-to-parent', array( $link, $parentSig, $pd, $pt, $ptz ) )->text();
		}

		if ( $extra ) {
			$extra = implode( ' | ', $extra );
		} else {
			$extra = "";
		}

		return array(
			/* $1  */ $authorPlain,
			/* $2  */ $authorFmt,
			/* $3  */ $date,
			/* $4  */ $time,
			/* $5  */ $permalink,
			/* $6  */ $extra
		);
	}

	/**
	 * Return a permanent link to the comment.
	 *
	 * @param $comment Comment.
	 * @param $date Comment date.
	 * @param $time Comment time.
	 * @param $tz Comment timezone information.
	 * @return HTML fragment.
	 */
	protected function getCommentPermalink( $comment, $date, $time, $tz ) {
		if ( $comment->mID ) {
			if ( $this->mPermalinkTitle ) {
				$title = $this->mPermalinkTitle;
				$title->setFragment( "#c{$comment->mID}" );
			} else {
				$title = $comment->mCommentTitle;
			}
			return Linker::link( $title,
				wfMessage( 'wikilog-comment-permalink', $date, $time, $tz, $comment->mVisited ? 1 : NULL )->parse(),
				array( 'title' => wfMessage( 'permalink' )->text() )
			);
		} else {
			return wfMessage( 'wikilog-comment-permalink', $date, $time, $tz )->text();
		}
	}

	/**
	 * Return an HTML fragment with various links (tools) that act upon
	 * the comment, like reply, accept, reject, edit, etc.
	 *
	 * @param $comment Comment.
	 * @return HTML fragment containing the links.
	 */
	protected function getCommentToolLinks( $comment ) {
		global $wgUser, $wgRequest;
		$tools = array();

		if ( $comment->mID && $comment->mCommentTitle &&
				$comment->mCommentTitle->exists() ) {
			if ( $this->mAllowReplies && $comment->isVisible() ) {
				$tools['reply'] = Xml::tags( 'a',
					array(
						'title' => wfMessage( 'wikilog-reply-to-comment' )->text(),
						'href' => $wgRequest->appendQueryValue( 'wlParent', $comment->mID ),
						'onclick' => 'return wlReplyTo('.$comment->mID.')',
					),
					wfMessage( 'wikilog-reply-lc' )->text()
				);
			}
			if ( $this->mAllowModeration && $comment->mStatus == WikilogComment::S_PENDING ) {
				$token = $wgUser->getEditToken();
				$tools['approve'] = Linker::link( $comment->mCommentTitle,
					wfMessage( 'wikilog-approve-lc' )->text(),
					array( 'title' => wfMessage( 'wikilog-comment-approve' )->text() ),
					array(
						'action' => 'wikilog',
						'wlActionCommentApprove' => 'approve',
						'wpEditToken' => $token
					),
					'known'
				);
				$tools['reject'] = Linker::link( $comment->mCommentTitle,
					wfMessage( 'wikilog-reject-lc' )->text(),
					array( 'title' => wfMessage( 'wikilog-comment-reject' )->text() ),
					array(
						'action' => 'wikilog',
						'wlActionCommentApprove' => 'reject',
						'wpEditToken' => $token
					),
					'known'
				);
			}
			$tools['page'] = Linker::link( $comment->mCommentTitle,
				wfMessage( 'wikilog-page-lc' )->text(),
				array( 'title' => wfMessage( 'wikilog-comment-page' )->text() ),
				array( 'section' => false ),
				'known'
			);
			// TODO: batch checking of page restrictions
			if ( $comment->mCommentTitle->quickUserCan( 'edit' ) ) {
				$tools['edit'] = Linker::link( $comment->mCommentTitle,
					wfMessage( 'wikilog-edit-lc' )->text(),
					array( 'title' => wfMessage( 'wikilog-comment-edit' )->text() ),
					array( 'action' => 'edit', 'section' => false ),
					'known'
				);
			}
			if ( $comment->mCommentTitle->quickUserCan( 'delete' ) ) {
				$tools['delete'] = Linker::link( $comment->mCommentTitle,
					wfMessage( 'wikilog-delete-lc' )->text(),
					array( 'title' => wfMessage( 'wikilog-comment-delete' )->text() ),
					array( 'action' => 'delete' ),
					'known'
				);
			}
			wfRunHooks( 'WikilogCommentToolLinks', array( $this, $comment, &$tools ) );
		}

		if ( $tools ) {
			$html = '';
			foreach ( $tools as $cls => $tool ) {
				$html .= Xml::tags( 'li', array( 'class' => "wl-comment-action-{$cls}" ), $tool );
			}
			return Xml::tags( 'ul', array( 'class' => 'wl-comment-tools' ), $html );
		} else {
			return '';
		}
	}
}
