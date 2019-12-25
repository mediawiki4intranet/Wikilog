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

class PageLastVisitUpdater implements DeferrableUpdate {

	var $visit = array();

	function __construct() {
		DeferredUpdates::addUpdate( $this );
	}

	function add( $pageid, $userid, $timestamp ) {
		$this->visit[] = array(
			'pv_page' => $pageid,
			'pv_user' => $userid,
			'pv_date' => $timestamp,
		);
	}

	function doUpdate() {
		if ( $this->visit ) {
			$dbw = wfGetDB( DB_MASTER );
			foreach ( $this->visit as &$v ) {
				$v['pv_date'] = $dbw->timestamp( $v['pv_date'] );
			}
			$dbw->replace( 'page_last_visit', array( array( 'pv_page', 'pv_user' ) ), $this->visit, __METHOD__ );
			$this->visit = array();
		}
	}

}

/**
 * Utilitary functions used by the Wikilog extension.
 */
class WikilogUtils {

	static $updater = false;

	/**
	 * Updates last visit date of Wiki page with id $pageid
	 */
	public static function updateLastVisit( $pageid, $timestamp = NULL, $userid = NULL ) {
		if ( !$userid ) {
			global $wgUser;
			$userid = $wgUser->getID();
			if ( !$userid ) {
				return;
			}
		}
		if ( $pageid instanceof Title ) {
			$pageid = $pageid->getArticleId();
		} elseif ( $pageid instanceof WikiPage ) {
			$pageid = $pageid->getID();
		}
		if ( !$pageid ) {
			return;
		}
		if ( !self::$updater ) {
			self::$updater = new PageLastVisitUpdater();
		}
		self::$updater->add( $pageid, $userid, $timestamp );
	}

	/**
	 * Updates the number of article comments for page $pageID.
	 */
	public static function updateTalkInfo( $pageID, $isWikilogPost = false ) {
		global $wgWikilogNamespaces;
		$dbw = wfGetDB( DB_MASTER );

		// Retrieve number of comments and max comment update timestamp
		$result = $dbw->select( 'wikilog_comments', 'COUNT(*), MAX( wlc_updated )',
			array( 'wlc_post' => $pageID ), __METHOD__ );
		$row = $dbw->fetchRow( $result );
		$dbw->freeResult( $result );

		if ( $isWikilogPost ) {
			// Only update wti_talk_updated when wlp_pubdate changes, not on every post change
			$pageUpdated = $dbw->selectField( 'wikilog_posts', 'wlp_pubdate',
				array( 'wlp_page' => $pageID ), __METHOD__ );
		} else {
			$pageUpdated = $dbw->selectField( array( 'page', 'revision' ), 'rev_timestamp',
				array( 'page_latest=rev_id', 'page_id' => $pageID ), __METHOD__ );
		}

		list( $count, $talkUpdated ) = $row;
		if ( !$talkUpdated || $pageUpdated > $talkUpdated ) {
			$talkUpdated = $pageUpdated;
		}
		$talkUpdated = wfTimestamp( TS_MW, $talkUpdated );

		// Update wikilog_talkinfo cache
		$dbw->replace( 'wikilog_talkinfo', array( 'wti_page' ), array( array(
				'wti_page' => $pageID,
				'wti_num_comments' => $count,
				'wti_talk_updated' => $dbw->timestamp( $talkUpdated )
			) ), __METHOD__ );

		return array( $count, $talkUpdated );
	}

	/**
	 * Retrieves an article parsed output either from parser cache or by
	 * parsing it again. If parsing again, stores it back into parser cache.
	 *
	 * @param $title Article title object.
	 * @param $feed Whether the result should be part of a feed.
	 * @return Two-element array containing the article and its parser output.
	 *
	 * @note Mw1.16+ provides Article::getParserOptions() and
	 *   Article::getParserOutput(), that could be used here in the future.
	 *   The problem is that getParserOutput() uses ParserCache exclusively,
	 *   which means that only ParserOptions control the key used to store
	 *   the output in the cache and there is no hook yet in
	 *   ParserCache::getKey() to set these extra bits (and the
	 *   'PageRenderingCache' hook is not useful here, it is in the wrong
	 *   place without access to the parser options). This is certainly
	 *   something that should be fixed in the future.  FIXME
	 *
	 * @note This function makes a clone of the parser if
	 *   $wgWikilogCloneParser is set, but cloning the parser is not
	 *   officially supported. The problem here is that we need a different
	 *   parser that we could mess up without interfering with normal page
	 *   rendering, and we can't create a new instance because of too many
	 *   broken extensions around. Check self::parserSanityCheck().
	 */
	public static function parsedArticle( Title $title, $feed = false ) {
		global $wgWikilogCloneParser;
		global $wgUser, $wgEnableParserCache;
		global $wgParser, $wgParserConf;

		static $parser = null;

		$article = new WikiPage( $title );

		# First try the parser cache.
		$useParserCache = $wgEnableParserCache &&
			intval( $wgUser->getOption( 'stubthreshold' ) ) == 0 &&
			$article->exists();
		$parserCache = ParserCache::singleton();

		# Parser options.
		$parserOpt = ParserOptions::newFromUser( $wgUser );
		$parserOpt->setTidy( true );
		if ( $feed ) {
			$parserOpt->setEditSection( false );

			$parserOpt->addExtraKey( "WikilogFeed" );
		} else {
			$parserOpt->enableLimitReport();
		}

		if ( $useParserCache ) {
			# Look for the parsed article output in the parser cache.
			$parserOutput = $parserCache->get( $article, $parserOpt );

			# On success, return the object retrieved from the cache.
			if ( $parserOutput ) {
				return array( $article, $parserOutput );
			}
		}

		# Enable some feed-specific behavior.
		if ( $feed ) {
			$saveFeedParse = WikilogParser::enableFeedParsing();
			$saveExpUrls = WikilogParser::expandLocalUrls();
		}

		# Get a parser instance, if not already cached.
		if ( is_null( $parser ) ) {
			if ( !StubObject::isRealObject( $wgParser ) ) {
				$wgParser->_unstub();
			}
			if ( $wgWikilogCloneParser ) {
				$parser = clone $wgParser;
			} else {
				$class = $wgParserConf['class'];
				$parser = new $class( $wgParserConf );
			}
		}
		$parser->startExternalParse( $title, $parserOpt, Parser::OT_HTML );

		# Parse article.
		$articleContent = $article->getContent();
		if ( !($articleContent instanceof TextContent) ){
			# Restore default behavior.
			if ( $feed ) {
				WikilogParser::enableFeedParsing( $saveFeedParse );
				WikilogParser::expandLocalUrls( $saveExpUrls );
			}
			return array( $article, '' );
		}
		$arttext = $articleContent->getNativeData();
		$parserOutput = $parser->parse( $arttext, $title, $parserOpt );

		# Save in parser cache.
		if ( $useParserCache && $parserOutput->getCacheTime() != -1 ) {
			$parserCache->save( $parserOutput, $article, $parserOpt );
		}

		# Restore default behavior.
		if ( $feed ) {
			WikilogParser::enableFeedParsing( $saveFeedParse );
			WikilogParser::expandLocalUrls( $saveExpUrls );
		}

		return array( $article, $parserOutput );
	}

	/**
	 * Check sanity of a second parser instance against the global one.
	 *
	 * @param $newparser New parser instance to be checked.
	 * @return Whether the second parser instance contains the same hooks as
	 *   the global one.
	 */
	private static function parserSanityCheck( $newparser ) {
		global $wgParser;

		$newparser->firstCallInit();

		$th_diff = array_diff_key( $wgParser->mTagHooks, $newparser->mTagHooks );
		$tt_diff = array_diff_key( $wgParser->mTransparentTagHooks, $newparser->mTransparentTagHooks );
		$fh_diff = array_diff_key( $wgParser->mFunctionHooks, $newparser->mFunctionHooks );

		if ( !empty( $th_diff ) || !empty( $tt_diff ) || !empty( $fh_diff ) ) {
			wfDebug( "*** Wikilog WARNING: Detected broken extensions installed. "
				  . "A second instance of the parser is not properly initialized. "
				  . "The following hooks are missing:\n" );
			if ( !empty( $th_diff ) ) {
				$hooks = implode( ', ', array_keys( $th_diff ) );
				wfDebug( "***    Tag hooks: $hooks.\n" );
			}
			if ( !empty( $tt_diff ) ) {
				$hooks = implode( ', ', array_keys( $tt_diff ) );
				wfDebug( "***    Transparent tag hooks: $hooks.\n" );
			}
			if ( !empty( $fh_diff ) ) {
				$hooks = implode( ', ', array_keys( $fh_diff ) );
				wfDebug( "***    Function hooks: $hooks.\n" );
			}
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Formats a list of authors.
	 * Given a list of authors, this function formats it in wiki syntax,
	 * with links to their user and user-talk pages, according to the
	 * 'wikilog-author-signature' system message.
	 *
	 * @param $list Array of authors.
	 * @return Wikitext-formatted textual list of authors.
	 */
	public static function authorList( $list ) {
		global $wgContLang;

		if ( is_string( $list ) ) {
			return self::authorSig( $list );
		}
		elseif ( is_array( $list ) ) {
			$authors = array_map( array( __CLASS__, 'authorSig' ), $list );
			return $wgContLang->listToText( $authors );
		}
		else {
			return '';
		}
	}

	/**
	 * Formats a single author signature.
	 * Uses the 'wikilog-author-signature' system message, in order to provide
	 * user and user-talk links.
	 *
	 * @param $author String, author name.
	 * @return Wikitext-formatted author signature.
	 */
	public static function authorSig( $author, $parse = false )
	{
		static $authorSigCache = array();
		if ( !isset( $authorSigCache[$author] ) )
		{
			$user = User::newFromName( $author );
			$n = $user->getRealName();
			if ( !$n )
				$n = $author;
			$authorSigCache[$author . ($parse ? '/1' : '/0')] = $parse
				? wfMessage( 'wikilog-author-signature', $user->getName(), $n )->parse()
				: wfMessage( 'wikilog-author-signature', $user->getName(), $n )->inContentLanguage()->text();
		}
		return $authorSigCache[$author . ($parse ? '/1' : '/0')];
	}

	/**
	 * Formats a list of categories.
	 * Given a list of categories, this function formats it in wiki syntax,
	 * with links to either their page or to Special:Wikilog.
	 *
	 * @param $list Array of categories.
	 * @return Wikitext-formatted textual list of categories.
	 */
	public static function categoryList( $list ) {
		global $wgContLang;
		$special = $wgContLang->specialPage( 'Wikilog' );
		$categories = array();
		foreach ( $list as $cat ) {
			$title = Title::makeTitle( NS_CATEGORY, $cat );
			$categoryUrl = $title->getPrefixedText();
			$categoryTxt = $title->getText();
			$categories[] = "[[{$special}/{$categoryUrl}|{$categoryTxt}]]";
		}
		return $wgContLang->listToText( $categories );
	}

	/**
	 * Formats a list of tags.
	 * Given a list of tags, this function formats it in wiki syntax,
	 * with links to Special:Wikilog.
	 *
	 * @param $list Array of tags.
	 * @return Wikitext-formatted textual list of tags.
	 */
	public static function tagList( $list ) {
		global $wgContLang;
		$special = $wgContLang->specialPage( 'Wikilog' );
		$tags = array();
		foreach ( $list as $tag ) {
			$tags[] = "[[{$special}/t={$tag}|{$tag}]]";
		}
		return $wgContLang->listToText( $tags );
	}

	/**
	 * Split summary of a wikilog article from the contents.
	 * If summary is part of the parser output, use it; otherwise, try to
	 * extract it from the content text (section zero, before the first
	 * heading).
	 *
	 * @param $parserOutput ParserOutput object.
	 * @return Two-element array with summary and content. Summary may be
	 *   NULL if nonexistent.
	 */
	public static function splitSummaryContent( $parserOutput ) {
		global $wgUseTidy;

		$content = Sanitizer::removeHTMLcomments( $parserOutput->getText() );

		if ( isset( $parserOutput->mExtWikilog ) && $parserOutput->mExtWikilog->mSummary ) {
			# Parser output contains wikilog output and summary, use it.
			$summary = Sanitizer::removeHTMLcomments( $parserOutput->mExtWikilog->mSummary );
		} else {
			# Use DOM to extract summary from the content text.
			try {
				$dom = new DOMDocument();
				@$dom->loadHTML('<?xml encoding="UTF-8">' . $content);
				$summary = new DOMDocument();
				$h = false;
				// Dive into imported <html><body>
				$ch = $dom->documentElement;
				if ( $ch && ( $ch = $ch->childNodes ) ) {
					foreach ( $ch->item( 0 )->childNodes as $node ) {
						# Cut summary at first heading
						if ( preg_match( '/^h\d$/is', $node->nodeName ) ) {
							$h = true;
							break;
						}
						if ( $node->nodeName == 'script' ) {
							continue;
						}
						if ( $node->nodeName == 'table' ) {
							$id = $node->attributes->getNamedItem( 'id' );
							if ( $id && $id->textContent == 'toc' ) {
								continue;
							}
						}
						$summary->appendChild( $summary->importNode( $node, true ) );
					}
				}
			} catch( Exception $e ) {
				$h = false;
			}
			if ( $h ) {
				$summary = $summary->saveHTML();
			} else {
				$summary = null;
			}
		}

		return array( $summary, $content );
	}

	/**
	 * Formats a comments page link.
	 *
	 * @param $item WikilogItem object.
	 * @return Wikitext-formatted comments link.
	 */
	public static function getCommentsWikiText( WikilogItem &$item ) {
		$commentsNum = $item->getNumComments();
		$commentsMsg = ( $commentsNum ? 'wikilog-has-comments' : 'wikilog-no-comments' );
		$commentsUrl = $item->mTitle->getTalkPage()->getPrefixedURL();
		$commentsTxt = wfMessage( $commentsMsg, $commentsNum )->inContentLanguage()->text();
		return "[[{$commentsUrl}|{$commentsTxt}]]";
	}

	/**
	 * Causes an update to the given Wikilog main page.
	 */
	public static function updateWikilog( $title ) {
		if ( $title->exists() ) {
			$title->invalidateCache();
			$title->purgeSquid();

			$dbw = wfGetDB( DB_MASTER );
			$dbw->update(
				'wikilog_wikilogs',
				array( 'wlw_updated' => $dbw->timestamp() ),
				array( 'wlw_page' => $title->getArticleID(), ),
				__METHOD__
			);
		}
	}

	/**
	 * Given a MagicWord, returns any array element which key matches the
	 * magic word. Always case-sensitive.
	 */
	public static function arrayMagicKeyGet( &$array, MagicWord $mw ) {
		foreach ( $mw->getSynonyms() as $key ) {
			if ( array_key_exists( $key, $array ) )
				return $array[$key];
		}
		return null;
	}

	/**
	 * Builds an HTML form in a table.
	 */
	public static function buildForm( $fields ) {
		$rows = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) ) {
				$row = Xml::tags( 'td', array( 'class' => 'mw-label' ), $field[0] ) .
					Xml::tags( 'td', array( 'class' => 'mw-input' ), $field[1] );
			} else {
				$row = Xml::tags( 'td', array( 'class' => 'mw-input',
					'colspan' => 2 ), $field );
			}
			$rows[] = Xml::tags( 'tr', array(), $row );
		}
		$form = Xml::tags( 'table', array( 'width' => '100%' ),
			implode( "\n", $rows ) );
		return $form;
	}

	/**
	 * Wraps a div, with a class, around some HTML fragment.
	 * Similar to Xml::wrapClass(..., 'div') or Xml::tags('div',...).
	 * This is something that should be in includes/Xml.php, doing it here
	 * to avoid Mw version dependency.
	 */
	public static function wrapDiv( $class, $text ) {
		return Xml::tags( 'div', array( 'class' => $class ), $text );
	}

	/**
	 * Returns the date and user parameters suitable for substitution in
	 * {{wl-publish:...}} parser function.
	 */
	public static function getPublishParameters() {
		global $wgUser, $wgLocaltimezone;

		$user = $wgUser->getName();
		$popt = ParserOptions::newFromUser( $wgUser );

		$unixts = wfTimestamp( TS_UNIX, $popt->getTimestamp() );
		if ( isset( $wgLocaltimezone ) ) {
			$oldtz = getenv( 'TZ' );
			putenv( "TZ={$wgLocaltimezone}" );
			$date = date( 'Y-m-d H:i:s O', $unixts );
			putenv( "TZ={$oldtz}" );
		} else {
			$date = date( 'Y-m-d H:i:s O', $unixts );
		}

		return array( 'date' => $date, 'user' => $user );
	}

	/**
	 * Return the given timestamp as a tuple with date, time and timezone
	 * in the local timezone (if defined). This is meant to be compatible
	 * with signatures produced by Parser::pstPass2(). It was based on this
	 * same function.
	 *
	 * @param $timestamp Timestamp.
	 * @return Array(3) containing date, time and timezone.
	 */
	public static function getLocalDateTime( $timestamp, $format = false ) {
		global $wgLang, $wgLocaltimezone;

		$ts = wfTimestamp( TS_UNIX, $timestamp );
		$tz = gmdate( 'T', $ts );
		$ts = gmdate( 'YmdHis', $ts );
		$ts = $wgLang->userAdjust( $ts );

		if ( !$format )
			$format = $wgLang->dateFormat( true );
		$df = $wgLang->getDateFormatString( 'date', $format );
		$tf = $wgLang->getDateFormatString( 'time', $format );
		$date = $wgLang->sprintfDate( $df, $ts );
		$time = $wgLang->sprintfDate( $tf, $ts );

		# Check for translation of timezones.
		$key = 'timezone-' . strtolower( trim( $tz ) );
		$value = wfMessage( $key )->inContentLanguage();
		if ( !$value->isBlank() ) $tz = $value->text();

		return array( $date, $time, $tz );
	}

	public static function getOldestRevision( $articleId ) {
		$row = NULL;
		$db = wfGetDB( DB_REPLICA );
		$revSelectFields = Revision::selectFields();
		while ( !$row ) {
			$row = $db->selectRow(
				array( 'revision' ), $revSelectFields,
				array( 'rev_page' => $articleId ), __METHOD__,
				array( 'ORDER BY' => 'rev_timestamp ASC', 'LIMIT' => 1 )
			);
			if ( !$row ) {
				$db = wfGetDB( DB_MASTER );
			}
		}
		return $row ? Revision::newFromRow( $row ) : null;
	}

	public static function sendHtmlMail($to, $from, $subject, $body, $headers)
	{
		global $wgVersion;
		if (version_compare($wgVersion, '1.25', '>='))
		{
			// MediaWiki 1.25+
			UserMailer::send($to, $from, $subject, $body, array(
				'headers' => $headers,
				'contentType' => 'text/html; charset=UTF-8',
			));
		}
		else
		{
			// MediaWiki 1.19+ or MediaWiki4Intranet 1.18
			UserMailer::send($to, $from, $subject, $body, NULL, 'text/html; charset=UTF-8', $headers);
		}
	}

	// 7bit 0bbbbbbb
	// 14bit 10bbbbbb bbbbbbbb
	// 21bit 110bbbbb bbbbbbbb bbbbbbbb
	// 28bit 1110bbbb bbbbbbbb bbbbbbbb bbbbbbbb
	// 35bit 11110bbb bbbbbbbb bbbbbbbb bbbbbbbb bbbbbbbb
	public static function encodeVarint( $int ) {
		if ( $int < 0x80 ) {
			return chr( $int );
		} elseif ( $int < 0x4000 ) {
			return chr( 0x80 | ( $int >> 8 ) ) . chr( $int & 0xFF );
		} elseif ( $int < 0x200000 ) {
			return chr( 0xC0 | ( $int >> 16 ) ) . chr( ( $int >> 8 ) & 0xFF ) . chr( $int & 0xFF );
		} elseif ( $int < 0x10000000 ) {
			return chr( 0xE0 | ( $int >> 24 ) ) . chr( ( $int >> 16 ) & 0xFF ) . chr( ( $int >> 8 ) & 0xFF ) . chr( $int & 0xFF );
		} else {
			return chr( 0xF0 | ( $int >> 32 ) ) . chr( ( $int >> 24 ) & 0xFF ) . chr( ( $int >> 16 ) & 0xFF ) . chr( ( $int >> 8 ) & 0xFF ) . chr( $int & 0xFF );
		}
	}

	public static function encodeVarintArray( $a ) {
		$s = '';
		foreach ( $a as $int ) {
			$s .= self::encodeVarint( $int );
		}
		return $s;
	}

	public static function decodeVarintArray( $s ) {
		$l = strlen( $s );
		$array = array();
		for ( $i = 0; $i < $l; ) {
			$b = ord( $s[$i++] );
			if ( !( $b & 0x80 ) ) {
				$array[] = $b;
			} elseif ( !( $b & 0x40 ) ) {
				$array[] = ( ( $b & 0x7F ) << 8 ) | ord( $s[$i++] );
			} elseif ( !( $b & 0x20 ) ) {
				$array[] = ( ( $b & 0x7F ) << 16 ) | ord( $s[$i++] ) << 8 | ord( $s[$i++] );
			} elseif ( !( $b & 0x10 ) ) {
				$array[] = ( ( $b & 0x7F ) << 24 ) | ord( $s[$i++] ) << 16 | ord( $s[$i++] ) << 8 | ord( $s[$i++] );
			} else {
				$array[] = ord( $s[$i++] ) << 24 | ord( $s[$i++] ) << 16 | ord( $s[$i++] ) << 8 | ord( $s[$i++] );
			}
		}
		return $array;
	}

}

/**
 * Generates a more user-friendly navigation bar for use in article and
 * comment pagers (shared between WikilogItemPager and WikilogCommentPager).
 */
class WikilogNavbar
{
	static $pagingLabels = array(
		'prev'  => '‹ $1',
		'next'  => '$1 ›',
		'first' => '« $1',
		'last'  => '$1 »'
	);
	static $linkTextMsgs = array(
		# pages style:  « first  ‹ previous 20  ...  next 20 ›  last »
		'pages' => array(
			'prev' => 'prevn',
			'next' => 'nextn',
			'first' => 'wikilog-pager-first',
			'last' => 'wikilog-pager-last'
		),
		# pages-sim style:  « first  ‹ previous  ...  next ›  last »
		'pages-sim' => array(
			'prev' => 'wikilog-pager-prev',
			'next' => 'wikilog-pager-next',
			'first' => 'wikilog-pager-first',
			'last' => 'wikilog-pager-last'
		),
		# chrono-fwd style:  « oldest  ‹ older 20  ...  newer 20 ›  newest »
		'chrono-fwd' => array(
			'prev' => 'pager-older-n',
			'next' => 'pager-newer-n',
			'first' => 'wikilog-pager-oldest',
			'last' => 'wikilog-pager-newest'
		),
		# chrono-rev style:  « newest  ‹ newer 20  ...  older 20 ›  oldest »
		'chrono-rev' => array(
			'prev' => 'pager-newer-n',
			'next' => 'pager-older-n',
			'first' => 'wikilog-pager-newest',
			'last' => 'wikilog-pager-oldest'
		),
	);

	protected $mPager, $mType;

	/**
	 * Constructor.
	 * @param $pager IndexPager  Pager object.
	 * @param $type string  Type of navigation bar to generate:
	 *   * 'pages': For normal pages, with 'first', 'last', 'previous', 'next';
	 *   * 'chrono-fwd': For chronological events, in forward order (later
	 *        pages contain newer events);
	 *   * 'chrono-rev': For chronological events, in reverse order (later
	 *        pages contain older events).
	 */
	function __construct( IndexPager $pager, $type = 'pages' ) {
		$this->mPager = $pager;
		$this->mType = $type;
	}

	/**
	 * Format and return the navigation bar.
	 * @param $limit integer  Number of itens being displayed.
	 * @return string  HTML-formatted navigation bar.
	 */
	public function getNavigationBar( $limit ) {
		global $wgLang;

		$limit = $wgLang->formatNum( $limit );
		$linkTexts = $disabledTexts = array();
		foreach ( self::$linkTextMsgs[$this->mType] as $type => $msg ) {
			$label = wfMessage( $msg, $limit )->escaped();
			$linkTexts[$type] = wfMsgReplaceArgs( self::$pagingLabels[$type], array( $label ) );
			$disabledTexts[$type] = Xml::wrapClass( $linkTexts[$type], 'disabled' );
		}

		$pagingLinks = $this->mPager->getPagingLinks( $linkTexts, $disabledTexts );
// 		$limitLinks = $this->mPager->getLimitLinks(); // XXX: Not used yet.
		$ellipsis = wfMessage( 'ellipsis' )->text();
		$html = "{$pagingLinks['first']} {$pagingLinks['prev']} {$ellipsis} {$pagingLinks['next']} {$pagingLinks['last']}";
		$html = WikilogUtils::wrapDiv( 'wl-pagination', $html );

		$dir = $wgLang->getDir();

		return Xml::tags( 'div',
			array(
				'class' => 'wl-navbar',
				'dir' => $dir
			),
			$html
		);
	}

}
