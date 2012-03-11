--
-- Page talk info, abstracted away from wikilog post concept
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_talkinfo (
  -- Primary key, reference to wiki article
  wti_page INTEGER UNSIGNED NOT NULL,
  -- Timestamp of last comment.
  wti_talk_updated BINARY( 14 ) NOT NULL,
  -- Cached number of comments.
  wti_num_comments INTEGER UNSIGNED,
  PRIMARY KEY ( wti_page )
) /*$wgDBTableOptions*/;

INSERT INTO /*$wgDBprefix*/wikilog_talkinfo ( wti_page, wti_talk_updated, wti_num_comments )
  SELECT wlp_page, wlp_talk_updated, wlp_num_comments FROM /*$wgDBprefix*/wikilog_posts;
