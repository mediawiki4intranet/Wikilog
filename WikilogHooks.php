<?php
/**
 * MediaWiki Wikilog extension
 */

use MediaWiki\MediaWikiServices;

if ( !defined( 'MEDIAWIKI' ) )
    die();

class WikilogHooks {

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

        $localDir = str_replace( '\\', '/', __DIR__ ); 
        $baseExtDir = str_replace( '\\', '/', $wgExtensionDirectory );
        $relativePath = ltrim( str_replace( $baseExtDir, '', $localDir ), '/' );
        $remotePath = $wgExtensionAssetsPath . '/' . $relativePath;

        $rl->register( 'ext.wikilog', [
            'localBasePath' => $localDir,
            'remoteBasePath' => $remotePath,
            'styles' => 'style/wikilog.css',
            'scripts' => 'style/wikilog.js',
            'targets' => [ 'desktop', 'mobile' ]
        ] );
    }

    public static function onSkinTemplateNavigation__Universal( $skinTemplate, &$links ) {
        $title = $skinTemplate->getTitle();
        
        if ( !$title || !class_exists( 'Wikilog' ) ) {
            return true;
        }

        $wi = Wikilog::getWikilogInfo( $title );
        
        if ( $wi && $wi->isMain() ) {
            $request = $skinTemplate->getRequest();
            $action = $request->getVal( 'action', 'view' );

            $tabText = wfMessage( 'wikilog-tab-title' )->exists() 
                ? wfMessage( 'wikilog-tab-title' )->text() 
                : 'Викилог';

            $links['views']['wikilog'] = [
                'id'    => 'ca-wikilog',
                'text'  => $tabText,
                'href'  => $title->getLocalURL( [ 'action' => 'wikilog' ] ),
                'class' => ( $action === 'wikilog' ) ? 'selected' : '',
            ];
        }
        
        return true;
    }

    

    public static function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
        $title = $wikiPage->getTitle();
        if ( !class_exists( 'Wikilog' ) ) return true;
        $wi = Wikilog::getWikilogInfo( $title );
        $nsInfo = \MediaWiki\MediaWikiServices::getInstance()->getNamespaceInfo();

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
            list( , $parserOutput ) = WikilogUtils::parsedArticle( $title );
            $wikilogData = $parserOutput ? $parserOutput->getExtensionData( 'wikilog' ) : null;
            if ( is_array($wikilogData) ) $wikilogData = (object)$wikilogData;

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


    public static function onEditPage__showStandardInputs_options( $editPage, $out, &$tabindex ) {
        $title = $editPage->getTitle();
        if ( !class_exists( 'Wikilog' ) ) return true;
        $wi = Wikilog::getWikilogInfo( $title );

        if ( $wi && $wi->isItem() ) {
            // ИСПРАВЛЕНИЕ: Проверяем статус в базе данных, как в старом коде
            $item = WikilogItem::newFromInfo( $wi );
            
            // Показываем, если статьи еще нет в базе ИЛИ она есть, но не опубликована
            if ( !$item || !$item->getIsPublished() ) {
                global $wgWikilogSignAndPublishDefault;
                $request = $editPage->getContext()->getRequest();
                $checked = $request->getBool( 'wlSignpub', $wgWikilogSignAndPublishDefault );

                $checkboxHtml = \MediaWiki\Html\Html::check( 'wlSignpub', $checked, [ 'id' => 'wl-signpub', 'tabindex' => ++$tabindex ] ) .
                    '&#160;' .
                    \MediaWiki\Html\Html::element( 'label', [
                        'for' => 'wl-signpub',
                        'title' => 'Вызывает подписывание и опубликование статьи в викилоге при сохранении. Снимите этот флажок, чтобы оставить статью в качестве черновика.'
                    ], 'Подписать и опубликовать эту статью' );

                $out->addHTML( \MediaWiki\Html\Html::rawElement( 'fieldset', [],
                    \MediaWiki\Html\Html::element( 'legend', [], 'Настройки викилога:' ) . $checkboxHtml
                ) );
            }
        }
        return true;
    }

    public static function onEditPage__importFormData( $editPage, $request ) {
        $title = $editPage->getTitle();
        if ( !class_exists( 'Wikilog' ) ) return true;
        $wi = Wikilog::getWikilogInfo( $title );

        if ( $wi && $wi->isItem() ) {
            $text = $request->getText( 'wpTextbox1', $editPage->textbox1 ?? '' );
            $date = gmdate( 'Y-m-d H:i:s \+0000' );
            $author = $editPage->getContext()->getUser()->getName();

            // 1. Магия тильд
            $sigs = [
                '/\n?(--)?~~~~~\n?/m' => "\n{{wl-publish: $date }}\n",
                '/\n?(--)?~~~~\n?/m' => "\n{{wl-publish: $date | $author }}\n",
                '/\n?(--)?~~~\n?/m' => "\n{{wl-author: $author }}\n"
            ];
            $text = preg_replace( array_keys( $sigs ), array_values( $sigs ), $text );

            // 2. Обрабатываем галочку из интерфейса
            if ( $request->getBool( 'wlSignpub' ) ) {
                $item = WikilogItem::newFromInfo( $wi );
                if ( !$item || !$item->getIsPublished() ) {
                    if ( !preg_match( '/\{\{\s*wl-publish\s*:/', $text ) ) {
                        $text = rtrim( $text ) . "\n\n{{wl-publish: $date | $author }}";
                    }
                }
            }

            $editPage->textbox1 = $text;
        }
        return true;
    }

}
