<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Api\Controllers;

use BoltSync\Inc\Plugin;
use BoltSync\Inc\Services\Core;
use BoltSync\Inc\Services\BoltSyncValidator;

/**
 * Handles general sync operations and the all-links view.
 */
class GeneralController extends Controller {

	protected BoltSyncValidator $post_sync_validator;

	public function __construct( Plugin $plugin, Core $core, BoltSyncValidator $post_sync_validator ) {
		parent::__construct( $plugin, $core );

		$this->post_sync_validator = $post_sync_validator;
	}

	/**
	 * Manually triggers a sync for a post and returns fresh validation data.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_post( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $post_id ) {
			return new \WP_Error( 'bolt_sync_missing_post_id', 'No post_id provided.', [ 'status' => 400 ] );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error( 'bolt_sync_post_not_found', 'Post not found.', [ 'status' => 404 ] );
		}

		$result = $this->core->sync_content( $post );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			$this->post_sync_validator->validate_post_sync( $post_id, false ),
			200
		);
	}

	/**
	 * Returns the Action Scheduler status of the most recent sync job for a post.
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public function get_sync_status( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return new \WP_REST_Response( [ 'status' => 'unavailable' ], 200 );
		}

		$args = [
			'hook'     => 'bolt_sync_sync_post',
			'per_page' => 1,
			'orderby'  => 'date',
			'order'    => 'DESC',
		];

		if ( ! empty( as_get_scheduled_actions( array_merge( $args, [ 'status' => \ActionScheduler_Store::STATUS_PENDING ] ) ) ) ) {
			$lock_expiration = \ActionScheduler::lock()->get_expiration( 'async-request-runner' );
			$eta_seconds     = ( $lock_expiration && $lock_expiration > time() )
				? (int) $lock_expiration - time()
				: null;

			return new \WP_REST_Response( [ 'status' => 'queued', 'eta_seconds' => $eta_seconds ], 200 );
		}

		if ( ! empty( as_get_scheduled_actions( array_merge( $args, [ 'status' => \ActionScheduler_Store::STATUS_RUNNING ] ) ) ) ) {
			return new \WP_REST_Response( [ 'status' => 'running' ], 200 );
		}

		$complete = as_get_scheduled_actions( array_merge( $args, [
			'status'  => \ActionScheduler_Store::STATUS_COMPLETE,
			'orderby' => 'date',
			'order'   => 'DESC',
		] ) );

		if ( ! empty( $complete ) ) {
			return new \WP_REST_Response( [ 'status' => 'complete' ], 200 );
		}

		$failed = as_get_scheduled_actions( array_merge( $args, [
			'status'  => \ActionScheduler_Store::STATUS_FAILED,
			'orderby' => 'date',
			'order'   => 'DESC',
		] ) );

		if ( ! empty( $failed ) ) {
			return new \WP_REST_Response( [ 'status' => 'failed' ], 200 );
		}

		return new \WP_REST_Response( [ 'status' => 'idle' ], 200 );
	}

	/**
	 * Retrieves all links enriched with post titles, edit links, and validation data.
	 *
	 * @return object[]
	 */
	public function get_all_links(): array {
		$links = $this->core->get_all_links();

		if ( empty( $links ) ) {
			return [];
		}

		$current_blog_id = get_current_blog_id();

		foreach ( $links as &$link ) {
			foreach ( $link->link_info as &$link_info ) {
				if ( ! $link_info->active || ! $link_info->post_id ) {
					continue;
				}

				switch_to_blog( $link_info->blog_id );

				$post_title            = get_the_title( $link_info->post_id );
				$link_info->post_title = $post_title ? html_entity_decode( $post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				$link_info->permalink  = get_permalink( $link_info->post_id );

				$admin_user_id = $this->core->get_admin_user_id();
				wp_set_current_user( $admin_user_id );
				$edit_link = get_edit_post_link( $link_info->post_id );
				wp_set_current_user( 0 );

				$link_info->edit_link = $edit_link ? html_entity_decode( $edit_link, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';

				restore_current_blog();

				if ( $link_info->blog_id === $current_blog_id ) {
					$link->validation = $this->post_sync_validator->validate_post_sync( $link_info->post_id, true );
				}
			}
			unset( $link_info );
		}
		unset( $link );

		return $links;
	}
}
