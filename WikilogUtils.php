<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;

if ( !defined( 'MEDIAWIKI' ) )
    die();

class PageLastVisitUpdater implements DeferrableUpdate {
    var $visit = [];

    function __construct() {
        DeferredUpdates::addUpdate( $this );
    }

    function add( $pageid, $userid, $timestamp ) {
        $this->visit[] = [
            'pv_page' => $pageid,
            'pv_user' => $userid,
            'pv_date' => $timestamp,
        ];
    }

    function doUpdate() {
        if ( $this->visit ) {
            $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
            foreach ( $this->visit as &$v ) {
                $v['pv_date'] = $dbw->timestamp( $v['pv_date'] );
            }
            $dbw->replace( 'page_last_visit', [ [ 'pv_page', 'pv_user' ] ], $this->visit, __METHOD__ );
            $this->visit = [];
        }
    }
}

class WikilogUtils {
    static $updater = false;

    public static function updateLastVisit( $pageid, $timestamp = null, $userid = null ) {
        if ( !$userid ) {
            $user = RequestContext::getMain()->getUser();
            $userid = $user->getId();
            if ( !$userid ) return;
        }
        if ( $pageid instanceof Title ) {
            $pageid = $pageid->getArticleId();
        }
        if ( !$pageid ) return;
        if ( !self::$updater ) {
            self::$updater = new PageLastVisitUpdater();
        }
        self::$updater->add( $pageid, $userid, $timestamp );
    }

    public static function updateTalkInfo( $pageID, $isWikilogPost = false ) {
        $lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
        $dbw = $lb->getConnection( DB_PRIMARY );

        $row = $dbw->selectRow( 'wikilog_comments', [ 'count' => 'COUNT(*)', 'max_upd' => 'MAX(wlc_updated)' ],
            [ 'wlc_post' => $pageID ], __METHOD__ );

        if ( $isWikilogPost ) {
            $pageUpdated = $dbw->selectField( 'wikilog_posts', 'wlp_pubdate', [ 'wlp_page' => $pageID ], __METHOD__ );
        } else {
            $pageUpdated = $dbw->selectField( [ 'page', 'revision' ], 'rev_timestamp',
                [ 'page_latest=rev_id', 'page_id' => $pageID ], __METHOD__ );
        }

        $count = $row->count;
        $talkUpdated = $row->max_upd;
        if ( !$talkUpdated || $pageUpdated > $talkUpdated ) {
            $talkUpdated = $pageUpdated;
        }

        $dbw->replace( 'wikilog_talkinfo', [ 'wti_page' ], [ [
                'wti_page' => $pageID,
                'wti_num_comments' => $count,
                'wti_talk_updated' => $dbw->timestamp( $talkUpdated )
            ] ], __METHOD__ );

        return [ $count, $talkUpdated ];
    }

    public static function authorSig( $author, $parse = false ) {
        $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $author );
        $n = $user->getRealName() ?: $author;
        $msg = wfMessage( 'wikilog-author-signature', $user->getName(), $n );
        return $parse ? $msg->parse() : $msg->inContentLanguage()->text();
    }

    public static function getLocalDateTime( $timestamp, $format = false ) {
        $lang = RequestContext::getMain()->getLanguage();
        $ts = wfTimestamp( TS_MW, $timestamp );
        $userTs = $lang->userAdjust( $ts );

        $df = $format ?: $lang->dateFormat( true );
        $date = $lang->userDate( $ts, RequestContext::getMain()->getUser() );
        $time = $lang->userTime( $ts, RequestContext::getMain()->getUser() );
        $tz = MediaWikiServices::getInstance()->getContentLanguage()->getTimezoneName( $ts );

        return [ $date, $time, $tz ];
    }

    public static function wrapDiv( $class, $text ) {
        return Html::rawElement( 'div', [ 'class' => $class ], $text );
    }

    public static function getCommentsWikiText( $item ) {
        $num = $item->getNumComments();
        $msg = $num ? 'wikilog-has-comments' : 'wikilog-no-comments';
        $txt = wfMessage( $msg, $num )->inContentLanguage()->text();
        return "[[" . $item->mTitle->getTalkPage()->getPrefixedText() . "|$txt]]";
    }
}

