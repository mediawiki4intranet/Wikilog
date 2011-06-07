<?php

# blogger.com import for Wikilog
# License: GPL v2 or later
# Copyright (c) 2010 Vitaliy Filippov

class WikilogBloggerImport
{
    static function parse_blogger_xml($str, $params = array())
    {
        $dbw = wfGetDB(DB_MASTER);
        $comment_ai = $dbw->selectField('wikilog_comments', 'MAX(wlc_id)', '1')+1;

        /* Default parameter values */
        global $wgContLang;
        $params += array(
            'blog' => '',
            'ns_blog' => $wgContLang->getNsText(NS_BLOG & ~1),
            'ns_blog_talk' => $wgContLang->getNsText(NS_BLOG | 1),
            'users' => array(),
        );

        /* Slurp XML using SimpleXML */
        $xml = simplexml_load_string(str_replace(array('<thr:in-reply-to', '</thr:in-reply-to'), array('<inreplyto', '</inreplyto'), $str));
        if (!$xml)
            return NULL;
        $out = array();

        /* Extract blog parameters */
        foreach ($xml->link as $l)
            if ($l['rel'] == 'alternate')
                $out['old_link'] = ''.$l['href'];
        $out['title'] = ''.$xml->title;
        $out['author'] = $params['users'][''.$xml->author->name] ? $params['users'][''.$xml->author->name] : 'WikiSysop';

        /* Initialize output arrays */
        $out['page'] = array();
        $out['wikilog_comments'] = array();
        $out['rewrite'] = array(array($out['old_link'], $params['ns_blog'].':'.$params['blog']));
        $refs = array();
        foreach ($xml->entry as $e)
        {
            $id = "".$e->id;
            if (strpos($id, '.post-'))
            {
                $ns = $params['ns_blog'];
                /* Extract values */
                $ts = strtotime($e->published);
                $title = str_replace(array('[', ']', '|'), array('(', ')', '-'), $params['blog']."/".date("Y-m-d ", $ts).$e->title);
                $content = HtmlToMediaWiki::html2wiki($e->content);
                $content = preg_replace('#<div class="blogger-post-footer">.*?</div>#is', '', $content);
                $old_link = '';
                foreach ($e->link as $l)
                    if ($l['rel'] == 'alternate')
                        $old_link = ''.$l['href'];
                /* Extract user */
                if (!($user = $params['users'][''.$e->author->name]))
                {
                    /* change user to default WikiSysop and append user identity to content */
                    $user = 'WikiSysop';
                    $l = $e->author->name;
                    $content = ($e->author->uri ? "[".$e->author->uri." $l]: " : "$l: ") . $content;
                }
                /* Append categories (tags) */
                foreach ($e->category as $cat)
                    if (substr($cat['term'], 0, 26) != 'http://schemas.google.com/')
                        $content .= "\n[[Category:".$cat['term']."]]";
                /* Append publication mark */
                $content .= "\n{{wl-publish: ".preg_replace('/([\d-]+)T([\d:]+)[\d\.]*([^:]*):([^:]*)/', '\1 \2 \3\4', $e->published)." | $user}}";
                $refs[$id] = array('title' => $title);
                if ($e->inreplyto)
                {
                    /* Is a comment to post with id=$ref: */
                    $ref = "".$e->inreplyto['ref'];
                    $title = $refs[$ref]['title'] . '/c' . sprintf("%06d", $comment_ai);
                    /* Remember comment parents */
                    $refs[$id] = array(
                        'title' => $refs[$ref]['title'],
                        'wlc_id' => $comment_ai,
                        'thread' => trim($refs[$ref]['thread'] . '/' . sprintf("%06d", $comment_ai), '/'),
                    );
                    $ns = $params['ns_blog_talk'];
                    /* Add comment row */
                    $out['wikilog_comments'][] = array(
                        'wlc_id'            => $comment_ai,
                        'wlc_parent'        => $refs[$ref]['wlc_id'] ? $refs[$ref]['wlc_id'] : NULL,
                        'wlc_thread'        => $refs[$id]['thread'],
                        'wlc_post'          => $params['ns_blog'].':'.$refs[$ref]['title'],
                        'wlc_user_text'     => $user,
                        'wlc_status'        => 'OK',
                        'wlc_timestamp'     => gmdate("YmdHis", $ts),
                        'wlc_updated'       => gmdate("YmdHis", $ts),
                        'wlc_comment_page'  => "$ns:$title",
                    );
                    $comment_ai++;
                }
                if ($old_link)
                    $out['rewrite'][] = array($old_link, "$ns:$title");
                /* Add page row */
                $out['page'][] = array(
                    'title' => "$ns:$title",
                    'timestamp' => gmdate("YmdHis", $ts),
                    'author' => $user,
                    'text' => $content,
                );
            }
        }

        return $out;
    }

    static function import_parsed_blogger($out)
    {
        $dbw = wfGetDB(DB_MASTER);
        $pageids = array();
        $users = array();
        /* Import pages and record their IDs */
        foreach ($out['page'] as $page)
        {
            $title = Title::newFromText($page['title']);
            if (!$title)
                die("Invalid title: $page[title]");
            /* Create article */
            $article = new Article($title);
            if (!$users[$page['author']])
                $users[$page['author']] = User::newFromName($page['author']);
            $user = $users[$page['author']];
            $flags = EDIT_FORCE_BOT;
            $status = Status::newGood(array());
            $summary = "Import Blogger entry";
            if (!$article->getId())
                $article->insertOn($dbw);
            $pageids[$page['title']] = $article->getId();
            /* Create revision */
            $revision = new Revision(array(
                'page'       => $article->getId(),
                'text'       => $page['text'],
                'comment'    => $summary,
                'user'       => $user->getId(),
                'user_text'  => $page['author'],
                'timestamp'  => $page['timestamp'],
                'minor_edit' => 0,
            ));
            $revId = $revision->insertOn($dbw);
            $changed = $article->updateIfNewerOn($dbw, $revision);
            /* Run ArticleSaveComplete hook */
            wfRunHooks('ArticleSaveComplete', array(&$article, &$user, &$page['text'], &$summary,
                0, NULL, NULL, &$flags, &$revision, &$status));
            /* Run ArticleEditUpdates hooks */
            $article->mPreparedEdit = $article->prepareTextForEdit($page['text'], $revId);
            $article->editUpdates($page['text'], $summary, false, $page['timestamp'], $revId, true);
        }
        /* Import Wikilog comment rows */
        foreach ($out['wikilog_comments'] as &$c)
        {
            $c['wlc_comment_page'] = $pageids[$c['wlc_comment_page']];
            $c['wlc_post'] = $pageids[$c['wlc_post']];
            $c['wlc_user'] = $users[$c['wlc_user_text']]->getId();
        }
        $dbw->insert('wikilog_comments', $out['wikilog_comments']);
        return $pageids;
    }
}
