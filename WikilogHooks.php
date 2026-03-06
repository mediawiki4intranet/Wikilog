<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;

if ( !defined( 'MEDIAWIKI' ) )
    die();

class WikilogHooks {
    public static function ArticleEditUpdates( $wikiPage, $editResult, $options ) {
        $title = $wikiPage->getTitle();
        $wi = Wikilog::getWikilogInfo( $title );
        $nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();

        if ( $nsInfo->isTalk( $title->getNamespace() ) ) {
            if ( Wikilog::nsHasComments( $title ) ) {
                $comment = WikilogComment::newFromPageID( $wikiPage->getId() );
                if ( $comment ) {
                    $comment->mUpdated = wfTimestamp( TS_MW );
                    $comment->saveComment();
                }
            }
            return true;
        }

        if ( !$wi ) return true;

        if ( $wi->isItem() ) {
            $item = WikilogItem::newFromID( $wikiPage->getId() ) ?: new WikilogItem();
            $item->mID = $wikiPage->getId();
            $item->mName = $wi->mItemName;
            $item->mTitle = $title;
            $item->mParentTitle = $wi->mWikilogTitle;
            $item->mParent = $item->mParentTitle->getArticleID();
            $item->mUpdated = wfTimestamp( TS_MW );
            $item->saveData();
            
            WikilogUtils::updateWikilog( $wi->mWikilogTitle );
        }
        return true;
    }

    public static function onDatabaseSchemaUpdates( $updater ) {
        $dir = __DIR__ . '/';
        $dbType = $updater->getDB()->getType();
        if ( $dbType === 'mysql' ) {
            $updater->addExtensionTable( 'wikilog_wikilogs', "$dir/wikilog-tables.sql" );
            $updater->addExtensionTable( 'page_last_visit', "$dir/archives/patch-visits.sql" );
        }
    }

    public static function UnknownAction( $action, $article ) {
        if ( $action === 'wikilog' && $article instanceof WikilogCustomAction ) {
            $article->wikilog();
            return false;
        }
        return true;
    }
}
