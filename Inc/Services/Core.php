<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

use BoltSync\Inc\Services\Sync\ACFSyncService;
use BoltSync\Inc\Services\Sync\ContentSyncService;
use BoltSync\Inc\Services\Sync\SeoSyncService;
use BoltSync\Inc\Services\Sync\TaxonomySyncService;

/**
 * Core service — link CRUD, sync orchestration, and helper utilities.
 */
final class Core {

	/**
	 * @var string
	 */
	private string $table_name;

	/**
	 * @var string
	 */
	private string $items_table_name;

	private const SYNC_LOCK_KEY  = 'bolt_sync_sync_lock_%d_%d';
	private const SYNC_LOCK_TTL  = 30;
	private const AS_SYNC_HOOK   = 'bolt_sync_sync_post';
	private const ADMIN_USER_KEY = 'bolt_sync_admin_user_id_%d';

	/**
	 * @var ContentSyncService
	 */
	private ContentSyncService $content_sync_service;

	/**
	 * @var ACFSyncService
	 */
	private ACFSyncService $acf_sync_service;

	/**
	 * @var TaxonomySyncService
	 */
	private TaxonomySyncService $taxonomy_sync_service;

	/**
	 * @var SeoSyncService
	 */
	private SeoSyncService $seo_sync_service;

	/**
	 * Guards against re-entrant sync calls within the same request.
	 *
	 * @var bool
	 */
	public static bool $is_syncing = false;

	public function __construct() {
		global $wpdb;

		$this->table_name       = $wpdb->base_prefix . 'bolt_sync_links';
		$this->items_table_name = $wpdb->base_prefix . 'bolt_sync_link_items';

		$this->acf_sync_service      = new ACFSyncService();
		$this->content_sync_service  = new ContentSyncService( $this->acf_sync_service );
		$this->content_sync_service->set_core( $this );
		$this->taxonomy_sync_service = new TaxonomySyncService();
		$this->seo_sync_service      = new SeoSyncService();
	}

	// -------------------------------------------------------------------------
	// Cache helpers
	// -------------------------------------------------------------------------

	/**
	 * Flushes all cached data for the bolt_sync cache group.
	 *
	 * @return bool
	 */
	public function flush_cache(): bool {
		return wp_cache_flush_group( 'bolt_sync' );
	}

	// -------------------------------------------------------------------------
	// Link CRUD — junction table
	// -------------------------------------------------------------------------

	/**
	 * Returns a link record with a hydrated `link_info` array from the items table.
	 */
	public function get_link( int $id ): object|false {
		global $wpdb;

		$cache_key = "bolt_sync_link_{$id}";
		$cached    = wp_cache_get( $cache_key, 'bolt_sync' );
		if ( $cached !== false ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$link = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, post_type, active, created_at FROM %i WHERE id = %d',
			$this->table_name,
			$id
		) );

		if ( ! $link ) {
			return false;
		}

		$link->id     = (int) $link->id;
		$link->active = (bool) (int) $link->active;

		$link->link_info = $this->get_link_items( $id );

		wp_cache_set( $cache_key, $link, 'bolt_sync', HOUR_IN_SECONDS );

		return $link;
	}

	/**
	 * Fetches all active item rows for a link as a plain array of objects.
	 * Only active=1 rows are returned — inactive/soft-deleted rows are ignored.
	 *
	 * @return object[]
	 */
	private function get_link_items( int $link_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$items = $wpdb->get_results( $wpdb->prepare(
			'SELECT blog_id, post_id, active FROM %i WHERE link_id = %d AND active = 1',
			$this->items_table_name,
			$link_id
		) );

		foreach ( $items as $item ) {
			$item->blog_id = (int) $item->blog_id;
			$item->post_id = (int) $item->post_id;
			$item->active  = (bool) (int) $item->active;
		}

		return $items ?: [];
	}

	/**
	 * Inserts a new link group and its items. Returns the new link ID or false.
	 *
	 * @param object[] $link_info
	 */
	public function insert_link( array $link_info ): int|false {
		global $wpdb;

		$post_type = $this->get_post_type_from_link( $link_info );

		if ( ! $this->validate_link_info_post_type( 0, $link_info, $post_type ) ) {
			return false;
		}

		$result = $wpdb->insert(
			$this->table_name,
			[
				'post_type' => $post_type,
				'active'    => 1,
			],
			[ '%s', '%d' ]
		);

		if ( $result === false ) {
			error_log( 'BoltSync insert_link error: ' . $wpdb->last_error );

			return false;
		}

		$new_link_id = (int) $wpdb->insert_id;

		$this->replace_link_items( $new_link_id, $link_info );

		foreach ( $link_info as $item ) {
			if ( ! empty( $item->post_id ) ) {
				$this->update_post_meta_link( $new_link_id, (int) $item->post_id, (int) $item->blog_id );
			}
		}

		wp_cache_delete( "bolt_sync_link_{$new_link_id}", 'bolt_sync' );

		return $new_link_id;
	}

	/**
	 * Updates an existing link group. Returns the link ID or false on failure.
	 *
	 * @param object[] $link_info
	 */
	public function update_link( int $link_id, array $link_info, bool $active = true ): int|false {
		global $wpdb;

		$link_info = array_values( array_filter( $link_info ) );

		$old_link = $this->get_link( $link_id );

		if ( ! $old_link ) {
			$this->delete_link( $link_id );

			return $this->insert_link( $link_info );
		}

		// Remove post meta from items that were removed / deactivated.
		$active_old = array_filter( $old_link->link_info, fn( $o ) => $o->active && $o->post_id );
		$active_new = array_filter( $link_info, fn( $o ) => ( $o->active ?? false ) && ( $o->post_id ?? 0 ) );

		$removed = array_udiff(
			(array) $active_old,
			(array) $active_new,
			static fn( $a, $b ) => ( $a->blog_id <=> $b->blog_id ) ?: ( $a->post_id <=> $b->post_id )
		);

		foreach ( $removed as $item ) {
			$this->update_post_meta_link( 0, (int) $item->post_id, (int) $item->blog_id );
		}

		$result = $wpdb->update(
			$this->table_name,
			[ 'active' => (int) $active ],
			[ 'id' => $link_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( $result === false ) {
			error_log( 'BoltSync update_link error: ' . $wpdb->last_error );

			return false;
		}

		$this->replace_link_items( $link_id, $link_info );

		foreach ( $active_new as $item ) {
			$this->update_post_meta_link( $link_id, (int) $item->post_id, (int) $item->blog_id );
		}

		wp_cache_delete( "bolt_sync_link_{$link_id}", 'bolt_sync' );

		return $link_id;
	}

	/**
	 * Deletes a link group and all its items.
	 */
	public function delete_link( int $id ): bool {
		global $wpdb;

		$link = $this->get_link( $id );

		if ( $link ) {
			foreach ( $link->link_info as $item ) {
				if ( ! empty( $item->post_id ) ) {
					$this->update_post_meta_link( 0, (int) $item->post_id, (int) $item->blog_id );
				}
			}
		}

		$wpdb->delete( $this->items_table_name, [ 'link_id' => $id ], [ '%d' ] );
		$wpdb->delete( $this->table_name, [ 'id' => $id ], [ '%d' ] );

		wp_cache_delete( "bolt_sync_link_{$id}", 'bolt_sync' );

		return true;
	}

	/**
	 * Removes only the single item for $blog_id from the link group when a post is deleted.
	 * The rest of the group remains intact.
	 */
	public function remove_link_item( int $link_id, int $blog_id, int $post_id ): void {
		global $wpdb;

		// Clear post meta on the departing site before removing the DB row so that
		// any concurrent getLinkId() call returns null and the auto-save effect
		// does not treat the stale link_id as authoritative.
		$this->update_post_meta_link( 0, $post_id, $blog_id );

		$wpdb->delete(
			$this->items_table_name,
			[
				'link_id' => $link_id,
				'blog_id' => $blog_id,
				'post_id' => $post_id,
			],
			[ '%d', '%d', '%d' ]
		);

		wp_cache_delete( "bolt_sync_link_{$link_id}", 'bolt_sync' );

		// If fewer than 2 active items remain, delete the whole group.
		$remaining = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE link_id = %d AND active = 1',
			$this->items_table_name,
			$link_id
		) );

		if ( $remaining < 2 ) {
			$this->delete_link( $link_id );
		}
	}

	/**
	 * Adds a single item to an existing link group and updates the post meta on that site.
	 *
	 * @param int $link_id
	 * @param int $blog_id
	 * @param int $post_id
	 *
	 * @return void
	 */
	/**
	 * Adds a single item to an existing link group, or re-activates it if a row
	 * already exists (after the BoltSyncUpgrade_1_3_0 UNIQUE constraint). Also updates post meta.
	 *
	 * @param int $link_id
	 * @param int $blog_id
	 * @param int $post_id
	 *
	 * @return void
	 */
	public function add_link_item( int $link_id, int $blog_id, int $post_id ): void {
		global $wpdb;

		// INSERT … ON DUPLICATE KEY UPDATE handles both the fresh-join and the
		// re-join case cleanly without a separate SELECT.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare(
			'INSERT INTO %i (link_id, blog_id, post_id, active)
			 VALUES (%d, %d, %d, 1)
			 ON DUPLICATE KEY UPDATE post_id = VALUES(post_id), active = 1',
			$this->items_table_name,
			$link_id,
			$blog_id,
			$post_id
		) );

		$this->update_post_meta_link( $link_id, $post_id, $blog_id );
		wp_cache_delete( "bolt_sync_link_{$link_id}", 'bolt_sync' );
	}

	/**
	 * Replaces all active items for a link (DELETE all + INSERT active-only rows).
	 * Inactive items from the JS payload are intentionally skipped — a site not
	 * in the new active set simply has no row rather than an active=0 row.
	 *
	 * @param object[] $link_info
	 */
	private function replace_link_items( int $link_id, array $link_info ): void {
		global $wpdb;

		$wpdb->delete( $this->items_table_name, [ 'link_id' => $link_id ], [ '%d' ] );

		foreach ( $link_info as $item ) {
			$active = (bool) filter_var( $item->active ?? true, FILTER_VALIDATE_BOOLEAN );
			if ( ! $active ) {
				continue;
			}

			$wpdb->insert(
				$this->items_table_name,
				[
					'link_id' => $link_id,
					'blog_id' => (int) ( $item->blog_id ?? 0 ),
					'post_id' => (int) ( $item->post_id ?? 0 ),
					'active'  => 1,
				],
				[ '%d', '%d', '%d', '%d' ]
			);
		}
	}

	/**
	 * Returns all link groups with hydrated link_info. Returns false if none found.
	 *
	 * @return object[]|false
	 */
	public function get_all_links(): array|false {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$links = $wpdb->get_results( $wpdb->prepare(
			'SELECT id, post_type, active, created_at FROM %i',
			$this->table_name
		) );

		restore_current_blog();

		if ( empty( $links ) ) {
			return false;
		}

		foreach ( $links as $link ) {
			$link->id     = (int) $link->id;
			$link->active = (bool) (int) $link->active;

			$link->link_info = $this->get_link_items( $link->id );
		}

		return $links;
	}

	// -------------------------------------------------------------------------
	// Post meta helpers
	// -------------------------------------------------------------------------

	/**
	 * Updates or removes the bolt_sync_link_id post meta on a given post.
	 * Pass $link_id = 0 to delete the meta entirely.
	 *
	 * @param int $link_id Pass 0 to delete the meta.
	 * @param int $post_id
	 * @param int $blog_id
	 *
	 * @return int|bool
	 */
	public function update_post_meta_link( int $link_id, int $post_id, int $blog_id = 0 ): int|bool {
		if ( ! $post_id ) {
			return false;
		}

		if ( $blog_id !== 0 ) {
			switch_to_blog( $blog_id );
		}

		if ( ! get_post_status( $post_id ) ) {
			if ( $blog_id !== 0 ) {
				restore_current_blog();
			}

			return false;
		}

		if ( $link_id === 0 ) {
			$result = delete_post_meta( $post_id, 'bolt_sync_link_id' );
		} else {
			$result = update_post_meta( $post_id, 'bolt_sync_link_id', $link_id );
		}

		if ( $blog_id !== 0 ) {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Returns the link ID stored in post meta for the given post, or false if not linked.
	 * Automatically removes stale meta if the referenced link no longer exists.
	 *
	 * @param int $post_id
	 *
	 * @return int|false
	 */
	public function get_link_id_from_post( int $post_id ): int|false {
		$link_id = get_post_meta( $post_id, 'bolt_sync_link_id', true );

		if ( ! is_numeric( $link_id ) || (int) $link_id === 0 ) {
			return false;
		}

		$link_id = (int) $link_id;

		if ( ! $this->get_link( $link_id ) ) {
			$this->update_post_meta_link( 0, $post_id );

			return false;
		}

		return $link_id;
	}

	// -------------------------------------------------------------------------
	// Sync
	// -------------------------------------------------------------------------

	/**
	 * Entry point called by the save_post / acf/save_post hooks.
	 * Dispatches an async Action Scheduler job to avoid blocking the editor.
	 */
	public function sync( int $post_id, \WP_Post $post, bool $update ): bool {
		if (
			! $update ||
			wp_is_post_autosave( $post_id ) ||
			wp_is_post_revision( $post_id )
		) {
			return false;
		}

		if ( self::$is_syncing ) {
			//error_log( sprintf( '[BoltSync] sync() — $is_syncing guard active for post %d on site %d, skipping.', $post_id, get_current_blog_id() ) );
			return false;
		}

		$lock_key = sprintf( self::SYNC_LOCK_KEY, $post_id, get_current_blog_id() );

		if ( wp_cache_get( $lock_key, 'bolt_sync_locks' ) ) {
			//error_log( sprintf( '[BoltSync] sync() — lock active for post %d on site %d, skipping.', $post_id, get_current_blog_id() ) );
			return false;
		}

		$link_id = $this->get_link_id_from_post( $post_id );

		if ( ! $link_id ) {
			return false;
		}

		$link = $this->get_link( $link_id );

		if ( ! $link || ! $link->active ) {
			//error_log( sprintf( '[BoltSync] sync() — link %d for post %d is inactive or missing, skipping.', $link_id, $post_id ) );
			return false;
		}

		/**
		 * Fires just before a sync job is queued. Return true from 'bolt_sync_skip_sync'
		 * to abort the sync entirely.
		 *
		 * @param int $post_id
		 * @param int $link_id
		 */
		if ( apply_filters( 'bolt_sync_skip_sync', false, $post_id, $link_id ) ) {
			return false;
		}

		do_action( 'bolt_sync_before_sync', $post_id, $link_id );

		$source_blog_id = get_current_blog_id();

		// Set the lock before dispatching so that any wp_after_insert_post hooks
		// fired from within sync_content (e.g. via wp_update_post on a target site
		// that also has bolt_sync_link_id) see the lock and bail — preventing infinite
		// recursion in the synchronous fallback path.
		wp_cache_set( $lock_key, true, 'bolt_sync_locks', self::SYNC_LOCK_TTL );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$job_args = [
				[
					'post_id'        => $post_id,
					'source_blog_id' => $source_blog_id,
				],
			];

			error_log( sprintf( '[BoltSync] sync() — queuing async Action Scheduler job for post %d (link %d) on site %d.', $post_id, $link_id, $source_blog_id ) );
			as_enqueue_async_action( self::AS_SYNC_HOOK, $job_args, 'bolt_sync', true );
		} else {
			error_log( sprintf( '[BoltSync] sync() — Action Scheduler unavailable, running sync inline for post %d (link %d) on site %d.', $post_id, $link_id, $source_blog_id ) );
			$this->sync_content( $post );
		}

		return true;
	}

	/**
	 * Callback for the Action Scheduler hook 'bolt_sync_sync_post'.
	 * Args are passed as a single array because the dispatch wraps them in a nested array.
	 *
	 * @param array{post_id: int, source_blog_id: int} $args
	 */
	public function handle_scheduled_sync( array $args ): void {
		$post_id        = (int) ( $args['post_id'] ?? 0 );
		$source_blog_id = (int) ( $args['source_blog_id'] ?? get_main_site_id() );

		if ( ! $post_id ) {
			return;
		}

		error_log( sprintf( '[BoltSync] handle_scheduled_sync() — Action Scheduler fired for post %d on site %d.', $post_id, $source_blog_id ) );

		switch_to_blog( $source_blog_id );
		$post = get_post( $post_id );
		restore_current_blog();

		if ( ! $post ) {
			error_log( sprintf( '[BoltSync] handle_scheduled_sync() — post %d not found on site %d, aborting.', $post_id, $source_blog_id ) );
			return;
		}

		switch_to_blog( $source_blog_id );
		$result = $this->sync_content( $post );
		restore_current_blog();

		error_log( sprintf( '[BoltSync] handle_scheduled_sync() — sync_content finished for post %d. Result: %s', $post_id, is_wp_error( $result ) ? $result->get_error_message() : 'ok' ) );

		$link_id = $this->get_link_id_from_post( $post_id );

		/**
		 * Fires after a sync job completes.
		 *
		 * @param int            $post_id
		 * @param int|false      $link_id
		 * @param bool|\WP_Error $result
		 * @param int            $source_blog_id
		 */
		do_action( 'bolt_sync_after_sync', $post_id, $link_id, $result, $source_blog_id );
	}

	/**
	 * Performs the actual cross-site sync for all linked sites.
	 */
	public function sync_content( array|\WP_Post $source_post ): bool|\WP_Error {
		if ( is_array( $source_post ) ) {
			$source_post = get_post( $source_post[0] ?? 0 );
			if ( ! $source_post ) {
				return new \WP_Error( 'bolt_sync_invalid_post', 'Source post not found.' );
			}
		}

		self::$is_syncing = true;

		try {
			return $this->do_sync_content( $source_post );
		} finally {
			self::$is_syncing = false;
		}
	}

	/**
	 * Inner implementation of sync_content, called within the $is_syncing guard.
	 */
	private function do_sync_content( \WP_Post $source_post ): bool|\WP_Error {
		error_log( sprintf( '[BoltSync] sync_content() — starting sync for post %d ("%s") on site %d.', $source_post->ID, $source_post->post_title, get_current_blog_id() ) );

		$link_id = $this->get_link_id_from_post( $source_post->ID );

		if ( ! $link_id ) {
			return new \WP_Error( 'bolt_sync_no_link', 'Post has no link.' );
		}

		$link = $this->get_link( $link_id );

		if ( ! $link || ! $link->active ) {
			return new \WP_Error( 'bolt_sync_inactive_link', 'Link is inactive.' );
		}

		$source_site_id = get_current_blog_id();
		$warnings       = [];

		foreach ( $link->link_info as $link_info ) {
			restore_current_blog();

			if ( ! $link_info->active ) {
				continue;
			}

			if ( $link_info->blog_id === get_current_blog_id() ) {
				continue;
			}

			switch_to_blog( $link_info->blog_id );

			$created_new_post = false;

			if ( empty( $link_info->post_id ) ) {
				$updated_post_id = wp_insert_post( [
					'post_title'   => $source_post->post_title,
					'post_content' => $source_post->post_content,
					'post_status'  => 'publish',
					'post_type'    => $source_post->post_type,
					'post_name'    => $source_post->post_name,
				] );

				$created_new_post = true;
			} else {
				if ( ! get_post_status( $link_info->post_id ) ) {
					error_log( sprintf( 'BoltSync: Linked post %d no longer exists on site %d.', $link_info->post_id, $link_info->blog_id ) );
					restore_current_blog();
					continue;
				}

				$updated_post_id = wp_update_post( [
					'ID'           => $link_info->post_id,
					'post_title'   => $source_post->post_title,
					'post_content' => $source_post->post_content,
					'post_status'  => $source_post->post_status,
					'post_type'    => $source_post->post_type,
					'post_name'    => $source_post->post_name,
				] );
			}

			if ( is_wp_error( $updated_post_id ) ) {
				error_log( sprintf( 'BoltSync: Failed to %s post "%s" on site %d: %s',
					$created_new_post ? 'create' : 'update',
					$source_post->post_title,
					$link_info->blog_id,
					$updated_post_id->get_error_message()
				) );
				restore_current_blog();
				continue;
			}

			$content = $source_post->post_content;

			$content = $this->content_sync_service->sync_inline_links( $content, $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );
			restore_current_blog();

			$content = $this->content_sync_service->sync_inline_media_blocks( $content, $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );
			restore_current_blog();

			$content = $this->content_sync_service->sync_acf_block_attachments( $content, $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );
			restore_current_blog();

			/**
			 * Filters the list of fields to sync for this target site.
			 *
			 * @param string[] $fields        Default sync field list.
			 * @param int      $post_id       Source post ID.
			 * @param int      $target_blog_id Target blog ID.
			 */
			$sync_fields = apply_filters( 'bolt_sync_sync_fields', [ 'acf', 'taxonomy', 'seo', 'thumbnail' ], $source_post->ID, $link_info->blog_id );

			if ( in_array( 'acf', $sync_fields, true ) ) {
				$this->acf_sync_service->sync_acf_fields( $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );
				restore_current_blog();
			}

			if ( in_array( 'taxonomy', $sync_fields, true ) ) {
				$this->taxonomy_sync_service->sync_post_terms( $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );
				restore_current_blog();
			}

			if ( in_array( 'seo', $sync_fields, true ) ) {
				$this->seo_sync_service->sync_yoast_seo_meta( $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );
				restore_current_blog();
			}

			if ( in_array( 'thumbnail', $sync_fields, true ) ) {
				$thumb_result = $this->content_sync_service->sync_post_thumbnail( $source_post->ID, $updated_post_id, $source_site_id, $link_info->blog_id );

				if ( is_wp_error( $thumb_result ) ) {
					$warnings[] = $thumb_result->get_error_message();
				}

				restore_current_blog();
			}

			$this->update_post_meta_link( $link_id, $updated_post_id, $link_info->blog_id );

			if ( $created_new_post ) {
				// Update the stored post_id in the items table.
				global $wpdb;
				$wpdb->update(
					$this->items_table_name,
					[ 'post_id' => $updated_post_id ],
					[
						'link_id' => $link_id,
						'blog_id' => $link_info->blog_id,
					],
					[ '%d' ],
					[ '%d', '%d' ]
				);
				wp_cache_delete( "bolt_sync_link_{$link_id}", 'bolt_sync' );

				error_log( sprintf( 'BoltSync: Created post %d on site %d for link %d.', $updated_post_id, $link_info->blog_id, $link_id ) );
			} else {
				error_log( sprintf( 'BoltSync: Updated post %d on site %d for link %d.', $updated_post_id, $link_info->blog_id, $link_id ) );
			}

			restore_current_blog();
		}

		if ( ! empty( $warnings ) ) {
			foreach ( $warnings as $warning ) {
				// Surface attachment mapping warnings as admin notices during REST requests.
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					add_action( 'admin_notices', static function () use ( $warning ) {
						echo '<div class="notice notice-warning"><p>' . esc_html( $warning ) . '</p></div>';
					} );
				} else {
					error_log( 'BoltSync sync warning: ' . $warning );
				}
			}
		}

		restore_current_blog();

		return true;
	}

	// -------------------------------------------------------------------------
	// Before-delete hook
	// -------------------------------------------------------------------------

	/**
	 * Removes the deleted post's item from its link group when a post is permanently deleted.
	 * Hooked to before_delete_post to keep link groups consistent.
	 *
	 * @param int|string $post_id
	 *
	 * @return void
	 */
	public function before_delete_post( int|string $post_id ): void {
		$post_id = (int) $post_id;

		$link_id = $this->get_link_id_from_post( $post_id );

		if ( ! $link_id ) {
			return;
		}

		$this->remove_link_item( $link_id, get_current_blog_id(), $post_id );
	}

	// -------------------------------------------------------------------------
	// Sites
	// -------------------------------------------------------------------------

	/**
	 * Returns a minimal site descriptor for a given blog ID, or false if not found.
	 * Normalises the site URL to a consistent `site_url` property.
	 *
	 * @param int $site_id Blog ID; defaults to the current blog when 0.
	 *
	 * @return object|false Object with `site_url` (string) and `path` (string), or false.
	 */
	public function get_site_by_id( int $site_id = 0 ): object|false {
		$site_id = $site_id ?: get_current_blog_id();

		foreach ( $this->get_sites() as $site ) {
			if ( (int) $site->blog_id === $site_id ) {
				return (object) [
					'site_url' => get_site_url( $site_id ),
					'path'     => $site->path,
				];
			}
		}

		return false;
	}

	/**
	 * Returns all sites in the network, enriched with network_id, main_site_id and locale.
	 * Results are cached in the bolt_sync group for one hour.
	 *
	 * @return object[]
	 */
	public function get_sites(): array {
		global $wpdb;

		$cached = wp_cache_get( 'bolt_sync_get_sites', 'bolt_sync' );
		if ( $cached !== false ) {
			return apply_filters( 'bolt_sync_sites', $cached );
		}

		$sites = get_sites( [ 'network_id' => get_current_network_id() ] );

		$networks = $wpdb->get_results( "SELECT * FROM {$wpdb->site}" ); // phpcs:ignore

		foreach ( $sites as $key => $site ) {
			$network_id = null;

			foreach ( $networks as $network ) {
				if ( $network->domain === $site->domain ) {
					$network_id = (int) $network->id;
					break;
				}
			}

			$sites[ $key ] = (object) array_merge( (array) $site, [
				'network_id'   => $network_id,
				'main_site_id' => get_main_site_id( $network_id ),
				'locale'       => $this->get_locale_by_site( (int) $site->blog_id ),
			] );
		}

		wp_cache_set( 'bolt_sync_get_sites', $sites, 'bolt_sync', HOUR_IN_SECONDS );

		return apply_filters( 'bolt_sync_sites', $sites );
	}

	/**
	 * Returns the locale string for a given site, falling back to 'en_gb'.
	 * Filterable via the 'bolt_sync_locale' filter.
	 *
	 * @param int $site_id
	 *
	 * @return string
	 */
	private function get_locale_by_site( int $site_id ): string {
		$locale = get_blog_option( $site_id, 'WPLANG', 'en_gb' );
		$locale = $locale !== '' ? $locale : 'en_gb';

		return (string) apply_filters( 'bolt_sync_locale', $locale, $site_id );
	}

	// -------------------------------------------------------------------------
	// Admin user helper
	// -------------------------------------------------------------------------

	/**
	 * Returns the first administrator user ID for the current blog, cached.
	 */
	public function get_admin_user_id(): int {
		$blog_id   = get_current_blog_id();
		$cache_key = sprintf( self::ADMIN_USER_KEY, $blog_id );
		$cached    = wp_cache_get( $cache_key, 'bolt_sync' );

		if ( $cached !== false ) {
			return (int) $cached;
		}

		$users   = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$user_id = ! empty( $users ) ? (int) $users[0] : 0;

		wp_cache_set( $cache_key, $user_id, 'bolt_sync', HOUR_IN_SECONDS );

		return $user_id;
	}

	// -------------------------------------------------------------------------
	// Validation helpers
	// -------------------------------------------------------------------------

	/**
	 * Validates that all posts in a link group share the same post type.
	 * Logs a warning and returns false if a mismatch is found.
	 *
	 * @param int      $link_id
	 * @param object[] $link_info
	 * @param string   $post_type
	 *
	 * @return bool
	 */
	public function validate_link_info_post_type( int $link_id, array $link_info, string $post_type ): bool {
		foreach ( $link_info as $item ) {
			if ( empty( $item->post_id ) ) {
				continue;
			}

			switch_to_blog( (int) $item->blog_id );
			$found_type = get_post_type( (int) $item->post_id );
			restore_current_blog();

			if ( $found_type !== $post_type ) {
				error_log( sprintf(
					'BoltSync: Post type mismatch in link %d. Found "%s", expected "%s".',
					$link_id,
					$found_type,
					$post_type
				) );

				return false;
			}
		}

		return true;
	}

	/**
	 * Resolves the post type from the first item in a link_info array.
	 *
	 * @param object[] $link_info
	 *
	 * @return string
	 */
	private function get_post_type_from_link( array $link_info ): string {
		$first = reset( $link_info );

		switch_to_blog( (int) $first->blog_id );
		$post_type = (string) get_post_type( (int) $first->post_id );
		restore_current_blog();

		return $post_type;
	}
}
