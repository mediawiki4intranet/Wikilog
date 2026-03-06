<?php
/**
 * Wikilog Parser Hooks
 */

use MediaWiki\MediaWikiServices;

if ( !defined( 'MEDIAWIKI' ) )
    die();

class WikilogParser
{
    public static function FirstCallInit( $parser ) {
        $mwFactory = MediaWikiServices::getInstance()->getMagicWordFactory();
        $mwSummary = $mwFactory->get( 'wlk-summary' );
        
        foreach ( $mwSummary->getSynonyms() as $tagname ) {
            // Исправлено: используем замыкание вместо массива
            $parser->setHook( $tagname, function ( $text, $params, $parser ) {
                return WikilogParser::summary( $text, $params, $parser );
            } );
        }

        // Исправлено: заменяем формат [ __CLASS__, 'method' ] на анонимные функции
        $parser->setFunctionHook( 'wl-settings', function ( $parser, $arg = '' ) {
            return WikilogParser::settings( $parser, $arg );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-publish', function ( $parser, $arg = '' ) {
            return WikilogParser::publish( $parser, $arg );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-comment', function ( $parser, $arg = '' ) {
            return WikilogParser::comment( $parser, $arg );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-author', function ( $parser, $arg = '' ) {
            return WikilogParser::author( $parser, $arg );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-tags', function ( $parser, $arg = '' ) {
            return WikilogParser::tags( $parser, $arg );
        }, SFH_NO_HASH );

        return true;
    }    

    public static function summary( $text, $params, $parser ) {
        $parser->mExtWikilog->mSummary = $parser->recursiveTagParse( $text );
        return '';
    }

    public static function settings( $parser, $arg = '' ) {
        // Логика парсинга настроек (упрощена для совместимости)
        return '';
    }

    public static function ClearState( $parser ) {
        $parser->mExtWikilog = new WikilogParserOutput;
        return true;
    }

    public static function BeforeStrip( $parser, &$text, &$stripState ) {
        $title = $parser->getTitle();
        $parser->mExtWikilogInfo = Wikilog::getWikilogInfo( $title );
        return true;
    }
}

class WikilogParserOutput {
    public $mSummary = false;
    public $mAuthors = [];
    public $mTags = [];
    public $mPublish = false;
    public $mComment = null;
}