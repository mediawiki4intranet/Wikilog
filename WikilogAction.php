<?php
use MediaWiki\Actions\Action;

class WikilogAction extends Action {
    public function getName() {
        return 'wikilog';
    }
    public function requiresUnblock() {
        return false;
    }
    public function requiresWrite() {
        return false;
    }
    public function getDescription() {
        return wfMessage( 'wikilog-tab-title' )->text();
    }
    public function show() {
        $article = $this->getArticle();
        if ( $article instanceof WikilogCustomAction ) {
            $article->wikilog();
        } else {
            $this->getOutput()->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
        }
    }
}
