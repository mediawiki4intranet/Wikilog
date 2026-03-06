<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Page\WikiPage;
use ParserOutput;
use RequestContext;
use Article;
use ParserOptions;

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
        $user = RequestContext::getMain()->getUser();
        $date = $lang->userDate( $ts, $user );
        $time = $lang->userTime( $ts, $user );
        $tz = MediaWikiServices::getInstance()->get( 'LanguageTimeUtils' )->getTimezoneName( $user, $ts );

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

    public static function parsedArticle( Title $title, $useParserCache = false ) {
        $wikipage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
        $popts = $wikipage->makeParserOptions( RequestContext::getMain() );
        if ( method_exists( $popts, 'setUseParserCache' ) ) {
            $popts->setUseParserCache( $useParserCache );
        } elseif ( method_exists( $popts, 'setCacheEnabled' ) ) {
            $popts->setCacheEnabled( $useParserCache );
        }
        $parserOutput = $wikipage->getParserOutput( $popts );
        if ( $parserOutput === null ) {
            $parserOutput = new ParserOutput();
        }
        return [ new Article($title), $parserOutput ];
    }

    public static function splitSummaryContent( ParserOutput $parserOutput ) {
        $text = $parserOutput->getText();
        $summary = $parserOutput->getExtensionData( 'wikilog-summary' );
        $moreMarker = $parserOutput->getExtensionData( 'wikilog-more' );

        if ( $summary !== null ) {
            // summary from <summary> tag.
            return [ $summary, $text ];
        } elseif ( $moreMarker ) {
            // summary from <!--more-->
            $parts = explode( $moreMarker, $text, 2 );
            return [ $parts[0], $text ];
        } else {
            // no summary
            return [ false, $text ];
        }
    }

    public static function buildForm( $fields ) {
        $form = '';
        foreach ( $fields as $field ) {
            if ( is_array( $field ) ) {
                $form .= '<tr><td class="mw-label">' . $field[0] . '</td><td class="mw-input">' . $field[1] . '</td></tr>';
            } else {
                $form .= '<tr><td colspan="2">' . $field . '</td></tr>';
            }
        }
        return '<table class="mw-htmlform-inner">' . $form . '</table>';
    }

    public static function updateWikilog( Title $title ) {
        // TODO: Implement this. It should update wikilog_wikilogs table.
        // It seems to aggregate data like authors and latest update time for a blog.
    }

    public static function authorList( $list ) {
        if ( is_string( $list ) ) {
            return self::authorLink( $list );
        }
        elseif ( is_array( $list ) ) {
            return implode( ', ', array_map( [ __CLASS__, 'authorLink' ], $list ) );
        }
        else {
            return '';
        }
    }

    public static function authorLink( $name )
    {
        $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $name );
        $realName = $user->getRealName();
        if ( !$realName ) {
            $realName = $user->getName();
        }
        return Linker::link( $user->getUserPage(), $realName );
    }
}

