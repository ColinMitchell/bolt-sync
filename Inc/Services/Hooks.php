<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

use BoltSync\Inc\Api\Api;
use BoltSync\Inc\Plugin;
use BoltSync\Inc\BoltSyncManager;

/**
 * Registers all WordPress hooks for the plugin.
 * All service dependencies are injected — no `new` calls inside.
 */
class Hooks {

	/**
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @var Api
	 */
	protected Api $api;

	/**
	 * @var BoltSyncManager
	 */
	protected BoltSyncManager $bolt_sync_manager;

	/**
	 * @var BoltSyncValidator
	 */
	protected BoltSyncValidator $post_sync_validator;

	/**
	 * @var ValidatorCron
	 */
	protected ValidatorCron $validator_cron;

	public function __construct(
		Plugin $plugin,
		Core $core,
		Api $api,
		BoltSyncManager $bolt_sync_manager,
		BoltSyncValidator $post_sync_validator,
		ValidatorCron $validator_cron
	) {
		$this->plugin              = $plugin;
		$this->core                = $core;
		$this->api                 = $api;
		$this->bolt_sync_manager   = $bolt_sync_manager;
		$this->post_sync_validator = $post_sync_validator;
		$this->validator_cron      = $validator_cron;

		add_action( 'init', [ $this, 'add_hooks' ] );
	}

	/**
	 * Registers all WordPress action and filter hooks for the plugin.
	 *
	 * @return void
	 */
	public function add_hooks(): void {
		add_action( 'plugins_loaded', [ $this->plugin, 'plugin_loaded' ] );
		add_action( 'plugins_loaded', [ $this->plugin, 'custom_cli_commands' ] );
		add_action( 'rest_api_init', [ $this->api, 'register_routes' ] );

		$this->validator_cron->init();

		// Action Scheduler callback for async sync jobs.
		add_action( 'bolt_sync_sync_post', [ $this->core, 'handle_scheduled_sync' ], 10, 1 );

		// Clear validator cache and queue re-validation after sync completes.
		add_action( 'bolt_sync_after_sync', function ( int $post_id, $link_id, $result, int $source_blog_id ) {
			$this->post_sync_validator->clear_cache_by_post_id( $post_id );

			if ( $link_id && function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					'bolt_sync_validate_post',
					[ [ 'post_id' => $post_id, 'source_blog_id' => $source_blog_id ] ],
					'bolt_sync',
					true
				);
			}
		}, 10, 4 );

		// Clear validator cache for the entire link group when any post is saved.
		add_action( 'wp_after_insert_post', function ( int $post_id, \WP_Post $post, bool $update ) {
			if ( ! $update || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}

			$link_id = $this->core->get_link_id_from_post( $post_id );
			if ( ! $link_id ) {
				return;
			}

			$link = $this->core->get_link( $link_id );
			if ( ! $link ) {
				return;
			}

			foreach ( $link->link_info as $item ) {
				if ( ! empty( $item->post_id ) ) {
					$this->post_sync_validator->clear_cache_by_post_id( (int) $item->post_id );
				}
			}
		}, 100, 3 );

		// Action Scheduler callback for async validation jobs.
		add_action( 'bolt_sync_validate_post', function ( array $args ) {
			$post_id        = (int) ( $args['post_id'] ?? 0 );
			$source_blog_id = (int) ( $args['source_blog_id'] ?? get_main_site_id() );

			if ( ! $post_id ) {
				return;
			}

			switch_to_blog( $source_blog_id );
			$this->post_sync_validator->validate_post_sync( $post_id, false );
			restore_current_blog();
		}, 10, 1 );

		// Inline post-edit validation.
		add_action( 'edit_form_after_title', function () {
			global $post_id;

			if ( ! $post_id ) {
				return;
			}

			if ( ! $this->core->get_link_id_from_post( (int) $post_id ) ) {
				return;
			}

			$this->post_sync_validator->validate_post_sync( (int) $post_id );
		} );

		// Sync hooks — prefer ACF when available so fields are already saved.
		if ( function_exists( 'acf' ) || class_exists( 'ACF' ) ) {
			add_action( 'acf/save_post', function ( $post_id ) {
				if ( ! is_numeric( $post_id ) || empty( get_post( $post_id ) ) ) {
					return;
				}

				if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
					return;
				}

				$post = get_post( (int) $post_id );
				$this->core->sync( (int) $post_id, $post, true );
			}, 99 );
		} else {
			add_action( 'wp_after_insert_post', [ $this->core, 'sync' ], 99, 3 );
		}

		// Cleanup single link item when a post is deleted.
		add_action( 'before_delete_post', [ $this->core, 'before_delete_post' ], 20 );

		// Daily stale-link pruner (scheduled at activate).
		add_action( 'bolt_sync_cleanup', [ $this, 'prune_stale_link_groups' ] );

		// Block editor assets.
		add_action( 'enqueue_block_editor_assets', function () {
			$post_type          = get_post_type();
			$enabled_post_types = apply_filters( 'bolt_sync_enable_bolt_sync_manager', get_post_types( [ 'show_ui' => true ] ) );

			if ( in_array( $post_type, (array) $enabled_post_types, true ) ) {
				$this->bolt_sync_manager->enqueue_scripts();
			}
		} );

		// Expose post_name on REST responses for public post types.
		add_action( 'rest_api_init', function () {
			foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
				add_filter( "rest_prepare_{$post_type}", [ $this->api, 'add_post_name_to_page_response' ], 10, 3 );
			}
		} );

		if ( is_admin() && class_exists( '\AC\ListScreen' ) ) {
			$core = $this->core;
			add_action( 'acp/column_types', static function ( $list_screen ) use ( $core ): void {
				$list_screen->register_column_type( new AdminColumnsColumn( $core ) );
			} );
		}

	}

	/**
	 * Deletes link groups that have fewer than 2 active items.
	 * Runs daily via the bolt_sync_cleanup cron event.
	 */
	public function prune_stale_link_groups(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$items_table = $wpdb->base_prefix . 'bolt_sync_link_items';
		$links_table = $wpdb->base_prefix . 'bolt_sync_links';

		// Find link IDs with fewer than 2 active items.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale_ids = $wpdb->get_col( "
			SELECT l.id
			FROM `{$links_table}` l
			LEFT JOIN `{$items_table}` i ON i.link_id = l.id AND i.active = 1
			GROUP BY l.id
			HAVING COUNT(i.id) < 2
		" );

		restore_current_blog();

		foreach ( $stale_ids as $link_id ) {
			$this->core->delete_link( (int) $link_id );
			error_log( sprintf( 'Bolt Sync cleanup: Pruned stale link group %d.', $link_id ) );
		}
	}

	/**
	 * Deregisters the primary action and REST hooks, typically used during testing.
	 *
	 * @return void
	 */
	public function remove_hooks(): void {
		remove_action( 'plugins_loaded', [ $this->plugin, 'plugin_loaded' ] );
		remove_action( 'rest_api_init', [ $this->api, 'register_routes' ] );
	}
}
