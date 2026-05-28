<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Upgrades;

/**
 * Bolt Sync DB Upgrade version: 1.1.0
 *
 * Adds a 'post_type' column and syncs values
 */
class BoltSyncUpgrade_1_1_0 {

	/**
	 * Executes the upgrade process by adding a new post type column
	 * and updating the associated values in the database.
	 *
	 * @return void
	 */
	public function upgrade(): void {
		$this->add_post_type_column();
		$this->update_values();
	}

	/**
	 * Adds a `post_type` column to the `bolt_sync_links` table in the database
	 * if it does not already exist. This operation runs on the main site in a
	 * multisite WordPress installation.
	 *
	 * @return void
	 */
	private function add_post_type_column(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$table_name = esc_sql( $wpdb->prefix . 'bolt_sync_links' );

		// Only run if column doesn't exist
		$column = $wpdb->get_results( $wpdb->prepare(
			'SHOW COLUMNS FROM %i LIKE %s',
			$table_name,
			'post_type'
		) );

		if ( empty( $column ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"ALTER TABLE %i ADD COLUMN %i VARCHAR(100) NOT NULL DEFAULT ''",
					$table_name,
					'post_type'
				)
			);
		}

		restore_current_blog();
	}

	/**
	 * Updates the values in the database for the specified table by processing
	 * each row, filtering the link information to retain only active entries,
	 * determining the corresponding post type, and updating the database records.
	 * If no active link information is found, the row is marked for deletion.
	 *
	 * @return void
	 */
	private function update_values(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$table_name = esc_sql( $wpdb->prefix . 'bolt_sync_links' );

		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, link_info FROM %i',
			[ $table_name ]
		) );

		foreach ( $rows as $row ) {
			$raw_info  = maybe_unserialize( $row->link_info );
			$link_info = is_array( $raw_info ) ? $raw_info : [];

			$link_info = array_filter( $link_info, fn( $obj ) => is_object( $obj ) && ! empty( $obj->active ) );

			if ( empty( $link_info ) ) {
				$wpdb->delete( $table_name, [ 'id' => $row->id ] );
				continue;
			}

			$post_type = $this->get_post_type_from_link( array_values( $link_info ) );

			$wpdb->update(
				$table_name,
				[ 'post_type' => $post_type ],
				[ 'id' => $row->id ]
			);
		}

		restore_current_blog();
	}

	/**
	 * Retrieves the post type associated with a given link information array.
	 *
	 * @param array $link_info An array containing link information,
	 *                         where the first element includes blog and post IDs.
	 *
	 * @return string The post type name associated with the provided post ID.
	 */
	private function get_post_type_from_link( array $link_info ): string {
		$first_pair = $link_info[0];

		switch_to_blog( $first_pair->blog_id );
		$post_type = get_post_type( $first_pair->post_id );
		restore_current_blog();

		return $post_type;
	}
}
