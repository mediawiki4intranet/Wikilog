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
  SELECT wlp_page, CASE WHEN MAX( wlc_updated ) > wlp_updated THEN MAX( wlc_updated ) ELSE wlp_updated END, COUNT(1)
  FROM /*$wgDBprefix*/wikilog_posts
  LEFT JOIN /*$wgDBprefix*/wikilog_comments ON wlc_post = wlp_page
  GROUP BY wlp_page;
