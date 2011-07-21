<?php

# Simple converter from HTML to MediaWiki
# Handles paragraphs, preformatted text, lists, bold/italic
# Copyright (c) 2010 Vitaliy Filippov

class HtmlToMediaWiki
{
    /* Tag translation rules:
       empty        : true to copy empty tag as-is
       html         : true to copy tag as HTML code (<html>...</html>)
       prefix       : tag is prepended with this text, \1 removes all whitespace before itself
       notrim       : true to not trim tag contents
       pre          : true to copy tag contents as-is
       attr         : hash of xml attributes (name => 1) allowed to copy as-is (has effect only when replacebegin is clear)
       replacebegin : start tag is replaced with this text, attribute substitutions are allowed (like '$href')
       replaceend   : end tag is replaced with this text

       Additional rules:
       <br /><br /> -> \n\n
       <li>'s and <dt>'s replacebegin is changed on each entry/exit to/from <ol>/<ul>/<dl>
    */

    static $tags = array(
        'h1' => array('replacebegin' => "\1\n\n== ", 'replaceend' => " ==\n"),
        'h2' => array('replacebegin' => "\1\n\n=== ", 'replaceend' => " ===\n"),
        'h3' => array('replacebegin' => "\1\n\n==== ", 'replaceend' => " ====\n"),
        'h4' => array('replacebegin' => "\1\n\n===== ", 'replaceend' => " =====\n"),
        'h5' => array('replacebegin' => "\1\n\n====== ", 'replaceend' => " ======\n"),
        'br' => array('empty' => 1, 'attr' => array('style' => 1, 'clear' => 1, 'class' => 1)),
        'pre' => array('prefix' => "\1\n", 'notrim' => 1, 'pre' => 1, 'attr' => array('style' => 1, 'class' => 1)),
        'ul' => array('replacebegin' => "\1\n"),
        'ol' => array('replacebegin' => "\1\n"),
        'dl' => array('replacebegin' => "\1\n"),
        'li' => array('replacebegin' => "\1\n", 'disallow' => array('p' => 1, 'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6)),
        'dt' => array('replacebegin' => "\1\n", 'disallow' => array('p' => 1, 'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6)),
        'dd' => array('replacebegin' => ': ', 'disallow' => array('p' => 1, 'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6)),
        'p' => array('replacebegin' => "\1\n\n"),
        'a' => array('replacebegin' => '[$href ', 'replaceend' => ']', 'require' => 'HtmlToMediaWiki::checkHref'),
        'b' => array('replacebegin' => "'''", 'replaceend' => "'''"),
        'i' => array('replacebegin' => "''", 'replaceend' => "''"),
        'img' => array('html' => 1),
        'object' => array('prefix' => "\1\n\n", 'html' => 1),
        'iframe' => array('prefix' => "\1\n\n", 'html' => 1),
        'table' => array('html' => 1),
        'div' => array('attr' => array('style' => 1, 'class' => 1)),
        'blockquote' => array('prefix' => "\1\n", 'attr' => array('style' => 1, 'class' => 1)),
    );

    static function checkHref($e)
    {
        $h = $e->getAttribute('href');
        return strlen($h) && $h{0} != '#';
    }

    static function loadDOM($html)
    {
        $dom = new DOMDocument();
        $oe = error_reporting();
        error_reporting($oe & ~E_WARNING);
        $dom->loadHTML("<?xml version='1.0' encoding='UTF-8'?>".mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        error_reporting($oe);
        return $dom;
    }

    static function domnodeopen($e)
    {
        $s = '<'.$e->nodeName;
        if ($e->hasAttributes())
            foreach ($e->attributes as $a)
                if (self::$tags[$e->nodeName]['attr'][$a->name])
                    $s .= ' '.htmlspecialchars($a->name).'="'.htmlspecialchars($a->value).'"';
        return $s;
    }

    static function domattr_replace($m)
    {
        global $domattr_re;
        return $m[1] . $domattr_re->attributes->getNamedItem($m[2])->value;
    }

    static function domattr($e, $s)
    {
        global $domattr_re;
        if (is_string($s))
        {
            $domattr_re = $e;
            $s = preg_replace_callback('/([^\\\\]|^)\\$([a-z0-9]+)/is', 'HtmlToMediaWiki::domattr_replace', $s);
            return $s;
        }
    }

    static function domhtml($element)
    {
        $xml = $element->ownerDocument->saveXML($element, LIBXML_NOEMPTYTAG);
        $xml = preg_replace('#(<(br|input|img)(\s+[^<>]*[^/])?)></\2>#', '\1 />', $xml);
        return $xml;
    }

    static function dom2wiki($e, $disallow = array())
    {
        // Copy text nodes
        if ($e->nodeType == XML_TEXT_NODE)
        {
            $s = preg_replace('/\s+/', ' ', $e->nodeValue);
            // Temporarily commented out
            //if ($s == ' ')
            //    return '';
            return htmlspecialchars($s);
        }
        $t = self::$tags[$e->nodeName];
        // Return <html>...</html> for non-transformable nodes
        if ($t['html'])
            return ($t['prefix'] !== NULL ? $t['prefix'] : '').'<html>'.self::domhtml($e).'</html>';
        // For empty nodes: return '' or $replaceempty or node itself if $empty == true
        if (!$e->childNodes->length)
        {
            if ($disallow[$e->nodeName])
                return '';
            elseif ($s = $t['replaceempty'])
                return self::domattr($e, $s);
            elseif ($t['empty'])
                return self::domnodeopen($e) . ' />';
            else
                return '';
        }
        // Change <li> level prefix inside <ul>/<ol>
        $s = '';
        if (($n = $e->nodeName) == 'ol' || $n == 'ul' || $n == 'dl')
        {
            $old_r = self::$tags['li']['replacebegin'];
            self::$tags['li']['replacebegin'] =
                $old_r.($n == 'ol' ? '#' : ($n == 'dl' ? ':' : '*')).' ';
            self::$tags['dt']['replacebegin'] =
                $old_r.($n == 'ol' ? '#' : ($n == 'dl' ? ';' : '*')).' ';
        }
        // Transform children of normal nodes
        if (!$t['pre'])
        {
            $disallow_child = $t['disallow'] ? $t['disallow'] : array();
            foreach ($e->childNodes as $n)
                $s .= self::dom2wiki($n, $disallow_child);
            $s = preg_replace('#\s*<br\s*/>\s*<br\s*/>\s*#is', "\n\n", $s);
        }
        // Copy content of preformatted nodes
        else
            $s = $e->nodeValue;
        // Restore <li>/<dt> level prefix
        if ($old_r !== NULL)
            self::$tags['li']['replacebegin'] =
                self::$tags['dl']['replacebegin'] = $old_r;
        // Transform [link <html>...</html>] into <html><a>...</a></html>
        if (strpos($s, '<html>') !== false && $e->nodeName == 'a')
            return ($t['prefix'] !== NULL ? $t['prefix'] : '').'<html>'.mb_convert_encoding(self::domhtml($e), 'UTF-8', 'HTML-ENTITIES').'</html>';
        // Trim content if not $notrim
        if (!$t['notrim'])
            $s = trim($s);
        // Return '' for forbidden empty nodes
        if ($s == '' && !$t['empty'])
            return '';
        if (!$t || $disallow[$e->nodeName] || $t['require'] && !call_user_func($t['require'], $e))
        {
            // Don't wrap content of disallowed nodes
            // or if there is no $require_attr attribute
            if ($e->nodeName == 'p')
                $s = "$s<br>";
        }
        // If not $replacebegin, wrap content as HTML
        elseif (!array_key_exists('replacebegin', $t))
        {
            $s = self::domnodeopen($e).'>'.$s.'</'.$e->nodeName.'>';
            if (array_key_exists('prefix', $t))
                $s = $t['prefix'] . $s;
        }
        // Wrap content into $replacebegin / $replaceend
        else
            $s = self::domattr($e, $t['replacebegin']).$s.self::domattr($e, $t['replaceend']);
        return $s;
    }

    static function html2wiki($html)
    {
        $html = preg_replace_callback('#(<pre[^<>]*>)(.*?)(</pre>)#is', create_function('$m', 'return $m[1].str_replace(array("<", ">"), array("&lt;", "&gt;"), $m[2]).$m[3];'), $html);
        $dom = self::loadDOM($html);
        $wiki = self::dom2wiki($dom->documentElement);
        $wiki = preg_replace('#\'\x01\'#', '\' \'', $wiki);
        $wiki = preg_replace('#\s*\x01#', ' ', $wiki);
        $wiki = trim($wiki);
        return $wiki;
    }
}
