<?php
/**
 * Plugin Name: Bolt Sync
 * Description: Adds the functionality to sync WordPress posts between multi-network sites.
 * Network: true
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * Version: 1.3.0
 * Author: Colin Mitchell
 * Author URI: https://github.com/colinmitchell
 * Plugin URI: https://github.com/colinmitchell/bolt-sync
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bolt-sync
 *
 * @package BoltSync
 */

declare( strict_types=1 );

use BoltSync\Inc\Plugin;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	die( 'We\'re sorry, but you can not directly access this file.' );
}

define( 'BOLT_SYNC_MAIN_FILE', __FILE__ );
define( 'BOLT_SYNC_PLUGIN_ABSOLUTE', __FILE__ );
define( 'BOLT_SYNC_VERSION', '1.3.0' );
define( 'BOLT_SYNC_DB_VERSION', '1.3.0' );
define( 'BOLT_SYNC_TEXTDOMAIN', 'bolt-sync' );
define( 'BOLT_SYNC_NAME', 'Bolt Sync' );
define( 'BOLT_SYNC_PLUGIN_ROOT', plugin_dir_path( __FILE__ ) );
define( 'BOLT_SYNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOLT_SYNC_MIN_PHP_VERSION', '8.1' );
define( 'BOLT_SYNC_WP_VERSION', '6.0' );

if ( version_compare( PHP_VERSION, BOLT_SYNC_MIN_PHP_VERSION, '<=' ) ) {
	add_action(
		'admin_init',
		static function () {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	);
	add_action(
		'admin_notices',
		static function () {
			echo wp_kses_post(
				sprintf(
					'<div class="notice notice-error"><p>%s</p></div>',
					// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
					__( '"Bolt Sync" requires PHP 8.1 or newer.', BOLT_SYNC_TEXTDOMAIN )
				)
			);
		}
	);

	// Return early to prevent loading the plugin.
	return;
}

if ( ! is_multisite() ) {
	add_action(
		'admin_init',
		static function () {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			set_transient( 'bolt_sync_multisite_required_notice', true, 30 );
		}
	);

	add_action(
		'admin_notices',
		static function () {
			if ( get_transient( 'bolt_sync_multisite_required_notice' ) ) {
				delete_transient( 'bolt_sync_multisite_required_notice' );
				echo wp_kses_post(
					sprintf(
						'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
						// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralDomain
						__( '"Bolt Sync" requires WordPress Multisite to be enabled. The plugin has not been activated.', BOLT_SYNC_TEXTDOMAIN )
					)
				);
			}
		}
	);

	// Return early to prevent loading the plugin.
	return;
}

// include autoloader from composer
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

// Register our blocks
add_action( 'init', function () {
	// register_block_type( BOLT_SYNC_PLUGIN_DIR . '/build/example-block' );
} );

// Setup plugin
add_action( 'bolt_sync_init', function ( Plugin $plugin ) {
	$plugin->init();
} );

/**
 * Start Plugin
 *
 * @param Plugin $plugin
 *
 * @since 1.0.0
 */
do_action( 'bolt_sync_init', new Plugin() );
