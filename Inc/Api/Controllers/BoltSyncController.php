<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Api\Controllers;

use BoltSync\Inc\Plugin;
use BoltSync\Inc\Services\Core;
use BoltSync\Inc\Services\BoltSyncValidator;
use WP_REST_Request;

/**
 * Handles CRUD operations for post link groups via the REST API.
 */
final class BoltSyncController extends Controller {

	/**
	 * @var BoltSyncValidator
	 */
	private BoltSyncValidator $validator;

	/**
	 * @param Plugin            $plugin
	 * @param Core              $core
	 * @param BoltSyncValidator $validator
	 */
	public function __construct( Plugin $plugin, Core $core, BoltSyncValidator $validator ) {
		parent::__construct( $plugin, $core );
		$this->validator = $validator;
	}

	/**
	 * Returns full UI data for the block-editor panel.
	 *
	 * Response shape:
	 *   {
	 *     sites:              object[],  // one entry per network site
	 *     suggested_group_id: int|null   // ID of a joinable peer group, or null
	 *   }
	 *
	 * @param int $post_id
	 *
	 * @return array{sites: object[], suggested_group_id: int|null}|false
	 */
	public function get_bolt_sync_manager( int $post_id ): array|false {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		$link_id         = $this->core->get_link_id_from_post( $post_id );
		$link            = $link_id ? $this->core->get_link( $link_id ) : false;
		$current_blog_id = get_current_blog_id();
		$current_user_id = get_current_user_id();

		// Integrity check: ensure this post is actually in the link group.
		if ( $link && ! $this->validate_link( $link, $post_id, $current_blog_id ) ) {
			error_log( sprintf( 'BoltSync: Post %d does not belong to link %d — removing stale meta.', $post_id, $link_id ) );
			$this->core->update_post_meta_link( 0, $post_id, $current_blog_id );
			$link    = false;
			$link_id = false;
		}

		// Build a fast blog_id → link_item lookup so we don't nest loops.
		$link_items = [];
		if ( $link ) {
			foreach ( $link->link_info as $item ) {
				$link_items[ (int) $item->blog_id ] = $item;
			}
		}

		$suggested_group_id = null;
		$result             = [];

		foreach ( $this->core->get_sites() as $site ) {
			$blog_id    = (int) $site->blog_id;
			$is_current = ( $blog_id === $current_blog_id );

			// Sensible defaults — overwritten below when data is available.
			$row           = clone $site;
			$row->isLink   = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$row->link_info = (object) [ // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				'blog_id'   => $blog_id,
				'post_id'   => $is_current ? $post_id : 0,
				'active'    => false,
				'post'      => null,
				'permalink' => null,
			];
			$row->is_current_site = $is_current; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$row->post_name       = $is_current ? $post->post_name : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$row->matching_post   = null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

			// ── Populate from link group membership ──────────────────────────────
			if ( isset( $link_items[ $blog_id ] ) ) {
				$li = $link_items[ $blog_id ];

				if ( $li->post_id ) {
					if ( ! $is_current ) {
						switch_to_blog( $blog_id );
					}

					$fetched = get_post( (int) $li->post_id );

					if ( $fetched ) {
						$post_obj = clone $fetched; // never mutate the WP object cache
						unset( $post_obj->post_content );
						$post_obj->edit_link = $this->get_edit_link( (int) $li->post_id, $current_user_id );
						$post_obj->permalink = get_permalink( (int) $li->post_id );

						$row->isLink   = true; // phpcs:ignore
						$row->link_info = (object) [ // phpcs:ignore
							'blog_id'   => $blog_id,
							'post_id'   => (int) $li->post_id,
							'active'    => true,
							'post'      => $post_obj,
							'permalink' => $post_obj->permalink,
						];
					}

					if ( ! $is_current ) {
						restore_current_blog();
					}
				}
			}

			// ── Sibling-post lookup for non-current sites ────────────────────────
			// Finds a post at the same path on the target site so the UI can offer
			// a link suggestion or a "join group" prompt.
			if ( ! $is_current ) {
				switch_to_blog( $blog_id );

				if ( $post_id === (int) get_option( 'page_on_front' ) ) {
					$matching = get_post( (int) get_option( 'page_on_front' ) );
				} else {
					$matching = get_page_by_path( $post->post_name, OBJECT, $post->post_type );
				}

				if ( $matching && $matching->post_status === 'publish' ) {
					$m = clone $matching;
					unset( $m->post_content );
					$m->permalink = get_permalink( $matching );
					$m->edit_link = $this->get_edit_link( $matching->ID, $current_user_id );
					$row->matching_post = $m; // phpcs:ignore

					// Expose the peer group ID as a per-site flag for the checkbox
					// UI (CheckboxSite uses it to show "Already in group").
					// Only stamp it when it's a *different* group from ours.
					$peer_link_id = $this->core->get_link_id_from_post( $matching->ID );
					if ( $peer_link_id && $peer_link_id !== $link_id ) {
						$row->matching_link_id = $peer_link_id; // phpcs:ignore
						// Surface the best candidate group top-level so JS doesn't
						// have to reduce across all sites.
						$suggested_group_id = $peer_link_id;
					}
				}

				restore_current_blog();
			}

			$result[] = $row;
		}

		$validation_status = null;

		if ( $link && $link_id ) {
			$cached = $this->validator->get_cached_result( $post_id );

			if ( $cached !== null ) {
				$total = count( $link->link_info );

				if ( $cached['valid'] ) {
					$validation_status = [
						'pass'  => true,
						'total' => $total,
					];
				} else {
					$failed_blog_ids = array_values( array_unique(
						array_map( static fn( $issue ) => (int) $issue['target_site_id'], $cached['issues'] ?? [] )
					) );

					$path_map     = [];
					foreach ( $result as $site_row ) {
						$path_map[ (int) $site_row->blog_id ] = $site_row->path;
					}

					$validation_status = [
						'pass'         => false,
						'total'        => $total,
						'failed_sites' => array_map( static fn( $bid ) => [
							'blog_id' => $bid,
							'path'    => $path_map[ $bid ] ?? "Site {$bid}",
						], $failed_blog_ids ),
					];
				}
			}
		}

		return [
			'sites'              => $result,
			'suggested_group_id' => $suggested_group_id,
			'validation_status'  => $validation_status,
		];
	}

	/**
	 * Returns the link ID for a given post, or false.
	 */
	public function get_link_by_post_id( int $post_id ): int|false {
		return $this->core->get_link_id_from_post( $post_id );
	}

	/**
	 * REST: POST /bolt-sync/v1/link
	 */
	public function insert_link( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = json_decode( $request->get_body(), false );

		if ( empty( $data ) ) {
			return new \WP_Error( 'bolt_sync_missing_data', 'Request body is empty.', [ 'status' => 400 ] );
		}

		$link_info = $this->create_link_info_from_sites( (int) $data->postId, $data->sites ); // phpcs:ignore

		$link_id = $this->core->insert_link( $link_info );

		if ( ! $link_id ) {
			return new \WP_Error( 'bolt_sync_insert_failed', 'Failed to insert link.', [ 'status' => 500 ] );
		}

		$this->core->update_post_meta_link( $link_id, (int) $data->postId, get_current_blog_id() ); // phpcs:ignore

		return new \WP_REST_Response( $link_id, 201 );
	}

	/**
	 * REST: POST /bolt-sync/v1/link/{id}
	 */
	public function update_link( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = json_decode( $request->get_body(), false );

		if ( empty( $data ) ) {
			return new \WP_Error( 'bolt_sync_missing_data', 'Request body is empty.', [ 'status' => 400 ] );
		}

		$link_id   = (int) $request->get_param( 'id' );
		$link_info = $this->create_link_info_from_sites( (int) $data->postId, $data->sites ); // phpcs:ignore

		$is_link_active = false;
		foreach ( $link_info as $info ) {
			if ( (int) $info->blog_id !== get_current_blog_id() && $info->active === true ) {
				$is_link_active = true;
				break;
			}
		}

		$result = $this->core->update_link( $link_id, $link_info, $is_link_active );

		if ( ! $result ) {
			return new \WP_Error( 'bolt_sync_update_failed', 'Failed to update link.', [ 'status' => 500 ] );
		}

		return new \WP_REST_Response( true, 200 );
	}

	/**
	 * REST: GET /bolt-sync/v1/link/{id}
	 */
	public function get_link( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link_id = (int) $request->get_param( 'id' );

		$link = $this->core->get_link( $link_id );

		if ( ! $link ) {
			return new \WP_Error( 'bolt_sync_not_found', 'Link not found.', [ 'status' => 404 ] );
		}

		return new \WP_REST_Response( $link, 200 );
	}

	/**
	 * REST: DELETE /bolt-sync/v1/link/{id}/leave
	 * Removes only the current site's item from the link group, leaving others intact.
	 */
	public function leave_link( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link_id = (int) $request->get_param( 'id' );
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $post_id ) {
			return new \WP_Error( 'bolt_sync_missing_post_id', 'post_id is required.', [ 'status' => 400 ] );
		}

		$link = $this->core->get_link( $link_id );

		if ( ! $link ) {
			return new \WP_Error( 'bolt_sync_not_found', 'Link not found.', [ 'status' => 404 ] );
		}

		$this->core->remove_link_item( $link_id, get_current_blog_id(), $post_id );

		return new \WP_REST_Response( true, 200 );
	}

	/**
	 * REST: POST /bolt-sync/v1/link/{id}/join
	 * Adds the current site's post to an existing link group without touching the other members.
	 */
	public function join_link( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link_id = (int) $request->get_param( 'id' );
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $post_id ) {
			return new \WP_Error( 'bolt_sync_missing_post_id', 'post_id is required.', [ 'status' => 400 ] );
		}

		$link = $this->core->get_link( $link_id );

		if ( ! $link ) {
			return new \WP_Error( 'bolt_sync_not_found', 'Link not found.', [ 'status' => 404 ] );
		}

		$this->core->add_link_item( $link_id, get_current_blog_id(), $post_id );

		return new \WP_REST_Response( $link_id, 200 );
	}

	/**
	 * REST: DELETE /bolt-sync/v1/link/{id}
	 */
	public function delete_link( WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$link_id = (int) $request->get_param( 'id' );

		$link = $this->core->get_link( $link_id );

		if ( ! $link ) {
			return new \WP_Error( 'bolt_sync_not_found', 'Link not found.', [ 'status' => 404 ] );
		}

		$deleted = $this->core->delete_link( $link_id );

		return new \WP_REST_Response( $deleted, 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true if the given post/blog pair exists in the link group.
	 *
	 * @param object|array $link
	 * @param int          $post_id
	 * @param int          $blog_id
	 *
	 * @return bool
	 */
	private function validate_link( object|array $link, int $post_id, int $blog_id ): bool {
		foreach ( $link->link_info as $link_info ) {
			if ( (int) $link_info->post_id === $post_id && (int) $link_info->blog_id === $blog_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a link_info array from the current post and the submitted site list.
	 *
	 * @param int   $post_id
	 * @param array $sites
	 *
	 * @return array
	 */
	private function create_link_info_from_sites( int $post_id, array $sites ): array {
		$link_info = [
			(object) [
				'blog_id' => get_current_blog_id(),
				'post_id' => $post_id,
				'active'  => true,
			],
		];

		$current_blog_id = get_current_blog_id();

		foreach ( $sites as $site ) {
			if ( (int) $site->blog_id === $current_blog_id ) {
				continue; // current site is already the first entry
			}

			$link_info[] = (object) [
				'blog_id' => (int) $site->blog_id,
				'post_id' => $site->link_info->post_id ?? false,
				'active'  => $site->link_info->active ?? false,
			];
		}

		return $link_info;
	}

	/**
	 * Returns the edit link for a post, impersonating the first admin if the
	 * current user lacks the capability.
	 */
	private function get_edit_link( int $post_id, int $current_user_id ): string {
		if ( $current_user_id && current_user_can( 'edit_post', $post_id ) ) {
			return (string) urldecode( (string) get_edit_post_link( $post_id ) );
		}

		$admin_user_id = $this->core->get_admin_user_id();
		wp_set_current_user( $admin_user_id );
		$link = urldecode( (string) get_edit_post_link( $post_id ) );
		wp_set_current_user( 0 );

		return $link;
	}
}
