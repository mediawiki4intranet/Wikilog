--
-- User subscriptions to blog articles
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_blog_subscriptions (
  ws_user INTEGER UNSIGNED NOT NULL,
  ws_page INTEGER UNSIGNED NOT NULL,
  ws_yes  TINYINT(1) NOT NULL,
  ws_date BINARY(14) NOT NULL,
  PRIMARY KEY (ws_user, ws_page)
) /*$wgDBTableOptions*/;
