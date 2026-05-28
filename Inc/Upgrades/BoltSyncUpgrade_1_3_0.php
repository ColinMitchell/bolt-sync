<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Upgrades;

/**
 * Bolt Sync DB Upgrade version: 1.3.0
 *
 * Cleans up legacy active=0 item rows that accumulated under the old soft-delete
 * model, deduplicates any remaining (link_id, blog_id) collisions, then adds a
 * UNIQUE constraint so duplicates can never occur again.
 */
class BoltSyncUpgrade_1_3_0 {

	public function upgrade(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$items_table = $wpdb->base_prefix . 'bolt_sync_link_items';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 1. Remove all soft-deleted (active=0) rows.
		$wpdb->query( "DELETE FROM `{$items_table}` WHERE active = 0" );

		// 2. For any remaining (link_id, blog_id) duplicates keep the row with the
		//    highest id (most recently inserted) and remove the older ones.
		$wpdb->query(
			"DELETE i1 FROM `{$items_table}` i1
			 INNER JOIN `{$items_table}` i2
			   ON i1.link_id = i2.link_id
			  AND i1.blog_id = i2.blog_id
			  AND i1.id < i2.id"
		);

		// 3. Add a UNIQUE constraint so the application layer can rely on
		//    at most one active row per (link_id, blog_id).
		$index_exists = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = '{$items_table}'
			   AND INDEX_NAME   = 'uidx_link_blog'"
		);

		if ( ! $index_exists ) {
			$wpdb->query( "ALTER TABLE `{$items_table}` ADD UNIQUE KEY `uidx_link_blog` (link_id, blog_id)" );
			error_log( 'Bolt Sync DB Upgrade 1.3.0: Added UNIQUE KEY uidx_link_blog on bolt_sync_link_items.' );
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		restore_current_blog();
	}
}
