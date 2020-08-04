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

class WikilogMainPage
	extends Article
	implements WikilogCustomAction
{
	/**
	 * Alternate views.
	 */
	protected static $views = array( 'summary', 'archives' );

	/**
	 * Wikilog data.
	 */
	private   $mWikilogDataLoaded = false;
	public    $mWikilogSubtitle   = false;
	public    $mWikilogIcon       = false;
	public    $mWikilogLogo       = false;
	public    $mWikilogAuthors    = false;
	public    $mWikilogUpdated    = false;
	public    $mWikilogPubdate    = false;

	/**
	 * Constructor.
	 */
	public function __construct( &$title, &$wi ) {
		parent::__construct( $title );
	}

	/**
	 * View action handler.
	 */
	public function view() {
		global $wgRequest, $wgOut, $wgMimeType, $wgUser;

		$query = new WikilogItemQuery( $this->mTitle );
		$query->setPubStatus( $wgRequest->getVal( 'show' ) );

		# RSS or Atom feed requested. Ignore all other options.
		if ( ( $feedFormat = $wgRequest->getVal( 'feed' ) ) ) {
			global $wgWikilogNumArticles;
			$feed = new WikilogItemFeed( $this->mTitle, $feedFormat, $query,
				$wgRequest->getInt( 'limit', $wgWikilogNumArticles ) );
			return $feed->execute();
		}

		# View selection.
		$view = $wgRequest->getVal( 'view', 'summary' );

		# Query filter options.
		$query->setCategory( $wgRequest->getVal( 'category' ) );
		$query->setAuthor( $wgRequest->getVal( 'author' ) );
		$query->setTag( $wgRequest->getVal( 'tag' ) );

		$year = $wgRequest->getIntOrNull( 'year' );
		$month = $wgRequest->getIntOrNull( 'month' );
		$day = $wgRequest->getIntOrNull( 'day' );
		$query->setDate( $year, $month, $day );

		# Display wiki text page contents.
		parent::view();

		# Subscription
		if ( !$wgUser->isAnon() ) {
			$link = SpecialWikilogSubscriptions::generateSubscriptionLink( $this->mTitle );
			$wgOut->addHtml( '<p id="wl-subscription-link">' . $link . '</p>' );
		}

		# Create pager object, according to the type of listing.
		if ( $view == 'archives' ) {
			$pager = new WikilogArchivesPager( $query );
		} else {
			$pager = new WikilogSummaryPager( $query );
		}

		global $wlCalPager;
		$wlCalPager = $pager;

		# Display list of wikilog posts.
		$body = $pager->getBody();
		$body .= $pager->getNavigationBar();
		$wgOut->addHTML( Xml::openElement( 'div', array( 'class' => 'wl-wrapper' ) ) );
		$wgOut->addHTML( $body );
		$wgOut->addHTML( Xml::closeElement( 'div' ) );

		# Get query parameter array, for the following links.
		$qarr = $query->getDefaultQuery();

		# Add feed links.
		$wgOut->setSyndicated();
		if ( isset( $qarr['show'] ) ) {
			$altquery = wfArrayToCGI( array_intersect_key( $qarr, WikilogItemFeed::$paramWhitelist ) );
			$wgOut->setFeedAppendQuery( $altquery );
		}

		# Add links for alternate views.
		foreach ( self::$views as $alt ) {
			if ( $alt != $view ) {
				$altquery = wfArrayToCGI( array( 'view' => $alt ), $qarr );
				$wgOut->addLink( array(
					'rel' => 'alternate',
					'href' => $this->mTitle->getLocalURL( $altquery ),
					'type' => $wgMimeType,
					'title' => wfMessage( "wikilog-view-{$alt}" )->inContentLanguage()->text()
				) );
			}
		}
	}

	/**
	 * Wikilog action handler.
	 */
	public function wikilog() {
		global $wgUser, $wgOut, $wgRequest;

		if ( $this->mTitle->exists() && $wgRequest->getBool( 'wlActionImport' ) ) {
			return $this->actionImport();
		}

		$wgOut->setPageTitle( wfMessage( 'wikilog-tab-title' )->text() );
		$wgOut->setRobotpolicy( 'noindex,nofollow' );

		if ( $this->mTitle->exists() ) {
			$skin = $this->getContext()->getSkin();
			$wgOut->addHTML( $this->formatWikilogDescription( $skin ) );
			$wgOut->addHTML( $this->formatWikilogInformation( $skin ) );
			if ( $this->mTitle->quickUserCan( 'edit' ) ) {
				$wgOut->addHTML( self::formNewItem( $this->mTitle ) );
				$wgOut->addHTML( $this->formImport() );
			}
		} elseif ( $this->mTitle->userCan( 'create' ) ) {
			$text = wfMessage( 'wikilog-missing-wikilog' )->parse();
			$text = WikilogUtils::wrapDiv( 'noarticletext', $text );
			$wgOut->addHTML( $text );
		} else {
			$this->showMissingArticle();
		}
	}

	/**
	 * Returns wikilog description as formatted HTML.
	 */
	protected function formatWikilogDescription( $skin ) {
		$this->loadWikilogData();

		$s = '';
		if ( $this->mWikilogIcon ) {
			$title = Title::makeTitle( NS_IMAGE, $this->mWikilogIcon );
			$file = wfFindFile( $title );
			$s .= $skin->makeImageLink2( $title, $file,
				array( 'align' => 'left' ),
				array( 'width' => '32' )
			);
		}
		$s .= Xml::tags( 'div', array( 'class' => 'wl-title' ),
			Linker::link( $this->mTitle, null, array(), array(), array( 'known', 'noclasses' ) ) );

		$st =& $this->mWikilogSubtitle;
		if ( is_array( $st ) ) {
			$tc = new WlTextConstruct( $st[0], $st[1] );
			$s .= Xml::tags( 'div', array( 'class' => 'wl-subtitle' ), $tc->getHTML() );
		} elseif ( is_string( $st ) && !empty( $st ) ) {
			$s .= Xml::element( 'div', array( 'class' => 'wl-subtitle' ), $st );
		}

		return Xml::tags( 'div', array( 'class' => 'wl-description' ), $s );
	}

	/**
	 * Returns wikilog information as formatted HTML.
	 */
	protected function formatWikilogInformation( $skin ) {
		$dbr = wfGetDB( DB_REPLICA );

		$row = $dbr->selectRow(
			array( 'wikilog_posts', 'page' ),
			'COUNT(*) as total, SUM(wlp_publish) as published',
			array(
				'wlp_page = page_id',
				'wlp_parent' => $this->mTitle->getArticleID(),
				'page_is_redirect' => 0
			),
			__METHOD__
		);
		$n_total = intval( $row->total );
		$n_published = intval( $row->published );
		$n_drafts = $n_total - $n_published;

		$cont = $this->formatPostCount( $skin, 'p', 'published', $n_published );
		$cont .= Xml::openElement( 'ul' );
		$cont .= $this->formatPostCount( $skin, 'li', 'drafts', $n_drafts );
		$cont .= $this->formatPostCount( $skin, 'li', 'all', $n_total );
		$cont .= Xml::closeElement( 'ul' );

		return Xml::fieldset( wfMessage( 'wikilog-information' )->text(), $cont ) . "\n";
	}

	/**
	 * Used by formatWikilogInformation(), formats a post count link.
	 */
	private function formatPostCount( $skin, $elem, $type, $num ) {
		global $wgWikilogFeedClasses;

		// Uses messages 'wikilog-post-count-published', 'wikilog-post-count-drafts', 'wikilog-post-count-all'
		$s = Linker::link( $this->mTitle,
			wfMessage( "wikilog-post-count-{$type}", $num )->text(),
			array(),
			array( 'view' => "archives", 'show' => $type ),
			array( 'knwon', 'noclasses' )
		);
		if ( !empty( $wgWikilogFeedClasses ) ) {
			$f = array();
			foreach ( $wgWikilogFeedClasses as $format => $class ) {
				$f[] = Linker::link( $this->mTitle,
					wfMessage( "feed-{$format}" )->text(),
					array( 'class' => "feedlink", 'type' => "application/{$format}+xml" ),
					array( 'view' => "archives", 'show' => $type, 'feed' => $format ),
					array( 'known', 'noclasses' )
				);
			}
			$s .= ' (' . implode( ', ', $f ) . ')';
		}
		return Xml::tags( $elem, null, $s );
	}

	/**
	 * Returns a form for new item creation.
	 * @param Title $title
	 */
	static function formNewItem( $title ) {
		global $wgScript, $wgWikilogStylePath, $wgMaxTitleBytes;

		$fields = array();
		if ( $title ) {
			$fields[] = Xml::element( 'input', array(
				'type' => 'hidden',
				'value' => $title->getPrefixedText(),
				'id' => 'wl-newitem-wikilog'
			) );
		} else {
			global $wgWikilogNamespaces;
			$dbr = wfGetDB( DB_REPLICA );
			$r = $dbr->select( 'page', 'page_id', array(
				'page_namespace' => $wgWikilogNamespaces,
				'page_title NOT LIKE \'%/%\'',
			), __METHOD__ );
			$opts = array();
			foreach ( $r as $obj ) {
				$t = Title::newFromID( $obj->page_id );
				if ( $t->userCan( 'edit' ) ) {
					$opts[] = $t;
				}
			}
			if ( !$opts ) {
				return '';
			}
			$wikilog_select = new XmlSelect( false, 'wl-newitem-wikilog' );
			foreach ( $opts as $o ) {
				$wikilog_select->addOption( $o->getText(), $o->getPrefixedText() );
			}
			$fields[] = Xml::label( wfMessage( 'wikilog-form-wikilog' )->text(), 'wl-newitem-wikilog' )
				. '&nbsp;' . $wikilog_select->getHTML();
		}
		$fields[] = Html::hidden( 'action', 'edit' );
		$fields[] = Html::hidden( 'preload', '' );
		$fields[] = Html::hidden( 'title', '' );
		$fields[] = Xml::inputLabel( wfMessage( 'wikilog-item-name' )->text(),
			false, 'wl-item-name', 70, date( 'Y-m-d ' ) );
		$fields[] = Xml::submitButton( wfMessage( 'wikilog-new-item-go' )->text() );

		$form = Xml::tags( 'form',
			array(
				'action' => $wgScript,
				'onsubmit' => 'return wlCheckNewItem(this, '
					. json_encode( array(
						'subpage' => wfMessage( 'wikilog-new-item-subpage' )->text(),
						'title' => array(
							'lng' => ( isset( $wgMaxTitleBytes ) ? $wgMaxTitleBytes : 255 ) - strlen( '/c' . WikilogComment::padID( 0 ) ),
							'msg' => wfMessage( 'wikilog-new-item-too-long' )->text()
						)
					) )
					. ');'
			),
			implode( "\n", $fields )
		);

		$form = Xml::fieldset( wfMessage( 'wikilog-new-item' )->text(), $form, array( 'id' => 'wl-new-item' ) ) . "\n";
		return $form;
	}

	/**
	 * Returns a form for blog import (currently only Blogger.com).
	 */
	protected function formImport() {
		global $wgScript;

		$fields = array();
		$fields[] = Html::hidden( 'title', $this->mTitle->getPrefixedText() );
		$fields[] = Html::hidden( 'action', 'wikilog' );
		$fields[] = Html::hidden( 'wikilog-import', 'blogger' );
		$fields[] = Xml::inputLabel( wfMessage( 'wikilog-import-file' )->text(), 'wlFile', 'wl-import-file', false, false, array('type' => 'file') );
		$fields[] = Xml::submitButton( wfMessage( 'wikilog-import-go' )->text(),
			array( 'name' => 'wlActionImport' ) );
		$fields[] = '<br />' . Xml::label( wfMessage( 'wikilog-import-aliases' )->text(), 'wl-user-aliases' ) .
			Xml::textarea( 'wlUserAliases', '', 40, 5, array( 'id' => 'wl-user-aliases' ) );

		$form = Xml::tags( 'form',
			array( 'action' => $wgScript, 'method' => 'POST', 'enctype' => 'multipart/form-data' ),
			implode( "\n", $fields )
		);

		return Xml::fieldset( wfMessage( 'wikilog-import' )->text(), $form ) . "\n";
	}

	/**
	 * Wikilog "import" action handler.
	 */
	protected function actionImport() {
		global $wgOut, $wgRequest;
		$wgOut->setPageTitle( wfMessage( 'wikilog-import' )->text() );

		if ( !$this->mTitle->quickUserCan( 'edit' ) ) {
			$wgOut->loginToUse();
			$wgOut->output();
			exit;
		}

		$file = $_FILES[ 'wlFile' ];
		if ( !empty( $_FILES['wlFile']['error'] ) )
		{
			switch( $_FILES['wlFile']['error'] )
			{
				case 1: # The uploaded file exceeds the upload_max_filesize directive in php.ini.
					$wgOut->addWikiMsg( 'importuploaderrorsize' );
				case 2: # The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
					$wgOut->addWikiMsg( 'importuploaderrorsize' );
				case 3: # The uploaded file was only partially uploaded
					$wgOut->addWikiMsg( 'importuploaderrorpartial' );
				case 6: #Missing a temporary folder. Introduced in PHP 4.3.10 and PHP 5.0.3.
					$wgOut->addWikiMsg( 'importuploaderrortemp' );
				# case else: # Currently impossible
			}
			return;
		}
		elseif ( is_uploaded_file( $_FILES['wlFile']['tmp_name'] ) )
		{
			$users = array();
			preg_match_all( '/\[\[([^\]\|]+)\|([^\]\|]+)\]\]/is', $wgRequest->getText( 'wlUserAliases' ), $matches, PREG_SET_ORDER );
			foreach ( $matches as $m )
				if (( $user = Title::newFromText( trim($m[1]), NS_USER )) &&
				    ( $user = User::newFromName( $user->getText() ) ))
					$users[ trim($m[2]) ] = $user->getName();
			$params = array(
				'blog' => $this->mTitle->getText(),
				'users' => $users,
			);
			$out = WikilogBloggerImport::parse_blogger_xml( file_get_contents( $_FILES['wlFile']['tmp_name'] ), $params );
			if ( $out )
				$result = WikilogBloggerImport::import_parsed_blogger( $out );
			if ( $result )
			{
				$wgOut->addWikiMsg( 'wikilog-import-ok', count( $result ), $this->mTitle->getPrefixedText() );
				/* Print RewriteRules */
				$rewrite = array_reverse( $out['rewrite'] );
				foreach ( $rewrite as &$r )
				{
					$u = preg_quote(preg_replace('#^[a-z]+://[^/]*/#', '', $r[0]));
					$r = "RewriteRule ^$u ".str_replace('%', '\\%', Title::newFromText($r[1])->getLocalUrl())." [R=301,L,NE]";
				}
				$rewrite = implode( "\n", $rewrite );
				$wgOut->addHTML( Xml::textarea( 'htaccess', $rewrite, 100, 10 ) );
			}
			else
				$wgOut->addWikiMsg( 'wikilog-import-failed' );
		}
	}

	/**
	 * Load current article wikilog data.
	 */
	private function loadWikilogData() {
		if ( !$this->mWikilogDataLoaded ) {
			$dbr = wfGetDB( DB_REPLICA );
			$data = $this->getWikilogDataFromId( $dbr, $this->getId() );
			if ( $data ) {
				$this->mWikilogSubtitle = unserialize( $data->wlw_subtitle );
				$this->mWikilogIcon = $data->wlw_icon;
				$this->mWikilogLogo = $data->wlw_logo;
				$this->mWikilogUpdated = wfTimestamp( TS_MW, $data->wlw_updated );
				$this->mWikilogAuthors = unserialize( $data->wlw_authors );
				if ( !is_array( $this->mWikilogAuthors ) ) {
					$this->mWikilogAuthors = array();
				}
			}
			$this->mWikilogDataLoaded = true;
		}
	}

	/**
	 * Return wikilog data from the database, matching a set of conditions.
	 */
	public static function getWikilogData( $dbr, $conditions ) {
		$row = $dbr->selectRow(
			'wikilog_wikilogs',
			array(
				'wlw_page',
				'wlw_subtitle',
				'wlw_icon',
				'wlw_logo',
				'wlw_authors',
				'wlw_updated'
			),
			$conditions,
			__METHOD__
		);
		return $row;
	}

	/**
	 * Return wikilog data from the database, matching the given page ID.
	 */
	public static function getWikilogDataFromId( $dbr, $id ) {
		return self::getWikilogData( $dbr, array( 'wlw_page' => $id ) );
	}
}
