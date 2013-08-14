<?php

/**
 * Syndication feed generator for wikilog comments.
 */
class WikilogCommentFeed
	extends WikilogFeed
{
	/**
	 * If displaying comments for a single article.
	 */
	protected $mSingleItem = false;

	/**
	 * Comment subject or subject parent.
	 */
	protected $mSubject = false;

	/**
	 * WikilogCommentFeed constructor.
	 *
	 * @param $title Title  Feed title and URL.
	 * @param $format string  Feed format ('atom' or 'rss').
	 * @param $query WikilogCommentQuery  Query parameters.
	 * @param $limit integer  Number of items to generate.
	 */
	public function __construct( Title $title, $format,
			WikilogCommentQuery $query, $limit = false )
	{
		global $wgWikilogNumComments;
		if ( !$limit ) {
			$limit = $wgWikilogNumComments;
		}

		$this->mSubject = $query->getSubject();
		if ( !$query->getIncludeSubpageComments() ) {
			$this->mSingleItem = true;
		}

		parent::__construct( $title, $format, $query, $limit );
	}

	public function getIndexField() {
		return 'wlc_timestamp';
	}

	/**
	 * Generates and populates a WlSyndicationFeed object,
	 * for title $title or for the whole site if it is false
	 *
	 * @return WlSyndicationFeed object.
	 */
	public function getFeedObject() {
		global $wgContLanguageCode, $wgWikilogFeedClasses, $wgFavicon, $wgLogo;

		$feedtitle = wfMsgForContent( 'wikilog-feed-title',
			wfMsgForContent(
				$this->mSubject ? 'wikilog-title-comments' : 'wikilog-title-comments-all',
				$this->mSubject ? $this->mSubject->getSubpageText() : ''
			),
			$wgContLanguageCode
		);
		$subtitle = wfMsgExt( 'wikilog-comment-feed-description', array( 'parse', 'content' ) );

		$res = $this->mQuery->select( $this->mDb, array(), 'MAX(wlc_updated) u' );
		$updated = $res->fetchObject();
		$updated = $updated->u;
		if ( !$updated ) {
			$updated = wfTimestampNow();
		}

		$url = $this->mTitle->getFullUrl();
		$feed = new $wgWikilogFeedClasses[$this->mFormat](
			$url, $feedtitle, $updated, $url
		);
		$feed->setSubtitle( new WlTextConstruct( 'html', $subtitle ) );
		if ( $wgFavicon !== false ) {
			$feed->setIcon( wfExpandUrl( $wgFavicon ) );
		}
		if ( $this->mCopyright ) {
			$feed->setRights( new WlTextConstruct( 'html', $this->mCopyright ) );
		}
		return $feed;
	}

	/**
	 * Generates and returns a single feed entry.
	 * @param $row The wikilog comment database entry.
	 * @return A new WlSyndicationEntry object.
	 */
	function formatFeedEntry( $row ) {
		global $wgMimeType;

		# Create comment object.
		$item = $this->mSingleItem ? $this->mSubject : false;
		$comment = WikilogComment::newFromRow( $row, $item );

		# Prepare some strings.
		if ( $comment->mUserID ) {
			$usertext = $comment->mUserText;
		} else {
			$usertext = wfMsgForContent( 'wikilog-comment-anonsig',
				$comment->mUserText, ''/*talk*/, $comment->mAnonName
			);
		}
		$title = wfMsgForContent( 'wikilog-comment-feed-title'.( $this->mSingleItem ? '1' : '2' ),
			$comment->mID, $usertext, $comment->mSubject->getSubpageText()
		);

		# Create new syndication entry.
		$entry = new WlSyndicationEntry(
			self::makeEntryId( $comment ),
			$title,
			$comment->mUpdated,
			$comment->getCommentArticleTitle()->getFullUrl()
		);

		# Comment text.
		if ( $comment->mCommentRev ) {
			list( $article, $parserOutput ) = WikilogUtils::parsedArticle( $comment->mCommentTitle, true );
			$content = Sanitizer::removeHTMLcomments( $parserOutput->getText() );
			if ( $content ) {
				$entry->setContent( new WlTextConstruct( 'html', $content ) );
			}
		}

		# Author.
		$usertitle = Title::makeTitle( NS_USER, $comment->mUserText );
		$useruri = $usertitle->exists() ? $usertitle->getFullUrl() : null;
		$entry->addAuthor( $usertext, $useruri );

		# Timestamp
		$entry->setPublished( $comment->mTimestamp );

		return $entry;
	}

	/**
	 * Returns the keys for the timestamp and feed output in the object cache.
	 */
	function getCacheKeys() {
		$title = $this->mSubject;
		$id = $title ? 'id:' . $title->getArticleId() : 'site';
		$ft = 'show:' . $this->mQuery->getModStatus() .
			':limit:' . $this->mLimit;
		return array(
			wfMemcKey( 'wikilog', $this->mFormat, $id, 'timestamp' ),
			wfMemcKey( 'wikilog', $this->mFormat, $id, $ft )
		);
	}

	/**
	 * Creates an unique ID for a feed entry. Tries to use $wgTaggingEntity
	 * if possible in order to create an RFC 4151 tag, otherwise, we use the
	 * page URL.
	 */
	public static function makeEntryId( WikilogComment $comment ) {
		global $wgTaggingEntity;
		if ( $wgTaggingEntity ) {
			$qstr = wfArrayToCGI( array( 'wk' => wfWikiID(), 'id' => $comment->getID() ) );
			return "tag:{$wgTaggingEntity}:/MediaWiki/Wikilog/comment?{$qstr}";
		} else {
			return $comment->getCommentArticleTitle()->getFullUrl();
		}
	}
}
