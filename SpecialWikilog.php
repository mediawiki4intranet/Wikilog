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
 * Special:Wikilog special page.
 * The primary function of this special page is to list all wikilog articles
 * (from all wikilogs) in reverse chronological order. The special page
 * provides many different ways to query articles by wikilog, date, tags, etc.
 * The special page also provides syndication feeds and can be included from
 * wiki articles.
 */
class SpecialWikilog
	extends IncludableSpecialPage
{
	/** Alternate views. */
	protected static $views = array( 'summary', 'archives' );

	/** Statuses. */
	protected static $statuses = array( 'all', 'published', 'drafts' );

	/**
	 * Constructor.
	 */
	function __construct( ) {
		parent::__construct( 'Wikilog' );
	}

	protected function getGroupName() {
		return 'changes';
	}

	/**
	 * Execute the special page.
	 * Called from MediaWiki.
	 */
	public function execute( $parameters ) {
		global $wgRequest;

		$feedFormat = $wgRequest->getVal( 'feed' );

		if ( $feedFormat && !$this->including() ) {
			$opts = $this->feedSetup();
			return $this->feedOutput( $feedFormat, $opts );
		} else {
			$opts = $this->webSetup( $parameters );
			return $this->webOutput( $opts );
		}
	}

	/**
	 * Returns default options.
	 */
	public function getDefaultOptions() {
		global $wgWikilogNumArticles;
		global $wgWikilogDefaultNotCategory;

		$opts = new FormOptions();
		$opts->add( 'view',     'summary' );
		$opts->add( 'show',     'published' );
		$opts->add( 'wikilog',  '' );
		$opts->add( 'category', '' );
		$opts->add( 'notcategory', '' );
		$opts->add( 'author',   '' );
		$opts->add( 'tag',      '' );
		$opts->add( 'year',     '', FormOptions::INTNULL );
		$opts->add( 'month',    '', FormOptions::INTNULL );
		$opts->add( 'day',      '', FormOptions::INTNULL );
		$opts->add( 'sort',     '' );
		$opts->add( 'limit',    $wgWikilogNumArticles );
		$opts->add( 'template', '' );
		return $opts;
	}

	/**
	 * Prepare special page parameters for a web request.
	 */
	public function webSetup( $parameters ) {
		global $wgRequest, $wgWikilogExpensiveLimit;
		global $wgWikilogDefaultNotCategory;

		$opts = $this->getDefaultOptions();
		$opts->fetchValuesFromRequest( $wgRequest );
		# Default "not in category"
		if ( is_null( $wgRequest->getVal('notcategory') ) )
			$opts['notcategory'] = $wgWikilogDefaultNotCategory;

		# Collect inline parameters, they have precedence over query params.
		$this->parseInlineParams( $parameters, $opts );

		$opts->validateIntBounds( 'limit', 0, $wgWikilogExpensiveLimit );
		return $opts;
	}

	/**
	 * Prepare special page parameters for a feed request.
	 * Since feeds must be cached for performance purposes, it is not allowed
	 * to make arbitrary queries. Only published status and limit parameters
	 * are recognized. Other parameters are ignored.
	 */
	public function feedSetup() {
		global $wgRequest, $wgFeedLimit;
		global $wgWikilogDefaultNotCategory;

		$opts = $this->getDefaultOptions();
		$opts->fetchValuesFromRequest( $wgRequest );
		# Default "not in category"
		if ( is_null( $wgRequest->getVal('notcategory') ) )
			$opts['notcategory'] = $wgWikilogDefaultNotCategory;

		$opts->validateIntBounds( 'limit', 0, $wgFeedLimit );
		return $opts;
	}

	/**
	 * Format the HTML output of the special page.
	 * @param $opts Form options, such as wikilog name, category, date, etc.
	 */
	public function webOutput( FormOptions $opts ) {
		global $wgRequest, $wgOut, $wgMimeType, $wgTitle, $wgParser, $wgUser;

		# Set page title, html title, nofollow, noindex, etc...
		$this->setHeaders();
		$this->outputHeader();

		# Build query object.
		$this->query = $query = self::getQuery( $opts );

		# Prepare the parser.
		# This must be called here if not including, before the pager
		# object is created. WikilogTemplatePager fails otherwise.
		if ( !$this->including() ) {
			$popts = $wgOut->parserOptions();
			$wgParser->startExternalParse( $wgTitle, $popts, Parser::OT_HTML );
		}

		# Create the pager object that will create the list of articles.
		if ( $opts['view'] == 'archives' )
		{
			if ( !$this->including() && preg_match( '/^\d{14,}$/s', $markallread_time = $wgRequest->getVal( 'markallread' ) ) )
			{
				$this->markAllRead( $query, $markallread_time );
				$vals = $wgRequest->getValues();
				unset( $vals['markallread'] );
				$wgOut->redirect( $wgTitle->getFullUrl( $vals ) );
				return;
			}
			$pager = new WikilogArchivesPager( $query, $this->including(), $opts['limit'] );
			$pager->noActions = true;
		}
		elseif ( $opts['template'] ) {
			$templ = Title::makeTitle( NS_TEMPLATE, $opts['template'] );
			$pager = new WikilogTemplatePager( $query, $templ, $opts['limit'], $this->including() );
		} else {
			$pager = new WikilogSummaryPager( $query, $opts['limit'], $this->including() );
		}
		$pager->setSort( $opts['sort'] );

		global $wlCalPager;
		$wlCalPager = $pager;

		# Handle special page inclusion.
		if ( $this->including() ) {
			# Get pager body.
			$body = $pager->getBody();
		}
		else {
			# If a wikilog is selected, set the title.
			$title = $query->getWikilogTitle();
			if ( !is_null( $title ) ) {
				# Retrieve wikilog front page
				$article = new Article( $title );
				$content = $article->getContent();
				$wgOut->setPageTitle( $title->getPrefixedText() );
				$wgOut->addWikiTextTitle( $content, $title, true );
			}

			# Display query options.
			$body = $this->getHeader( $opts );

			if ( $opts['view'] == 'archives' && $wgUser->getId() ) {
				# Display "Mark all read" link
				$body .= $this->getMarkAllReadLink();
			}

			# Get pager body.
			$body .= $pager->getBody();

			# Add navigation bars.
			$body .= $pager->getNavigationBar();

            # Wikilog Subscribtions
            $body .= '<p>' . SpecialWikilogSubscriptions::subcriptionsRuleLink() . '</p>';

			# Wrap only when not including
			$body = Xml::wrapClass( $body, 'wl-wrapper', 'div' );
		}

		# Output.
		$wgOut->addHTML( $body );

		# Get query parameter array, for the following links.
		$qarr = $query->getDefaultQuery();

		# Add feed links.
		$wgOut->setSyndicated();
			$altquery = wfArrayToCGI( array_intersect_key( $qarr, WikilogItemFeed::$paramWhitelist ) );
		if ( $altquery ) {
			$wgOut->setFeedAppendQuery( $altquery );
		}

		# Add links for alternate views.
		foreach ( self::$views as $alt ) {
			if ( $alt != $opts['view'] ) {
				$altquery = wfArrayToCGI( array( 'view' => $alt ), $qarr );
				$wgOut->addLink( array(
					'rel' => 'alternate',
					'href' => $wgTitle->getLocalURL( $altquery ),
					'type' => $wgMimeType,
					'title' => wfMessage( "wikilog-view-{$alt}" )->inContentLanguage()->text(),
				) );
			}
		}
	}

	/**
	 * Mark all items with last update time before $time='YYYYMMDDHHMMSS' as read.
	 */
	public function markAllRead( $query, $time )
	{
		global $wgUser;
		$dbw = wfGetDB( DB_MASTER );
		$p = $dbw->tablePrefix();
		$sql = $query->selectSQLText( $dbw, array(), "wlp_page", array("wlp_pubdate <= $time"), __METHOD__ );
		$userid = $wgUser->getId();
		$dbw->query("DELETE FROM {$p}page_last_visit WHERE (pv_user, pv_page) IN (SELECT $userid, wlp_page FROM ($sql) t1)", __METHOD__);
		$dbw->query("DELETE FROM {$p}page_last_visit WHERE (pv_user, pv_page) IN (SELECT $userid, t2.wlc_comment_page FROM ($sql) t1 JOIN wikilog_comments t2 ON t2.wlc_post=t1.wlp_page AND t2.wlc_updated <= $time)", __METHOD__);
		$dbw->query("INSERT INTO {$p}page_last_visit (pv_user, pv_page, pv_date) SELECT $userid, wlp_page, $time FROM ($sql) t1", __METHOD__);
		$dbw->query("INSERT INTO {$p}page_last_visit (pv_user, pv_page, pv_date) SELECT $userid, t2.wlc_comment_page, $time FROM ($sql) t1 JOIN wikilog_comments t2 ON t2.wlc_post=t1.wlp_page AND t2.wlc_updated <= $time", __METHOD__);
	}

	/**
	 * Output a Mark all read link.
	 */
	public function getMarkAllReadLink()
	{
		global $wgRequest, $wgTitle;
		$query = $wgRequest->getValues();
		$query['markallread'] = wfTimestamp( TS_MW );
		return Xml::wrapClass(
			Xml::element( 'a', array( 'href' => $wgTitle->getFullUrl( $query ) ), wfMessage( 'wikilog-mark-all-read' )->text() ),
			'markallread', 'p'
		);
	}

	/**
	 * Format the syndication feed output of the special page.
	 * @param $format Feed format ('atom' or 'rss').
	 * @param $opts Form options, such as wikilog name, category, date, etc.
	 */
	public function feedOutput( $format, FormOptions $opts ) {
		global $wgTitle;

		$feed = new WikilogItemFeed( $wgTitle, $format, self::getQuery( $opts ),
			$opts['limit'] );
		return $feed->execute();
	}

	/**
	 * Returns the name used as page title in the special page itself,
	 * and also the name that will be listed in Special:Specialpages.
	 */
	public function getDescription() {
		return wfMessage( 'wikilog-specialwikilog-title' )->text();
	}

	/**
	 * Parse inline parameters passed after the special page name.
	 * Example: Special:Wikilog/Category:catname/tag=tagname/5
	 * @param $parameters Inline parameters after the special page name.
	 * @param $opts Form options.
	 */
	public function parseInlineParams( $parameters, FormOptions $opts ) {
		global $wgWikilogNamespaces;

		if ( empty( $parameters ) ) return;

		/* ';' supported for backwards compatibility */
		foreach ( preg_split( '|[/;]|', $parameters ) as $par ) {
			if ( is_numeric( $par ) ) {
				$opts['limit'] = intval( $par );
			} elseif ( in_array( $par, self::$statuses ) ) {
				$opts['show'] = $par;
			} elseif ( in_array( $par, self::$views ) ) {
				$opts['view'] = $par;
			} elseif ( preg_match( '/^sort=(.+)$/', $par, $m ) ) {
				$opts['sort'] = $m[1];
			} elseif ( preg_match( '/^t(?:ag)?=(.+)$/', $par, $m ) ) {
				$opts['tag'] = $m[1];
			} elseif ( preg_match( '/^y(?:ear)?=(.+)$/', $par, $m ) ) {
				$opts['year'] = intval( $m[1] );
			} elseif ( preg_match( '/^m(?:onth)?=(.+)$/', $par, $m ) ) {
				$opts['month'] = intval( $m[1] );
			} elseif ( preg_match( '/^d(?:ay)?=(.+)$/', $par, $m ) ) {
				$opts['day'] = intval( $m[1] );
			} elseif ( preg_match( '/^date=(.+)$/', $par, $m ) ) {
				if ( ( $date = self::parseDateParam( $m[1] ) ) ) {
					list( $opts['year'], $opts['month'], $opts['day'] ) = $date;
				}
			} elseif ( preg_match( '/^notcategory=(.*)$/', $par, $m ) ) {
				$opts['notcategory'] = $m[1];
			} else {
				if ( ( $t = Title::newFromText( $par ) ) !== null ) {
					$ns = $t->getNamespace();
					if ( in_array( $ns, $wgWikilogNamespaces ) ) {
						$opts['wikilog'] = $t->getPrefixedDBkey();
					} elseif ( $ns == NS_CATEGORY ) {
						$opts['category'] = $t->getDBkey();
					} elseif ( $ns == NS_USER ) {
						$opts['author'] = $t->getDBkey();
					} elseif ( $ns == NS_TEMPLATE ) {
						$opts['template'] = $t->getDBkey();
					}
				}
			}
		}
	}

	/**
	 * Formats and returns the page header.
	 * @param $opts Form options.
	 * @return HTML of the page header.
	 */
	protected function getHeader( FormOptions $opts ) {
		global $wgScript;

		$out = Html::hidden( 'title', $this->getTitle()->getPrefixedText() );

		$out .= $this->getQueryForm( $opts );

		$unconsumed = $opts->getUnconsumedValues();
		foreach ( $unconsumed as $key => $value ) {
			$out .= Html::hidden( $key, $value );
		}

		$out = Xml::tags( 'form', array( 'action' => $wgScript ), $out );
		$out = Xml::fieldset( wfMessage( 'wikilog-form-legend' )->text(), $out,
			array( 'class' => 'wl-options' )
		);
		$out .= WikilogMainPage::formNewItem( NULL );
		return $out;
	}

	/**
	 * Formats and returns a query form.
	 * @param $opts Form options.
	 * @return HTML of the query form.
	 */
	protected function getQueryForm( FormOptions $opts ) {
		global $wgContLang;

		$align = $wgContLang->isRtl() ? 'left' : 'right';
		$fields = $this->getQueryFormFields( $opts );
		$columns = array_chunk( $fields, ( count( $fields ) + 1 ) / 2, true );

		$out = Xml::openElement( 'table', array( 'width' => '100%' ) ) .
				Xml::openElement( 'tr' );

		foreach ( $columns as $fields ) {
			$out .= Xml::openElement( 'td' );
			$out .= Xml::openElement( 'table' );

			foreach ( $fields as $row ) {
				if ( !$row )
					continue;
				$out .= Xml::openElement( 'tr' );
				if ( is_array( $row ) ) {
					$out .= Xml::tags( 'td', array( 'align' => $align ), $row[0] );
					$out .= Xml::tags( 'td', null, $row[1] );
				} else {
					$out .= Xml::tags( 'td', array( 'colspan' => 2 ), $row );
				}
				$out .= Xml::closeElement( 'tr' );
			}

			$out .= Xml::closeElement( 'table' );
			$out .= Xml::closeElement( 'td' );
		}

		$out .= Xml::closeElement( 'tr' ) . Xml::closeElement( 'table' );
		return $out;
	}

	/* Get possible options for combo-boxes */
	protected function getSelectOptions()
	{
		$dbr = wfGetDB( DB_REPLICA );
		$select_options = array();

		/* Wikilogs */
		$query = clone $this->query;
		$query->setWikilogTitle(NULL);
		$res = $query->select( $dbr,
			array(), 'p.page_namespace, p.page_title',
			array(), __FUNCTION__,
			array('GROUP BY' => 'wlp_parent', 'ORDER BY' => 'w.page_title')
		);
		$values = array();
		while( $row = $dbr->fetchRow( $res ) )
		{
			$parts = explode( '/', $row['page_title'] );
			$row = Title::makeTitleSafe( $row['page_namespace'], $parts[0] );
			if ($row)
				$values[] = array( $row->getText(), $row->getPrefixedText() );
		}
		$dbr->freeResult( $res );
		$select_options['wikilog'] = $values;

		/* Authors */
		$query = clone $this->query;
		$query->setAuthor(NULL);
		$res = $query->select( $dbr, array(), 'DISTINCT wlp_authors', array(), __FUNCTION__ );
		$rows = array();
		while( $row = $dbr->fetchRow( $res ) )
			$rows += unserialize( $row['wlp_authors'] );
		$dbr->freeResult( $res );
		ksort( $rows );
		$select_options['author'] = array();
		foreach( $rows as $k => $v )
			$select_options['author'][] = array($k);

		// Categories
		// Make two queries instead of one with OR
		// Otherwise it leads to fullscan of 'categorylinks'
		$query = clone $this->query;
		$query->setCategory( NULL );
		$res = $query->select(
			$dbr, array( 'wlpostcat' => 'categorylinks' ),
			'wlpostcat.cl_to', array(), __FUNCTION__, array(),
			array( 'wlpostcat' =>
				array( 'INNER JOIN', array( 'wlpostcat.cl_from=wlp_page' ) )
			)
		);
		$rows = array();
		foreach ( $res as $row )
		{
			$row = Title::makeTitleSafe( NS_CATEGORY, $row->cl_to )->getText();
			$rows[ $row ] = array( $row );
		}
		$res = $query->select(
			$dbr, array( 'wlpostcat' => 'categorylinks' ),
			'wlpostcat.cl_to', array(), __FUNCTION__, array(),
			array( 'wlpostcat' =>
				array( 'INNER JOIN', array( 'wlpostcat.cl_from=wlp_parent' ) )
			)
		);
		foreach ( $res as $row )
		{
			$row = Title::makeTitleSafe( NS_CATEGORY, $row->cl_to )->getText();
			$rows[ $row ] = array( $row );
		}

		$select_options['category'] = array_values( $rows );

		return $select_options;
	}

	/* Get possible Author options for combo-box */
	protected function getAuthorOptions() {
		$dbr = wfGetDB( DB_REPLICA );
	}

	/**
	 * Returns query form fields.
	 * @param $opts Form options.
	 * @return Array of form fields.
	 */
	protected function getQueryFormFields( FormOptions $opts ) {
		global $wgWikilogEnableTags;
		global $wgWikilogDefaultNotCategory;
		global $wgWikilogSearchDropdowns;
		global $wgLang, $wgUser;

		$fields = array();
		$formvalues = array();
		$formfields = array('wikilog' => true, 'category' => true);
		if ( $wgUser && $wgUser->getID() )
			$formfields['notcategory'] = false;
		$formfields['author'] = true;
		if ( $wgWikilogEnableTags )
			$formfields['tag'] = false;

		if ( $wgWikilogSearchDropdowns )
			$select_options = $this->getSelectOptions();

		foreach ( $formfields as $valueid => $dropdown )
		{
			$formvalues[$valueid] = str_replace( '_', ' ', $opts->consumeValue( $valueid ) );
			if ($wgWikilogSearchDropdowns && $dropdown)
			{
				/* If drop-down lists are enabled site-wide and permitted for this field */
				$values = $select_options[$valueid];
				if ( count( $values ) > 0 )
				{
					$select = new XmlSelect( $valueid, 'wl-'.$valueid, $formvalues[$valueid] );
					$select->addOption( wfMessage( 'wikilog-form-all' )->text(), '' );
					foreach( $values as $o )
						$select->addOption( $o[0], count($o) > 1 ? $o[1] : false );
					$fields[$valueid] = array(
						Xml::label( wfMessage( 'wikilog-form-'.$valueid )->text(), 'wl-'.$valueid ),
						$select->getHTML()
					);
				}
				else
					$fields[$valueid] = array();
			}
			else
			{
				$fields[$valueid] = Xml::inputLabelSep(
					wfMessage( 'wikilog-form-'.$valueid )->text(), $valueid, 'wl-'.$valueid, 40,
					$formvalues[$valueid]
				);
			}
		}

		$month_select = new XmlSelect( 'month', 'wl-month', $opts->consumeValue( 'month' ) );
		$month_select->setAttribute( 'onchange', "{var wly=document.getElementById('wl-year');if(wly&&!wly.value){wly.value='".date('Y')."';}}" );
		$month_select->addOption( wfMessage( 'monthsall' )->text(), '' );
		for ($i = 1; $i <= 12; $i++)
			$month_select->addOption( $wgLang->getMonthName( $i ), $i );
		$year_field = Xml::input( 'year', 4, $opts->consumeValue( 'year' ), array( 'maxlength' => 4, 'id' => 'wl-year' ) );
		$fields['date'] = array(
			Xml::label( wfMessage( 'wikilog-form-date' )->text(), 'wl-month' ),
			$month_select->getHTML() . "&nbsp;" . $year_field
		);
		$opts->consumeValue( 'day' );	// ignore day, not really useful

		$viewSelect = new XmlSelect( 'view', 'wl-view', $opts->consumeValue( 'view' ) );
		$viewSelect->addOption( wfMessage( 'wikilog-view-summary' )->text(), 'summary' );
		$viewSelect->addOption( wfMessage( 'wikilog-view-archives' )->text(), 'archives' );
		$fields['view'] = array(
			Xml::label( wfMessage( 'wikilog-form-view' )->text(), 'wl-view' ),
			$viewSelect->getHTML()
		);
		if( $wgUser && $wgUser->getID() )
		{
			$statusSelect = new XmlSelect( 'show', 'wl-status', $opts->consumeValue( 'show' ) );
			$statusSelect->addOption( wfMessage( 'wikilog-show-all' )->text(), 'all' );
			$statusSelect->addOption( wfMessage( 'wikilog-show-published' )->text(), 'published' );
			$statusSelect->addOption( wfMessage( 'wikilog-show-drafts' )->text(), 'drafts' );
			$fields['status'] = array(
				Xml::label( wfMessage( 'wikilog-form-status' )->text(), 'wl-status' ),
				$statusSelect->getHTML()
			);
		}

		$fields['submit'] = Xml::submitbutton( wfMessage( 'allpagessubmit' )->text() );
		return $fields;
	}

	/**
	 * Returns a Wikilog query object given the form options.
	 * @param $opts Form options.
	 * @return Wikilog query object.
	 */
	public static function getQuery( $opts ) {
		global $wgWikilogNamespaces;

		$query = new WikilogItemQuery();
		$query->setPubStatus( $opts['show'] );
		if ( $opts['wikilog'] ) {
			$t = Title::newFromText( $opts['wikilog'] );
			if ( $t && in_array( $t->getNamespace(), $wgWikilogNamespaces ) ) {
				if ( $t->getText() == '*' ) {
					$query->setNamespace( $t->getNamespace() );
				} else {
					$query->setWikilogTitle( $t );
				}
			} else {
				$query->setEmpty();
			}
		}
		if ( ( $t = $opts['category'] ) ) {
			$query->setCategory( $t );
		}
		if ( ( $t = $opts['notcategory'] ) ) {
			$query->setNotCategory( $t );
		}
		if ( ( $t = $opts['author'] ) ) {
			$query->setAuthor( $t );
		}
		if ( ( $t = $opts['tag'] ) ) {
			$query->setTag( $t );
		}
		$query->setDate( $opts['year'], $opts['month'], $opts['day'] );
		return $query;
	}

	/**
	 * Parse inline date parameter.
	 * @param $date Text representation of date "YYYY-MM-DD".
	 * @return Array(3) if date parsed successfully, where each element
	 *   represents a component of the date, being the last two optional.
	 *   False in case of error.
	 */
	public static function parseDateParam( $date ) {
		$m = array();
		if ( preg_match( '|^(\d+)(?:[/-](\d+)(?:[/-](\d+))?)?$|', $date, $m ) ) {
			return array(
				intval( $m[1] ),
				( isset( $m[2] ) ? intval( $m[2] ) : null ),
				( isset( $m[3] ) ? intval( $m[3] ) : null )
			);
		} else {
			return false;
		}
	}
}
