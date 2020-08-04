<?php

# Wikilog Calendar
# Календарь для расширения Wikilog
# Copyright (c) Vitaliy Filippov, 2010+

class WikilogCalendar
{
    /* Weekday number (0-6) for the given UNIX time */
    static function weekday($ts)
    {
        global $wgWikilogWeekStart;
        if (!$wgWikilogWeekStart)
            $wgWikilogWeekStart = 0;
        return (date('N', $ts) + 6 - $wgWikilogWeekStart) % 7;
    }
    /* Next month */
    static function nextMonth($m)
    {
        if (0+substr($m, 4, 2) < 12)
            return substr($m, 0, 4) . sprintf("%02d", substr($m, 4, 2)+1);
        return (substr($m, 0, 4) + 1) . '01';
    }
    /* Previous month */
    static function prevMonth($m)
    {
        if (0+substr($m, 4, 2) > 1)
            return substr($m, 0, 4) . sprintf("%02d", substr($m, 4, 2)-1);
        return (substr($m, 0, 4) - 1) . '12';
    }
    /* Month and year name */
    static function monthName($month)
    {
        global $wgContLang;
        return $wgContLang->getMonthName(0+substr($month, 4, 2)).' '.substr($month, 0, 4);
    }
    /* Make HTML code for a multiple month calendar */
    static function makeCalendar($dates, $pager)
    {
        if (!$dates)
            return '';
        $months = array();
        foreach ($dates as $k => $d)
        {
            $m = substr($k, 0, 6);
            $months[$m] = true;
        }
        krsort($months);
        $months = array_keys($months);
        $html = '';
        foreach ($months as $m)
            $html .= self::makeMonthCalendar($m, $dates);
        /* append paging links */
        $links = self::makePagingLinks($months, $pager);
        $html = $links . $html . $links;
        return $html;
    }
    /* Make HTML code for paging links */
    static function makePagingLinks($months, $pager)
    {
        if (!empty($pager->mIsFirst) && !empty($pager->mIsLast))
            return '';
        $urlLimit = $pager->mLimit == $pager->mDefaultLimit ? '' : $pager->mLimit;
        if (!empty($pager->mIsFirst))
            $next = false;
        else
            $next = array('dir' => 'prev', 'offset' => ($nextmonth = self::nextMonth($months[0])).'01000000', 'limit' => $urlLimit);
        if (!empty($pager->mIsLast))
            $prev = false;
        else
            $prev = array('dir' => 'next', 'offset' => ($prevmonth = $months[count($months)-1]).'01000000', 'limit' => $urlLimit );
        $html = '<p class="wl-calendar-nav">';
        if ($prev)
            $html .= $pager->makeLink(wfMessage('wikilog-calendar-prev', self::monthName(self::prevMonth($prevmonth)))->text(), $prev, 'prev');
        if ($next)
            $html .= $pager->makeLink(wfMessage('wikilog-calendar-next', self::monthName($nextmonth))->text(), $next, 'next');
        $html .= '</p>';
        return $html;
    }
    /* Make HTML code for a single month calendar */
    static function makeMonthCalendar($month, $dates)
    {
        $max = self::nextMonth($month);
        $max = wfTimestamp(TS_UNIX, $max.'01000000')-86400;
        $max += 86400 * (6 - self::weekday($max));
        $min = wfTimestamp(TS_UNIX, $month.'01000000');
        $min -= 86400 * self::weekday($min);
        $html = '<table class="wl-calendar"><tr>';
        for ($ts = $min, $i = 0; $ts <= $max; $ts += 86400, $i++)
        {
            if ($i && !($i % 7))
                $html .= '</tr><tr>';
            $d = date('Ymd', $ts);
            $html .= '<td class="';
            if (substr($d, 0, 6) != $month)
                $html .= 'wl-calendar-other ';
            $html .= 'wl-calendar-day';
            if (!empty($dates[$d]))
                $html .= '"><a href="'.htmlspecialchars($dates[$d]['link']).'" title="'.htmlspecialchars($dates[$d]['title']).'">';
            else
                $html .= '-empty">';
            $html .= date('j', $ts);
            if (!empty($dates[$d]))
                $html .= '</a>';
            $html .= '</td>';
        }
        $html .= '</tr></table>';
        $html = '<p class="wl-calendar-month">'.self::monthName($month).'</p>' . $html;
        return $html;
    }
    /* Make HTML code for calendar for the given fucking query object */
    static function sidebarCalendar($pager)
    {
        global $wgRequest, $wgWikilogNumArticles;
        $dbr = wfGetDB(DB_REPLICA);
        // Make limit and offset work, but only in the terms of
        // selecting displayed MONTHS, not DATES. I.e. if there
        // are posts selected from 2011-01-15 to 2011-02-15,
        // make calendar for full january and february months,
        // not for the first half of february and second of january
        list($limit) = $wgRequest->getLimitOffset($wgWikilogNumArticles, '');
        $offset = $wgRequest->getVal('offset');
        $dir = $wgRequest->getVal('dir') == 'prev';
        // FIXME this is a problem: when sorted by a different field,
        // month limits may be very large... So, we are always sorting on wlp_pubdate.
        //$sort = $wgRequest->getVal('sort');
        //if (!in_array($sort, WikilogArchivesPager::$sortableFields))
        $sort = 'wlp_pubdate';
        // First limit is taken from the query
        $firstOffset = substr($offset, 0, -8); // allow 5-digit year O_O
        $firstOffset .= ($dir ? '01000000' : '31240000');
        // The second limit needs to be selected from the DB
        $sql = $pager->mQuery->selectSQLText($dbr,
            array(), 'wlp_pubdate',
            $offset ? array('wlp_pubdate' . ($dir ? '>' : '<') . $dbr->addQuotes($offset)) : array(),
            __METHOD__,
            array('LIMIT' => $limit, 'ORDER BY' => $sort . ($dir ? ' ASC' : ' DESC'))
        );
        $sql = "SELECT ".($dir ? 'MAX' : 'MIN')."(wlp_pubdate) o FROM ($sql) derived";
        $res = $dbr->query($sql, __METHOD__);
        $row = $res->fetchObject();
        if ($row)
        {
            $otherOffset = substr($row->o, 0, -8);
            $otherOffset .= ($dir ? '31240000' : '01000000');
        }
        // Then select posts grouped by date
        $where = array();
        if ($firstOffset)
            $where[] = 'wlp_pubdate' . ($dir ? '>' : '<') . $dbr->addQuotes($firstOffset);
        if ($otherOffset)
            $where[] = 'wlp_pubdate' . ($dir ? '<' : '>') . $dbr->addQuotes($otherOffset);
        $sql = $pager->mQuery->selectSQLText($dbr,
            array(), 'wikilog_posts.*', $where, __METHOD__,
            array('ORDER BY' => 'wlp_pubdate' . ($dir ? ' ASC' : ' DESC'))
        );
        // Count posts by dates
        $sql = "SELECT wlp_page, wlp_pubdate, COUNT(wlp_page) numposts FROM ($sql) derived GROUP BY SUBSTR(wlp_pubdate,1,8)";
        // Join dates having only one post to page table
        $t_page = $dbr->tableName('page');
        $sql = "SELECT * FROM ($sql) derived2 LEFT JOIN $t_page pp ON derived2.numposts=1 AND pp.page_id=derived2.wlp_page";
        // Build hash table based on date
        $sp = Title::newFromText('Special:Wikilog');
        $dates = array();
        $res = $dbr->query($sql, __METHOD__);
        foreach ($res as $row)
        {
            $date = substr($row->wlp_pubdate, 0, 8);
            if ($row->numposts == 1)
            {
                /* link to the post if it's the only one for that date */
                $title = Title::newFromRow($row);
                $dates[$date] = array(
                    'link'  => $title->getLocalUrl(),
                    'title' => $title->getPrefixedText(),
                );
            }
            else
            {
                /* link to archive page if there's more than one post for that date */
                $dates[$date] = array(
                    'link'  => $sp->getLocalUrl(array(
                        'view'  => 'archives',
                        'year'  => substr($date, 0, -4),
                        'month' => substr($date, 4, 2),
                        'day'   => substr($date, 6, 2),
                    )),
                    'title' => wfMessage('wikilog-calendar-archive-link-title',
                        $sp->getPrefixedText(),
                        date('Y-m-d', wfTimestamp(TS_UNIX, $row->wlp_pubdate))
                    )->parse(),
                );
            }
        }
        $dbr->freeResult($res);
        /* build calendar HTML code */
        $html = self::makeCalendar($dates, $pager);
        return $html;
    }
}
