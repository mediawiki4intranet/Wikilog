--
-- Last visit dates for some Wiki pages (Wikilog posts and comments)
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/page_last_visit (
  pv_user INTEGER UNSIGNED NOT NULL,
  pv_page INTEGER UNSIGNED NOT NULL,
  pv_date BINARY(14) NOT NULL,
  PRIMARY KEY (pv_user, pv_page)
) /*$wgDBTableOptions*/;
