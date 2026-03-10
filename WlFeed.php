<?php
if ( !defined( 'MEDIAWIKI' ) )
	die();

/**
 * Main WlFeed class.
 */
class WlFeed
{

	/**
	 * Configuration: Override default MediaWiki syndication classes. If set
	 * to true, default feed classes defined in $wgFeedClasses global will
	 * be overriden with compatibility classes of WlFeed. This causes all
	 * MediaWiki feeds (Special:Recentchanges, page history, etc) to be
	 * served through this extension.
	 *
	 * Use with caution. WlFeed classes have some differences from system
	 * feed classes, for example, regarding to ctype= and quirks= parameters
	 * and caching.
	 */
	static public $cfgOverride = false;

	/**
	 * System class equivalence.
	 */
	static private $classEquiv = array(
		'AtomFeed' => 'WlAtomFeedCompat',
		'RSSFeed' => 'WlRSSFeedCompat'
	);

	/**
	 * Extension setup function.
	 */
	static function ExtensionInit() {
		# Override system feeds.
		if ( self::$cfgOverride ) {
			global $wgFeedClasses;
			foreach ( $wgFeedClasses as $t => $c ) {
				if ( isset( self::$classEquiv[$c] ) ) {
					$wgFeedClasses[$t] = self::$classEquiv[$c];
				}
			}
		}
	}
}
