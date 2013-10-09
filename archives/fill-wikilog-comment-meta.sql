UPDATE /*$wgDBprefix*/wikilog_comments w JOIN /*$wgDBprefix*/page p JOIN /*$wgDBprefix*/revision r JOIN /*$wgDBprefix*/text t
    LEFT JOIN (/*$wgDBprefix*/wikilog_comments pc JOIN /*$wgDBprefix*/page pp ON pp.page_id=pc.wlc_comment_page) ON pc.wlc_id=w.wlc_parent
SET t.old_text=CONCAT(t.old_text, '\n{{wl-comment: ', IFNULL(pp.page_title, ''),
    CASE WHEN w.wlc_anon_name IS NULL OR w.wlc_anon_name='' THEN '' ELSE CONCAT(' | ', w.wlc_anon_name) END, '}}')
WHERE w.wlc_comment_page=p.page_id AND r.rev_id=p.page_latest AND r.rev_text_id=t.old_id AND t.old_text NOT LIKE '%{{wl-comment: %';
