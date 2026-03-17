<?php
use MediaWiki\Title\Title;

if ( !defined( 'MEDIAWIKI' ) )
	die();

/**
 * Singleton class that provides an interface to Captchas implemented
 * through the ConfirmEdit extension. Only Captchas derived from
 * SimpleCaptcha can be used.
 */
class WlCaptcha
{
	public static $instance = null;
	public static $initialized = false;

	public static function getInstance() {
		if ( !self::$initialized ) {
			self::$initialized = true;
			if ( class_exists( 'ConfirmEditHooks' ) ) {
				$captcha = ConfirmEditHooks::getInstance();
				if ( $captcha instanceof SimpleCaptcha ) {
					self::$instance = new WlCaptchaAdapter( $captcha );
				} else {
					$classname = get_class( $captcha );
					trigger_error( "Only captchas derived from SimpleCaptcha " .
						"are compatible with Wikilog. The current captcha, " .
						"{$classname}, isn't compatible.", E_USER_WARNING );
				}
			}
		}
		return self::$instance;
	}

	public static function confirmEdit( $title, $newText, $oldText = null ) {
		$captcha = self::getInstance();
		if ( $captcha ) {
			return $captcha->confirmEdit( $title, $newText, $oldText );
		} else {
			return true;
		}
	}

	public static function getCaptchaForm() {
		$captcha = self::getInstance();
		if ( $captcha ) {
			return $captcha->getCaptchaForm();
		} else {
			return false;
		}
	}

}

/**
 * Adapter for SimpleCaptcha derived classes.
 *
 * ConfirmEdit depends on the EditPage interface and hooks, that are not used
 * by Wikilog. This class creates a more generic interface for SimpleCaptcha,
 * allowing it to be used to verify wikilog comments.
 *
 * A lot of code is duplicated and adapted from the original SimpleCaptcha
 * class, Copyright (C) 2005-2007 Brion Vibber.
 */
class WlCaptchaAdapter
{
	public $mCaptcha;

	public function __construct( &$captcha ) {
		$this->mCaptcha = $captcha;
	}

	public function confirmEdit( $title, $newText, $oldText = null ) {
		return $this->doConfirmEdit( $title, $newText, $oldText );
	}

	private function doConfirmEdit( $title, $newText, $oldText = null ) {
		if ( $this->shouldCheck( $title, $newText, $oldText ) ) {
			if ( method_exists( $this->mCaptcha, 'passCaptchaFromRequest' ) ) {
				global $wgRequest, $wgUser;
				return $this->mCaptcha->passCaptchaFromRequest( $wgRequest, $wgUser );
			}
			return $this->mCaptcha->passCaptcha();
		} else {
			wfDebug( "WlCaptchaAdapter: no need to show captcha.\n" );
			return true;
		}
	}

	private function shouldCheck( $title, $newText, $oldText = null ) {
		global $wgUser, $wgCaptchaWhitelistIP, $wgCaptchaRegexes;
		global $wgEmailAuthentication, $ceAllowConfirmedEmail;

		if ( $wgUser->isAllowed( 'skipcaptcha' ) ) {
			wfDebug( "WlCaptchaAdapter: user group allows skipping captcha\n" );
			return false;
		}

		if ( !empty( $wgCaptchaWhitelistIP ) ) {
			$ip = wfGetIp();
			foreach ( $wgCaptchaWhitelistIP as $range ) {
				if ( IP::isInRange( $ip, $range ) ) {
					return false;
				}
			}
		}

		if ( $wgEmailAuthentication && $ceAllowConfirmedEmail &&
			$wgUser->isEmailConfirmed() ) {
			wfDebug( "WlCaptchaAdapter: user has confirmed mail, skipping captcha\n" );
			return false;
		}

		if ( $this->captchaTriggers( $title, 'edit' ) ) {
			$this->mCaptcha->trigger = sprintf( "Edit trigger by '%s' at [[%s]]",
				$wgUser->getName(), $title->getPrefixedText() );
			$this->mCaptcha->action = 'edit';
			wfDebug( "WlCaptchaAdapter: checking all edits...\n" );
			return true;
		}

		if ( $this->captchaTriggers( $title, 'create' ) && is_null( $oldText ) ) {
			$this->mCaptcha->trigger = sprintf( "Create trigger by '%s' at [[%s]]",
				$wgUser->getName(), $title->getPrefixedText() );
			$this->mCaptcha->action = 'create';
			wfDebug( "WlCaptchaAdapter: checking on page creation...\n" );
			return true;
		}

		if ( $this->captchaTriggers( $title, 'addurl' ) ) {
			$oldLinks = $this->findLinks( $title, $oldText );
			$newLinks = $this->findLinks( $title, $newText );
			$unknownLinks = array_filter( $newLinks, array( &$this->mCaptcha, 'filterLink' ) );
			$addedLinks = array_diff( $unknownLinks, $oldLinks );
			$numLinks = count( $addedLinks );
			if ( $numLinks > 0 ) {
				$this->mCaptcha->trigger = sprintf( "%dx url trigger by '%s' at [[%s]]: %s",
					$numLinks, $wgUser->getName(), $title->getPrefixedText(),
					implode( ", ", $addedLinks ) );
				$this->mCaptcha->action = 'addurl';
				return true;
			}
		}

		if ( !empty( $wgCaptchaRegexes ) ) {
			foreach ( $wgCaptchaRegexes as $regex ) {
				$newMatches = array();
				if ( preg_match_all( $regex, $newtext, $newMatches ) ) {
					$oldMatches = array();
					preg_match_all( $regex, $oldtext, $oldMatches );
					$addedMatches = array_diff( $newMatches[0], $oldMatches[0] );
					$numHits = count( $addedMatches );
					if ( $numHits > 0 ) {
						$this->mCaptcha->trigger = sprintf( "%dx %s trigger by '%s' at [[%s]]: %s",
							$numHits, $regex, $wgUser->getName(), $title->getPrefixedText(),
							implode( ", ", $addedMatches ) );
						$this->mCaptcha->action = 'edit';
						return true;
					}
				}
			}
		}

		return false;
	}

	private function captchaTriggers( $title, $action ) {
		global $wgCaptchaTriggers, $wgCaptchaTriggersOnNamespace;
		if ( isset( $wgCaptchaTriggersOnNamespace[$title->getNamespace()][$action] ) )
			return $wgCaptchaTriggersOnNamespace[$title->getNamespace()][$action];
		return ( !empty( $wgCaptchaTriggers[$action] ) );
	}

	private function findLinks( $title, $text ) {
		global $wgUser;
		$parser = \MediaWiki\MediaWikiServices::getInstance()->getParser();
		if ( $text ) {
			$options = new ParserOptions();
			$text = $parser->preSaveTransform( $text, $title, $wgUser, $options );
			$out = $parser->parse( $text, $title, $options );
			return array_keys( $out->getExternalLinks() );
		} else {
			return array();
		}
	}

	public function getCaptchaForm() {
		global $wgOut;
		return '<div class="captcha">' .
			$wgOut->parse( $this->mCaptcha->getMessage( $this->mCaptcha->action ) ) .
			$this->mCaptcha->getForm() .
			'</div>';
	}
}
