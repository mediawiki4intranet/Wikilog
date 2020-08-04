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
 * Common wikilog pager interface.
 */
interface WikilogItemPager
	extends Pager
{
	function including( $x = null );
}

/**
 * Summary pager.
 *
 * Lists wikilog articles from one or more wikilogs (selected by the provided
 * query parameters) in reverse chronological order, displaying article
 * sumaries, authors, date and number of comments. This pager also provides
 * a "read more" link when appropriate. If there are more articles than
 * some threshold, the user may navigate through "newer posts"/"older posts"
 * links.
 *
 * Formatting is controlled by a number of system messages.
 */
class WikilogSummaryPager
	extends ReverseChronologicalPager
	implements WikilogItemPager
{
	# Override default limits.
	public $mLimitsShown = array( 5, 10, 20, 50 );

	# Local variables.
	public $mQuery = null;			///< Wikilog item query data
	public $mIncluding = false;		///< If pager is being included
	public $noActions = false;		///< Hide "Actions" column, used in SpecialWikilog.php
	public $mShowEditLink = false;	///< If edit links are shown.

	/**
	 * Constructor.
	 * @param $query Query object, containing the parameters that will select
	 *   which articles will be shown.
	 * @param $limit Override how many articles will be listed.
	 */
	function __construct( WikilogItemQuery $query, $limit = false, $including = false ) {
		# WikilogItemQuery object drives our queries.
		$this->mQuery = $query;
		$this->mIncluding = $including;

		# Parent constructor.
		parent::__construct();

		# Fix our limits, Pager's defaults are too high.
		global $wgUser, $wgWikilogNumArticles;
		$this->mDefaultLimit = $wgWikilogNumArticles;

		if ( $limit ) {
			$this->mLimit = $limit;
		} else {
			list( $this->mLimit, /* $offset */ ) =
				$this->mRequest->getLimitOffset( $wgWikilogNumArticles, '' );
		}

		# This is too expensive, limit listing.
		global $wgWikilogExpensiveLimit;
		if ( $this->mLimit > $wgWikilogExpensiveLimit ) {
			$this->mLimit = $wgWikilogExpensiveLimit;
		}

		# Check parser state, setup edit links.
		global $wgOut, $wgParser, $wgTitle;
		if ( $this->mIncluding ) {
			$popt = $wgParser->getOptions();
		} else {
			$popt = $wgOut->parserOptions();

		# We will need a clean parser if not including.
			$wgParser->startExternalParse( $wgTitle, $popt, Parser::OT_HTML );
		}
		$this->mShowEditLink = $popt->getEditSection();
	}

	/**
	 * Property accessor/mutators.
	 */
	function including( $x = null ) { return wfSetVar( $this->mIncluding, $x ); }

	function getQueryInfo() {
		return $this->mQuery->getQueryInfo( $this->mDb );
	}

	function getDefaultQuery() {
		return parent::getDefaultQuery() + $this->mQuery->getDefaultQuery();
	}

	function getIndexField() {
		return 'wlp_pubdate';
	}

	function setSort( $field ) {
		if ( WikilogArchivesPager::staticIsFieldSortable( $field ) ) {
			$this->mIndexField = $field;
		}
	}

	function reallyDoQuery( $offset, $limit, $descending ) {
		// Wikilog is OVER-OBJECT-ORIENTED and requires such UGLY HACKS for sorting to work
		$old = false;
		if ( isset( WikilogArchivesPager::$indexFieldOverride[$this->mIndexField] ) ) {
			$old = $this->mIndexField;
			$this->mIndexField = WikilogArchivesPager::$indexFieldOverride[$this->mIndexField];
		}
		$r = parent::reallyDoQuery( $offset, $limit, $descending );
		if ( $old ) {
			$this->mIndexField = $old;
		}
		return $r;
	}

	function getStartBody() {
		return "<div class=\"wl-roll visualClear\">\n";
	}

	function getEndBody() {
		return "</div>\n";
	}

	function getEmptyBody() {
		return '<div class="wl-empty">' . wfMessage( 'wikilog-pager-empty' )->text() . "</div>";
	}

	function getNavigationBar() {
			if ( !$this->isNavigationBarShown() ) return '';
		if ( !isset( $this->mNavigationBar ) ) {
			$navbar = new WikilogNavbar( $this, 'chrono-rev' );
			$this->mNavigationBar = $navbar->getNavigationBar( $this->mLimit );
		}
		return $this->mNavigationBar;
	}

	function formatRow( $row ) {
		global $wgWikilogExtSummaries;
		$skin = $this->getSkin();
		$header = $footer = '';

		# Retrieve article parser output and other data.
		$item = WikilogItem::newFromRow( $row );
		list( $article, $parserOutput ) = WikilogUtils::parsedArticle( $item->mTitle );
		list( $summary, $content ) = WikilogUtils::splitSummaryContent( $parserOutput );

		// FIXME: Do not use global output, pass it from somewhere
		global $wgOut;
		$wgOut->addModules( $parserOutput->getModules() );
		$wgOut->addModuleStyles( $parserOutput->getModuleStyles() );
		$wgOut->addModuleScripts( $parserOutput->getModuleScripts() );

		# Retrieve the common header and footer parameters.
		$params = $item->getMsgParams( $wgWikilogExtSummaries, $parserOutput );

		# Article title heading, with direct link article page and optional
		# edit link (if user can edit the article).
		$titleText = Sanitizer::escapeHtmlAllowEntities( $item->mName );
		if ( !$item->getIsPublished() )
			$titleText .= wfMessage( 'wikilog-draft-title-mark' )->inContentLanguage()->text();
		$heading = Linker::link( $item->mTitle, $titleText, array(), array(),
			array( 'known', 'noclasses' )
		);
		if ( $this->mShowEditLink && $item->mTitle->quickUserCan( 'edit' ) ) {
			$heading = $this->doEditLink( $item->mTitle, $item->mName ) . $heading;
		}
		$heading = Xml::tags( 'h2', null, $heading );

		# Sumary entry header.
		$key = $this->mQuery->isSingleWikilog()
			? 'wikilog-summary-header-single'
			: 'wikilog-summary-header';
		$msg = wfMessage( $key, $params )->inContentLanguage()->text();
		if ( !empty( $msg ) ) {
			$header = WikilogUtils::wrapDiv( 'wl-summary-header', $this->parse( $msg ) );
		}

		# Summary entry text.
		if ( $summary ) {
			$more = $this->parse( wfMessage( 'wikilog-summary-more', $params )->inContentLanguage()->plain() );
			$summary = WikilogUtils::wrapDiv( 'wl-summary', $summary . $more );
		} else {
			$summary = WikilogUtils::wrapDiv( 'wl-summary', $content );
		}

		# Update last visit
		WikilogUtils::updateLastVisit( $item->getID() );

		# Summary entry footer.
		$key = $this->mQuery->isSingleWikilog()
			? 'wikilog-summary-footer-single'
			: 'wikilog-summary-footer';
		$msg = wfMessage( $key, $params )->inContentLanguage()->text();
		if ( !empty( $msg ) ) {
			$footer = WikilogUtils::wrapDiv( 'wl-summary-footer', $this->parse( $msg ) );
		}

		# Assembly the entry div.
		$divclass = array( 'wl-entry', 'visualClear' );
		if ( !$item->getIsPublished() )
			$divclass[] = 'wl-draft';
		$entry = WikilogUtils::wrapDiv(
			implode( ' ', $divclass ),
			$heading . $header . $summary . $footer
		);
		return $entry;
	}

	/**
	 * Parse a given wikitext and returns the resulting HTML fragment.
	 * Uses either $wgParser->recursiveTagParse() or $wgParser->parse()
	 * depending whether the content is being included in another
	 * article. Note that the parser state can't be reset, or it will
	 * break the parser output.
	 * @param $text Wikitext that should be parsed.
	 * @return Resulting HTML fragment.
	 */
	protected function parse( $text ) {
		global $wgTitle, $wgParser, $wgOut;
		if ( $this->mIncluding ) {
			return $wgParser->recursiveTagParse( $text ) . "\n";
		} else {
			$popts = $wgOut->parserOptions();
			$output = $wgParser->parse( $text, $wgTitle, $popts, true, false );
			return $output->getText();
		}
	}

	/**
	 * Returns a wikilog article edit link, much similar to a section edit
	 * link in normal articles.
	 * @param $title Title  The title of the target article.
	 * @param $tooltip string  The tooltip to be included in the link, wrapped
	 *   in the 'wikilog-edit-hint' message.
	 * @return string  HTML fragment.
	 */
	private function doEditLink( $title, $tooltip = null ) {
		$skin = $this->getSkin();
		$attribs = array();
		if ( !is_null( $tooltip ) ) {
			$attribs['title'] = wfMessage( 'wikilog-edit-hint', $tooltip )->text();
		}
		$link = Linker::link( $title, wfMessage( 'wikilog-edit-lc' )->text(),
			$attribs,
			array( 'action' => 'edit' ),
			array( 'noclasses', 'known' )
		);

		$result = wfMessage( 'editsection-brackets', $link )->escaped();
		$result = "<span class=\"editsection\">$result</span>";

		wfRunHooks( 'DoEditSectionLink', array( $skin, $title, "", $tooltip, &$result ) );
		return $result;
	}
}

/**
 * Template pager.
 *
 * Lists wikilog articles like #WikilogSummaryPager, but using a given
 * template to format the summaries. The template receives the article
 * data through its parameters:
 *
 * - 'class': div element class attribute
 * - 'wikilogTitle': title (as text) of the wikilog page
 * - 'wikilogPage': title (prefixed, for link) of the wikilog page
 * - 'title': title (as text) of the article page
 * - 'page': title (prefixed, for link) of the article page
 * - 'authors': authors
 * - 'tags': tags
 * - 'published': empty (draft) or "*" (published)
 * - 'date': article publication date
 * - 'time': article publication time
 * - 'tz': timezone information
 * - 'updatedDate': article last update date
 * - 'updatedTime': article last update time
 * - 'summary': article summary
 * - 'hasMore': empty (summary only) or "*" (has more than summary)
 * - 'comments': comments page link
 */
class WikilogTemplatePager
	extends WikilogSummaryPager
{
	protected $mTemplate, $mTemplateTitle;

	/**
	 * Constructor.
	 */
	function __construct( WikilogItemQuery $query, Title $template, $limit = false, $including = false ) {
		global $wgParser, $wgUser;

		# Parent constructor.
		parent::__construct( $query, $limit, $including );

		# Load template
		if ( !$wgParser->mOptions ) {
			$wgParser->parse( '', $template, ParserOptions::newFromUser( $wgUser ) );
		}
		list( $this->mTemplate, $this->mTemplateTitle ) =
			$wgParser->getTemplateDom( $template );
		if ( $this->mTemplate === false ) {
			$this->mTemplate = "[[:$template]]";
		}
	}

	function getDefaultQuery() {
		$query = parent::getDefaultQuery();
		$query['template'] = $this->mTemplateTitle->getPartialURL();
		return $query;
	}

	function getStartBody() {
		return '';
	}

	function getEndBody() {
		return '';
	}

	function formatRow( $row ) {
		global $wgParser, $wgLang;
		global $wgWikilogPagerDateFormat;

		# Retrieve article parser output and other data.
		$item = WikilogItem::newFromRow( $row );
		list( $article, $parserOutput ) = WikilogUtils::parsedArticle( $item->mTitle );
		list( $summary, $content ) = WikilogUtils::splitSummaryContent( $parserOutput );
		if ( empty( $summary ) ) {
			$summary = $content;
			$hasMore = false;
		} else {
			$hasMore = true;
		}

		# Some general data.
		$authors = WikilogUtils::authorList( array_keys( $item->mAuthors ) );
		$tags = implode( wfMessage( 'comma-separator' )->inContentLanguage()->text(), array_keys( $item->mTags ) );
		$comments = WikilogUtils::getCommentsWikiText( $item );
		$divclass = 'wl-entry' . ( $item->getIsPublished() ? '' : ' wl-draft' );

		$itemPubdate = $item->getPublishDate();
		list( $publishedDate, $publishedTime, $publishedTz ) =
				WikilogUtils::getLocalDateTime( $itemPubdate, $wgWikilogPagerDateFormat );

		$now = wfTimestampNow( TS_MW );

		$itemUpdated = $item->getUpdatedDate();
		list( $updatedDate, $updatedTime, ) =
				WikilogUtils::getLocalDateTime( $itemUpdated, $wgWikilogPagerDateFormat );

		$itemTalkUpdated = $item->getTalkUpdatedDate();
		list( $talkUpdatedDate, $talkUpdatedTime, ) =
				WikilogUtils::getLocalDateTime( $itemTalkUpdated, $wgWikilogPagerDateFormat );

		$nc = $item->getNumComments();
		if ( !$nc ) {
			$nc = '';
		}

		# Template parameters.
		$vars = array(
			'class'         => $divclass,
			'wikilogTitle'  => $item->mParentName,
			'wikilogPage'   => $item->mParentTitle->getPrefixedText(),
			'title'         => $item->mName,
			'page'          => $item->mTitle->getPrefixedText(),
			'talkpage'      => $item->mTitle->getTalkPage()->getPrefixedText(),
			'authors'       => $authors,
			'tags'          => $tags,
			'published'     => $item->getIsPublished() ? '*' : '',
			'dateInFuture'  => $itemPubdate > $now,
			'date'          => $publishedDate,
			'time'          => $publishedTime,
			'tz'            => $publishedTz,
			'updatedDate'   => $updatedDate,
			'updatedTime'   => $updatedTime,
			'talkUpdatedDate' => $talkUpdatedDate,
			'talkUpdatedTime' => $talkUpdatedTime,
			'summary'       => $wgParser->insertStripItem( $summary ),
			'hasMore'       => $hasMore ? '*' : '',
			'comments'      => $comments,
			'ncomments'     => $nc,
		);

		$frame = $wgParser->getPreprocessor()->newCustomFrame( $vars );
		$text = $frame->expand( $this->mTemplate );

		return $this->parse( $text );
	}
}

/**
 * Archives pager.
 *
 * Lists wikilog articles in a table, with date, authors, wikilog and
 * title, without summaries, for easy navigation through large amounts of
 * articles.
 */
class WikilogArchivesPager
	extends TablePager
	implements WikilogItemPager
{
	# Local variables.
	public $mQuery = null;			///< Wikilog item query data
	public $mIncluding = false;		///< If pager is being included

	static $sortableFields = array(
		'wlp_pubdate',
		'wlp_updated',
		'wlw_title',
		'wlp_title',
		'wti_num_comments',
		'wti_talk_updated',
	);

	static $indexFieldOverride = array(
		// FIXME These sort orders aren't that useful because you can't sort on two fields at once
		// I.e. sort on wikilog title is mostly useless
		'wlw_namespace'  => 'w.page_namespace',
		'wlw_title'      => 'w.page_title',
		'page_namespace' => 'p.page_namespace',
		'page_title'     => 'p.page_title',
	);

	/**
	 * Constructor.
	 */
	function __construct( WikilogItemQuery $query, $including = false, $limit = false ) {
		# WikilogItemQuery object drives our queries.
		$this->mQuery = $query;
		$this->mQuery->setOption( 'last-visit-date', true );
		$this->mIncluding = $including;

		# Parent constructor.
		parent::__construct();

		# Fix our limits, Pager's defaults are too high.
		global $wgUser, $wgWikilogNumArticles;
		$this->mDefaultLimit = $wgWikilogNumArticles;

		if ( $limit ) {
			$this->mLimit = $limit;
		} else {
			$this->mLimit = $wgWikilogNumArticles;
		}

		# This is too expensive, limit listing.
		global $wgWikilogExpensiveLimit;
		if ( $this->mLimit > $wgWikilogExpensiveLimit ) {
			$this->mLimit = $wgWikilogExpensiveLimit;
		}
	}

	/**
	 * Property accessor/mutators.
	 */
	function including( $x = null ) { return wfSetVar( $this->mIncluding, $x ); }

	function getQueryInfo() {
		return $this->mQuery->getQueryInfo( $this->mDb );
	}

	function getDefaultQuery() {
		$query = parent::getDefaultQuery() + $this->mQuery->getDefaultQuery();
		$query['view'] = 'archives';
		return $query;
	}

	function getTableClass() {
		return 'wl-archives TablePager';
	}

	// Should be static, but isn't in TablePager :-E
	function isFieldSortable( $field ) {
		return in_array( $field, self::$sortableFields );
	}
	static function staticIsFieldSortable( $field ) {
		return in_array( $field, self::$sortableFields );
	}

	function setSort( $field ) {
		if ( $this->isFieldSortable( $field ) ) {
			$this->mIndexField = $field;
		}
	}

	function reallyDoQuery( $offset, $limit, $descending ) {
		// Wikilog is OVER-OBJECT-ORIENTED and requires such UGLY HACKS for sorting to work
		$old = false;
		if ( isset( self::$indexFieldOverride[$this->mIndexField] ) ) {
			$old = $this->mIndexField;
			$this->mIndexField = self::$indexFieldOverride[$this->mIndexField];
		}
		$r = parent::reallyDoQuery( $offset, $limit, $descending );
		if ( $old ) {
			$this->mIndexField = $old;
		}
		return $r;
	}

	function getNavigationBar() {
			if ( !$this->isNavigationBarShown() ) return '';
		if ( !isset( $this->mNavigationBar ) ) {
			$navbar = new WikilogNavbar( $this, 'pages' );
			$this->mNavigationBar = $navbar->getNavigationBar( $this->mLimit );
		}
		return $this->mNavigationBar;
	}

	function formatRow( $row ) {
		global $wgUser;
		$attribs = array( 'class' => '' );
		$columns = array();
		$this->mCurrentRow = $row;
		$this->mCurrentItem = WikilogItem::newFromRow( $row );
		if ( !$this->mCurrentItem->getIsPublished() ) {
			$attribs['class'] = 'wl-draft';
		}
		if ( $wgUser->getID() ) {
			$dbr = wfGetDB( DB_REPLICA );
			$result = $dbr->select(
				array( 'wikilog_comments', 'page_last_visit' ),
				'COUNT(*)',
				array( 'wlc_status' => 'OK', 'IFNULL(wlc_updated>pv_date,1)', 'wlc_post' => $row->wlp_page ),
				__METHOD__,
				NULL,
				array( 'page_last_visit' => array( 'LEFT JOIN', array( 'pv_page = wlc_comment_page', 'pv_user' => $wgUser->getID() ) ) )
			);
			$v = $dbr->fetchRow( $result );
			$dbr->freeResult( $result );
			$row->wlp_unread_comments = $v[0];
			if ( $row->wlp_last_visit < $row->wlp_updated || $row->wlp_unread_comments ) {
				$attribs['class'] .= ' wl-unread';
			}
		}
		foreach ( $this->getFieldNames() as $field => $name ) {
			$value = isset( $row->$field ) ? $row->$field : null;
			$formatted = strval( $this->formatValue( $field, $value ) );
			if ( $formatted == '' ) {
				$formatted = WL_NBSP;
			}
			$class = 'TablePager_col_' . htmlspecialchars( $field );
			$columns[] = "<td class=\"$class\">$formatted</td>";
		}
		return Xml::tags( 'tr', $attribs, implode( "\n", $columns ) ) . "\n";
	}

	function formatValue( $name, $value ) {
		global $wgLang;

		switch ( $name ) {
			case 'wlp_pubdate':
				$s = $wgLang->timeanddate( $value, true );
				if ( !$this->mCurrentRow->wlp_publish ) {
					$s = Xml::wrapClass( $s, 'wl-draft-inline' );
				}
				return $s;

			case 'wti_talk_updated':
				return $wgLang->timeanddate( $value, true );

			case 'wlp_updated':
				return $value;

			case 'wlp_authors':
				return $this->authorList( $this->mCurrentItem->mAuthors );

			case 'wlw_title':
				$page = $this->mCurrentItem->mParentTitle;
				$text = Sanitizer::escapeHtmlAllowEntities( $this->mCurrentItem->mParentName );
				return Linker::link( $page, $text, array(), array(),
					array( 'known', 'noclasses' ) );

			case 'wlp_title':
				$page = $this->mCurrentItem->mTitle;
				$text = Sanitizer::escapeHtmlAllowEntities( $this->mCurrentItem->mName );
				$s = Linker::link( $page, $text, array(), array(),
					array( 'known', 'noclasses' ) );
				if ( !$this->mCurrentRow->wlp_publish ) {
					$draft = wfMessage( 'wikilog-draft-title-mark' )->text();
					$s = Xml::wrapClass( "$s $draft", 'wl-draft-inline' );
				}
				return $s;

			case 'wti_num_comments':
				$page = $this->mCurrentItem->mTitle->getTalkPage();
				$text = $this->mCurrentItem->getNumComments();
				if ( !empty( $this->mCurrentRow->wlp_unread_comments ) ) {
					$text .= ' (' . $this->mCurrentRow->wlp_unread_comments . ')';
				}
				return Linker::link( $page, $text, array(), array(),
					array( 'known', 'noclasses' ) );

			case '_wl_actions':
				if ( $this->mCurrentItem->mTitle->quickUserCan( 'edit' ) ) {
					return $this->doEditLink( $this->mCurrentItem->mTitle, $this->mCurrentItem->mName );
				} else {
					return '';
				}

			default:
				return htmlentities( $value );
		}
	}

	function getDefaultSort() {
		global $wgRequest;
		// A hack to set default sort direction
		if ( !$wgRequest->getBool( 'asc' ) && ! $wgRequest->getBool( 'desc' ))
			$wgRequest->setVal('desc', 1);
		return 'wlp_pubdate';
	}

	function getFieldNames() {
		global $wgWikilogEnableComments;

		$fields = array();

		$fields['wlp_pubdate']			= wfMessage( 'wikilog-published' )->escaped();
 		// $fields['wlp_updated']			= wfMessage( 'wikilog-updated' )->escaped();
		$fields['wlp_authors']			= wfMessage( 'wikilog-authors' )->escaped();

		if ( !$this->mQuery->isSingleWikilog() )
			$fields['wlw_title']		= wfMessage( 'wikilog-wikilog' )->escaped();

		$fields['wlp_title']			= wfMessage( 'wikilog-title' )->escaped();

		if ( $wgWikilogEnableComments )
			$fields['wti_num_comments']	= wfMessage( 'wikilog-comments' )->escaped();

		if ( empty( $this->noActions ) )
			$fields['_wl_actions']			= wfMessage( 'wikilog-actions' )->escaped();

		$fields['wti_talk_updated'] = wfMessage( 'wikilog-talk-updated' )->escaped();

		return $fields;
	}

	/**
	 * Formats the given list of authors into a textual comma-separated list.
	 * @param $list Array with wikilog article author information.
	 * @return Resulting HTML fragment.
	 */
	private function authorList( $list ) {
		if ( is_string( $list ) ) {
			return $this->authorLink( $list );
		}
		elseif ( is_array( $list ) ) {
			$list = array_keys( $list );
			return implode( ', ', array_map( array( &$this, 'authorLink' ), $list ) );
		}
		else {
			return '';
		}
	}

	/**
	 * Formats an author user page link.
	 * @param $name Username of the author.
	 * @return Resulting HTML fragment.
	 */
	private function authorLink( $name )
	{
		$skin = $this->getSkin();
		$user = User::newFromName( $name );
		$name = $user->getRealName();
		if ( !$name )
			$name = $user->getName();
		return Linker::link( $user->getUserPage(), $name );
	}

	/**
	 * Returns a wikilog article edit link, much similar to a section edit
	 * link in normal articles.
	 * @param $title Title  The title of the target article.
	 * @param $tooltip string  The tooltip to be included in the link, wrapped
	 *   in the 'wikilog-edit-hint' message.
	 * @return string  HTML fragment.
	 */
	private function doEditLink( $title, $tooltip = null ) {
		$skin = $this->getSkin();
		$attribs = array();
		if ( !is_null( $tooltip ) ) {
			$attribs['title'] = wfMessage( 'wikilog-edit-hint', $tooltip )->text();
		}
		$link = Linker::link( $title, wfMessage( 'wikilog-edit-lc' )->text(),
			$attribs,
			array( 'action' => 'edit' ),
			array( 'noclasses', 'known' )
		);

		$result = wfMessage( 'editsection-brackets', $link )->escaped();
		return $result;
	}
}
