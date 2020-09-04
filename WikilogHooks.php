<?php
/**
 * MediaWiki Wikilog extension
 * Copyright © 2008-2010 Juliano F. Ravasi
 * http://www.mediawiki.org/wiki/Extension:Wikilog
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

/**
 * @file
 * @ingroup Extensions
 * @author Juliano F. Ravasi < dev juliano info >
 */

if ( !defined( 'MEDIAWIKI' ) )
	die();

/**
 * General wikilog hooks.
 */
class WikilogHooks
{
	/**
	 * ArticleEditUpdates hook handler function.
	 * Performs post-edit updates if article is a wikilog article or a comment.
	 */
	static function ArticleEditUpdates( $article, &$editInfo, $changed ) {
		$title = $article->getTitle();
		$wi = Wikilog::getWikilogInfo( $title );

		if ( $title->isTalkPage() ) {
			if ( Wikilog::nsHasComments( $title ) &&
				!isset( WikilogComment::$saveInProgress[$title->getPrefixedText()] ) ) {
				$comment = $article instanceof WikilogCommentsPage
					? $article->mSingleComment : WikilogComment::newFromPageID( $article->getId() );
				if ( $comment ) {
					$comment->mUpdated = wfTimestamp( TS_MW );
					$comment->saveComment();
				} elseif ( isset( $editInfo->output->mExtWikilog ) &&
					$editInfo->output->mExtWikilog->mComment ) {
					list( $parent, $anonName ) = $editInfo->output->mExtWikilog->mComment;
					# Update entry in wikilog_comments table
					$comment = WikilogComment::newFromCreatedPage( $title, $parent, $anonName );
					$comment->saveComment();
				}
			}
			return true;
		}

		# Do nothing if not a wikilog article.
		if ( !$wi ) {
			return true;
		}

		if ( $wi->isItem() ) {
			# ::WikilogItemPage::
			$item = WikilogItem::newFromInfo( $wi );
			if ( !$item ) {
				$item = new WikilogItem();
			}

			if ( !$wi->getTitle()->getArticleID() ) {
				// If the parent (blog) page is not created yet - create it automatically
				$page = new WikiPage( $wi->getTitle() );
				$page->doEdit( wfMessage( 'wikilog-newtalk-text' )->text(), wfMessage( 'wikilog-newtalk-summary' )->text(), EDIT_FORCE_BOT );
			}

			$item->mName = $wi->getItemName();
			$item->mTitle = $wi->getItemTitle();
			$item->mParentName = $wi->getName();
			$item->mParentTitle = $wi->getTitle();
			$item->mParent = $item->mParentTitle->getArticleID();

			# Override item name if {{DISPLAYTITLE:...}} was used.
			$dtText = $editInfo->output->getDisplayTitle();
			if ( $dtText ) {
				# Tags are stripped on purpose.
				$dtText = Sanitizer::stripAllTags( $dtText );
				$dtParts = explode( '/', $dtText, 2 );
				if ( count( $dtParts ) > 1 ) {
					$item->mName = $dtParts[1];
				}
			}

			# Override item name if {{DISPLAYTITLE:...}} was used.
			$dtText = $editInfo->output->getDisplayTitle();
			if ( $dtText ) {
				# Tags are stripped on purpose.
				$dtText = Sanitizer::stripAllTags( $dtText );
				$dtParts = explode( '/', $dtText, 2 );
				if ( count( $dtParts ) > 1 ) {
					$item->mName = $dtParts[1];
				}
			}

			$item->resetID( $article->getId() );

			# Check if we have any wikilog metadata available.
			if ( isset( $editInfo->output->mExtWikilog ) ) {
				$output = $editInfo->output->mExtWikilog;

				$wasPublished = $item->mPublish;

				# Update entry in wikilog_posts table.
				# Entries in wikilog_authors and wikilog_tags are updated
				# during LinksUpdate process.
				$item->mPublish = $output->mPublish;
				$item->mUpdated = wfTimestamp( TS_MW );
				$item->mPubDate = $output->mPublish ? $output->mPubDate : $item->mUpdated;
				$item->mAuthors = $output->mAuthors;
				$item->mTags    = $output->mTags;
				$item->saveData();

				if ( !$wasPublished && $item->mPublish ) {
				    global $wgEnableEmail;
					// Send email notifications about the new post
					if ( $wgEnableEmail ) {
					    SpecialWikilogSubscriptions::sendEmails( $article,
						    !empty( $editInfo->pstContent ) ? $editInfo->pstContent->getNativeData() : $article->getText() );
					}
				}
			} else {
				# Remove entry from tables. Entries in wikilog_authors and
				# wikilog_tags are removed during LinksUpdate process.
				$item->deleteData();
			}

			# Invalidate cache of parent wikilog page.
			WikilogUtils::updateWikilog( $wi->getTitle() );
		} else {
			# ::WikilogMainPage::
			$dbw = wfGetDB( DB_MASTER );
			$id = $article->getId();

			# Check if we have any wikilog metadata available.
			if ( isset( $editInfo->output->mExtWikilog ) ) {
				$output = $editInfo->output->mExtWikilog;
				$subtitle = $output->mSummary
					? array( 'html', $output->mSummary )
					: '';

				# Update entry in wikilog_wikilogs table. Entries in
				# wikilog_authors and wikilog_tags are updated during
				# LinksUpdate process.
				$dbw->replace(
					'wikilog_wikilogs',
					'wlw_page',
					array(
						'wlw_page' => $id,
						'wlw_subtitle' => serialize( $subtitle ),
						'wlw_icon' => $output->mIcon ? $output->mIcon->getDBKey() : '',
						'wlw_logo' => $output->mLogo ? $output->mLogo->getDBKey() : '',
						'wlw_authors' => serialize( $output->mAuthors ),
						'wlw_updated' => $dbw->timestamp()
					),
					__METHOD__
				);
			} else {
				# Remove entry from tables. Entries in wikilog_authors and
				# wikilog_tags are removed during LinksUpdate process.
				$dbw->delete( 'wikilog_wikilogs', array( 'wlw_page' => $id ), __METHOD__ );
			}
		}

		return true;
	}

	/**
	 * ArticleDelete hook handler function.
	 * Purges wikilog metadata when an article is deleted.
	 */
	static function ArticleDelete( $article, $user, $reason, &$error ) {
		# Delete comment.
		$title = $article->getTitle();
		if ( $title->isTalkPage() ) {
			if ( Wikilog::nsHasComments( $title ) ) {
				$comment = $article instanceof WikilogCommentsPage
					? $article->mSingleComment : WikilogComment::newFromPageID( $article->getId() );
				if ( $comment ) {
					$dbw = wfGetDB( DB_MASTER );
					$hasChildren = $dbw->selectField( 'wikilog_comments', 'wlc_id', array( 'wlc_parent' => $comment->mID ), __METHOD__, array( 'LIMIT' => 1 ) );
					if ( $hasChildren ) {
						$comment->mStatus = WikilogComment::S_DELETED;
						$comment->saveComment();
					} else {
						$comment->deleteComment();
					}
				}
			}
			return true;
		}

		# Retrieve wikilog information.
		$wi = Wikilog::getWikilogInfo( $article->getTitle() );
		$id = $article->getId();

		# Take special procedures if it is a wikilog page.
		if ( $wi ) {
			$dbw = wfGetDB( DB_MASTER );

			if ( $wi->isItem() ) {
				# Delete table entries.
				$dbw->delete( 'wikilog_posts',    array( 'wlp_page'   => $id ) );
				$dbw->delete( 'wikilog_comments', array( 'wlc_parent' => $id ) );
				$dbw->delete( 'wikilog_authors',  array( 'wla_page'   => $id ) );
				$dbw->delete( 'wikilog_tags',     array( 'wlt_page'   => $id ) );
				$dbw->delete( 'wikilog_comments', array( 'wlc_post'   => $id ) );

				# Invalidate cache of parent wikilog page.
				WikilogUtils::updateWikilog( $wi->getTitle() );
			} else {
				# Delete table entries.
				$dbw->delete( 'wikilog_wikilogs', array( 'wlw_page'   => $id ) );
				$dbw->delete( 'wikilog_posts',    array( 'wlp_parent' => $id ) );
				$dbw->delete( 'wikilog_authors',  array( 'wla_page'   => $id ) );
				$dbw->delete( 'wikilog_tags',     array( 'wlt_page'   => $id ) );
			}
		}

		return true;
	}

	/**
	 * ArticleSave hook handler function.
	 *
	 * Add article signature if user selected "sign and publish" option in
	 * EditPage, or if there is ~~~~ in the text.
	 */
	static function ArticleSave( &$wikiPage, &$user, &$content, &$summary,
						$isMinor, $isWatch, $section, &$flags, &$status )
	{
		$t = WikilogUtils::getPublishParameters();
		$txtDate = $t['date'];
		$txtUser = $t['user'];
		$text = ContentHandler::getContentText( $content );

		// $article->mExtWikilog piggybacked from WikilogHooks::EditPageAttemptSave().
		if ( isset( $wikiPage->mExtWikilog ) && $wikiPage->mExtWikilog['signpub'] ) {
			$text = rtrim( $text ) . "\n{{wl-publish: $txtDate | $txtUser }}\n";
		} elseif ( Wikilog::getWikilogInfo( $wikiPage->getTitle() ) ) {
			global $wgParser;
			$sigs = array(
				'/\n?(--)?~~~~~\n?/m' => "\n{{wl-publish: $txtDate }}\n",
				'/\n?(--)?~~~~\n?/m' => "\n{{wl-publish: $txtDate | $txtUser }}\n",
				'/\n?(--)?~~~\n?/m' => "\n{{wl-author: $txtUser }}\n"
			);
			$wgParser->startExternalParse( $wikiPage->getTitle(), ParserOptions::newFromUser( $user ), Parser::OT_WIKI );
			$text = $wgParser->replaceVariables( $text );
			$text = preg_replace( array_keys( $sigs ), array_values( $sigs ), $text );
			$text = $wgParser->mStripState->unstripBoth( $text );
		}
		$content = new WikitextContent( $text );
		return true;
	}

	/**
	 * TitleMoveComplete hook handler function.
	 * Handles moving articles to and from wikilog namespaces.
	 */
	static function TitleMoveComplete( $oldtitle, $newtitle, $user, $pageid, $redirid ) {
		global $wgWikilogNamespaces;

		# Check if it was or is now in a wikilog namespace.
		$oldwl = in_array( ( $oldns = $oldtitle->getNamespace() ), $wgWikilogNamespaces );
		$newwl = in_array( ( $newns = $newtitle->getNamespace() ), $wgWikilogNamespaces );

		if ( $oldwl && $newwl ) {
			# Moving title between wikilog namespaces.
			# Update wikilog data.
			wfDebug( __METHOD__ . ": Moving title between wikilog namespaces " .
				"($oldns, $newns). Updating wikilog data.\n" );

			$wi = Wikilog::getWikilogInfo( $newtitle );
			$item = WikilogItem::newFromID( $pageid );
			if ( $wi && $wi->isItem() && !$wi->isTalk() && $item ) {
				$item->mName = $wi->getItemName();
				# FIXME: need to reparse due to {{DISPLAYTITLE:...}}.
				$item->mTitle = $wi->getItemTitle();
				$item->mParentName = $wi->getName();
				$item->mParentTitle = $wi->getTitle();
				$item->mParent = $item->mParentTitle->getArticleID();
				$item->saveData();
			}
		} elseif ( $newwl ) {
			# Moving from normal namespace to wikilog namespace.
			# Create wikilog data.
			wfDebug( __METHOD__ . ": Moving from another namespace to wikilog " .
				"namespace ($oldns, $newns). Creating wikilog data.\n" );
			# FIXME: This needs a reparse of the wiki text in order to
			# populate wikilog metadata. Or forbid this action.
		} elseif ( $oldwl ) {
			# Moving from wikilog namespace to normal namespace.
			# Purge wikilog data.
			wfDebug( __METHOD__ . ": Moving from wikilog namespace to another " .
				"namespace ($oldns, $newns). Purging wikilog data.\n" );
			$dbw = wfGetDB( DB_MASTER );
			$dbw->delete( 'wikilog_wikilogs', array( 'wlw_page'   => $pageid ) );
			$dbw->delete( 'wikilog_posts',    array( 'wlp_page'   => $pageid ) );
			$dbw->delete( 'wikilog_posts',    array( 'wlp_parent' => $pageid ) );
			$dbw->delete( 'wikilog_authors',  array( 'wla_page'   => $pageid ) );
			$dbw->delete( 'wikilog_tags',     array( 'wlt_page'   => $pageid ) );
//			$dbw->delete( 'wikilog_comments', array( 'wlc_post'   => $pageid ) );
			# FIXME: Decide what to do with the comments.
		}
		return true;
	}

	/**
	 * EditPage::showEditForm:fields hook handler function.
	 * Adds wikilog article options to edit pages.
	 */
	static function EditPageEditFormFields( $editpage, $output ) {
		$wi = Wikilog::getWikilogInfo( $editpage->mTitle );
		if ( $wi && $wi->isItem() && !$wi->isTalk() ) {
			global $wgUser, $wgWikilogSignAndPublishDefault;
			$fields = array();
			$item = WikilogItem::newFromInfo( $wi );

			// [x] Sign and publish this wikilog article.
			if ( !$item || !$item->getIsPublished() ) {
				if ( isset( $editpage->wlSignpub ) ) {
					$checked = $editpage->wlSignpub;
				} else {
					$checked = !$item && $wgWikilogSignAndPublishDefault;
				}
				$label = wfMessage( 'wikilog-edit-signpub' )->parse();
				$tooltip = wfMessage( 'wikilog-edit-signpub-tooltip' )->parse();
				$fields['wlSignpub'] =
					Xml::check( 'wlSignpub', $checked, array(
						'id' => 'wl-signpub',
						'tabindex' => 1, // after text, before summary
					) ) . WL_NBSP .
					Xml::element( 'label', array(
						'for' => 'wl-signpub',
						'title' => $tooltip,
					), $label );
			}

			$fields = implode( $fields, "\n" );
			$html = Xml::fieldset(
				wfMessage( 'wikilog-edit-fieldset-legend' )->parse(),
				$fields
			);
			$editpage->editFormTextAfterWarn .= $html;
		}
		return true;
	}

	/**
	 * EditPage::importFormData hook handler function.
	 * Import wikilog article options form data in edit pages.
	 * @note Requires MediaWiki 1.16+.
	 */
	static function EditPageImportFormData( $editpage, $request ) {
		if ( $request->wasPosted() ) {
			$editpage->wlSignpub = $request->getCheck( 'wlSignpub' );
		}
		return true;
	}

	/**
	 * EditPage::attemptSave hook handler function.
	 * Check edit page options.
	 */
	static function EditPageAttemptSave( $editpage ) {
		$options = array(
			'signpub' => $editpage->wlSignpub
		);

		// Piggyback options into article object. Will be retrieved later
		// in 'ArticleEditUpdates' hook.
		$editpage->mArticle->getPage()->mExtWikilog = $options;
		return true;
	}

	/**
	 * LoadExtensionSchemaUpdates hook handler function.
	 * Updates wikilog database tables.
	 *
	 * @todo Add support for PostgreSQL and SQLite databases.
	 */
	static function ExtensionSchemaUpdates( $updater ) {
		register_shutdown_function( 'WikilogRegenThreads::execute' );

		$dir = dirname( __FILE__ ) . '/';

		if ( $updater === null ) {
			global $wgDBtype, $wgExtNewIndexes, $wgExtNewTables;
			if ( $wgDBtype == 'mysql' ) {
				$wgExtNewTables[] = array( "wikilog_wikilogs", "{$dir}wikilog-tables.sql" );
				$wgExtNewTables[] = array( "page_last_visit", "{$dir}archives/patch-visits.sql" );
				$wgExtNewTables[] = array( "wikilog_subscriptions", "{$dir}archives/patch-subscriptions.sql" );
				$wgExtNewTables[] = array( "wikilog_talkinfo", "{$dir}archives/patch-talkinfo.sql" );
				$wgExtNewIndexes[] = array( "wikilog_comments", "wlc_timestamp", "{$dir}archives/patch-comments-indexes.sql" );
				$wgUpdates['mysql'][] = 'WikilogHooks::createForeignKeys';
			} else {
				// TODO: PostgreSQL, SQLite, etc...
				print "\n" .
					"Warning: There are no table structures for the Wikilog\n" .
					"extension other than for MySQL at this moment.\n\n";
			}
		} else {
			if ( $updater->getDB()->getType() == 'mysql' ) {
				$updater->addExtensionUpdate( array( 'addTable', "wikilog_wikilogs",
					"{$dir}wikilog-tables.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', "page_last_visit",
					"{$dir}archives/patch-visits.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', "wikilog_subscriptions",
					"{$dir}archives/patch-subscriptions.sql", true ) );
				$updater->addExtensionUpdate( array( 'addTable', "wikilog_talkinfo",
					"{$dir}archives/patch-talkinfo.sql", true ) );
				$updater->addExtensionUpdate( array( 'addIndex', "wikilog_comments",
					"wlc_timestamp", "{$dir}archives/patch-comments-indexes.sql", true ) );
				$updater->addExtensionUpdate( array( 'WikilogHooks::createForeignKeys' ) );
			} elseif ( $updater->getDB()->getType() == 'postgres' ) {
				$updater->addExtensionUpdate( array( 'addTable', "wikilog_wikilogs",
					"{$dir}wikilog-tables.pg.sql", true ) );
			} else {
				// TODO: PostgreSQL, SQLite, etc...
				print "\n" .
					"Warning: There are no table structures for the Wikilog\n" .
					"extension other than for MySQL at this moment.\n\n";
			}
		}
		return true;
	}

	/**
	 * Creates some missing fields and foreign keys for Wikilog tables.
	 */
	static function createForeignKeys() {
		$dbw = wfGetDB( DB_MASTER );
		// Try to setup foreign keys on Wikilog tables (MySQL/InnoDB only)
		// Rather a hack for MediaWiki
		$keys = array(
			array( 'wikilog_comments', 'wlc_post', 'page', 'page_id', 'CASCADE' ),
			array( 'wikilog_comments', 'wlc_comment_page', 'page', 'page_id', 'SET NULL' ),
			array( 'wikilog_comments', 'wlc_parent', 'wikilog_comments', 'wlc_id', 'SET NULL' ),
			array( 'wikilog_posts',    'wlp_page', 'page', 'page_id', 'CASCADE' ),
			array( 'wikilog_wikilogs', 'wlw_page', 'page', 'page_id', 'CASCADE' ),
			array( 'wikilog_talkinfo', 'wti_page', 'page', 'page_id', 'CASCADE' ),
		);
		$rand = "_tmp_".rand();
		foreach ( $keys as $k ) {
			$res = $dbw->query( "SHOW CREATE TABLE ".$dbw->tableName( $k[0] ), __METHOD__ );
			$sql = $res->fetchRow();
			$exists = strpos( $sql[1], "CONSTRAINT `$k[0]_$k[1]_$k[3]`" ) !== false;
			$equal = strpos( $sql[1], "CONSTRAINT `$k[0]_$k[1]_$k[3]` ".
				"FOREIGN KEY (`$k[1]`) REFERENCES ".$dbw->tableName( $k[2] )." (`$k[3]`) ON DELETE $k[4] ON UPDATE CASCADE" ) !== false;
			if ( $exists && !$equal ) {
				print "Removing foreign key on $k[0] ($k[1]) -> $k[2] ($k[3])\n";
				$dbw->query( "ALTER TABLE ".$dbw->tableName( $k[0] )." DROP FOREIGN KEY `$k[0]_$k[1]_$k[3]`" );
				$exists = false;
			}
			if ( !$exists ) {
				print "Adding foreign key on $k[0] ($k[1]) -> $k[2] ($k[3])\n";
				if ( $k[2] == $k[0] ) {
					$dbw->query(
						"CREATE TEMPORARY TABLE `$rand` AS SELECT $k[3] FROM ".$dbw->tableName( $k[2] )
					);
					$t = "`$rand`";
				} else {
					$t = $dbw->tableName( $k[2] );
				}
				if ( $k[4] == 'DELETE' ) {
					$dbw->query(
						"DELETE FROM ".$dbw->tableName( $k[0] ).
						" WHERE NOT EXISTS (SELECT $k[3] FROM $t WHERE $k[3]=$k[1])"
					);
				} else {
					$dbw->query(
						"UPDATE ".$dbw->tableName( $k[0] ).
						" SET $k[1]=NULL WHERE NOT EXISTS (SELECT $k[3] FROM $t WHERE $k[3]=$k[1])"
					);
				}
				$dbw->query(
					"ALTER TABLE ".$dbw->tableName( $k[0] )." ADD CONSTRAINT $k[0]_$k[1]_$k[3]".
					" FOREIGN KEY ($k[1]) REFERENCES ".$dbw->tableName( $k[2] ).
					" ($k[3]) ON DELETE ".$k[4]." ON UPDATE CASCADE"
				);
				if ( $k[2] == $k[0] ) {
					$dbw->query("DROP TABLE `$rand`");
				}
			}
		}
	}

	/**
	 * UnknownAction hook handler function.
	 * Handles ?action=wikilog requests.
	 */
	static function UnknownAction( $action, $article ) {
		if ( $action == 'wikilog' && $article instanceof WikilogCustomAction ) {
			$article->wikilog();
			return false;
		}
		return true;
	}

	/**
	 * Group Wikilog comments by post in enhanced recent changes
	 */
	static function EnhancedChangesListGroupBy( &$rc, &$title, &$secureName ) {
		global $wgWikilogNamespaces;
		static $talk = array();
		if( !$talk ) {
			foreach( $wgWikilogNamespaces as $ns ) {
				$talk[ MWNamespace::getTalk( $ns ) ] = true;
			}
		}
		if( isset( $talk[ $title->getNamespace() ] ) &&
			substr_count( $secureName, '/' ) == 2 ) {
			$secureName = $title->getNsText() . ':' . $title->getBaseText();
			return false;
		}
		return true;
	}

}

class WikilogRegenThreads {
	static function execute() {
		$dbw = wfGetDB( DB_MASTER );
		if ( !$dbw->selectField( 'wikilog_comments', 'wlc_id',
			array( 'wlc_thread=LPAD(CONCAT(wlc_id, \'\'), 6, \'0\')' ), __METHOD__ ) ) {
			return;
		}
		print "Regenerating wlc_thread for Wikilog comments\n";
		$res = $dbw->select( 'wikilog_comments','wlc_id, wlc_parent',
			array( '1' ), __METHOD__, array( 'ORDER BY' => 'wlc_id' ) );
		$rows = array();
		foreach ( $res as $row ) {
			if ( !$row->wlc_parent ) {
				$row->wlc_thread = WikilogUtils::encodeVarint( $row->wlc_id );
			} else {
				$p = $rows[$row->wlc_parent];
				$row->wlc_thread = $p->wlc_thread . WikilogUtils::encodeVarint( $row->wlc_id - $p->wlc_id );
			}
			$rows[$row->wlc_id] = $row;
		}
		foreach ( $rows as $row ) {
			$dbw->update( 'wikilog_comments', array( 'wlc_thread' => $row->wlc_thread ),
				array( 'wlc_id' => $row->wlc_id ), __METHOD__ );
		}
	}
}
