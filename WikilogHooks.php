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
            $parserOutput = $editResult->getParserOutput();
            $wikilogData = $parserOutput->getExtensionData( 'wikilog' );

            $item = WikilogItem::newFromID( $wikiPage->getId() ) ?: new WikilogItem();
            $item->mID = $wikiPage->getId();
            $item->mName = $wi->mItemName;
            $item->mTitle = $title;
            $item->mParentTitle = $wi->mWikilogTitle;
            $item->mParent = $item->mParentTitle->getArticleID();
            $item->mUpdated = wfTimestamp( TS_MW );

            if ( $wikilogData ) {
                $item->mPublish = $wikilogData->mPublish;
                if ( $wikilogData->mPubDate ) {
                    $item->mPubDate = $wikilogData->mPubDate;
                }
                $item->mAuthors = $wikilogData->mAuthors;
                $item->mTags = $wikilogData->mTags;
            }

            if ( !$item->mPubDate ) {
                $item->mPubDate = $item->mUpdated;
            }

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

    public static function onResourceLoaderRegisterModules( \MediaWiki\ResourceLoader\ResourceLoader $rl ) {
        global $wgExtensionDirectory, $wgExtensionAssetsPath;

        // ИСПОЛЬЗУЕМ __DIR__ НАПРЯМУЮ, так как файл уже в корне расширения
        $localDir = str_replace( '\\', '/', __DIR__ ); 
        $baseExtDir = str_replace( '\\', '/', $wgExtensionDirectory );
        
        // Вычисляем относительный путь (например, 'our/Wikilog' или просто 'Wikilog')
        $relativePath = ltrim( str_replace( $baseExtDir, '', $localDir ), '/' );
        
        // Формируем URL для браузера
        $remotePath = $wgExtensionAssetsPath . '/' . $relativePath;

        // Регистрируем модуль на лету
        $rl->register( 'ext.wikilog', [
            'localBasePath' => $localDir,
            'remoteBasePath' => $remotePath,
            'styles' => 'style/wikilog.css',
            'scripts' => 'style/wikilog.js',
            'targets' => [ 'desktop', 'mobile' ]
        ] );
    }
}
