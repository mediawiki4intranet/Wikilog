<?php

if ($argv[1] == '-h' || $argv[1] == '--help')
{
    print "Simple HTML to MediaWiki converter\nUSAGE: php html2mw.php < INPUT_FILE > OUTPUT_FILE\n";
    exit;
}

require_once 'HtmlToMediaWiki.php';
$html = '';
while (!feof(STDIN))
    $html .= fread(STDIN, 65536);
if (!strlen($html))
{
    print "Empty input. Run php html2mw.php --help for usage\n";
    exit;
}
print HtmlToMediaWiki::html2wiki($html);
