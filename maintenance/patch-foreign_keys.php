<?php

$dir = dirname(__FILE__);
require_once("$dir/../../../maintenance/commandLine.inc");

echo "Wikilog database patch: add foreign keys\n";
$dbw = wfGetDB(DB_MASTER);
$P = $dbw->tablePrefix();
$r = $dbw->query("SHOW CREATE TABLE `${P}wikilog_comments`");
$r = $dbw->fetchRow($r);
$r = $r[1];
if (!strpos($r, 'wikilog_comments_wlc_post_page_id'))
{
    $dbw->query("DELETE FROM `${P}wikilog_comments` WHERE (SELECT page_id FROM `${P}page` WHERE page_id=wlc_post) IS NULL");
    $dbw->query("ALTER TABLE `${P}wikilog_comments` ADD CONSTRAINT wikilog_comments_wlc_post_page_id FOREIGN KEY (wlc_post) REFERENCES `${P}page` (page_id) ON DELETE CASCADE ON UPDATE CASCADE");
}
if (!strpos($r, 'wikilog_comments_wlc_comment_page_page_id'))
{
    $dbw->query("DELETE FROM `${P}wikilog_comments` WHERE (SELECT page_id FROM `${P}page` WHERE page_id=wlc_comment_page) IS NULL");
    $dbw->query("ALTER TABLE `${P}wikilog_comments` ADD CONSTRAINT wikilog_comments_wlc_comment_page_page_id FOREIGN KEY (wlc_comment_page) REFERENCES `${P}page` (page_id) ON DELETE CASCADE ON UPDATE CASCADE");
}
$r = $dbw->query("SHOW CREATE TABLE `${P}wikilog_posts`");
$r = $dbw->fetchRow($r);
$r = $r[1];
if (!strpos($r, 'wikilog_posts_wlp_page_page_id'))
{
    $dbw->query("DELETE FROM `${P}wikilog_posts` WHERE (SELECT page_id FROM `${P}page` WHERE page_id=wlp_page) IS NULL");
    $dbw->query("ALTER TABLE `${P}wikilog_posts` ADD CONSTRAINT wikilog_posts_wlp_page_page_id FOREIGN KEY (wlp_page) REFERENCES `${P}page` (page_id) ON DELETE CASCADE ON UPDATE CASCADE");
}
