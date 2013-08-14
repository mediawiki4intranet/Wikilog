<?php

/**
 * Wikilog comment SQL query driver.
 * This class drives queries for wikilog comments, given the fields to filter.
 * @since Wikilog v1.1.0.
 */
class WikilogCommentQuery
	extends WikilogQuery
{
	// Valid filter values for moderation status.
	const MS_ALL        = 'all';		///< Return all comments.
	const MS_ACCEPTED   = 'accepted';	///< Return only accepted comments.
	const MS_PENDING    = 'pending';	///< Return only pending comments.
	const MS_NOTDELETED = 'notdeleted';	///< Return all but deleted comments.
	const MS_NOTPENDING = 'notpending';	///< Return all but pending comments.

	public static $modStatuses = array(
		self::MS_ALL, self::MS_ACCEPTED, self::MS_PENDING,
		self::MS_NOTDELETED, self::MS_NOTPENDING
	);

	// Local variables.
	private $mModStatus = self::MS_ALL;	///< Filter by moderation status.
	private $mNamespace = false;		///< Filter by namespace.
	private $mSubject = false;			///< Filter by subject article.
	private $mThread = false;			///< Filter by thread.
	private $mAuthor = false;			///< Filter by author.
	private $mDate = false;				///< Filter by date.
	private $mIncludeSubpages = false;	///< Include comments to all subpages of subject page.
	private $mSort = 'thread';			///< Sort order (only 'thread' is supported by now).
	private $mLimit = 0;				///< Limit.
	private $mFirstCommentId = false;	///< Forward navigation: ID of the first comment on page.
	private $mNextCommentId = false;	///< Backward navigation: ID of the first comment on NEXT page.

	// The real page boundaries are saved here after calling getQueryInfo().
	// You can pass them back to WikilogCommentQuery using setXXXCommentId().
	private $mRealFirstCommentId = false;
	private $mRealNextCommentId = false;

	/**
	 * Constructor.
	 * @param $from Title subject.
	 */
	public function __construct( $subject = null ) {
		parent::__construct();

		if ( $subject ) {
			$this->setSubject( $subject );
		}
	}

	/**
	 * Set the moderation status to query for.
	 * @param $modStatus Moderation status, string or integer.
	 */
	public function setModStatus( $modStatus ) {
		if ( is_null( $modStatus ) ) {
			$this->mModStatus = self::MS_ALL;
		} elseif ( in_array( $modStatus, self::$modStatuses ) ) {
			$this->mModStatus = $modStatus;
		} else {
			throw new MWException( __METHOD__ . ": Invalid moderation status." );
		}
	}

	/**
	 * Set the namespace to query for. Only comments for articles published
	 * in the given namespace are returned. The wikilog and item filters have
	 * precedence over this filter.
	 * @param $ns Namespace to query for.
	 */
	public function setNamespace( $ns ) {
		$this->mNamespace = $ns;
	}

	/**
	 * Set the page to query for. Only comments for the given article
	 * are returned. You may set includeSubpageComments() and then
	 * all comments for all subpages of this page will be also returned.
	 * @param Title $item page to query for
	 */
	public function setSubject( Title $page ) {
		$this->mSubject = $page;
	}

	/**
	 * Set the comment thread to query for. Only replies to the given thread
	 * is returned. This is intended to be used together with setItem(), in
	 * order to use the proper database index (see the wlc_post_thread index).
	 * @param $thread Thread path identifier to query for (array or string).
	 */
	public function setThread( $thread ) {
		if ( is_array( $thread ) ) {
			$thread = implode( '/', $thread );
		}
		$this->mThread = $thread;
	}

	/**
	 * Set sort order and limit.
	 * Note that the query sorted on thread ALWAYS includes full threads
	 * (threads are not broken)
	 */
	public function setLimit( $sort, $limit ) {
		$this->mSort = $sort;
		$this->mLimit = $limit;
	}

	/**
	 * Sets the author to query for.
	 * @param $author User page title object or text.
	 */
	public function setAuthor( $author ) {
		if ( is_null( $author ) || is_object( $author ) ) {
			$this->mAuthor = $author;
		} elseif ( is_string( $author ) ) {
			$t = Title::makeTitleSafe( NS_USER, $author );
			if ( $t !== null ) {
				$this->mAuthor = User::getCanonicalName( $t->getText() );
			}
		}
	}

	/**
	 * Set the date to query for.
	 * @param $year Comment year.
	 * @param $month Comment month, optional. If ommited, look for comments
	 *   during the whole year.
	 * @param $day Comment day, optional. If ommited, look for comments
	 *   during the whole month or year.
	 */
	public function setDate( $year, $month = false, $day = false ) {
		$interval = self::partialDateToInterval( $year, $month, $day );
		if ( $interval ) {
			list( $start, $end ) = $interval;
			$this->mDate = (object)array(
				'year'  => $year,
				'month' => $month,
				'day'   => $day,
				'start' => $start,
				'end'   => $end
			);
		}
	}

	public function setIncludeSubpageComments( $inc ) {
		$this->mIncludeSubpages = $inc;
	}

	public function setFirstCommentId( $id ) {
		$this->mFirstCommentId = $id;
	}

	public function setNextCommentId( $id ) {
		$this->mNextCommentId = $id;
	}

	/**
	 * Accessor functions.
	 */
	public function getModStatus() { return $this->mModStatus; }
	public function getNamespace() { return $this->mNamespace; }
	public function getSubject() { return $this->mSubject; }
	public function getThread() { return $this->mThread; }
	public function getAuthor() { return $this->mAuthor; }
	public function getDate() { return $this->mDate; }
	public function getLimit() { return $this->mLimit; }
	public function getIncludeSubpageComments() { return $this->mIncludeSubpages; }
	public function getFirstCommentId() { return $this->mFirstCommentId; }
	public function getNextCommentId() { return $this->mNextCommentId; }
	public function getRealFirstCommentId() { return $this->mRealFirstCommentId; }
	public function getRealNextCommentId() { return $this->mRealNextCommentId; }

	/**
	 * Organizes all the query information and constructs the table and
	 * field lists that will later form the SQL SELECT statement.
	 * @param $db Database object.
	 * @param $opts Array with query options. Keys are option names, values
	 *   are option values.
	 * @return Array with tables, fields, conditions, options and join
	 *   conditions, to be used in a call to $db->select(...).
	 */
	public function getQueryInfo( $db, $opts = array() ) {
		$this->setOptions( $opts );

		# Basic defaults.
		$wlc_tables = WikilogComment::selectTables();
		$q_tables = $wlc_tables['tables'];
		$q_fields = WikilogComment::selectFields();
		$q_conds = array();
		$q_options = array();
		$q_joins = $wlc_tables['join_conds'];

		# Invalid filter.
		if ( $this->mEmpty ) {
			$q_conds[] = '0=1';
		}

		# Filter by moderation status.
		if ( $this->mModStatus == self::MS_ACCEPTED ) {
			$q_conds['wlc_status'] = 'OK';
		} elseif ( $this->mModStatus == self::MS_PENDING ) {
			$q_conds['wlc_status'] = 'PENDING';
		} elseif ( $this->mModStatus == self::MS_NOTDELETED ) {
			$q_conds[] = "wlc_status <> " . $db->addQuotes( 'DELETED' );
		} elseif ( $this->mModStatus == self::MS_NOTPENDING ) {
			$q_conds[] = "wlc_status <> " . $db->addQuotes( 'PENDING' );
		}

		# Filter by subject page.
		if ( $this->mSubject ) {
			if ( $this->mIncludeSubpages ) {
				$q_conds['p.page_namespace'] = $this->mSubject->getNamespace();
				$q_conds[] = '(p.page_title = ' . $db->addQuotes( $this->mSubject->getDBkey() ) . ' OR p.page_title ' .
					$db->buildLike( $this->mSubject->getDBkey() . '/', $db->anyString() ) . ')';
			} else {
				$q_conds['wlc_post'] = $this->mSubject->getArticleId();
				if ( $this->mThread ) {
					$q_conds[] = 'wlc_thread ' . $db->buildLike( $this->mThread . '/', $db->anyString() );
				}
			}
		} elseif ( $this->mNamespace !== false ) {
			$q_conds['c.page_namespace'] = $this->mNamespace;
		}

		# Filter by author.
		if ( $this->mAuthor ) {
			$q_conds['wlc_user_text'] = $this->mAuthor;
		}

		# Filter by date.
		if ( $this->mDate ) {
			$q_conds[] = 'wlc_timestamp >= ' . $db->addQuotes( $this->mDate->start );
			$q_conds[] = 'wlc_timestamp < ' . $db->addQuotes( $this->mDate->end );
		}

		# Sort order and limits
		if ( $this->mSort == 'thread' ) {
			$dbr = wfGetDB( DB_SLAVE );
			$first = $last = $back = false;
			if ( $this->mNextCommentId ) {
				// Backward navigation: next comment ID is set from the outside.
				// Determine first comment ID from it.
				if ( $this->mNextCommentId != 'MAX' ) {
					$back = $last = $this->getPostThread( $dbr, $this->mNextCommentId );
				} else {
					// FIXME "Go to the last page" is kludgy anyway with our "not-break-the-thread" idea.
					// I.e. "last page" will not be the same as if you navigate forward.
					// This can be fixed only by partitioning the full query set into pages at the beginning.
					$back = true;
				}
			} else {
				// Forward navigation: first comment ID may be set from the outside.
				// Determine real limit from it.
				$first = $this->getPostThread( $dbr, $this->mFirstCommentId );
			}
			list( $first, $last ) = $this->getLimitPostThread( $dbr, $q_tables, $q_conds, $q_options, $q_joins,
				$this->mIncludeSubpages ? false : $this->mThread, $first, $last, $back, $this->mLimit );
			$q_options['ORDER BY'] = 'wlc_post, wlc_thread, wlc_id';
			$q_conds[] = $this->getPostThreadCond( $dbr, $first, $last );
			// Save real page boundaries so pager can retrieve them later
			if ( $first ) {
				$this->mRealFirstCommentId = $first->id;
			}
			if ( $last ) {
				$this->mRealNextCommentId = $last->id;
			}
		}

		return array(
			'tables' => $q_tables,
			'fields' => $q_fields,
			'conds' => $q_conds,
			'options' => $q_options,
			'join_conds' => $q_joins
		);
	}

	/**
	 * Determine $first or $last post/thread boundary from another condition
	 * ($last or $first respectively), query options and a limit.
	 * This is the function responsive for NOT CUTTING discussion threads in the middle.
	 *  @return array( $newFirst, $newLast )
	 */
	function getLimitPostThread( $dbr, $tables, $conds, $options, $joins, $parentThread, $first, $last, $backwards, $limit ) {
		if ( $limit <= 0 ) {
			return false;
		}
		$tmpConds = $conds;
		$tmpConds[] = $this->getPostThreadCond( $dbr, $first, $last );
		$tmpOpts = $options;
		$tmpOpts['LIMIT'] = 1;
		$tmpOpts['OFFSET'] = $limit-1;
		$dir = $backwards ? 'DESC' : 'ASC';
		$tmpOpts['ORDER BY'] = "wlc_post $dir, wlc_thread $dir, wlc_id $dir";
		$other = false;
		// Select $limit'th comment, get post and thread from it
		$res = $dbr->select( $tables, 'wlc_post, wlc_thread', $tmpConds, __METHOD__, $tmpOpts, $joins );
		$row = $res->fetchObject();
		if ( $row ) {
			$p = $parentThread ? 1+strlen( $parentThread ) : 0;
			$other = (object)array(
				'post' => $row->wlc_post,
				'thread' => substr( $row->wlc_thread, 0, $p+6 ),
			);
			if ( !$backwards ) {
				// Next thread number (for LessThan)
				$other->thread = substr( $other->thread, 0, -6 ) .
					sprintf( "%06d", 1 + substr( $other->thread, -6 ) );
			}
			$tmpConds = $conds;
			$tmpConds[] = $this->getPostThreadCond( $dbr, $backwards ? $first : $other, $backwards ? $other : $last );
			$tmpOpts['OFFSET'] = 0;
			// Get "other" comment id
			$res = $dbr->select( $tables, 'wlc_id', $tmpConds, __METHOD__, $tmpOpts, $joins );
			$row = $res->fetchObject();
			$other->id = $row ? $row->wlc_id : false;
		}
		return $backwards ? array( $other, $last ) : array( $first, $other );
	}

	/**
	 * Get post and thread for comment #$id
	 */
	function getPostThread( $dbr, $id ) {
		if ( !$id ) {
			return false;
		}
		$res = $dbr->select( 'wikilog_comments', 'wlc_post, wlc_thread',
			array( 'wlc_id' => $this->mFirstCommentId ), __METHOD__ );
		$row = $dbr->fetchObject( $res );
		if ( $row ) {
			return (object)array(
				'id' => $id,
				'post' => intval( $row->wlc_post ),
				'thread' => $row->wlc_thread,
			);
		}
		return false;
	}

	/**
	 * Returns the condition to select comments between (first post, first thread) and (last post, last thread)
	 *  @param object $first (post => post ID, thread => thread)
	 *  @param object $last (post => post ID, thread => thread)
	 *  @return string
	 */
	function getPostThreadCond( $dbr, $first, $last ) {
		if ( !$first && !$last ) {
			return '1=1';
		}
		if ( $first && $last && $first->post == $last->post ) {
			return "wlc_post = {$first->post} AND (wlc_thread >= {$first->thread} AND wlc_thread < {$last->thread})";
		}
		$cond = array();
		if ( $first ) {
			$cond[] = "wlc_post >= {$first->post} AND (wlc_post > {$first->post}".
				" OR wlc_thread >= ".$dbr->addQuotes( $first->thread ).")";
		}
		if ( $last ) {
			$cond[] = "wlc_post <= {$last->post} AND (wlc_post < {$last->post}".
				" OR wlc_thread < ".$dbr->addQuotes( $last->thread ).")";
		}
		return implode( ' AND ', $cond );
	}

	/**
	 * Returns the query information as an array suitable to be used to
	 * construct a URL to Special:WikilogComments with the proper query
	 * parameters. Used in navigation links.
	 */
	public function getDefaultQuery() {
		$query = array();

		//..............
		if ( $this->mNamespace !== false ) {
			$query['wikilog'] = Title::makeTitle( $this->mNamespace, "*" )->getPrefixedDBKey();
		}

		if ( $this->mModStatus != self::MS_ALL ) {
			$query['show'] = $this->mModStatus;
		}

		if ( $this->mAuthor ) {
			$query['author'] = $this->mAuthor;
		}

		if ( $this->mDate ) {
			$query['year']  = $this->mDate->year;
			$query['month'] = $this->mDate->month;
			$query['day']   = $this->mDate->day;
		}

		return $query;
	}

}

