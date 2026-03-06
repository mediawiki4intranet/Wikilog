<?php

use MediaWiki\Title\Title;

class WikilogInfo {
    public $mTitle;
    public $mWikilogTitle;
    public $mWikilogName;
    public $mItemName;

    public function __construct( $title ) {
        $this->mTitle = $title;
        $parts = explode( '/', $title->getText(), 2 );
        
        if ( count( $parts ) > 1 ) {
            // Это пост (Blog:Name/PostName)
            $this->mWikilogName = $parts[0];
            $this->mItemName = $parts[1];
        } else {
            // Это главная страница блога (Blog:Name)
            $this->mWikilogName = $parts[0];
            $this->mItemName = null;
        }
        
        // Используем полный путь к классу или импортированный алиас
        $this->mWikilogTitle = Title::makeTitle( $title->getNamespace(), $this->mWikilogName );
    }

    public function isMain() {
        return $this->mItemName === null;
    }

    public function isItem() {
        return $this->mItemName !== null;
    }
}