<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Api;

use BoltSync\Inc\Api\Controllers\GeneralController;
use BoltSync\Inc\Api\Controllers\BoltSyncController;
use BoltSync\Inc\Plugin;
use BoltSync\Inc\Services\Core;
use BoltSync\Inc\Services\BoltSyncValidator;

/**
 * Registers all REST API routes.
 */
class Api {

	/**
	 * @var string
	 */
	protected string $namespace = 'bolt-sync/v1';

	/**
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * @var BoltSyncController
	 */
	protected BoltSyncController $bolt_sync_controller;

	/**
	 * @var GeneralController
	 */
	protected GeneralController $general_controller;

	/**
	 * @param Plugin            $plugin
	 * @param Core              $core
	 * @param BoltSyncValidator $validator
	 */
	public function __construct( Plugin $plugin, Core $core, BoltSyncValidator $validator ) {
		$this->plugin                       = $plugin;
		$this->bolt_sync_controller = new BoltSyncController( $plugin, $core, $validator );
		$this->general_controller           = new GeneralController( $plugin, $core, $validator );
	}

	/**
	 * Registers all REST API routes for the bolt-sync/v1 namespace.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/get-link-id/(?P<id>[0-9-_,]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_link_by_post_id' ],
				'permission_callback' => [ $this, 'authorize_read' ],
			],
		] );

		register_rest_route( $this->namespace, '/link', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this->bolt_sync_controller, 'insert_link' ],
				'permission_callback' => [ $this, 'authorize_write' ],
			],
		] );

		register_rest_route( $this->namespace, '/bolt-sync-manager/(?P<id>[0-9-_,]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_bolt_sync_manager' ],
				'permission_callback' => [ $this, 'authorize_read' ],
			],
		] );

		register_rest_route( $this->namespace, '/link/(?P<id>[0-9]+)/leave', [
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this->bolt_sync_controller, 'leave_link' ],
				'permission_callback' => [ $this, 'authorize_write' ],
			],
		] );

		register_rest_route( $this->namespace, '/link/(?P<id>[0-9]+)/join', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this->bolt_sync_controller, 'join_link' ],
				'permission_callback' => [ $this, 'authorize_write' ],
			],
		] );

		register_rest_route( $this->namespace, '/link/(?P<id>[0-9-_,]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this->bolt_sync_controller, 'get_link' ],
				'permission_callback' => [ $this, 'authorize_read' ],
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this->bolt_sync_controller, 'delete_link' ],
				'permission_callback' => [ $this, 'authorize_write' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this->bolt_sync_controller, 'update_link' ],
				'permission_callback' => [ $this, 'authorize_write' ],
			],
		] );

		register_rest_route( $this->namespace, '/all-links', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_all_links_request' ],
				'permission_callback' => [ $this, 'authorize_read' ],
			],
		] );

		register_rest_route( $this->namespace, '/sync', [
			[
				'methods'             => 'POST',
				'callback'            => [ $this->general_controller, 'sync_post' ],
				'permission_callback' => [ $this, 'authorize_write' ],
			],
		] );

		register_rest_route( $this->namespace, '/sync-status/(?P<id>[0-9]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this->general_controller, 'get_sync_status' ],
				'permission_callback' => [ $this, 'authorize_read' ],
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Route callbacks
	// -------------------------------------------------------------------------

	/**
	 * Returns the link ID associated with the given post ID.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_link_by_post_id( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		return new \WP_REST_Response(
			$this->bolt_sync_controller->get_link_by_post_id( $post_id ),
			200
		);
	}

	/**
	 * Returns the full bolt sync manager UI data for the given post ID.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_bolt_sync_manager( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'id' );

		$data = $this->bolt_sync_controller->get_bolt_sync_manager( $post_id );

		if ( $data === false ) {
			return new \WP_Error( 'bolt_sync_missing_data', 'Bolt Sync Manager data error.', [ 'status' => 400 ] );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Returns all link groups. Optionally flushes the cache when ?cache=false is passed.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_all_links_request( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cache_param = $request->get_param( 'cache' );

		if ( ! empty( $cache_param ) && ! filter_var( $cache_param, FILTER_VALIDATE_BOOLEAN ) ) {
			$this->general_controller->flush_cache();
		}

		return new \WP_REST_Response( $this->general_controller->get_all_links(), 200 );
	}

	/**
	 * Appends post_name to the REST response for public post types.
	 *
	 * @param \WP_REST_Response $data
	 * @param \WP_Post          $post
	 * @param string            $context
	 *
	 * @return \WP_REST_Response
	 */
	public function add_post_name_to_page_response( $data, $post, $context ) {
		$data->data['post_name'] = $post->post_name;

		return $data;
	}

	// -------------------------------------------------------------------------
	// Authorization
	// -------------------------------------------------------------------------

	/**
	 * Read endpoints: any editor-level user can fetch sync status / link data.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function authorize_read( \WP_REST_Request $request ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Write/delete endpoints: requires delete_sites on multisite or manage_options on single-site.
	 * Filterable via 'bolt_sync_rest_authorize_capability'.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function authorize_write( \WP_REST_Request $request ): bool {
		$capability = is_multisite() ? 'delete_sites' : 'manage_options';

		/**
		 * Filter the capability required for write operations.
		 *
		 * @param string           $capability
		 * @param \WP_REST_Request  $request
		 */
		$capability = (string) apply_filters( 'bolt_sync_rest_authorize_capability', $capability, $request );

		return current_user_can( $capability );
	}

	/**
	 * @deprecated Use authorize_read() or authorize_write() instead.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function authorize( \WP_REST_Request $request ): bool {
		return $this->authorize_read( $request );
	}
}
