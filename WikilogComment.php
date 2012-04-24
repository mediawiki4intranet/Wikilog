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
	 * Returns the wikilog comment id.
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
		$dbr = wfGetDB( DB_SLAVE );
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
			'wlc_updated'   => $dbw->timestamp( $this->mUpdated )
		);

		$delayed = array();

		# Main update.
		$sendtext = false;
		if ( $this->mID ) {
			$dbw->update( 'wikilog_comments', $data,
				array( 'wlc_id' => $this->mID ), __METHOD__ );
		} else {
			$cid = $dbw->nextSequenceValue( 'wikilog_comments_wlc_id' );
			$data = array( 'wlc_id' => $cid ) + $data;
			$dbw->insert( 'wikilog_comments', $data, __METHOD__ );
			$this->mID = $dbw->insertId();

			# Now that we have an ID, we can generate the thread.
			$this->mThread = array();
			if ( $this->mParent ) {
				if ( !$this->mParentObj ) {
					$this->mParentObj = WikilogComment::newFromID( $this->mParent );
				}
				if ( !$this->mParentObj || !$this->mParentObj->mThread ) {
					throw new MWException( 'Invalid parent history.' );
				}
				$this->mThread = $this->mParentObj->mThread;
			}
			$this->mThread[] = self::padID( $this->mID );
			$delayed['wlc_thread'] = implode( '/', $this->mThread );
			$emailnotify = true;
		}

		# Save article with comment text.
		$this->mCommentTitle = $this->getCommentArticleTitle();
		if ( $this->mTextChanged ) {
			$art = new Article( $this->mCommentTitle );
			$art->doEdit( $this->mText, $this->getAutoSummary() );
			$this->mTextChanged = false;

			$this->mCommentPage = $art->getID();
			$delayed['wlc_comment_page'] = $this->mCommentPage;
		}

		# Delayed updates.
		if ( !empty( $delayed ) ) {
			$dbw->update( 'wikilog_comments', $delayed,
				array( 'wlc_id' => $this->mID ), __METHOD__ );
		}

		# Update number of comments
		WikilogUtils::updateTalkInfo( $this->mPost );

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
		global $wgParser, $wgPasswordSender;
		/* Message arguments:
		 * $1 = full page name of comment page
		 * $2 = name of the user who posted the new comment
		 * $3 = full URL to Wikilog item
		 * $4 = Wikilog item talk page anchor for the new comment
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
		// $to_ids = array( userid => TRUE|FALSE (can unsubscribe | cannot) )
		$to_ids = array();
		if ( $this->mParentObj ) {
			// Always notify parent comment author
			$to_ids[ $this->mParentObj->mUserID ] = false;
			$args[4] = $this->mParentObj->mCommentTitle->getPrefixedText();
			$args[5] = $this->mParentObj->mUserText;
		}
		// Get user IDs for notification
		$dbr = wfGetDB( DB_SLAVE );
		$id = $this->mSubject->getArticleId();
		$parent = Title::makeTitle( $this->mSubject->getNamespace(), $this->mSubject->getBaseText() );
		$wlid = $parent->getArticleId();
		$s = $dbr->tableName( 'wikilog_subscriptions' );
		$result = $dbr->query(
			// Notify users subscribed to this post
			"SELECT ws_user, 1 FROM $s WHERE ws_page=$id AND ws_yes=1".
			" UNION ALL".
			// Notify users subscribed to the blog and not unsubscribed from this post
			" SELECT s1.ws_user, 1 FROM $s s1 LEFT JOIN $s s2 ON s2.ws_user=s1.ws_user".
			" AND s2.ws_page=$id AND s2.ws_yes=0 WHERE s1.ws_page=$wlid AND s1.ws_yes=1 AND s2.ws_user IS NULL".
			" UNION ALL".
			// Always notify post author(s), and they cannot unsubscribe (0 means that)
			" SELECT wla_author, 0 FROM ".$dbr->tableName( 'wikilog_authors' )." WHERE wla_page=$id",
			__METHOD__
		);
		while ( $u = $dbr->fetchRow( $result ) ) {
			if ( !array_key_exists( $u[0], $to_ids ) ) {
				$to_ids[ $u[0] ] = $u[1];
			}
		}
		$dbr->freeResult( $result );
		// Build message subject, body and unsubscribe link
		$saveExpUrls = WikilogParser::expandLocalUrls();
		$popt = new ParserOptions( User::newFromId( $this->mUserID ) );
		$subject = $wgParser->parse( wfMsgNoTrans( 'wikilog-comment-email-subject', $args ),
			$this->mSubject, $popt, false, false );
		$subject = strip_tags( $subject->getText() );
		$body = $wgParser->parse( wfMsgNoTrans( 'wikilog-comment-email-body', $args),
			$this->mSubject, $popt, true, false );
		$body = $body->getText();
		WikilogParser::expandLocalUrls( $saveExpUrls );
		// Unsubscribe link is appended to e-mails of users that can unsubscribe
		global $wgServer, $wgScript;
		$unsubscribe = wfMsgNoTrans(
			'wikilog-comment-email-unsubscribe',
			$this->mSubject->getSubpageText(),
			$wgServer.$wgScript.'?'.http_build_query( array(
				'title' => $this->mSubject->getTalkPage()->getPrefixedText(),
				'action' => 'wikilog',
				'wlActionSubscribe' => 1,
				'wl-subscribe' => 0,
			) )
		);
		// Build e-mail lists (with unsubscribe link, without unsubscribe link)
		$to_with = array();
		$to_without = array();
		foreach ( $to_ids as $id => $can_unsubcribe ) {
			// Do not send user his own comments
			if ( $id != $this->mUserID ) {
				$email = new MailAddress( User::newFromId( $id )->getEmail() );
				if ( $email ) {
					if ( $can_unsubcribe ) {
						$to_with[] = $email;
					} else {
						$to_without[] = $email;
					}
				}
			}
		}
		// Send e-mails using $wgPasswordSender as from address
		$from = new MailAddress( $wgPasswordSender, 'Wikilog' );
		if ( $to_with ) {
			UserMailer::send( $to_with, $from, $subject, $body . $unsubscribe );
		}
		if ( $to_without ) {
			UserMailer::send( $to_without, $from, $subject, $body );
		}
	}

	/**
	 * Deletes comment data from the database.
	 */
	public function deleteComment() {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->begin();

		$dbw->delete( 'wikilog_comments', array( 'wlc_id' => $this->mID ), __METHOD__ );
		WikilogUtils::updateTalkInfo( $this->mPost );

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
	 */
	public function getCommentArticleTitle() {
		if ( $this->mCommentTitle ) {
			return $this->mCommentTitle;
		} elseif ( $this->mCommentPage ) {
			return Title::newFromID( $this->mCommentPage, Title::GAID_FOR_UPDATE );
		} else {
			$it = $this->mSubject;
			return Title::makeTitle(
				MWNamespace::getTalk( $it->getNamespace() ),
				$it->getText() . '/c' . self::padID( $this->mID )
			);
		}
	}

	/**
	 * Returns automatic summary (for recent changes) for the posted comment.
	 */
	public function getAutoSummary() {
		global $wgContLang;
		$user = $this->mUserID ? $this->mUserText : $this->mAnonName;
		$summ = $wgContLang->truncate( str_replace( "\n", ' ', $this->mText ),
			max( 0, 200 - strlen( wfMsgForContent( 'wikilog-comment-autosumm' ) ) ),
			'...' );
		return wfMsgForContent( 'wikilog-comment-autosumm', $user, $summ );
	}

	/**
	 * Returns the discussion history for a given comment. This is used to
	 * populate the $comment->mThread of a new comment whose id is @a $id
	 * and parent is @a $parent.
	 *
	 * @param $id Comment id of the new comment.
	 * @param $parent Comment id of its parent.
	 * @return Array of ids from the history since the first comment until
	 *   the given one.
	 */
	public static function getThreadHistory( $id, $parent ) {
		$thread = array();

		if ( $parent ) {
			$dbr = wfGetDB( DB_SLAVE );
			$thread = $dbr->selectField(
				'wikilog_comments',
				'wlc_thread',
				array( 'wlc_id' => intval( $parent ) ),
				__METHOD__
			);
			if ( $thread !== false ) {
				$thread = explode( '/', $thread );
			} else {
				throw new MWException( 'Invalid parent history.' );
			}
		}

		$thread[] = self::padID( $id );
		return $thread;
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
		$comment->mParent       = intval( $row->wlc_parent );
		$comment->mThread       = explode( '/', $row->wlc_thread );
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
	 * Creates a new comment object from an existing comment id.
	 * Data is fetched from the database.
	 * @param $item Wikilog article item.
	 * @param $id Comment id.
	 * @return New WikilogComment object, or NULL if comment doesn't exist.
	 */
	public static function newFromID( $id ) {
		$dbr = wfGetDB( DB_SLAVE );
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
		$dbr = wfGetDB( DB_SLAVE );
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

	/**
	 * Constructor.
	 *
	 * @param $title Title of the page.
	 * @param $wi WikilogInfo object with information about the wikilog and
	 *   the item.
	 */
	public function __construct( $skin = false, $allowReplies = false ) {
		global $wgUser;
		$this->mSkin = $skin ? $skin : $wgUser->getSkin();
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
			$status = wfMsg( "wikilog-comment-{$hidden}" );
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
		return Xml::tags( 'div', array(
			'class' => implode( ' ', $divclass ),
			'id' => ( $comment->mID ? "c{$comment->mID}" : 'cpreview' )
		), $html );
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
			$status = WikilogUtils::wrapDiv( 'wl-comment-status', wfMsg( "wikilog-comment-{$hidden}" ) );
		}

		$header = wfMsgExt( 'wikilog-comment-header', array( 'content', 'parsemag', 'replaceafter' ), $params );
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
		$footer = wfMsgExt( 'wikilog-comment-footer', array( 'content', 'parsemag', 'replaceafter' ), $params );
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
	 * 'replaceafter' parameter to wfMsgExt().
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
			$authorFmt = wfMsgForContent( 'wikilog-comment-anonsig',
				Xml::wrapClass( $this->mSkin->userLink( $comment->mUserID, $comment->mUserText ), 'wl-comment-author' ),
				$this->mSkin->userTalkLink( $comment->mUserID, $comment->mUserText ),
				htmlspecialchars( $comment->mAnonName )
			);
		}

		list( $date, $time, $tz ) = WikilogUtils::getLocalDateTime( $comment->mTimestamp );
		$permalink = $this->getCommentPermalink( $comment, $date, $time, $tz );

		$extra = array();
		if ( $this->mShowItem ) {
			# Display item title.
			$extra[] = wfMsgForContent( 'wikilog-comment-note-item',
				$this->mSkin->link( $comment->mSubject, $comment->mSubject->getSubpageText() )
			);
		}
		if ( $comment->mID && $comment->mCommentTitle &&
				$comment->mCommentTitle->exists() )
		{
			if ( $comment->mUpdated != $comment->mTimestamp ) {
				# Comment was edited.
				list( $updDate, $updTime, $updTz ) = WikilogUtils::getLocalDateTime( $comment->mUpdated );
				$extra[] = $this->mSkin->link( $comment->mCommentTitle,
					wfMsgForContent( 'wikilog-comment-note-edited', $updDate, $updTime, $updTz ),
					array( 'title' => wfMsg( 'wikilog-comment-history' ) ),
					array( 'action' => 'history' ), 'known'
				);
			}
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
			return $this->mSkin->link( $title,
				wfMsgExt( 'wikilog-comment-permalink', array( 'parseinline' ), $date, $time, $tz, $comment->mVisited ? 1 : NULL ),
				array( 'title' => wfMsg( 'permalink' ) )
			);
		} else {
			return wfMsg( 'wikilog-comment-permalink', $date, $time, $tz );
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
						'title' => wfMsg( 'wikilog-reply-to-comment' ),
						'href' => $wgRequest->appendQueryValue( 'wlParent', $comment->mID )
					),
					wfMsg( 'wikilog-reply-lc' )
				);
			}
			if ( $this->mAllowModeration && $comment->mStatus == WikilogComment::S_PENDING ) {
				$token = $wgUser->editToken();
				$tools['approve'] = $this->mSkin->link( $comment->mCommentTitle,
					wfMsg( 'wikilog-approve-lc' ),
					array( 'title' => wfMsg( 'wikilog-comment-approve' ) ),
					array(
						'action' => 'wikilog',
						'wlActionCommentApprove' => 'approve',
						'wpEditToken' => $token
					),
					'known'
				);
				$tools['reject'] = $this->mSkin->link( $comment->mCommentTitle,
					wfMsg( 'wikilog-reject-lc' ),
					array( 'title' => wfMsg( 'wikilog-comment-reject' ) ),
					array(
						'action' => 'wikilog',
						'wlActionCommentApprove' => 'reject',
						'wpEditToken' => $token
					),
					'known'
				);
			}
			$tools['page'] = $this->mSkin->link( $comment->mCommentTitle,
				wfMsg( 'wikilog-page-lc' ),
				array( 'title' => wfMsg( 'wikilog-comment-page' ) ),
				array( 'section' => false ),
				'known'
			);
			// TODO: batch checking of page restrictions
			if ( $comment->mCommentTitle->quickUserCan( 'edit' ) ) {
				$tools['edit'] = $this->mSkin->link( $comment->mCommentTitle,
					wfMsg( 'wikilog-edit-lc' ),
					array( 'title' => wfMsg( 'wikilog-comment-edit' ) ),
					array( 'action' => 'edit', 'section' => false ),
					'known'
				);
			}
			if ( $comment->mCommentTitle->quickUserCan( 'delete' ) ) {
				$tools['delete'] = $this->mSkin->link( $comment->mCommentTitle,
					wfMsg( 'wikilog-delete-lc' ),
					array( 'title' => wfMsg( 'wikilog-comment-delete' ) ),
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
