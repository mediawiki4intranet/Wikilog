<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

if ( !defined( 'MEDIAWIKI' ) )
    die();

define( 'WL_NBSP', '&#160;' );

class Wikilog
{
    public static function ExtensionInit() {
        global $wgNamespacesWithSubpages, $wgWikilogNamespaces;
        foreach ( (array)$wgWikilogNamespaces as $ns ) {
            $wgNamespacesWithSubpages[$ns] = true;
            $wgNamespacesWithSubpages[$ns ^ 1] = true;
        }
    }

    /**
     * Возвращенный метод для совместимости с вашим LocalSettings.php
     */
    public static function setupBlogNamespace( $ns ) {
        global $wgExtraNamespaces, $wgWikilogNamespaces, $wgNamespaceAliases, 
               $wgWikilogCommentNamespaces, $wgContentNamespaces, $wgLanguageCode,
               $wgWikilogCommentsOnItemPage;

        $ns = $ns & ~1;
        if ( !defined( 'NS_BLOG' ) ) define( 'NS_BLOG', $ns );
        if ( !defined( 'NS_BLOG_TALK' ) ) define( 'NS_BLOG_TALK', $ns + 1 );

        $wgWikilogNamespaces[] = $ns;
        $wgContentNamespaces[] = $ns;
        
        if ( $wgWikilogCommentNamespaces !== true ) {
            $wgWikilogCommentNamespaces[NS_BLOG_TALK] = true;
        }
        if ( $wgWikilogCommentsOnItemPage !== true ) {
            $wgWikilogCommentsOnItemPage[NS_BLOG] = true;
        }

        // Загрузка имен из i18n
        require __DIR__ . '/Wikilog.i18n.ns.php';
        $lang = $wgLanguageCode ?: 'en';
        $names = $namespaceNames[$lang] ?? $namespaceNames['en'];
        
        $wgExtraNamespaces[NS_BLOG] = $names[NS_BLOG];
        $wgExtraNamespaces[NS_BLOG_TALK] = $names[NS_BLOG_TALK];
        
        foreach ( $namespaceNames['en'] as $id => $name ) {
            $wgNamespaceAliases[$name] = $id;
        }
    }

    public static function getPreferences( $user, &$defaultPreferences ) {
        $defaultPreferences['wl-subscribetoall'] = [
            'type' => 'toggle',
            'label-message' => 'wl-subscribetoall',
            'section' => 'misc/wikilog',
        ];
        return true;
    }

    public static function ArticleFromTitle( $title, &$article ) {
        if ( $title->isTalkPage() ) {
            $page = WikilogCommentsPage::createInstance( $title );
            if ( $page ) {
                $article = $page;
                return false;
            }
        } elseif ( ( $wi = self::getWikilogInfo( $title ) ) ) {
            if ( $wi->isItem() ) {
                $item = WikilogItem::newFromInfo( $wi );
                $article = new WikilogItemPage( $title, $item );
            } else {
                $article = new WikilogMainPage( $title, $wi );
            }
            return false;
        }
        return true;
    }

    public static function ArticleViewHeader( $article, &$outputDone, $pcache ) {
        if ( $article instanceof WikilogCommentsPage && $article->getID() == 0 ) {
            $outputDone = true;
            return false;
        }
        return true;
    }

    public static function ArticleViewFooter( $article, $patrolFooterShown ) {
        global $wgWikilogCommentsOnItemPage;
        $title = $article->getTitle();
        $ns = $title->getNamespace();
        $nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
        
        if ( !$nsInfo->isTalk( $ns ) && !( $article instanceof WikilogMainPage ) &&
            ( $wgWikilogCommentsOnItemPage === true || isset( $wgWikilogCommentsOnItemPage[$ns] ) ) ) {
            $talk = $title->getTalkPage();
            $comments = WikilogCommentsPage::createInstance( $talk );
            if ( $comments ) {
                $comments->outputComments();
            }
        }
        return true;
    }

    public static function BeforePageDisplay( $output, $skin ) {
        $output->addModules( 'ext.wikilog' );
        return true;
    }

    public static function getWikilogInfo( $title ) {
        global $wgWikilogNamespaces;
        if ( !$title ) return null;

        $nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
        $ns = $nsInfo->getSubject( $title->getNamespace() );
        
        if ( in_array( $ns, (array)$wgWikilogNamespaces ) ) {
            $wi = new WikilogInfo( $title );
            if ( $wi->mWikilogName ) return $wi;
        }
        return null;
    }

    public static function nsHasComments( $title ) {
        global $wgWikilogCommentNamespaces;
        $nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
        $ns = $nsInfo->getTalk( $title->getNamespace() );
        return $wgWikilogCommentNamespaces === true || isset( $wgWikilogCommentNamespaces[$ns] );
    }
}