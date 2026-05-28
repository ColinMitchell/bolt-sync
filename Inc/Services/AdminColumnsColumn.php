<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

/**
 * AdminColumnsColumn Plugin Class
 */
final class AdminColumnsColumn extends \AC\Column {
	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @param Core $core
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
		add_action( 'admin_head', [ $this, 'add_admin_styles' ] );
		$this->set_type( 'bolt_sync_sync_status' );
		$this->set_label( 'Bolt Sync Sync Status' );
		$this->set_group( 'bolt_sync' );
	}

	/**
	 * Returns the rendered HTML for the Bolt Sync sync-status column cell.
	 *
	 * @param int $id Post ID.
	 *
	 * @return string
	 */
	public function get_value( $id ): string {
		$post_id = $id;
		$post    = get_post( $post_id );
		$sites   = $this->core->get_sites();

		$link_id = $this->core->get_link_id_from_post( $post_id );

		// Get all posts with matching post_name across all sites
		$all_matching_sites = $this->get_all_matching_posts( $post, $sites );

		// If no link is found, return all matching sites (minus current one)
		if ( ! $link_id ) {
			return $this->output( [], $all_matching_sites );
		}

		// Only get link if we have a valid link_id
		$link = $this->core->get_link( $link_id );

		// Cross reference with link to get linked and unlinked arrays
		$result = $this->cross_reference_with_link( $all_matching_sites, $link );

		return $this->output( $result['linked_sites'], $result['unlinked_sites'] );
	}

	/**
	 * Finds posts with the same slug on every other network site.
	 *
	 * @param \WP_Post $post
	 * @param object[] $sites
	 *
	 * @return array
	 */
	private function get_all_matching_posts( $post, $sites ) {
		$matching_sites  = [];
		$current_blog_id = get_current_blog_id();

		foreach ( $sites as $site ) {
			// Skip current site
			if ( $site->blog_id == $current_blog_id ) {
				continue;
			}

			switch_to_blog( $site->blog_id );

			$matching_post = null;

			// Check if the post is the home page
			if ( $post->ID == (int) get_option( 'page_on_front' ) ) {
				$front_page_id = get_option( 'page_on_front' );
				if ( $front_page_id ) {
					$matching_post = get_post( $front_page_id );
				}
			} else {
				// Get the post with the same slug
				$matching_post = get_page_by_path( $post->post_name, OBJECT, $post->post_type );
			}

			if ( $matching_post ) {
				$edit_link   = get_edit_post_link( $matching_post->ID );
				$post_status = get_post_status( $matching_post->ID );
				$permalink   = get_permalink( $matching_post->ID );
				$post_title  = get_the_title( $matching_post->ID );
				$post_slug   = get_post_field( 'post_name', $matching_post->ID );

				$matching_sites[ $site->blog_id ] = [
					'edit_link'   => $edit_link,
					'permalink'   => $permalink,
					'site_path'   => trim( $site->path, '/' ),
					'site_name'   => $site->blogname ?? $site->path,
					'post_status' => $post_status,
					'post_slug'   => $post_slug,
					'post_title'  => $post_title,
					'post_id'     => $matching_post->ID,
				];
			}

			restore_current_blog();
		}

		return $matching_sites;
	}

	/**
	 * Separates matching sites into linked and unlinked buckets by comparing against the link group.
	 *
	 * @param array        $all_matching_sites
	 * @param object|false $link
	 *
	 * @return array
	 */
	private function cross_reference_with_link( $all_matching_sites, $link ) {
		$linked_sites   = [];
		$unlinked_sites = [];

		if ( ! $link || ! isset( $link->link_info ) ) {
			return [
				'linked_sites'   => [],
				'unlinked_sites' => $all_matching_sites,
			];
		}

		// Get blog IDs that are actively linked
		$active_linked_blog_ids = [];
		foreach ( $link->link_info as $link_info ) {
			if ( ! empty( $link_info->post_id ) && $link_info->active && $link_info->blog_id != get_current_blog_id() ) {
				$active_linked_blog_ids[] = $link_info->blog_id;
			}
		}

		// Separate matching sites into linked and unlinked
		foreach ( $all_matching_sites as $blog_id => $site_data ) {
			if ( in_array( $blog_id, $active_linked_blog_ids ) ) {
				$linked_sites[ $blog_id ] = $site_data;
			} else {
				$unlinked_sites[ $blog_id ] = $site_data;
			}
		}

		return [
			'linked_sites'   => $linked_sites,
			'unlinked_sites' => $unlinked_sites,
		];
	}

	/**
	 * Renders the full column cell HTML for linked and unlinked sites.
	 *
	 * @param array $linked_sites
	 * @param array $unlinked_sites
	 *
	 * @return string
	 */
	public function output( $linked_sites, $unlinked_sites ) {
		if ( empty( $linked_sites ) && empty( $unlinked_sites ) ) {
			return '<div class="bolt-sync-status bolt-sync-no-link">
                    <span class="bolt-sync-text">No Matching Posts Found</span>
                </div>';
		}

		$linked_count   = count( $linked_sites );
		$unlinked_count = count( $unlinked_sites );
		$output         = '<div class="bolt-sync-sync-links">';

		// Header with count
		if ( $linked_count > 0 ) {
			$output .= sprintf(
				'<div class="bolt-sync-header">
	            <span class="dashicons dashicons-networking" title="Synced to %d site%s"></span>
	            <span class="bolt-sync-count">%d Linked Site%s</span>
	        </div>',
				$linked_count,
				$linked_count !== 1 ? 's' : '',
				$linked_count,
				$linked_count !== 1 ? 's' : ''
			);
		}

		// Linked sites list
		if ( $linked_count > 0 ) {
			$output .= '<div class="bolt-sync-links-list">';
			foreach ( $linked_sites as $site ) {
				$output .= $this->render_site_item( $site, 'linked' );
			}
			$output .= '</div>';
		}

		// Unlinked sites header and list
		if ( $unlinked_count > 0 ) {
			$output .= '<div class="bolt-sync-links-list unlinked">';
			foreach ( $unlinked_sites as $site ) {
				$output .= $this->render_site_item( $site, 'unlinked' );
			}
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders the HTML for a single site row within the column cell.
	 *
	 * @param array  $site
	 * @param string $type 'linked' or 'unlinked'
	 *
	 * @return string
	 */
	private function render_site_item( $site, $type = 'linked' ): string {
		$status_class = $site['post_status'] === 'publish' ? 'published' : 'draft';
		$status_icon  = $type === 'linked'
			? ( $site['post_status'] === 'publish' ? 'yes-alt' : 'edit' )
			: 'dismiss';
		$status_text  = ucfirst( $site['post_status'] );

		$edit_tooltip = sprintf(
			'Edit %s (ID: %d) - Status: %s',
			esc_attr( $site['post_title'] ),
			$site['post_id'],
			$status_text
		);

		$view_tooltip = sprintf(
			'Preview Post - Status: %s',
			esc_attr( $site['post_title'] )
		);

		$display_text = $site['site_path'] ? '/' . $site['site_path'] . '/ ./' . $site['post_slug'] . '/' : $site['site_name'] . '/ ./' . $site['post_slug'] . '/';

		return sprintf(
			'<div class="bolt-sync-link-item bolt-sync-status-%s">
				<a href="%s" target="_blank" title="%s" class="bolt-sync-edit-link">
					<span class="bolt-sync-site-indicator">
						<span class="dashicons dashicons-%s"></span>
					</span>
					<span class="bolt-sync-site-name">%s</span>
				</a>
				<a href="%s" target="_blank" title="%s" class="bolt-sync-view-link">
					<span class="bolt-sync-external-icon dashicons dashicons-external"></span>
				</a>
			</div>',
			$status_class,
			esc_url( $site['edit_link'] ),
			esc_attr( $edit_tooltip ),
			$status_icon,
			$display_text,
			esc_url( $site['permalink'] ),
			esc_attr( $view_tooltip )
		);
	}

	/**
	 * Outputs inline CSS for the Bolt Sync sync-status column when Admin Columns is active.
	 *
	 * @return void
	 */
	public function add_admin_styles() {
		if ( ! class_exists( 'AC\ListScreen' ) ) {
			return;
		}
		echo '<style>
      	/* Bolt Sync Admin Column Styles */
		.bolt-sync-sync-links {
			font-size: 12px;
			line-height: 1.4;
		}

		.bolt-sync-links-list.unlinked {
			margin-top: 10px;
		}

		.bolt-sync-header {
			display: flex;
			align-items: center;
			gap: 5px;
			padding: 4px 8px;
			background: #f0f0f1;
			border-left: 3px solid #0073aa;
		}

		.bolt-sync-header.unlinked {
			background: #fff4e6;
			border-left: 3px solid #dba617;
		}

		.bolt-sync-header .dashicons {
			color: #0073aa;
			font-size: 14px;
			width: 14px;
			height: 14px;
		}

		.bolt-sync-header.unlinked .dashicons {
			color: #dba617;
		}

		.bolt-sync-count {
			font-weight: 600;
			color: #0073aa;
		}

		.bolt-sync-header.unlinked .bolt-sync-count {
			color: #dba617;
		}

		.bolt-sync-links-list {
			display: flex;
			flex-direction: column;
			gap: 1px;
		}

		.bolt-sync-link-item {
			display: flex;
			align-items: center;
			gap: 2px;
		}

		.bolt-sync-edit-link {
			box-sizing: border-box;
			width: 88%;
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 4px 8px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 3px 0 0 3px;
			text-decoration: none;
			color: #2271b1;
			transition: all 0.2s ease;
			flex: 1;
			font-size: 11px;
			  white-space: nowrap;
		}

		.bolt-sync-view-link {
		box-sizing: border-box;
		width: 12%;
			display: flex;
			align-items: center;
			padding: 4px 8px;
			background: #fff;
			border: 1px solid #ddd;
			text-decoration: none;
			color: #2271b1;
			transition: all 0.2s ease;
			font-size: 11px;
			min-width: 32px;
			justify-content: center;
		}

		.bolt-sync-edit-link:hover,
		.bolt-sync-view-link:hover {
			background: #f6f7f7;
			border-color: #0073aa;
			color: #0073aa;
			text-decoration: none;
		}

		.bolt-sync-site-link {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 4px 8px;
			background: #fff;
			border: 1px solid #ddd;
			text-decoration: none;
			color: #2271b1;
			transition: all 0.2s ease;
			width: 100%;
			font-size: 11px;
		}

		.bolt-sync-site-link:hover {
			background: #f6f7f7;
			border-color: #0073aa;
			color: #0073aa;
			text-decoration: none;
		}

		.bolt-sync-site-indicator {
			display: flex;
			align-items: center;
		}

		.bolt-sync-site-indicator .dashicons {
			font-size: 12px;
			width: 12px;
			height: 12px;
		}

		.bolt-sync-status-published .bolt-sync-site-indicator .dashicons {
			color: #00a32a;
		}

		.bolt-sync-links-list.unlinked .bolt-sync-site-indicator .dashicons {
			color: #FF0000;
		}

		.bolt-sync-status-draft .bolt-sync-site-indicator .dashicons {
			color: #dba617;
		}

		.bolt-sync-site-name {
			flex: 1;
			font-weight: 500;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.bolt-sync-external-icon {
			font-size: 15px !important;
			width: 15px !important;
			height: 15px !important;
		}

		/* Status indicators for no-link states */
		.bolt-sync-status {
			display: flex;
			align-items: center;
			gap: 6px;
			padding: 6px 10px;
			font-size: 11px;
		}

		.bolt-sync-status .dashicons {
			font-size: 14px;
			width: 14px;
			height: 14px;
		}

		.bolt-sync-no-link {
			background: #f6f7f7;
			color: #646970;
			border-left: 3px solid #ddd;
		}

		.bolt-sync-invalid {
			background: #fef7f0;
			color: #d63638;
			border-left: 3px solid #d63638;
		}

		.bolt-sync-inactive, .bolt-sync-no-targets {
			background: #fffbf0;
			color: #b32d2e;
			border-left: 3px solid #dba617;
		}

		.bolt-sync-text {
			font-weight: 500;
		}
	</style>';
	}
	/**
	 * Make column exportable (optional)
	 */
	/*
	public function export() {
		return new AC\Export\Model\StrippedValue( $this );
	}*/
}
