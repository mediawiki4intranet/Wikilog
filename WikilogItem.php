<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

if ( !defined( 'MEDIAWIKI' ) )
    die();

class WikilogItem {
    public $mID, $mName, $mTitle, $mParent, $mParentTitle, $mPublish, $mPubDate, $mUpdated;
    public $mAuthors = [];
    public $mTags = [];
    public $mNumComments = 0;
    public $mTalkUpdated;

    public static function selectTables( $db = null ) {
        return [
            'tables' => [
                'wikilog_posts',
                'p' => 'page',
                'w' => 'page',
                'wikilog_talkinfo',
            ],
            'join_conds' => [
                'p' => [ 'JOIN', 'p.page_id = wlp_page' ],
                'w' => [ 'JOIN', 'w.page_id = wlp_parent' ],
                'wikilog_talkinfo' => [ 'LEFT JOIN', 'wti_page = wlp_page' ],
            ]
        ];
    }

    public static function selectFields() {
        return [
            'wlp_page',
            'wlp_parent',
            'wlp_title',
            'wlp_publish',
            'wlp_pubdate',
            'wlp_updated',
            'wlp_authors',
            'wlp_tags',
            'p.page_id',
            'p.page_namespace',
            'p.page_title',
            'p.page_len',
            'p.page_is_redirect',
            'p.page_latest',
            'w.page_namespace AS wlw_namespace',
            'w.page_title AS wlw_title',
            'wti_num_comments',
            'wti_talk_updated',
        ];
    }

    public function getID() { return $this->mID; }
    public function getNumComments() { return $this->mNumComments; }
    public function getIsPublished() { return $this->mPublish; }
    public function getPublishDate() { return $this->mPubDate; }
    public function getUpdatedDate() { return $this->mUpdated; }
    public function getTalkUpdatedDate() { return $this->mTalkUpdated; }

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
            [ '*', 'wlw_namespace' => 'w.page_namespace', 'wlw_title' => 'w.page_title' ],
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

    public static function newFromInfo( WikilogInfo $wi ) {
        return self::newFromID( $wi->mTitle->getArticleID() );
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
        if ( isset( $row->wti_num_comments ) ) {
            $item->mNumComments = (int)$row->wti_num_comments;
        }
        if ( isset( $row->wti_talk_updated ) ) {
            $item->mTalkUpdated = $row->wti_talk_updated;
        }
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
