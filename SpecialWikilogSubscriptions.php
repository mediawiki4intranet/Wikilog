<?php


if ( !defined( 'MEDIAWIKI' ) )
	die();

class SpecialWikilogSubscriptions
	extends IncludableSpecialPage
{
    
    const SUBSCRIPTIONS_ON_PAGE = 20;
    
	/**
	 * Constructor.
	 */
	function __construct( ) {
		parent::__construct( 'WikilogSubscriptions' );
	}
    
    public function execute( $parameters ) {
        global $wgUser, $wgRequest;
        
        if( $wgUser->isAnon() ) {
            return $this->errorPage();
        }
        
        if( $wgRequest->getVal('subscribe_to') ) {
            return $this->subscribe();
        }
        
        $id = $wgUser->getId();
        $dbr = wfGetDB( DB_SLAVE );

        $opts = array(
            'blogs' => array( ),
            'comments' => array( ),
            'blogs_offset' => $wgRequest->getInt('boffset'),
            'comments_offset' => $wgRequest->getInt('coffset'),
            'blogs_limit' => $wgRequest->getInt('blimit') ?: self::SUBSCRIPTIONS_ON_PAGE,
            'comments_limit' => $wgRequest->getInt('climit') ?: self::SUBSCRIPTIONS_ON_PAGE,
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
        foreach( $dbOptions as $key => $dbOpts )
        {
            $tbl = $dbr->tableName( $dbOpts['tbl'] );
            $crow = $dbr->fetchRow( $dbr->query( "SELECT COUNT(*) as count FROM $tbl WHERE {$dbOpts['condition']}" ) );
            $opts[$key . '_count'] = $crow['count'] - 0;
            
            if( $opts[$key . '_count'] <= $opts[$key . '_offset'] ) {
                $opts[$key . '_offset'] = 0;
            }
            
            $res = $dbr->query(
                // Notify users subscribed to this post
                "SELECT * FROM $tbl WHERE {$dbOpts['condition']} LIMIT {$opts[$key . '_offset']},{$opts[$key . '_limit']}"
            );
            while( $row = $dbr->fetchRow( $res ) ) {
                $opts[$key][] = Title::newFromID( $row[ $dbOpts['pageID_key'] ] );;
            }
        }

        return $this->webOutput( $opts );
    }
    
    public function webOutput( $opts ) {
        global $wgOut, $wgWikilogStylePath;

		$this->setAndOutputHeader();
        $specTitle = SpecialPage::getTitleFor( 'wikilogsubscriptions' );
        
        $blogs = '<div>';
        $blogs .= '<h2>' . wfMsgNoTrans( 'wikilog-subscription-blogs' ) . '</h2>';
        if( count($opts['blogs']) > 0 ) {
            $blogs .= '<table class="wikitable">';
            $blogs .= '<tr><th>' . wfMsgNoTrans( 'wikilog-subscription-header-action') . '</th><th>' . wfMsgNoTrans( 'wikilog-subscription-header-blogs' ) . '</th>';
            foreach ( $opts['blogs'] as $title ) {
                $blogs .= $this->itemHTML( $title );
            }
            $blogs .= '</table>';
        }
        else {
            $blogs .= wfMsgNoTrans( 'wikilog-subscription-blogs-empty' );
        }
        $blogs .= '</div>';
        $wgOut->addHtml($blogs);
        
        $link = wfViewPrevNext( 
                $opts['blogs_offset'],
                $opts['blogs_limit'],
                $specTitle,
                http_build_query( array( 'coffset' => $opts['comments_offset'], 'climit' => $opts['comments_limit'] ) ),
                $opts['blogs_offset'] + $opts['blogs_limit'] >= $opts['blogs_count']
        );
        $link = str_replace('&amp;offset', '&amp;boffset', $link);
        $link = str_replace('&amp;limit', '&amp;blimit', $link);
        $wgOut->addHTML( $link );
        
        $wgOut->addHtml('<p></p>');
                
        $comments = '<div>';
        $comments .= '<h2>' . wfMsgNoTrans( 'wikilog-subscription-comments' ) . '</h2>';
        if ( count($opts['comments']) > 0 ) {
            $comments .= '<table class="wikitable">';
            $comments .= '<tr><th>' . wfMsgNoTrans( 'wikilog-subscription-header-action' ) . '</th><th>' . wfMsgNoTrans( 'wikilog-subscription-header-comments' ) . '</th>';
            foreach ( $opts['comments'] as $title ) {
                $comments .= $this->itemHTML( $title, true );
            }
            $comments .= '</table>';
        }
        else {
            $comments .= wfMsgNoTrans( 'wikilog-subscription-comments-empty' );
        }
        $comments .= '</div>';
        $wgOut->addHtml( $comments );
        $link = wfViewPrevNext(
                $opts['comments_offset'],
                $opts['comments_limit'],
                $specTitle,
                http_build_query( array( 'boffset' => $opts['blogs_offset'], 'blimit' => $opts['blogs_limit'] ) ),
                $opts['comments_offset'] + $opts['comments_limit'] >= $opts['comments_count']
        );
        $link = str_replace('&amp;offset', '&amp;coffset', $link);
        $link = str_replace('&amp;limit', '&amp;climit', $link);
        $wgOut->addHTML( $link );
        

        return $wgOut;
    }
    
    public function errorPage( $error = 'wikilog-subscription-unauthorized' ) {
        global $wgOut;
        return $wgOut->showPermissionsErrorPage([[$error]]);
    }
    
    /**
     * 
     * @global WebRequest $wgRequest
     * @global User $wgUser
     * @global OutputPage $wgOut
     * @return type
     */
    protected function subscribe( ) {
        global $wgRequest, $wgUser, $wgOut;
        
        $id = $wgRequest->getVal('subscribe_to');
        
        $title = Title::newFromID($id);
        if (!$title || !$title->userCanRead( )) {
            return $this->errorPage( 'wikilog-subscription-access-denied' );
        }
        
        $subscribe = $wgRequest->getBool('subscribe') ? 1 : 0;
        $isComments = $wgRequest->getBool('comment');
        
        if( $subscribe && $isComments ) {
            $talk = $title->getTalkPage();
            if( !$talk ) {
                return $this->errorPage( 'wikilog-subscription-access-denied' );
            }
            $this->setAndOutputHeader( );
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
		$this->setAndOutputHeader( );
        
        if( $subscribe ) {
            $wgOut->addHtml( '<p>' . wfMsgNoTrans( 'wikilog-subscription-blog-subscribed' , $title->getPrefixedText() ) . '</p><p>' . self::generateSubscriptionLink($title, true) . '</p>' );
        }
        elseif ($isComments) {
            $wi = Wikilog::getWikilogInfo( $title );
            $wgOut->addHtml( 
                    '<p>' . wfMsgNoTrans( 'wikilog-subscription-comment-unsubscribed-' . ($wi->isMain() ? 'blog' : 'article' ) , $title->getPrefixedText() ) .
                    '</p><p>' . $this->getCommentSubscription( $title->getTalkPage() ) . '</p>'
            );
        }
        else {
            $wgOut->addHtml( '<p>' . wfMsgNoTrans( 'wikilog-subscription-blog-unsubscribed' , $title->getPrefixedText() ) . '</p><p>' . self::generateSubscriptionLink($title, false) . '</p>' );
        }
        self::subcriptionsRuleLink();
        
        return $wgOut;
    }
    
    protected function setAndOutputHeader( ) {
		# Set page title, html title, nofollow, noindex, etc...
		$this->setHeaders();
		$this->outputHeader();
    }
    
    /**
     * @param Title $title
     * @return text HTML
     */
    protected function itemHTML( $title, $comments = false ) {
        global $wgUser;
        
        $params = array( );
        $query = array(
            'subscribe_to' => $title->getArticleID(),
            'subscribe' => 0
        );
        if( $comments ) {
            $query ['comment'] = 1;
        }
        $unsubscribeLink = $wgUser->getSkin()->link( SpecialPage::getTitleFor( 'wikilogsubscriptions' ), wfMsgNoTrans( 'wikilog-subscription-item-unsubscribe' ), $params, $query );
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
     * 
     * @global User $wgUser
     * @param Title $title
     * @param boolean $subscribed
     * @return string
     */
    public static function generateSubscriptionLink( $title, $subscribed = null ) {
        global $wgUser;
        if( $wgUser->isAnon() || !$title->userCanRead() ) {
            return '';
        }
        $spec = SpecialPage::getTitleFor( 'wikilogsubscriptions' );
        $prefix = '';
        if( $subscribed === null ) {
            $subscribed = self::checkSubscribing( $title->getArticleID() );
            $prefix = wfMsgNoTrans( $subscribed ? 'wikilog-subscription-unsubscribe-prefix' : 'wikilog-subscription-subscribe-prefix');
        }
        
        $query = array(
            'subscribe_to' => $title->getArticleID(),
            'subscribe' => ($subscribed ? 0 : 1)
        );
        $link = $wgUser->getSkin()->link( $spec, '', array( ), $query );
        $link = explode('><', $link);
        
        
        return $prefix . wfMsgNoTrans( $subscribed ? 'wikilog-subscription-unsubscribe' : 'wikilog-subscription-subscribe', $link[0] . '>', '<' . $link[1] );
    }
    
    public static function subcriptionsRuleLink( $out = true ) {
        global $wgOut, $wgUser;
        $link = $wgUser->getSkin()->link( SpecialPage::getTitleFor( 'wikilogsubscriptions' ), wfMsgNoTrans( 'wikilog-subscription-return-link') );
        if( $out )
        {
            $wgOut->addHTML( $link );
        }
        else {
            return $link;
        }
        return null;
    }
    
    /**
     * 
     * @param WikilogWikiItemPage $article
     * @param type $user
     * @param type $text
     * @param type $summary
     * @param type $minoredit
     * @param type $watchthis
     * @param type $sectionanchor
     * @param type $flags
     * @param type $revision
     * @param type $status
     * @param type $baseRevId
     * @return boolean
     */
    public static function sendEmails( &$article, &$user, $text, $summary,
            $minoredit, $watchthis, $sectionanchor, &$flags, $revision, &$status, $baseRevId )
    {
        if ( isset( $article->mExtWikilog ) && $article->mExtWikilog['signpub'] ) {
            global $wgUser, $wgParser, $wgPasswordSender;
            
            $dbr = wfGetDB( DB_SLAVE );
            $tbl = $dbr->tableName( 'wikilog_blog_subscriptions' );
            
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
            $res = $dbr->query(
                // Notify users subscribed to this post
                "SELECT ws_user FROM $tbl WHERE ws_page=$articleID AND ws_yes=1"
            );
            
            if( $dbr->numRows( $res ) > 0 ) {
                $emails = [];
                
                while( $row = $dbr->fetchRow( $res ) ) {
                    $user = User::newFromId( $row['ws_user'] );
                    if( !$user || $user->getId() == $wgUser->getId() || !$title->userCanReadEx( $user ) || !$user->canReceiveEmail() ) {
                        continue;
                    }
                    $emails[] = new MailAddress( $user->getEmail() );
                }
                
                if( !empty($emails) ) {
                    $from = new MailAddress( $wgPasswordSender, 'Wikilog' );
                    UserMailer::send( $emails, $from, $subject, $body );
                }
            }
        }
		return true;
    }
    
    protected static function checkSubscribing( $articleID ) {
        global $wgUser;
        
        if( $wgUser->isAnon() ) {
            return false;
        }
        
        $id = $wgUser->getId();
        $dbr = wfGetDB( DB_SLAVE );
        $t = $dbr->tableName( 'wikilog_blog_subscriptions' );
        $result = $dbr->query(
            // Notify users subscribed to this post
            "SELECT * FROM $t WHERE ws_user=$id AND ws_page=$articleID AND ws_yes=1"
        );
        
        return $dbr->numRows($result) > 0;
    }
}
