<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Upgrades;

/**
 * Bolt Sync DB Upgrade version: 1.2.0
 *
 * Replaces the serialized link_info column with a proper junction table
 * (bolt_sync_link_items). The migration runs inside a transaction so it
 * rolls back cleanly on failure.
 */
class BoltSyncUpgrade_1_2_0 {

	public function upgrade(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$links_table = $wpdb->base_prefix . 'bolt_sync_links';
		$items_table = $wpdb->base_prefix . 'bolt_sync_link_items';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );

		try {
			$this->ensure_links_table( $links_table );
			$this->create_items_table( $items_table );
			$this->migrate_link_info( $links_table, $items_table );
			$this->drop_link_info_column( $links_table );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			error_log( 'Bolt Sync DB Upgrade 1.2.0 failed: ' . $e->getMessage() );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		restore_current_blog();
	}

	/**
	 * Creates the bolt_sync_links table if it does not already exist, and ensures
	 * the post_type column is present (handles partial upgrades from pre-1.1.0).
	 *
	 * @param string $links_table
	 *
	 * @return void
	 */
	private function ensure_links_table( string $links_table ): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$links_table} (
			id         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_type  varchar(100)        NOT NULL DEFAULT '',
			active     tinyint(1)          NOT NULL DEFAULT 1,
			created_at datetime            DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Ensure the post_type column exists in case the table predates DB 1.1.0.
		$this->ensure_post_type_column( $links_table );
	}

	/**
	 * Adds the post_type column to the links table if it does not already exist.
	 * Needed when upgrading from the legacy {prefix}links table which pre-dates DB 1.1.0.
	 *
	 * @param string $links_table
	 *
	 * @return void
	 */
	private function ensure_post_type_column( string $links_table ): void {
		global $wpdb;

		$column_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = %s
			   AND COLUMN_NAME  = %s',
			$links_table,
			'post_type'
		) );

		if ( ! $column_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$links_table}` ADD COLUMN `post_type` varchar(100) NOT NULL DEFAULT '' AFTER `id`" );
			error_log( "Bolt Sync DB Upgrade 1.2.0: Added `post_type` column to `{$links_table}`." );
		}
	}

	/**
	 * Creates the bolt_sync_link_items junction table if it does not already exist.
	 *
	 * @param string $items_table
	 *
	 * @return void
	 */
	private function create_items_table( string $items_table ): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$items_table} (
			id         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id    bigint(20) UNSIGNED NOT NULL,
			blog_id    bigint(20) UNSIGNED NOT NULL,
			post_id    bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			active     tinyint(1)          NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			KEY idx_link_id (link_id),
			KEY idx_blog_id (blog_id),
			KEY idx_post_id (post_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function migrate_link_info( string $links_table, string $items_table ): void {
		global $wpdb;

		// Only migrate if link_info column still exists.
		$column_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = %s
			   AND COLUMN_NAME  = %s',
			$links_table,
			'link_info'
		) );

		if ( ! $column_exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, link_info FROM `{$links_table}`" );

		foreach ( $rows as $row ) {
			$link_info = maybe_unserialize( $row->link_info );

			if ( ! is_array( $link_info ) ) {
				continue;
			}

			foreach ( $link_info as $item ) {
				$item = (object) $item;

				$wpdb->insert(
					$items_table,
					[
						'link_id' => (int) $row->id,
						'blog_id' => (int) ( $item->blog_id ?? 0 ),
						'post_id' => (int) ( $item->post_id ?? 0 ),
						'active'  => (int) filter_var( $item->active ?? true, FILTER_VALIDATE_BOOLEAN ),
					],
					[ '%d', '%d', '%d', '%d' ]
				);
			}
		}
	}

	private function drop_link_info_column( string $links_table ): void {
		global $wpdb;

		$column_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS
			 WHERE TABLE_SCHEMA = DATABASE()
			   AND TABLE_NAME   = %s
			   AND COLUMN_NAME  = %s',
			$links_table,
			'link_info'
		) );

		if ( $column_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$links_table}` DROP COLUMN `link_info`" );
		}
	}
}
