<?php

if ( !defined( 'MEDIAWIKI' ) )
	die();

class WikilogCommentPagerSwitcher {
	const PT_THREAD = 'thread';
	const PT_LIST   = 'list';

	protected static $ptClasses = array(
		self::PT_THREAD => 'WikilogCommentThreadPager',
		self::PT_LIST   => 'WikilogCommentListPager',
	);
    protected static $ptIsSet = false;

	public static function getType() {
		$info = static::getInfo();
		return $info['type'];
	}

	public static function getClass() {
		return static::$ptClasses[static::getType()];
	}

	public static function checkType() {
		if ( !empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$info = static::getInfo();
			$msince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
			if ( !$info || !isset( $info['time'] ) || ( $info['time'] > $msince ) ) {
				unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
			}
		}
	}

	public static function setType( $type = self::PT_THREAD ) {
		if (self::$ptIsSet) {
			return;
		}
		global $wgRequest;
		$id = static::getPageId();
		if ( !isset( static::$ptClasses[$type] ) ) {
			$type = static::PT_THREAD;
		}
		$types = $wgRequest->getSessionData( 'wikilog-comments-pager-type' );
		if ( !$types || !is_array($types) ) {
			$types = array();
		}
		$types[$id] = array (
			'type' => $type,
			'time' => time(),
		);
		$wgRequest->setSessionData( 'wikilog-comments-pager-type', $types );
		self::$ptIsSet = true;

		unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
	}

	protected static function getPageId() {
		global $wgTitle;
		$wi = Wikilog::getWikilogInfo($wgTitle);
		if ( $wi ) {
			$id = $wi->mItemTitle->getArticleID();
		} else {
			$id = $wgTitle->getArticleID();
		}
		return $id;
	}

	protected static function getInfo() {
		global $wgRequest;
		$id = static::getPageId();
		$types = $wgRequest->getSessionData( 'wikilog-comments-pager-type' );
		if ( !$types || !is_array($types) || !isset($types[$id])  || !isset($types[$id]['type']) ) {
			static::setType();
			$types = $wgRequest->getSessionData( 'wikilog-comments-pager-type' );
		}
		return $types[$id];
	}
}
