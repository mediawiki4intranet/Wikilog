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
        $parser->setFunctionHook( 'wl-settings', function ( $parser, $frame, $args ) {
            return WikilogParser::settings( $parser, $frame, $args );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-publish', function ( $parser, $frame, $args ) {
            return WikilogParser::publish( $parser, $frame, $args );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-comment', function ( $parser, $frame, $args ) {
            return WikilogParser::comment( $parser, $frame, $args );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-author', function ( $parser, $frame, $args ) {
            return WikilogParser::author( $parser, $frame, $args );
        }, SFH_NO_HASH );

        $parser->setFunctionHook( 'wl-tags', function ( $parser, $frame, $args ) {
            return WikilogParser::tags( $parser, $frame, $args );
        }, SFH_NO_HASH );

        $mwMore = $mwFactory->get( 'wlk-more' );
        foreach ( $mwMore->getSynonyms() as $tagname ) {
            $parser->setHook( $tagname, function ( $text, $params, $parser ) {
                return WikilogParser::more( $text, $params, $parser );
            } );
        }

        return true;
    }

    public static function more( $text, $params, $parser ) {
        $marker = '<!--wikilog-more-->';
        $parser->mExtWikilog->mMore = $marker;
        return $marker;
    }

    public static function summary( $text, $params, $parser ) {
        $parser->mExtWikilog->mSummary = $parser->recursiveTagParse( $text );
        return '';
    }

    public static function publish( $parser, $frame, $args ) {
        $parser->mExtWikilog->mPublish = true;
        $date = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : false;
        if ( $date ) {
            $parser->mExtWikilog->mPubDate = wfTimestamp( TS_MW, strtotime( $date ) );
        }
        for ( $i = 1; $i < count( $args ); $i++ ) {
            $author = trim( $frame->expand( $args[$i] ) );
            if ( $author !== '' ) {
                $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $author );
                if ( $user && $user->getId() ) {
                    $parser->mExtWikilog->mAuthors[$user->getName()] = $user->getId();
                } else {
                    $parser->mExtWikilog->mAuthors[$author] = 0;
                }
            }
        }
        return '';
    }

    public static function comment( $parser, $frame, $args ) {
        $parser->mExtWikilog->mComment = [];
        foreach ( $args as $arg ) {
            $parser->mExtWikilog->mComment[] = trim( $frame->expand( $arg ) );
        }
        return '';
    }

    public static function author( $parser, $frame, $args ) {
        foreach ( $args as $arg ) {
            $author = trim( $frame->expand( $arg ) );
            if ( $author !== '' ) {
                $user = MediaWikiServices::getInstance()->getUserFactory()->newFromName( $author );
                if ( $user && $user->getId() ) {
                    $parser->mExtWikilog->mAuthors[$user->getName()] = $user->getId();
                } else {
                    $parser->mExtWikilog->mAuthors[$author] = 0;
                }
            }
        }
        return '';
    }

    public static function tags( $parser, $frame, $args ) {
        foreach ( $args as $arg ) {
            $tag = trim( $frame->expand( $arg ) );
            if ( $tag !== '' ) {
                $parser->mExtWikilog->mTags[$tag] = 1;
            }
        }
        return '';
    }

    public static function settings( $parser, $frame, $args ) {
        // Логика парсинга настроек (упрощена для совместимости)
        return '';
    }

    public static function onParserStart( $parser ) {
        $parser->mExtWikilog = new WikilogParserOutput;
        return true;
    }

    public static function BeforeStrip( $parser, &$text, &$stripState ) {
        $title = $parser->getTitle();
        $parser->mExtWikilogInfo = Wikilog::getWikilogInfo( $title );
        return true;
    }

    public static function onInternalParseBeforeSanitize( $parser, &$text, $stripState ) {
        if ( isset( $parser->mExtWikilog ) ) {
            $output = $parser->getOutput();
            $output->setExtensionData( 'wikilog', $parser->mExtWikilog );
        }
        return true;
    }
}

class WikilogParserOutput {
    public $mSummary = false;
    public $mMore = false;
    public $mAuthors = [];
    public $mTags = [];
    public $mPublish = false;
    public $mPubDate = null;
    public $mComment = null;
}
