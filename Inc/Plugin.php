<?php
declare( strict_types=1 );

namespace BoltSync\Inc;

use BoltSync\Admin\Admin;
use BoltSync\Inc\Api\Api;
use BoltSync\Inc\Services\AdminBarSyncStatus;
use BoltSync\Inc\Services\Cleanup;
use BoltSync\Inc\Services\Core;
use BoltSync\Inc\Services\Hooks;
use BoltSync\Inc\Services\BoltSyncValidator;
use BoltSync\Inc\Services\ValidatorCron;
use BoltSync\Inc\Upgrades\BoltSyncUpgrade_1_1_0;
use BoltSync\Inc\Upgrades\BoltSyncUpgrade_1_2_0;
use BoltSync\Inc\Upgrades\BoltSyncUpgrade_1_3_0;

/**
 * Main Plugin class.
 *
 * All services are instantiated once here and passed via constructor injection.
 * No service should call `new Core()` (or any other service) itself.
 */
class Plugin {

	/**
	 * @var BoltSyncManager
	 */
	protected BoltSyncManager $post_sync_manager;

	/**
	 * @var Api
	 */
	protected Api $api;

	/**
	 * @var Hooks
	 */
	protected Hooks $hooks;

	/**
	 * @var Admin
	 */
	protected Admin $admin;

	/**
	 * @var Cleanup
	 */
	protected Cleanup $cleanup;

	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @var AdminBarSyncStatus
	 */
	protected AdminBarSyncStatus $admin_bar_sync_status;

	public function __construct() {
		$this->register_cache_groups();
		$this->init();
	}

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 * @uses "BOLT_SYNC_init" action
	 *
	 * @since 0.0.1
	 */
	public function init(): void {
		$this->maybe_upgrade();

		$this->core = new Core();

		$validator      = new BoltSyncValidator( $this->core );
		$validator_cron = new ValidatorCron( $this->core, $validator );
		$this->cleanup  = new Cleanup( $this->core );

		$this->api                   = new Api( $this, $this->core, $validator );
		$this->admin                 = new Admin( $this );
		$this->post_sync_manager     = new BoltSyncManager( $this, $this->core, $validator );
		$this->admin_bar_sync_status = new AdminBarSyncStatus( $this->core );

		$this->hooks = new Hooks( $this, $this->core, $this->api, $this->post_sync_manager, $validator, $validator_cron );
	}

	/**
	 * Registers WP object-cache groups.
	 *  - bolt_sync         : global group (shared across sites, works with Redis)
	 *  - bolt_sync_locks   : non-persistent group (per-request memory only — never serialised to Redis)
	 */
	private function register_cache_groups(): void {
		wp_cache_add_global_groups( [ 'bolt_sync' ] );
		wp_cache_add_non_persistent_groups( [ 'bolt_sync_locks' ] );
	}

	/**
	 * Checks the stored DB version and runs any pending upgrade routines.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$current_db_version = get_site_option( 'bolt_sync_db_version', '1.0.0' );

		if ( version_compare( $current_db_version, BOLT_SYNC_DB_VERSION, '<' ) ) {
			$this->upgrade_database( $current_db_version );
			update_site_option( 'bolt_sync_db_version', BOLT_SYNC_DB_VERSION );
		}
	}

	/**
	 * Runs any database upgrade logic for versions older than the current DB version.
	 *
	 * @param string $current_db_version
	 *
	 * @return void
	 */
	private function upgrade_database( string $current_db_version ): void {
		if ( version_compare( $current_db_version, '1.1.0', '<' ) ) {
			( new BoltSyncUpgrade_1_1_0() )->upgrade();
		}

		if ( version_compare( $current_db_version, '1.2.0', '<' ) ) {
			( new BoltSyncUpgrade_1_2_0() )->upgrade();
		}

		if ( version_compare( $current_db_version, '1.3.0', '<' ) ) {
			( new BoltSyncUpgrade_1_3_0() )->upgrade();
		}
	}

	/**
	 * Creates the plugin's custom database tables using dbDelta if they do not already exist.
	 *
	 * @return void
	 */
	private function create_db_tables(): void {
		global $wpdb;

		switch_to_blog( get_main_site_id() );

		$charset_collate = $wpdb->get_charset_collate();
		$links_table     = $wpdb->base_prefix . 'bolt_sync_links';
		$items_table     = $wpdb->base_prefix . 'bolt_sync_link_items';

		$sql_links = "CREATE TABLE IF NOT EXISTS {$links_table} (
			id         bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_type  varchar(100)        NOT NULL DEFAULT '',
			active     tinyint(1)          NOT NULL DEFAULT 1,
			created_at datetime            DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset_collate};";

		$sql_items = "CREATE TABLE IF NOT EXISTS {$items_table} (
			id      bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id bigint(20) UNSIGNED NOT NULL,
			blog_id bigint(20) UNSIGNED NOT NULL,
			post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			active  tinyint(1)          NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			KEY idx_link_id (link_id),
			KEY idx_blog_id (blog_id),
			KEY idx_post_id (post_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_links );
		dbDelta( $sql_items );

		restore_current_blog();
	}

	/**
	 * Runs on plugin activation — creates DB tables and schedules the daily cleanup cron event.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->create_db_tables();

		if ( ! wp_next_scheduled( 'bolt_sync_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'bolt_sync_cleanup' );
		}
	}

	/**
	 * Runs on plugin deactivation — clears all scheduled cron events.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		wp_clear_scheduled_hook( 'bolt_sync_cleanup' );
		wp_clear_scheduled_hook( 'bolt_sync_validate_posts_batch' );
	}

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @return void
	 */
	public function plugin_loaded(): void {
		load_plugin_textdomain( 'bolt-sync' );
	}

	/**
	 * Stub for registering WP-CLI commands.
	 *
	 * @return void
	 */
	public function custom_cli_commands(): void {}

	/**
	 * Returns the REST API service instance.
	 *
	 * @return Api
	 */
	public function get_rest_api(): Api {
		return $this->api;
	}

	/**
	 * Returns the Core service instance.
	 *
	 * @return Core
	 */
	public function get_core(): Core {
		return $this->core;
	}
}
