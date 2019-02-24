-- Tables used by the MediaWiki Wikilog extension -- PostgreSQL
--
-- You should not have to create these tables manually unless you are doing
-- a manual installation. In normal conditions, maintenance/update.php should
-- perform any needed database setup.
--

--
-- All existing wikilogs and associated metadata.
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_wikilogs (
  -- Primary key, reference to wikilog front page article.
  wlw_page INT NOT NULL,

  -- Serialized PHP object representing the wikilog description or subtitle.
  wlw_subtitle TEXT NOT NULL,

  -- Image that provides iconic visual identification of the feed.
  wlw_icon VARCHAR(255) NOT NULL,

  -- Image that provides visual identification of the feed.
  wlw_logo VARCHAR(255) NOT NULL,

  -- Serialized PHP array of authors.
  wlw_authors TEXT NOT NULL,

  -- Last time the wikilog (including posts) was updated.
  wlw_updated TIMESTAMPTZ NOT NULL,

  PRIMARY KEY (wlw_page)

) /*$wgDBTableOptions*/;

CREATE INDEX /*$wgDBprefix*/wikilog_wikilogs_wlw_updated ON /*$wgDBprefix*/wikilog_wikilogs (wlw_updated);

--
-- All wikilog posts and associated metadata.
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_posts (
  -- Primary key, reference to wiki article associated with this post.
  wlp_page INT NOT NULL,

  -- Parent wikilog.
  wlp_parent INT NOT NULL,

  -- Post title derived from page(page_title), in order to simplify indexing.
  wlp_title TEXT NOT NULL,

  -- Either if the post was published or not.
  wlp_publish BOOLEAN NOT NULL DEFAULT FALSE,

  -- If wlp_publish = TRUE, this is the date that the post was published,
  -- otherwise, it is the date of the last draft revision (for sorting).
  wlp_pubdate TIMESTAMPTZ NOT NULL,

  -- Last time the post was updated.
  wlp_updated TIMESTAMPTZ NOT NULL,

  -- Serialized PHP array of authors.
  wlp_authors TEXT NOT NULL,

  -- Serialized PHP array of tags.
  wlp_tags TEXT NOT NULL,

  PRIMARY KEY (wlp_page)
) /*$wgDBTableOptions*/;

CREATE INDEX /*$wgDBprefix*/wikilog_posts_wlp_parent ON /*$wgDBprefix*/wikilog_posts (wlp_parent);
CREATE INDEX /*$wgDBprefix*/wikilog_posts_wlp_title ON /*$wgDBprefix*/wikilog_posts (wlp_title);
CREATE INDEX /*$wgDBprefix*/wikilog_posts_wlp_pubdate ON /*$wgDBprefix*/wikilog_posts (wlp_pubdate);
CREATE INDEX /*$wgDBprefix*/wikilog_posts_wlp_updated ON /*$wgDBprefix*/wikilog_posts (wlp_updated);

--
-- Last visit dates for some Wiki pages (Wikilog posts and comments)
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/page_last_visit (
  pv_user INT NOT NULL,
  pv_page INT NOT NULL,
  pv_date TIMESTAMPTZ NOT NULL,
  PRIMARY KEY (pv_user, pv_page)
) /*$wgDBTableOptions*/;

--
-- User subscriptions to posts (like to forum topics)
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_subscriptions (
  ws_user INT NOT NULL,
  ws_page INT NOT NULL,
  ws_yes  BOOLEAN NOT NULL,
  ws_date TIMESTAMPTZ NOT NULL,
  PRIMARY KEY (ws_user, ws_page)
) /*$wgDBTableOptions*/;

--
-- Authors of each post.
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_authors (
  -- Reference to post wiki article which this author is associated to.
  wla_page INT NOT NULL,

  -- ID of the author of the post.
  wla_author INT NOT NULL,

  -- Name of the author of the post.
  wla_author_text VARCHAR(255) NOT NULL,

  PRIMARY KEY (wla_page, wla_author_text)
) /*$wgDBTableOptions*/;

CREATE INDEX /*$wgDBprefix*/wikilog_authors_wla_page ON /*$wgDBprefix*/wikilog_authors (wla_page);
CREATE INDEX /*$wgDBprefix*/wikilog_authors_wla_author ON /*$wgDBprefix*/wikilog_authors (wla_author);
CREATE INDEX /*$wgDBprefix*/wikilog_authors_wla_author_text ON /*$wgDBprefix*/wikilog_authors (wla_author_text);

--
-- Tags associated with each post.
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_tags (
  -- Reference to post wiki article which this tag is associated to.
  wlt_page INT NOT NULL,

  -- Tag associated with the post.
  wlt_tag VARCHAR(255) NOT NULL,

  PRIMARY KEY (wlt_page, wlt_tag)
) /*$wgDBTableOptions*/;

CREATE INDEX /*$wgDBprefix*/wikilog_tags_wlt_tag ON /*$wgDBprefix*/wikilog_tags (wlt_tag);

--
-- Post comments.
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_comments (
  -- Unique comment identifier, across the whole wiki.
  wlc_id SERIAL NOT NULL,

  -- Parent comment, for threaded discussion. NULL for top-level comments.
  wlc_parent INT,

  -- Thread history, used for sorting. An array of wlc_id values of all parent
  -- comments up to and including the current comment. Each id is padded with
  -- zeros to six digits ("000000") and joined with slashes ("/").
  wlc_thread VARCHAR(255) NOT NULL DEFAULT '',

  -- Reference to post wiki article which this comment is associated to.
  wlc_post INT NOT NULL,

  -- ID of the author of the comment, if a registered user.
  wlc_user INT NOT NULL,

  -- Name of the author of the comment.
  wlc_user_text VARCHAR(255) NOT NULL,

  -- Name used for anonymous (not logged in) posters.
  wlc_anon_name VARCHAR(255),

  -- Comment status. For hidden or deleted comments, a placeholder is left
  -- with some description about what happened to the comment.
  -- 'OK'      -- OK, comment is visible
  -- 'PENDING' -- Comment is pending moderation
  -- 'DELETED' -- Comment was deleted
  wlc_status VARCHAR(7) NOT NULL DEFAULT 'OK',

  -- Date and time the comment was first posted.
  wlc_timestamp TIMESTAMPTZ NOT NULL,

  -- Date and time the comment was edited for the last time.
  wlc_updated TIMESTAMPTZ NOT NULL,

  -- Wiki article that contains this comment, to allow editing, revision
  -- history and more. This should be joined with `page` and `text` to get
  -- the actual comment text.
  wlc_comment_page INT,

  PRIMARY KEY (wlc_id)
) /*$wgDBTableOptions*/;

CREATE INDEX /*$wgDBprefix*/wikilog_comments_wlc_post_thread ON /*$wgDBprefix*/wikilog_comments (wlc_post, wlc_thread);
CREATE INDEX /*$wgDBprefix*/wikilog_comments_wlc_timestamp ON /*$wgDBprefix*/wikilog_comments (wlc_timestamp);
CREATE INDEX /*$wgDBprefix*/wikilog_comments_wlc_updated ON /*$wgDBprefix*/wikilog_comments (wlc_updated);
CREATE INDEX /*$wgDBprefix*/wikilog_comments_wlc_comment_page ON /*$wgDBprefix*/wikilog_comments (wlc_comment_page);

--
-- Page talk info, abstracted away from wikilog post concept
--
CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/wikilog_talkinfo (
  -- Primary key, reference to wiki article
  wti_page INT NOT NULL,

  -- Timestamp of last comment.
  wti_talk_updated TIMESTAMPTZ NOT NULL,

  -- Cached number of comments.
  wti_num_comments INT NOT NULL,

  PRIMARY KEY (wti_page)
) /*$wgDBTableOptions*/;
