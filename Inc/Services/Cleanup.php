<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

/**
 * Database cleanup service.
 *
 * Repairs orphaned post meta and incorrect link_id assignments.
 * Stale link groups (< 2 active items) are pruned by Hooks::prune_stale_link_groups()
 * on the daily 'bolt_sync_cleanup' cron event.
 */
class Cleanup {

	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @var bool
	 */
	protected bool $dry_run = true;

	/**
	 * @param Core $core
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * Main cleanup — repairs post meta and resolves duplicate link assignments.
	 * Safe to run in dry-run mode (default) to preview changes without applying them.
	 */
	public function cleanup(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$links_table = $wpdb->base_prefix . 'bolt_sync_links';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $links_table ) ) !== $links_table ) {
			error_log( 'Bolt Sync Cleanup: links table not found.' );
			restore_current_blog();

			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$links = $wpdb->get_results( "SELECT id, active FROM `{$links_table}`" );

		restore_current_blog();

		if ( empty( $links ) ) {
			error_log( 'Bolt Sync Cleanup: no links found.' );

			return;
		}

		$stats = [
			'total_links'           => count( $links ),
			'posts_fixed'           => 0,
			'orphaned_meta_removed' => 0,
			'duplicates_resolved'   => 0,
		];

		error_log( sprintf( 'Bolt Sync Cleanup: %d links to process (DRY RUN: %s).', $stats['total_links'], $this->dry_run ? 'YES' : 'NO' ) );

		$post_link_map = [];

		foreach ( $links as $link ) {
			$link_id = (int) $link->id;

			if ( empty( $link->active ) ) {
				continue;
			}

			$link_obj   = $this->core->get_link( $link_id );
			$link_items = $link_obj ? $link_obj->link_info : [];

			foreach ( $link_items as $item ) {
				if ( ! $item->post_id || ! $item->active ) {
					continue;
				}

				$blog_id = (int) $item->blog_id;
				$post_id = (int) $item->post_id;

				switch_to_blog( $blog_id );
				$post_exists = (bool) get_post( $post_id );

				if ( ! $post_exists ) {
					error_log( sprintf( 'Bolt Sync Cleanup: Post %d not found on site %d (link %d).', $post_id, $blog_id, $link_id ) );
					restore_current_blog();
					continue;
				}

				$stored_link_id = (int) $this->core->get_link_id_from_post( $post_id );

				if ( $stored_link_id !== $link_id ) {
					error_log( sprintf( 'Bolt Sync Cleanup: Post %d on site %d has wrong link_id (stored: %d, expected: %d).', $post_id, $blog_id, $stored_link_id, $link_id ) );

					if ( ! $this->dry_run ) {
						$this->core->update_post_meta_link( $link_id, $post_id, $blog_id );
						$stats['posts_fixed']++;
					}
				}

				restore_current_blog();

				$map_key = "{$blog_id}_{$post_id}";

				if ( isset( $post_link_map[ $map_key ] ) ) {
					error_log( sprintf( 'Bolt Sync Cleanup: Duplicate — Post %d on site %d in links %d and %d.', $post_id, $blog_id, $post_link_map[ $map_key ], $link_id ) );

					if ( ! $this->dry_run ) {
						// Remove from the current (later-found) link to keep the first one encountered.
						global $wpdb;
						$wpdb->delete(
							$wpdb->base_prefix . 'bolt_sync_link_items',
							[ 'link_id' => $link_id, 'blog_id' => $blog_id, 'post_id' => $post_id ],
							[ '%d', '%d', '%d' ]
						);
						$stats['duplicates_resolved']++;
					}
				} else {
					$post_link_map[ $map_key ] = $link_id;
				}
			}
		}

		$this->cleanup_orphaned_post_meta( $post_link_map, $stats );

		error_log( 'Bolt Sync Cleanup complete. Stats: ' . print_r( $stats, true ) );
	}

	/**
	 * Scans all sites for posts with bolt_sync_link_id meta that no longer map to a valid link group,
	 * and removes or corrects the stale meta.
	 *
	 * @param array $valid_post_link_map
	 * @param array $stats
	 *
	 * @return void
	 */
	protected function cleanup_orphaned_post_meta( array $valid_post_link_map, array &$stats ): void {
		foreach ( $this->core->get_sites() as $site ) {
			switch_to_blog( (int) $site->blog_id );

			$query = new \WP_Query( [
				'post_type'      => 'any',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'meta_query'     => [ [ 'key' => 'bolt_sync_link_id', 'compare' => 'EXISTS' ] ],
				'fields'         => 'ids',
			] );

			foreach ( $query->posts as $post_id ) {
				$link_id = $this->core->get_link_id_from_post( (int) $post_id );
				$map_key = "{$site->blog_id}_{$post_id}";

				if ( ! $link_id || ! isset( $valid_post_link_map[ $map_key ] ) ) {
					error_log( sprintf( 'Bolt Sync Cleanup: Removing orphaned bolt_sync_link_id from post %d on site %d.', $post_id, $site->blog_id ) );

					if ( ! $this->dry_run ) {
						delete_post_meta( (int) $post_id, 'bolt_sync_link_id' );
						$stats['orphaned_meta_removed']++;
					}
				} elseif ( $valid_post_link_map[ $map_key ] !== $link_id ) {
					error_log( sprintf( 'Bolt Sync Cleanup: Fixing link_id on post %d site %d (was: %d, should be: %d).', $post_id, $site->blog_id, $link_id, $valid_post_link_map[ $map_key ] ) );

					if ( ! $this->dry_run ) {
						$this->core->update_post_meta_link( $valid_post_link_map[ $map_key ], (int) $post_id );
						$stats['posts_fixed']++;
					}
				}
			}

			restore_current_blog();
		}
	}

	/**
	 * Sets whether cleanup runs in dry-run mode (log only) or applies changes.
	 *
	 * @param bool $dry_run
	 *
	 * @return void
	 */
	public function set_dry_run( bool $dry_run ): void {
		$this->dry_run = $dry_run;
	}

	/**
	 * Runs the cleanup routine manually, optionally in dry-run mode.
	 *
	 * @param bool $dry_run
	 *
	 * @return void
	 */
	public function run_manual_cleanup( bool $dry_run = true ): void {
		$this->set_dry_run( $dry_run );
		$this->cleanup();
	}
}
