<?php


if ( !defined( 'MEDIAWIKI' ) )
    die();

class SpecialWikilogSubscriptions
    extends IncludableSpecialPage
{
    
    const SUBSCRIPTIONS_ON_PAGE = 20;
    
    protected $mTitle;
    
    /**
     * Constructor.
     */
    function __construct( ) {
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
        $dbOptions = array(
            'blogs' => array(
                'tbl' => 'wikilog_blog_subscriptions',
                'condition' => "ws_user=$id AND ws_yes=1",
                'pageID_key' => 'ws_page',
            ),
            'comments' => array(
                'tbl' => 'wikilog_subscriptions',
                'condition' => "ws_user=$id AND ws_yes=1",
                'pageID_key' => 'ws_page',
            ),
        );
        foreach ( $dbOptions as $key => $dbOpts ) {
            $opts[$key . '_count'] = $dbr->selectField( $dbOpts['tbl'], "COUNT(*)", $dbOpts['condition'] );
            
            if ( $opts[$key . '_count'] <= $opts[$key . '_offset'] ) {
                $opts[$key . '_offset'] = 0;
            }
            
            $res = $dbr->select( $dbOpts['tbl'], '*', $dbOpts['condition'], __METHOD__,
                    array( 'LIMIT' => $opts[$key . '_limit'], 'OFFSET' => $opts[$key . '_offset'] )
            );
            foreach ( $res as $row ) {
                $opts[$key][] = Title::newFromID( $row->{$dbOpts['pageID_key']} );
            }
        }

        return $this->webOutput( $opts );
    }
    
    public function webOutput( $opts ) {
        global $wgOut;

        $this->setAndOutputHeader();
        
        $this->webOutputPartial($opts, 'blogs', 'boffset', 'blimit', http_build_query( array( 'coffset' => $opts['comments_offset'], 'climit' => $opts['comments_limit'] ) ) );
        $wgOut->addHtml( '<p></p>' );
        $this->webOutputPartial($opts, 'comments', 'coffset', 'climit', http_build_query( array( 'boffset' => $opts['blogs_offset'], 'blimit' => $opts['blogs_limit'] ) ) );

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
            $html .= '<tr><th>' . wfMsgNoTrans( 'wikilog-subscription-header-action') . '</th><th>' . wfMsgNoTrans( 'wikilog-subscription-header-' . $key ) . '</th>';
            foreach ( $opts[$key] as $title ) {
                $html .= $this->itemHTML( $title );
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
        $link = str_replace('&amp;offset', '&amp;' . $offsetReplacement, $link);
        $link = str_replace('&amp;limit', '&amp;' . $limitReplacement, $link);
        $wgOut->addHTML( $link );
    }


    /**
     * To subscribe current user to specified blog
     * @global WebRequest $wgRequest
     * @global User $wgUser
     * @global OutputPage $wgOut
     * @return type
     */
    protected function subscribe( ) {
        global $wgRequest, $wgUser, $wgOut;
        
        $id = $wgRequest->getVal( 'subscribe_to' );
        
        $title = Title::newFromID( $id );
        if (!$title || !$title->userCanRead( )) {
            return $this->errorPage( 'wikilog-subscription-access-denied' );
        }
        
        $subscribe = $wgRequest->getBool( 'subscribe' ) ? 1 : 0;
        $isComments = $wgRequest->getBool( 'comment' );
        
        if ( $subscribe && $isComments ) {
            $talk = $title->getTalkPage();
            if ( !$talk ) {
                return $this->errorPage( 'wikilog-subscription-access-denied' );
            }
            $this->setAndOutputHeader(s);
            $wgOut->addHtml( $this->getCommentSubscription( $talk ) );
            self::subcriptionsRuleLink();
            return $wgOut;
        }
        
        $dbr = wfGetDB( DB_SLAVE );
        $dbr->replace( 
            $isComments ? 'wikilog_subscriptions' : 'wikilog_blog_subscriptions',
            array( array( 'ws_page', 'ws_user' ) ),
            array(
                'ws_page' => $title->getArticleID(),
                'ws_user' => $wgUser->getID(),
                'ws_yes'  => $subscribe,
                'ws_date' => wfTimestamp( TS_MW ),
            ),
            __METHOD__
        );
        
        # webOutput
        $this->setAndOutputHeader();
        
        if ( $subscribe ) {
            $wgOut->addHtml( '<p>' . wfMsgNoTrans( 'wikilog-subscription-blog-subscribed' , $wgUser->getSkin()->link( $title, $title->getPrefixedText() ) ) . '</p><p>' . self::generateSubscriptionLink($title, true) . '</p>' );
        } elseif ( $isComments ) {
            $wi = Wikilog::getWikilogInfo( $title );
            $wgOut->addHtml( 
                    '<p>' . wfMsgNoTrans( 'wikilog-subscription-comment-unsubscribed-' . ( $wi->isMain( ) ? 'blog' : 'article' ) , $title->getPrefixedText() ) .
                    '</p><p>' . $this->getCommentSubscription( $title->getTalkPage() ) . '</p>'
            );
        } else {
            $wgOut->addHtml( '<p>' . wfMsgNoTrans( 'wikilog-subscription-blog-unsubscribed' , $wgUser->getSkin()->link( $title, $title->getPrefixedText() ) ) . '</p><p>' . self::generateSubscriptionLink($title, false) . '</p>' );
        }
        self::subcriptionsRuleLink();
        
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
        $titleLink = $wgUser->getSkin()->link($title, $title->getPrefixedText());
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
     * 
     * @param Title $title
     * @return string
     */
    protected function getCommentSubscription( $title ) {
        return wfMsgNoTrans( 'wikilog-subscription-comment-subscription', $title->getLinkUrl(), $title->getPrefixedText() );
    }

    /**
     * Generate HTML link for subscription to $title
     * @global User $wgUser
     * @param Title $title
     * @param boolean $subscribed Flag that current user is subscribed to article $title. If not null do not check
     * @return string
     */
    public static function generateSubscriptionLink( $title, $subscribed = null ) {
        global $wgUser;
        if ( $wgUser->isAnon() || !$title->userCanRead() ) {
            return '';
        }
        $spec = SpecialPage::getTitleFor( 'wikilogsubscriptions' );
        $prefix = '';
        if ( $subscribed === null ) {
            
            if ( $wgUser->isAnon() ) {
                $subscribed = false;
            }

            $id = $wgUser->getId();
            $dbr = wfGetDB( DB_SLAVE );
            $articleID = $title->getArticleID();
            $subscribed = $dbr->selectField( 'wikilog_blog_subscriptions', '1', "ws_user=$id AND ws_page=$articleID AND ws_yes=1", __METHOD__ );
            
            $prefix = wfMsgNoTrans( $subscribed ? 'wikilog-subscription-unsubscribe-prefix' : 'wikilog-subscription-subscribe-prefix' );
        }
        
        $query = array(
            'subscribe_to' => $title->getArticleID(),
            'subscribe' => ($subscribed ? 0 : 1)
        );
        $linkToPage = $wgUser->getSkin()->link( $title, $title->getPrefixedText() );
        $link = $wgUser->getSkin()->link( $spec, '', array(), $query );
        $link = explode('><', $link);
        
        
        return $prefix . wfMsgNoTrans( $subscribed ? 'wikilog-subscription-unsubscribe' : 'wikilog-subscription-subscribe', $link[0] . '>', '<' . $link[1], $linkToPage );
    }
    
    /**
     * Link to Subscription management page
     * @global OutputPage $wgOut
     * @global User $wgUser
     * @param boolean $out
     * @return null | string
     */
    public static function subcriptionsRuleLink( $out = true ) {
        global $wgOut, $wgUser;
        $link = $wgUser->getSkin()->link( SpecialPage::getTitleFor( 'wikilogsubscriptions' ), wfMsgNoTrans( 'wikilog-subscription-return-link') );
        if ( $out ) {
            $wgOut->addHTML( $link );
        } else {
            return $link;
        }
        return null;
    }
    
    public static function sendEmails( &$article, &$user, $text, $summary,
            $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId )
    {
        if ( isset( $article->mExtWikilog ) && $article->mExtWikilog['signpub'] ) {
            global $wgUser, $wgParser, $wgPasswordSender;
            
            $dbr = wfGetDB( DB_SLAVE );
            
            $title = $article->getTitle();
            $wi = Wikilog::getWikilogInfo( $title );
            $args = array(
                $title->getPrefixedText( ),
                $wgUser->getName( ),
                $title->getFullURL( ),
                $wi->mWikilogTitle->getText( ),
                $text,
                $wi->mItemTalkTitle->getFullURL( ),
                $wi->mItemTalkTitle->getPrefixedText( ),
            );
            $saveExpUrls = WikilogParser::expandLocalUrls();
            $popt = new ParserOptions( User::newFromId( $wgUser->getId( ) ) );
            $subject = $wgParser->parse( wfMsgNoTrans( 'wikilog-subscription-email-subject', $args ),
                $title, $popt, false, false );
            $subject = strip_tags( $subject->getText( ) );
            $body = $wgParser->parse( wfMsgNoTrans( 'wikilog-subscription-email-body', $args),
                $title, $popt, true, false );
            $body = $body->getText();
            
            $body .= 
                    self::generateSubscriptionLink( $wi->mWikilogTitle, true ) .
                    '<br/>' .
                    self::subcriptionsRuleLink( false );
            WikilogParser::expandLocalUrls( $saveExpUrls );
            
            $articleID = $wi->mWikilogTitle->getArticleID();
            $res = $dbr->select( 'wikilog_blog_subscriptions', 'ws_user', "ws_page=$articleID AND ws_yes=1", __METHOD__ );
            
            $emails = array();

            foreach ( $res as $row ) {
                $user = User::newFromId( $row->ws_user );
                if ( !$user || $user->getId() == $wgUser->getId() || !$title->userCanReadEx( $user ) || !$user->canReceiveEmail() ) {
                    continue;
                }
                $emails[] = new MailAddress( $user->getEmail() );
            }

            if ( !empty( $emails ) ) {
                $from = new MailAddress( $wgPasswordSender, 'Wikilog' );
                UserMailer::send( $emails, $from, $subject, $body );
            }
        }
        return true;
    }
}
