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
 * Common wikilog comment pager interface.
 * @since Wikilog v1.1.0.
 */
abstract class WikilogCommentPager
	extends IndexPager
{
	/// Wikilog comment query data.
	protected $mQuery = null;

	/// Wikilog comment formatter.
	protected $mFormatter = null;

	/// If the pager is being included.
	protected $mIncluding = false;

	/// If displaying comments for a single article.
	protected $mSingleItem = false;

	/// Trigger for displaying a reply comment form.
	protected $mReplyTrigger = null;
	protected $mReplyCallback = null;

	/**
	 * Constructor.
	 * @param $query WikilogCommentQuery  Query object, containing the
	 *   parameters that will select which comments will be shown.
	 * @param $formatter WikilogCommentFormatter  Comment formatter object.
	 * @param $including boolean  Whether the listing is being included in
	 *   another page.
	 */
	function __construct( WikilogCommentQuery $query, $formatter = null,
			$including = false )
	{
		global $wgUser, $wgParser;
		global $wgWikilogNumComments, $wgWikilogExpensiveLimit;

		# WikilogCommentQuery object drives our queries.
		$this->mQuery = $query;
		$this->mIncluding = $including;

		# Prepare the comment formatter.
		$this->mFormatter = $formatter ? $formatter :
			new WikilogCommentFormatter( $this->getSkin() );
		if ( $query->getIncludeSubpageComments() || !$query->getSubject() ) {
			$this->mFormatter->setShowItem( true );
		}

		# Parent constructor.
		parent::__construct();

		# Fix our limits, Pager defaults are too high.
		$this->mDefaultLimit = $wgWikilogNumComments;
		list( $this->mLimit, /* $offset */ ) =
			$this->mRequest->getLimitOffset( $wgWikilogNumComments, '' );

		# This is too expensive, limit listing.
		if ( $this->mLimit > $wgWikilogExpensiveLimit ) {
			$this->mLimit = $wgWikilogExpensiveLimit;
		}
	}

	/**
	 * Set the comment formatter.
	 * @param $formatter Comment formatter object.
	 * @return WikilogCommentFormatter Previous value.
	 */
	public function setFormatter( WikilogCommentFormatter $formatter ) {
		return wfSetVar( $this->mFormatter, $formatter );
	}

	/**
	 * Set the reply trigger. This makes getBody() function to call back
	 * the given function $callback when the comment $id is displayed.
	 * This is used to inject a reply comment form after the comment.
	 *
	 * @param $id integer  Comment ID that will trigger the callback.
	 * @param $callback callback  Callback function, receives the comment
	 *   as argument and should return an HTML fragment.
	 */
	public function setReplyTrigger( $id, $callback = null ) {
		$this->mReplyTrigger = $id;
		$this->mReplyCallback = $callback;
	}

	function getQueryInfo() {
		return $this->mQuery->getQueryInfo( $this->mDb );
	}

	function getDefaultQuery() {
		return parent::getDefaultQuery();
	}

	function getStartBody() {
		return Xml::openElement( 'div', array( 'class' => 'wl-threads' ) );
	}

	function getEndBody() {
		return Xml::closeElement( 'div' ); // wl-threads
	}

	function getEmptyBody() {
		return WikilogUtils::wrapDiv( 'wl-empty', wfMessage( 'wikilog-pager-empty' )->text() );
	}

	function getNavigationBar() {
		if ( !$this->isNavigationBarShown() ) {
			return '';
		}
		if ( !isset( $this->mNavigationBar ) ) {
			$navbar = new WikilogNavbar( $this );
			$this->mNavigationBar = $navbar->getNavigationBar( $this->mLimit );
		}
		return $this->mNavigationBar;
	}
}

/**
 * Comment list pager.
 *
 * Lists wikilog comments in list format. If there are more comments than
 * some threshold, navigation links are used to visit other pages of comments.
 */
class WikilogCommentListPager
	extends WikilogCommentPager
{
	public $mDefaultDirection = true;

	/**
	 * Constructor.
	 * @param $query WikilogCommentQuery  Query object, containing the
	 *   parameters that will select which comments will be shown.
	 * @param $formatter WikilogCommentFormatter  Comment formatter object.
	 * @param $including boolean  Whether the listing is being included in
	 *   another page.
	 */
	function __construct( WikilogCommentQuery $query, $formatter = null,
			$including = false )
	{
		parent::__construct( $query, $formatter, $including );
	}

	function getIndexField() {
		return 'wlc_timestamp';
	}

	function formatRow( $row ) {
		# Retrieve comment data.
		$subject = $this->mQuery->getIncludeSubpageComments() ? NULL : $this->mQuery->getSubject();
		$comment = WikilogComment::newFromRow( $row, $subject );
		$comment->loadText();
		return $this->mFormatter->formatComment( $comment );
	}
}

/**
 * Comment thread pager.
 *
 * Lists wikilog comments in thread format. If there are more comments than
 * some threshold, navigation links are used to visit other pages of comments.
 * The thread pager also supports injecting a reply form below any comment.
 */
class WikilogCommentThreadPager
	extends WikilogCommentPager
{
	var $mRootLevel = -1;

	/**
	 * Minimal comment nesting level needed for folding "linear" threads.
	 * @FIXME move into configuration
	 */
	var $mMinFoldLevel = 4;

	/**
	 * Constructor.
	 * @param $query WikilogCommentQuery  Query object, containing the
	 *   parameters that will select which comments will be shown.
	 * @param $formatter WikilogCommentFormatter  Comment formatter object.
	 * @param $including boolean  Whether the listing is being included in
	 *   another page.
	 */
	function __construct( WikilogCommentQuery $query, $formatter = false,
			$including = false )
	{
		parent::__construct( $query, $formatter, $including );
	}

	function getIndexField() {
		return 'wlc_id';
	}

	function getEndBody() {
		return $this->mFormatter->closeCommentThreads() . parent::getEndBody();
	}

	function doQuery() {
		if ( $this->mIsBackwards ) {
			$this->mQuery->setNextCommentId( $this->mOffset ? $this->mOffset : 'MAX' );
		} else {
			$this->mQuery->setFirstCommentId( $this->mOffset );
		}
		$this->mQuery->setLimit( 'thread', $this->mLimit );

		// Execute query
		$dbr = wfGetDB( DB_REPLICA );
		$res = $this->mQuery->select( $dbr, array(), false );
		$nchild = array();
		$rows = array();
		foreach ( $res as $row ) {
			if ( !isset( $nchild[ $row->wlc_parent ] ) ) {
				$nchild[ $row->wlc_parent ] = 0;
			}
			$nchild[ $row->wlc_parent ]++;
			$rows[ $row->wlc_id ] = $row;
		}

		// Determine root thread level
		$rootThread = $this->mQuery->getThread();
		if ( $rootThread ) {
			$this->mRootLevel = count( WikilogUtils::decodeVarintArray( $rootThread ) );
		}

		// Fold non-forking comment threads when level goes above $this->mMinFoldLevel
		foreach ( $rows as &$row ) {
			if ( !$row->wlc_parent || !isset( $rows[ $row->wlc_parent ] ) ) {
				$row->level = $this->mRootLevel+1;
			} elseif ( $rows[ $row->wlc_parent ]->level-$this->mRootLevel-1 <= $this->mMinFoldLevel ||
				$nchild[ $row->wlc_parent ] > 1 || $nchild[ $rows[ $row->wlc_parent ]->wlc_parent ] > 1 ) {
				// Create a nested thread when either:
				// - Nesting level is not above $this->mMinFoldLevel
				// - This comment has a sibling
				// - Parent comment has a sibling
				$row->level = $rows[ $row->wlc_parent ]->level + 1;
			} else {
				// In other cases, do not start a nested thread ("fold" it)
				$row->level = $rows[ $row->wlc_parent ]->level;
			}
		}
		$this->mRows = $rows;

		// Give Pager the parameters
		$this->mIsFirst = !$this->mQuery->getRealFirstCommentId();
		$this->mIsLast = !$this->mQuery->getRealNextCommentId();
		$this->mLastShown = $this->mQuery->getRealNextCommentId();
		$this->mFirstShown = $this->mQuery->getRealFirstCommentId();
	}

	function getBody() {
		if ( !$this->mQueryDone ) {
			$this->doQuery();
		}

		$html = '';
		if ( count( $this->mRows ) ) {
			// First preload comments to allow batch loading
			$comments = array();
			$subject = $this->mQuery->getIncludeSubpageComments() ? NULL : $this->mQuery->getSubject();
			foreach ( $this->mRows as $i => $row ) {
				$comments[$i] = WikilogComment::newFromRow( $row, $subject );
			}
			wfRunHooks( 'WikilogPreloadComments', array( $this, &$comments ) );

			$level = $this->mRootLevel;
			foreach ( $comments as $num => $comment ) {
				// Open/close comment threads
				$curLevel = $this->mRows[ $num ]->level;
				if ( $curLevel > $level ) {
					for ( $i = $level ; $i < $curLevel; $i++ ) {
						$html .= '<div class="wl-thread">';
					}
				} elseif ( $curLevel < $level ) {
					for ( $i = $curLevel; $i < $level; $i++ ) {
						$html .= '</div>';
					}
				}
				$level = $curLevel;

				// Retrieve comment data.
				$comment->loadText();

				// Format comment
				$doReply = $this->mReplyTrigger && $comment->mID == $this->mReplyTrigger;
				$html .= $this->mFormatter->formatComment( $comment, $doReply );
				if ( $doReply && is_callable( $this->mReplyCallback ) ) {
					if ( ( $res = call_user_func( $this->mReplyCallback, $comment, true ) ) ) {
						$html .= WikilogUtils::wrapDiv( 'wl-indent', $res );
					}
				}
			}
			for ( $i = $this->mRootLevel; $i < $level; $i++ ) {
				$html .= '</div>';
			}
		} else {
			$html .= $this->getEmptyBody();
		}
		return $html;
	}

	public function formatRow( $row ) {

		$doReply = $this->mReplyTrigger && $comment->mID == $this->mReplyTrigger;

		$html = $this->mFormatter->startCommentThread( $comment );
		$html .= $this->mFormatter->formatComment( $comment, $doReply );

		if ( $doReply && is_callable( $this->mReplyCallback ) ) {
			if ( ( $res = call_user_func( $this->mReplyCallback, $comment ) ) ) {
				$html .= WikilogUtils::wrapDiv( 'wl-indent', $res );
			}
		}
		return $html;
	}
}
