<?php

$dir = dirname(__FILE__);
require_once("$dir/../../../maintenance/commandLine.inc");

echo "Wikilog database patch: wikilog_posts.wlp_talk_updated\n";
$dbw = wfGetDB(DB_MASTER);
$P = $dbw->tablePrefix();
if (!$dbw->fieldExists('wikilog_posts', 'wlp_talk_updated'))
{
    $dbw->query("ALTER TABLE `${P}wikilog_posts` ADD `wlp_talk_updated` BINARY(14) NOT NULL AFTER `wlp_updated`");
    $dbw->query("UPDATE `${P}wikilog_posts` SET `wlp_talk_updated`=IFNULL((SELECT MAX(wlc_timestamp) FROM wikilog_comments WHERE wlc_post=wlp_page), `wlp_pubdate`)");
}
