<?php
/**
 * Special page aliases used by Wikilog extension.
 *
 * @file
 * @ingroup Extensions
 */

$specialPageAliases = array();

/** English (English)
 * @author Juliano F. Ravasi
 */
$specialPageAliases['en'] = array(
	'Wikilog' => array( 'Wikilog', 'Wikilogs' ),
);

/** Galician (Galego) */
$specialPageAliases['gl'] = array(
	'Wikilog' => array( 'Wikilog' ),
);

/** Japanese (日本語) */
$specialPageAliases['ja'] = array(
	'Wikilog' => array( 'ウィキ記録' ),
);

/** Malayalam (മലയാളം) */
$specialPageAliases['ml'] = array(
	'Wikilog' => array( 'വിക്കിരേഖ', 'വിക്കിരേഖകൾ' ),
);

/** Portuguese (Português) */
$specialPageAliases['pt'] = array(
	'Wikilog' => array( 'Wikilog', 'Wikilogs' ),
);

/**
 * For backwards compatibility with MediaWiki 1.15 and earlier.
 */
$aliases =& $specialPageAliases;