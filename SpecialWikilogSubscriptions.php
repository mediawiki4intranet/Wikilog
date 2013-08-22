<?php

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

    public function execute( $parameters ) {
        global $wgUser, $wgRequest;

        if ( $wgUser->isAnon() ) {
            return $this->errorPage();
        }

        if ( $wgRequest->getVal( 'subscribe_to' ) ) {
            return $this->subscribe();
        }

        $id = $wgUser->getId();
        $dbr = wfGetDB( DB_SLAVE );

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
        $res = $dbr->select( array( $tbl, 'page' ), 'page.*', $where, __METHOD__, $qo );
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
            http_build_query( array( 'coffset' => $opts['comments_offset'], 'climit' => $opts['comments_limit'] ) )
        );
        $wgOut->addHtml( '<p></p>' );
        $this->webOutputPartial(
            $opts, 'comments', 'coffset', 'climit',
            http_build_query( array( 'boffset' => $opts['blogs_offset'], 'blimit' => $opts['blogs_limit'] ) )
        );

        return $wgOut;
    }

    public function errorPage( $error = 'wikilog-subscription-unauthorized' ) {
        global $wgOut;
        return $wgOut->showPermissionsErrorPage( array( array( $error ) ) );
    }

    protected function webOutputPartial( $opts, $key, $offsetReplacement, $limitReplacement, $query ) {
        global $wgOut;

        $html = '<div>';
        $html .= '<h2>' . wfMsgNoTrans( 'wikilog-subscription-' . $key ) . '</h2>';
        if ( count( $opts[$key] ) > 0 ) {
            $html .= '<table class="wikitable">';
            $html .= '<tr><th>' . wfMsgNoTrans( 'wikilog-subscription-header-action');
            $html .= '</th><th>' . wfMsgNoTrans( 'wikilog-subscription-header-' . $key ) . '</th>';
            foreach ( $opts[$key] as $title ) {
                $html .= $this->itemHTML( $title, $key == 'comments' );
            }
            $html .= '</table>';
        } else {
            $html .= wfMsgNoTrans( 'wikilog-subscription-' . $key . '-empty' );
        }
        $html .= '</div>';
        $wgOut->addHtml( $html );

        $link = wfViewPrevNext(
            $opts[$key . '_offset'],
            $opts[$key . '_limit'],
            $this->mTitle,
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
        if ( !$title || !$title->userCanRead() ) {
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
                    'ws_date' => wfTimestamp( TS_MW ),
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
                '<p>' . wfMsgNoTrans( 'wikilog-subscription-blog-subscribed', $wgUser->getSkin()->link( $title, $title->getPrefixedText() ) ) .
                '</p><p>' . self::generateSubscriptionLink( $title, true, true ) . '</p>'
            );
        } elseif ( $isComments ) {
            $wi = Wikilog::getWikilogInfo( $title );
            $key = ( $wi->isMain() ? 'wikilog-subscription-comment-unsubscribed-blog' : 'wikilog-subscription-comment-unsubscribed-article' );
            $wgOut->addHtml(
                '<p>' . wfMsgNoTrans( $key, $title->getPrefixedText() ) .
                '</p><p>' . $this->getCommentSubscription( $title->getTalkPage() ) . '</p>'
            );
        } else {
            $wgOut->addHtml(
                '<p>' . wfMsgNoTrans( 'wikilog-subscription-blog-unsubscribed', $wgUser->getSkin()->link( $title, $title->getPrefixedText() ) ) .
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
        $unsubscribeLink = $wgUser->getSkin()->link( $this->mTitle, wfMsgNoTrans( 'wikilog-subscription-item-unsubscribe' ), $params, $query );
        $titleLink = $wgUser->getSkin()->link( $title, $title->getPrefixedText() );
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
        return wfMsgNoTrans( 'wikilog-subscription-comment-subscription', $title->getLinkUrl(), $title->getPrefixedText() );
    }

    /**
     * Generate HTML link for subscription to $title
     *
     * @global User $wgUser
     * @param Title $title
     * @param boolean $subscribed Flag that current user is subscribed to article $title. If not null do not check
     * @return string
     */
    public static function generateSubscriptionLink( $title, $subscribed = null, $forEmail = false ) {
        global $wgUser;
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
        return $prefix . wfMsgNoTrans( $msg, $title->getText(), $link );
    }

    /**
     * Link to subscription management page
     *
     * @global User $wgUser
     * @return string
     */
    public static function subcriptionsRuleLink() {
        global $wgUser;
        return $wgUser->getSkin()->link(
            SpecialPage::getTitleFor( 'wikilogsubscriptions' ),
            wfMsgNoTrans( 'wikilog-subscription-return-link' )
        );
    }

    public static function sendEmails( &$article, &$user, $text, $summary,
        $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId )
    {
        if ( isset( $article->mExtWikilog ) && $article->mExtWikilog['signpub'] ) {
            global $wgUser, $wgParser, $wgPasswordSender, $wgServer;

            $dbr = wfGetDB( DB_SLAVE );

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

            // Generate body
            $saveExpUrls = WikilogParser::expandLocalUrls();
            $popt = new ParserOptions( User::newFromId( $wgUser->getId() ) );
            $subject = $wgParser->parse( wfMsgNoTrans( 'wikilog-subscription-email-subject', $args ),
                $title, $popt, false, false );
            $subject = strip_tags( $subject->getText() );
            $body = $wgParser->parse( wfMsgNoTrans( 'wikilog-subscription-email-body', $args),
                $title, $popt, true, false );
            $body = $body->getText();
            $body .= self::generateSubscriptionLink( $wi->mWikilogTitle, true, true ) .
                '<br />' . self::subcriptionsRuleLink();
            WikilogParser::expandLocalUrls( $saveExpUrls );

            // Select subscribers
            $blogID = $wi->mWikilogTitle->getArticleID();
            $res = $dbr->select( 'watchlist', 'wl_user',
                'wl_namespace = ' . NS_BLOG . ' AND wl_title = ' . $dbr->addQuotes( $wi->mWikilogTitle->getText() ), __METHOD__ );
            $emails = array();
            foreach ( $res as $row ) {
                $user = User::newFromId( $row->wl_user );
                $user->mGroups = NULL;
                if ( !$user || $title->getUserPermissionsErrors( 'read', $user ) ) {
                    continue;
                }
                $emails[] = new MailAddress( $user->getEmail() );
            }

            // Send the message
            if ( $emails ) {
                $serverName = substr( $wgServer, strpos( $wgServer, '//' ) + 2 );
                $headers = array(
                    'Message-ID' => '<wikilog-' . $article->getId() . '@' . $serverName . '>',
                );
                $from = new MailAddress( $wgPasswordSender, 'Wikilog' );
                UserMailer::send( $emails, $from, $subject, $body, null, null, $headers );
            }
        }
        return true;
    }
}
