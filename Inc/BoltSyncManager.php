<?php
declare( strict_types=1 );

namespace BoltSync\Inc;

use BoltSync\Inc\Api\Controllers\BoltSyncController;
use BoltSync\Inc\Services\Core;
use BoltSync\Inc\Services\BoltSyncValidator;

/**
 * BoltSyncManager Plugin Class
 */
final class BoltSyncManager {

	/**
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * @var BoltSyncController
	 */
	protected BoltSyncController $controller;

	/**
	 * @param Plugin            $plugin
	 * @param Core              $core
	 * @param BoltSyncValidator $validator
	 */
	public function __construct( Plugin $plugin, Core $core, BoltSyncValidator $validator ) {
		$this->plugin     = $plugin;
		$this->controller = new BoltSyncController( $plugin, $core, $validator );
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Registers the bolt_sync_link_id post meta and enqueues the block-editor JS bundle.
	 *
	 * @return void
	 */
	public function init(): void {
		register_post_meta( '', 'bolt_sync_link_id', [
			'type'         => 'integer',
			'description'  => 'Link ID for the Bolt Sync link relationship.',
			'single'       => true,
			// 'object_subtype' => 'my_article', // optional custom post types
			'show_in_rest' => true,
		] );

		wp_register_script(
			'bolt-sync-manager-js',
			plugins_url( 'build/bolt-sync-manager/index.js', BOLT_SYNC_MAIN_FILE ),
			array(
				'wp-plugins',
				'wp-edit-post',
				'wp-element',
				'wp-components',
			),
			filemtime( BOLT_SYNC_PLUGIN_DIR . 'build/bolt-sync-manager/index.js' ),
			true,
		);
	}

	/**
	 * Localises and enqueues the bolt-sync-manager script for the current post.
	 *
	 * @return void
	 */
	public function enqueue_scripts(): void {
		global $post;
		$current_user = wp_get_current_user();
		$user_role    = ! empty( $current_user->roles ) ? $current_user->roles[0] : false;

		// Dev note, you could also preload any options here too instead of doing useEffect on init
		wp_localize_script(
			'bolt-sync-manager-js',
			'boltSync',
			[
				'apiUrl'          => rest_url( 'bolt-sync/v1' ),
				'userRole'        => $user_role,
				'boltSyncManager' => $this->controller->get_bolt_sync_manager( $post->ID ),
				'linkId'          => $this->controller->get_link_by_post_id( $post->ID ),
			]
		);

		wp_enqueue_script( 'bolt-sync-manager-js' );
	}

	/**
	 * Always authorises access — actual capability checks are handled by the REST layer.
	 *
	 * @return true
	 */
	public function authorize() {
		return true;
	}
}
