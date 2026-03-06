<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;

if ( !defined( 'MEDIAWIKI' ) )
    die();

class WikilogItem {
    public $mID, $mName, $mTitle, $mParent, $mParentTitle, $mPublish, $mPubDate, $mUpdated;
    public $mAuthors = [];
    public $mTags = [];
    public $mNumComments = 0;

    public function getID() { return $this->mID; }
    public function getNumComments() { return $this->mNumComments; }
    public function getIsPublished() { return $this->mPublish; }
    public function getPublishDate() { return $this->mPubDate; }
    public function getUpdatedDate() { return $this->mUpdated; }

    public function saveData() {
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $dbw->replace(
            'wikilog_posts',
            'wlp_page',
            [
                'wlp_page'    => $this->mID,
                'wlp_parent'  => $this->mParent,
                'wlp_title'   => $this->mName,
                'wlp_publish' => $this->mPublish ? 1 : 0,
                'wlp_pubdate' => $dbw->timestamp( $this->mPubDate ),
                'wlp_updated' => $dbw->timestamp( $this->mUpdated ),
                'wlp_authors' => serialize( $this->mAuthors ),
                'wlp_tags'    => serialize( $this->mTags ),
            ],
            __METHOD__
        );
        WikilogUtils::updateTalkInfo( $this->mID, true );
    }

    public static function newFromID( $id ) {
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $row = $dbr->selectRow(
            [ 'wikilog_posts', 'p' => 'page', 'w' => 'page' ],
            [ '*', 'w_ns' => 'w.page_namespace', 'w_title' => 'w.page_title' ],
            [ 'wlp_page' => $id ],
            __METHOD__,
            [],
            [ 
                'p' => [ 'JOIN', 'p.page_id = wlp_page' ],
                'w' => [ 'JOIN', 'w.page_id = wlp_parent' ]
            ]
        );
        return $row ? self::newFromRow( $row ) : null;
    }

    public static function newFromRow( $row ) {
        $item = new self();
        $item->mID = (int)$row->wlp_page;
        $item->mName = $row->wlp_title;
        $item->mTitle = Title::makeTitle( $row->page_namespace, $row->page_title );
        $item->mParentTitle = Title::makeTitle( $row->wlw_namespace ?? 0, $row->wlw_title ?? '' );
        $item->mPublish = (bool)$row->wlp_publish;
        $item->mPubDate = $row->wlp_pubdate;
        $item->mUpdated = $row->wlp_updated;
        $item->mAuthors = unserialize( $row->wlp_authors ) ?: [];
        return $item;
    }

    public function getMsgParams( $extended = false ) {
        list( $date, $time, $tz ) = WikilogUtils::getLocalDateTime( $this->mPubDate );
        return [
            $this->mParentTitle->getPrefixedText(),
            $this->mParentTitle->getText(),
            $this->mTitle->getPrefixedText(),
            $this->mName,
            count($this->mAuthors),
            '', 
            WikilogUtils::authorList( array_keys($this->mAuthors) ),
            $date, $time,
            WikilogUtils::getCommentsWikiText( $this ),
            0, '', 0, '', $tz
        ];
    }
}