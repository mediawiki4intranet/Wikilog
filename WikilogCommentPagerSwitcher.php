<?php

if ( !defined( 'MEDIAWIKI' ) )
	die();

class WikilogCommentPagerSwitcher {
	public static function getType( Title $subject ) {
		$info = static::getInfo( $subject );
		return $info ? $info['type'] : 'thread';
	}

	public static function checkType( Title $subject ) {
		if ( !empty( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$info = static::getInfo( $subject );
			$msince = strtotime( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
			if ( $info && ( !isset( $info['time'] ) || $info['time'] > $msince ) ) {
				unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
			}
		}
	}

	public static function setType( Title $subject, $type ) {
		global $wgRequest;
		$id = $subject ? $subject->getArticleId() : 0;
		$types = $wgRequest->getSessionData( 'wikilog-comments-pager-type' );
		if ( !$types || !is_array( $types ) ) {
			$types = array();
		}
		$types[$id] = array(
			'type' => $type,
			'time' => time(),
		);
		$wgRequest->setSessionData( 'wikilog-comments-pager-type', $types );
		unset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
	}

	protected static function getInfo( Title $subject ) {
		global $wgRequest;
		$id = $subject ? $subject->getArticleId() : 0;
		$types = $wgRequest->getSessionData( 'wikilog-comments-pager-type' );
		if ( !$types || !is_array( $types ) || !isset( $types[$id] )  || !isset( $types[$id]['type'] ) ) {
			return NULL;
		}
		return $types[$id];
	}
}
