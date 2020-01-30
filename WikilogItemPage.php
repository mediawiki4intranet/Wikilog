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
 * Wikilog article namespace handler class.
 *
 * Displays a wikilog article. Includes a header and a footer, counts the
 * number of comments, provides a link back to the wikilog main page, etc.
 */
class WikilogItemPage
	extends Article
{
	/**
	 * Wikilog article item object.
	 */
	protected $mItem;

	/**
	 * Constructor.
	 * @param $title Article title object.
	 * @param $item Wikilog item object.
	 */
	public function __construct( Title $title, WikilogItem $item = null ) {
		parent::__construct( $title );
		$this->mItem = $item;
	}

	/**
	 * Return the appropriate WikiPage object for WikilogItemPage.
	 */
	protected function newPage( Title $title ) {
		return new WikilogWikiItemPage( $title );
	}

	/**
	 * Constructor from a page ID.
	 * @param $id Int article ID to load.
	 */
	public static function newFromID( $id ) {
		$t = Title::newFromID( $id );
		$i = WikilogItem::newFromID( $id );
		return $t == null ? null : new self( $t, $i );
	}

	/**
	 * View page action handler.
	 */
	public function view() {
		global $wgOut, $wgUser, $wgContLang, $wgFeed, $wgWikilogFeedClasses;

		# Get skin
		$skin = $this->getContext()->getSkin();

		if ( $this->mItem ) {
			$params = $this->mItem->getMsgParams( true );

			# Display draft notice.
			if ( !$this->mItem->getIsPublished() ) {
				$wgOut->wrapWikiMsg( '<div class="mw-warning">$1</div>', array( 'wikilog-reading-draft' ) );
			}

			# Item page header.
			$headerTxt = wfMessage( 'wikilog-entry-header', $params )->inContentLanguage()->parse();
			if ( !empty( $headerTxt ) ) {
				$wgOut->addHtml( WikilogUtils::wrapDiv( 'wl-entry-header', $headerTxt ) );
			}

			# Display article.
			parent::view();

			# Update last visit
			if ( $this->mItem ) {
				WikilogUtils::updateLastVisit( $this->mItem->getID() );
			}

			# Item page footer.
			$footerTxt = wfMessage( 'wikilog-entry-footer', $params )->inContentLanguage()->parse();
			if ( !empty( $footerTxt ) ) {
				$wgOut->addHtml( WikilogUtils::wrapDiv( 'wl-entry-footer', $footerTxt ) );
			}

			# Add feed links.
			$links = array();
			if ( $wgFeed ) {
				foreach ( $wgWikilogFeedClasses as $format => $class ) {
					$wgOut->addLink( array(
						'rel' => 'alternate',
						'type' => "application/{$format}+xml",
						'title' => wfMessage(
							"page-{$format}-feed",
							$this->mItem->mParentTitle->getPrefixedText()
						)->inContentLanguage()->text(),
						'href' => $this->mItem->mParentTitle->getLocalUrl( "feed={$format}" )
					) );
				}
			}

			# Override page title.
			# NOTE (MW1.16+): Must come after parent::view().
			$fullPageTitle = wfMessage( 'wikilog-title-item-full',
				$this->mItem->mName,
				$this->mItem->mParentTitle->getPrefixedText()
			)->text();
			$wgOut->setPageTitle( Sanitizer::escapeHtmlAllowEntities( $this->mItem->mName ) );
			$wgOut->setHTMLTitle( wfMessage( 'pagetitle', $fullPageTitle )->text() );

			# Set page subtitle
			$subtitleTxt = wfMessage( 'wikilog-entry-sub', $params )->inContentLanguage()->text();
			if ( !empty( $subtitleTxt ) ) {
				$wgOut->setSubtitle( $wgOut->parse( $subtitleTxt ) );
			} else {
				$wgOut->setSubtitle( '' );
			}
		} else {
			# Display article.
			parent::view();
		}
	}
}

/**
 * Wikilog WikiPage class for WikilogItemPage.
 */
class WikilogWikiItemPage
	extends WikiPage
{
	/**
	 * Constructor from a page ID.
	 * @param $id Int article ID to load.
	 */
	public static function newFromID( $id, $from = 'fromdb' ) {
		$t = Title::newFromID( $id );
		return $t == null ? null : new self( $t );
	}
}
