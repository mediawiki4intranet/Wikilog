<?php

/**
 * Subscription manager special page for Wikilog
 * Copyright © 2013 Vladimir Koptev, © 2013+ Vitaliy Filippov
 * http://wiki.4intra.net/Wikilog
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

if ( !defined( 'MEDIAWIKI' ) )
    die();

class SpecialWikilogSubscriptions
    extends IncludableSpecialPage
{
    const SUBSCRIPTIONS_ON_PAGE = 20;

    protected $mTitle;

    function __construct() {
        parent::__construct( 'WikilogSubscriptions' );
        $this->mTitle = SpecialPage::getTitleFor( 'wikilogsubscriptions' );
    }

    protected function getGroupName() {
        return 'changes';
    }

    public function execute( $parameters ) {
        global $wgUser, $wgRequest;

        if ( $wgUser->isAnon() ) {
            return $this->errorPage();
        }

        if ( $wgRequest->getVal( 'subscribe_to' ) ) {
            return $this->subscribe();
        }

        $id = $wgUser->getId();
        $dbr = wfGetDB( DB_REPLICA );

        $opts = array(
            'blogs' => array(),
            'comments' => array(),
            'blogs_offset' => $wgRequest->getInt( 'boffset' ),
            'comments_offset' => $wgRequest->getInt( 'coffset' ),
            'blogs_limit' => $wgRequest->getInt( 'blimit' ) ?: self::SUBSCRIPTIONS_ON_PAGE,
            'comments_limit' => $wgRequest->getInt( 'climit' ) ?: self::SUBSCRIPTIONS_ON_PAGE,
        );

        // Select blog subscriptions (blog page watches)
        $tbl = 'watchlist';
        $where = array( 'wl_user' => $id, 'wl_namespace' => NS_BLOG );
        $opts['blogs_count'] = $dbr->selectField( $tbl, "COUNT(*)", $where );
        if ( $opts['blogs_count'] <= $opts['blogs_offset'] ) {
            $opts['blogs_offset'] = 0;
        }
        $qo = array(
            'ORDER BY' => 'wl_title',
            'LIMIT' => $opts['blogs_limit'],
            'OFFSET' => $opts['blogs_offset']
        );
        $res = $dbr->select( $tbl, '*', $where, __METHOD__, $qo );
        foreach ( $res as $row ) {
            $title = Title::makeTitleSafe( $row->wl_namespace, $row->wl_title  );
            $wi = Wikilog::getWikilogInfo( $title );
            if ( $wi->isMain() ) {
                $opts['blogs'][] = $title;
            }
        }

        // Select comment subscriptions
        $tbl = 'wikilog_subscriptions';
        $where = array( 'ws_user' => $id, 'ws_yes' => 1 );
        $opts['comments_count'] = $dbr->selectField( $tbl, "COUNT(*)", $where );
        if ( $opts['comments_count'] <= $opts['comments_offset'] ) {
            $opts['comments_offset'] = 0;
        }
        $where[] = 'page_id=ws_page';
        $qo = array(
            'ORDER BY' => 'page_namespace, page_title',
            'LIMIT' => $opts['comments_limit'],
            'OFFSET' => $opts['comments_offset']
        );
        $res = $dbr->select( array( $tbl, 'p' => 'page' ), 'p.*', $where, __METHOD__, $qo );
        foreach ( $res as $row ) {
            $opts['comments'][] = Title::newFromRow( $row );
        }

        return $this->webOutput( $opts );
    }

    public function webOutput( $opts ) {
        global $wgOut;

        $this->setAndOutputHeader();

        $this->webOutputPartial(
            $opts, 'blogs', 'boffset', 'blimit',
            array( 'coffset' => $opts['comments_offset'], 'climit' => $opts['comments_limit'] )
        );
        $wgOut->addHtml( '<p></p>' );
        $this->webOutputPartial(
            $opts, 'comments', 'coffset', 'climit',
            array( 'boffset' => $opts['blogs_offset'], 'blimit' => $opts['blogs_limit'] )
        );

        return $wgOut;
    }

    public function errorPage( $error = 'wikilog-subscription-unauthorized' ) {
        global $wgOut;
        return $wgOut->showPermissionsErrorPage( array( array( $error ) ) );
    }

    protected function webOutputPartial( $opts, $key, $offsetReplacement, $limitReplacement, $query ) {
        global $wgOut, $wgLang;

        $html = '<div>';
        $html .= '<h2>' . wfMessage( 'wikilog-subscription-'.$key )->plain() . '</h2>';
        if ( count( $opts[$key] ) > 0 ) {
            $html .= '<table class="wikitable">';
            $html .= '<tr><th>' . wfMessage( 'wikilog-subscription-header-action' )->plain();
            $html .= '</th><th>' . wfMessage( 'wikilog-subscription-header-'.$key )->plain() . '</th>';
            foreach ( $opts[$key] as $title ) {
                $html .= $this->itemHTML( $title, $key == 'comments' );
            }
            $html .= '</table>';
        } else {
            $html .= wfMessage( 'wikilog-subscription-'.$key.'-empty' )->plain();
        }
        $html .= '</div>';
        $wgOut->addHtml( $html );
        $link = $wgLang->viewPrevNext(
            $this->mTitle,
            $opts[$key . '_offset'],
            $opts[$key . '_limit'],
            $query,
            $opts[$key . '_offset'] + $opts[$key . '_limit'] >= $opts[$key . '_count']
        );
        $link = str_replace( '&amp;offset', '&amp;' . $offsetReplacement, $link );
        $link = str_replace( '&amp;limit', '&amp;' . $limitReplacement, $link );
        $wgOut->addHTML( $link );
    }

    /**
     * Subscribe current user to specified blog
     *
     * @global WebRequest $wgRequest
     * @global User $wgUser
     * @global OutputPage $wgOut
     * @return type
     */
    protected function subscribe() {
        global $wgRequest, $wgUser, $wgOut;

        $id = $wgRequest->getVal( 'subscribe_to' );

        $title = Title::newFromID( $id );
        if ( !$title || !$title->userCan( 'read' ) ) {
            return $this->errorPage( 'wikilog-subscription-access-denied' );
        }

        $subscribe = $wgRequest->getBool( 'subscribe' ) ? 1 : 0;
        $isComments = $wgRequest->getBool( 'comment' );

        if ( $subscribe && $isComments ) {
            $talk = $title->getTalkPage();
            $this->setAndOutputHeader();
            $wgOut->addHtml( $this->getCommentSubscription( $talk ) . self::subcriptionsRuleLink() );
            return $wgOut;
        }

        if ( $isComments ) {
            $dbw = wfGetDB( DB_MASTER );
            $dbw->replace(
                'wikilog_subscriptions',
                array( array( 'ws_page', 'ws_user' ) ),
                array(
                    'ws_page' => $title->getArticleID(),
                    'ws_user' => $wgUser->getID(),
                    'ws_yes'  => $subscribe,
                    'ws_date' => $dbw->timestamp(),
                ),
                __METHOD__
            );
        } else {
            $watch = WatchedItem::fromUserTitle( $wgUser, $title );
            if ( $subscribe ) {
                $watch->addWatch();
            } else {
                $watch->removeWatch();
            }
        }
        $title->invalidateCache();

        $this->setAndOutputHeader();

        if ( $subscribe ) {
            $wgOut->addHtml(
                '<p>' . wfMessage( 'wikilog-subscription-blog-subscribed', Linker::link( $title, $title->getPrefixedText() ) )->plain() .
                '</p><p>' . self::generateSubscriptionLink( $title, true, true ) . '</p>'
            );
        } elseif ( $isComments ) {
            $wi = Wikilog::getWikilogInfo( $title );
            $key = ( $wi->isMain() ? 'wikilog-subscription-comment-unsubscribed-blog' : 'wikilog-subscription-comment-unsubscribed-article' );
            $wgOut->addHtml(
                '<p>' . wfMessage( $key, $title->getPrefixedText() )->plain() .
                '</p><p>' . $this->getCommentSubscription( $title->getTalkPage() ) . '</p>'
            );
        } else {
            $wgOut->addHtml(
                '<p>' . wfMessage( 'wikilog-subscription-blog-unsubscribed', Linker::link( $title, $title->getPrefixedText() ) )->plain() .
                '</p><p>' . self::generateSubscriptionLink( $title, false, true ) . '</p>'
            );
        }
        $wgOut->addHtml( self::subcriptionsRuleLink() );

        return $wgOut;
    }

    protected function setAndOutputHeader() {
        # Set page title, html title, nofollow, noindex, etc...
        $this->setHeaders();
        $this->outputHeader();
    }

    protected function itemHTML( $title, $comments = false ) {
        global $wgUser;

        $params = array();
        $query = array(
            'subscribe_to' => $title->getArticleID(),
            'subscribe' => 0
        );
        if ( $comments ) {
            $query ['comment'] = 1;
        }
        $unsubscribeLink = Linker::link( $this->mTitle, wfMessage( 'wikilog-subscription-item-unsubscribe' )->plain(), $params, $query );
        $titleLink = Linker::link( $title, $title->getPrefixedText() );
        $html = <<<END_STRING
<tr>
    <td>{$unsubscribeLink}</td>
    <td>{$titleLink}</td>
</tr>
</div>
END_STRING;
        return $html;
    }

    /**
     * @param Title $title
     * @return string
     */
    protected function getCommentSubscription( $title ) {
        return wfMessage( 'wikilog-subscription-comment-subscription', $title->getLinkUrl(), $title->getPrefixedText() )->plain();
    }

    /**
     * Generate HTML link for subscription to $title
     *
     * @global User $wgUser
     * @param Title $title
     * @param boolean $subscribed Flag that current user is subscribed to article $title. If not null do not check
     * @return string
     */
    public static function generateSubscriptionLink( $title, $subscribed = null, $forEmail = false, $lang = NULL ) {
        global $wgUser, $wgLang;
        if ( $wgUser->isAnon() ) {
            return '';
        }

        $prefix = '';
        if ( $subscribed === null ) {
            $subscribed = $wgUser->isWatched( $title );
        }

        $query = array(
            'subscribe_to' => $title->getArticleID(),
            'subscribe' => $subscribed ? 0 : 1,
        );
        $spec = SpecialPage::getTitleFor( 'wikilogsubscriptions' );
        $link = $spec->getLocalUrl( $query );

        $msg = ( $forEmail
            ? ( $subscribed ? 'wikilog-subscription-unsubscribe-email' : 'wikilog-subscription-subscribe-email' )
            : ( $subscribed ? 'wikilog-subscription-unsubscribe' : 'wikilog-subscription-subscribe' ) );
        return $prefix . wfMessage( $msg, $title->getText(), $link )
            ->inLanguage( $lang ? $lang : $wgLang )->plain();
    }

    /**
     * Link to subscription management page
     *
     * @global User $wgUser
     * @return string
     */
    public static function subcriptionsRuleLink( $lang = NULL ) {
        global $wgLang;
        return Linker::link(
            SpecialPage::getTitleFor( 'wikilogsubscriptions' ),
            wfMessage( 'wikilog-subscription-return-link' )
                ->inLanguage( $lang ? $lang : $wgLang )->plain()
        );
    }

    public static function sendEmails( &$article, $text ) {
        global $wgUser, $wgPasswordSender, $wgServer, $wgContLang;

        $dbr = wfGetDB( DB_REPLICA );

        $title = $article->getTitle();
        $wi = Wikilog::getWikilogInfo( $title );
        $args = array(
            $title->getSubpageText(),
            $wgUser->getName(),
            $title->getFullURL(),
            $wi->mWikilogTitle->getText(),
            $text,
            $wi->mItemTalkTitle->getFullURL(),
            $wi->mItemTalkTitle->getPrefixedText(),
        );

        $blogID = $wi->mWikilogTitle->getArticleID();
        $w = $dbr->tableName( 'watchlist' );
        $u = $dbr->tableName( 'user' );
        $up = $dbr->tableName( 'user_properties' );
        $res = $dbr->query(
            // Select subscribers
            "SELECT $u.*, up_value user_language FROM $w".
            " INNER JOIN $u ON user_id=wl_user".
            " LEFT JOIN $up ON up_user=user_id AND up_property='language'".
            " WHERE wl_namespace=" . NS_BLOG .
            " AND wl_title=" . $dbr->addQuotes( $wi->mWikilogTitle->getDBkey() ) .
            // Select users who watch ALL blogs
            " UNION SELECT $u.*, l.up_value user_language FROM $up s".
            " INNER JOIN $u ON user_id=s.up_user" .
            " LEFT JOIN $up l ON l.up_user=user_id AND l.up_property='language'".
            " WHERE s.up_property='wl-subscribetoallblogs' AND s.up_value='1'"
        );
        $emails = array();
        foreach ( $res as $row ) {
            if ( !$row->user_language ) {
                $row->user_language = $wgContLang->getCode();
            }
            $user = User::newFromRow( $row );
            if ( $user && $user->getEmail() && $user->getEmailAuthenticationTimestamp() &&
                !$title->getUserPermissionsErrors( 'read', $user ) ) {
                $emails[$row->user_language][$row->user_id] = new MailAddress( $user->getEmail() );
            }
        }
        if ( !$emails ) {
            return true;
        }

        // Generate body and subject in all selected languages and send it
        $saveExpUrls = WikilogParser::expandLocalUrls();
        $from = new MailAddress( $wgPasswordSender, 'Wikilog' );
        $serverName = substr( $wgServer, strpos( $wgServer, '//' ) + 2 );
        $headers = array(
            'Message-ID' => '<wikilog-' . $article->getId() . '@' . $serverName . '>',
        );
        foreach ( $emails as $lang => &$send ) {
            $subject = strip_tags( wfMessage( 'wikilog-subscription-email-subject', $args )
                ->inLanguage( $lang )->parse() );
            $body = wfMessage( 'wikilog-subscription-email-body', $args )
                ->inLanguage( $lang )->parse() .
                self::generateSubscriptionLink( $wi->mWikilogTitle, true, true, $lang ) .
                '<br />' . self::subcriptionsRuleLink( $lang );
            WikilogUtils::sendHtmlMail( array_values( $send ), $from, $subject, $body, $headers );
        }
        WikilogParser::expandLocalUrls( $saveExpUrls );

        return true;
    }
}
