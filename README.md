# Wikilog

Wikilog is a MediaWiki extension that enhances the wiki software with some common blogging features, making it a wiki-blog-forum hybrid.

## Installation

- Download the extension and install it as `./extensions/Wikilog`  
- Backup your database and local configuration (always a good idea)  
- Add minimal configuration to your `LocalSettings.php`  
  - `100` should be the first unused namespace number in your wiki:


```php
wfLoadExtension( 'Wikilog' )
```

Set up your Blog namespace

```php
Wikilog::setupBlogNamespace(100);
```

You can connect it to your search by default:
```
$wgNamespacesToBeSearchedDefault[100] = 1; 
```


Run after install or upgrade:

```bash
php maintenance/update.php
```


Optionally tune settings
```
$wgWikilogNumArticles = 20;
$wgWikilogNumComments = 50;
$wgWikilogExpensiveLimit = 100;
$wgWikilogSignAndPublishDefault = false;
$wgWikilogCommentNamespaces = array();
$wgWikilogPagerDateFormat = 'ymd hms';
$wgWikilogDefaultNotCategory = false;
$wgWikilogCommentsOnItemPage = true;
```


# Usage

## Quickstart

- Install the extension and configure the Blog namespace
- Create a blog: `Blog:My_blog`
- Create a post:
  - Click **Wikilog**
  - Enter title → **Create**
  - Write content → check **Publish** → Save
- Comment:
  - Open blog
  - Click **comments**
  - Write and post

## Advanced tips

- Forum view:
  `<your_wiki>/Special:Wikilog?view=archives&show=published&sort=wlp_talk_updated&desc=1`
- `Special:Wikilog` — all posts + RSS/email
- Filters: author, date, category
- `Special:WikilogComments` — all comments
- Blog → Discussion → all comments
- Enable comments on normal pages:
  `$wgWikilogCommentNamespaces = true;`

---

# Features

- Forum view (sorting by last comment)
- Hierarchical comments on normal pages
- Email notifications (comments & posts)
- RSS/Atom feeds
- Subscriptions to blogs/comments
- Post creation form
- Dropdown filters
- Calendar widget
- Auto-fold comments
- Up to 250 nesting levels
- Thread-safe pagination
- Blogger import
- Bug fixes

> Localisation: English, Russian

---

# TODO

- Optimize comment performance
- Consider TreeTalk-based comments
<!-- - Check IntraACL compatibility -->

---


# Installation

- Download the extension and install it as `./extensions/Wikilog`  
- Backup your database and local configuration (always a good idea)  
- Add minimal configuration to your `LocalSettings.php`  
  *(100 should be the first unused namespace number in your wiki)*:

```php
require_once( 'extensions/Wikilog/Wikilog.php' );
Wikilog::setupBlogNamespace( 100 );
```

- (Optional) Override some configuration from `WikilogDefaultSettings.php`  
- Run the script to create or update the database tables:

```bash
php maintenance/update.php
```

# History

* Original version of Wikilog was abandoned in 2012 by Juliano F. Ravasi.
* 2009-2014 Rewrited and supported by Vitaly Filippov
* Now supported by Stas Fomin
