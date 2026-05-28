<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

/**
 * AdminBarSyncStatus Plugin Class
 * Adds Bolt Sync sync status to the admin bar on edit post screens
 */
final class AdminBarSyncStatus {
	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @param Core $core
	 */
	public function __construct( Core $core ) {
		$this->core = $core;

		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_menu' ], 100 );
		add_action( 'admin_head', [ $this, 'add_admin_bar_styles' ] );
		add_action( 'wp_head', [ $this, 'add_admin_bar_styles' ] );
	}

	/**
	 * Adds the Bolt Sync sync-status node to the admin bar on post-edit screens.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 *
	 * @return void
	 */
	public function add_admin_bar_menu( $wp_admin_bar ): void {
		// Only show on post edit screens
		if ( ! $this->is_edit_post_screen() ) {
			return;
		}

		$post_id = $this->get_current_post_id();
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		// Get sync status data
		$sync_data = $this->get_sync_status_data( $post );

		// Create main menu item
		$wp_admin_bar->add_menu( [
			'id'    => 'bolt-sync-sync-status',
			'title' => $this->get_menu_title( $sync_data ),
			'href'  => false,
			'meta'  => [
				'class' => 'bolt-sync-admin-bar-item',
				// 'title' => $this->get_menu_tooltip( $sync_data ),
			],
		] );

		// Add submenu items for each linked/unlinked site
		$this->add_submenu_items( $wp_admin_bar, $sync_data );
	}

	/**
	 * Returns true when the current screen is a post-edit page.
	 *
	 * @return bool
	 */
	private function is_edit_post_screen(): bool {
		global $pagenow;

		return (
				   is_admin() &&
				   in_array( $pagenow, [ 'post.php', 'post-new.php' ] ) &&
				   isset( $_GET['action'] ) &&
				   $_GET['action'] === 'edit'
			   ) || (
				   is_admin() &&
				   $pagenow === 'post.php' &&
				   isset( $_GET['post'] )
			   );
	}

	/**
	 * Returns the post ID being edited on the current screen, or null.
	 *
	 * @return int|null
	 */
	private function get_current_post_id(): ?int {
		global $post;

		if ( isset( $_GET['post'] ) ) {
			return (int) $_GET['post'];
		}

		if ( $post && $post->ID ) {
			return $post->ID;
		}

		return null;
	}

	/**
	 * Builds the linked/unlinked site data for the given post.
	 *
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	private function get_sync_status_data( $post ): array {
		$sites   = $this->core->get_sites();
		$link_id = $this->core->get_link_id_from_post( $post->ID );

		// Get all posts with matching post_name across all sites
		$all_matching_sites = $this->get_all_matching_posts( $post, $sites );

		// If no link is found, return all matching sites as unlinked
		if ( ! $link_id ) {
			return [
				'linked_sites'   => [],
				'unlinked_sites' => $all_matching_sites,
				'has_link'       => false,
				'total_sites'    => count( $all_matching_sites ),
			];
		}

		// Get link data
		$link   = $this->core->get_link( $link_id );
		$result = $this->cross_reference_with_link( $all_matching_sites, $link );

		return [
			'linked_sites'   => $result['linked_sites'],
			'unlinked_sites' => $result['unlinked_sites'],
			'has_link'       => true,
			'total_sites'    => count( $result['linked_sites'] ) + count( $result['unlinked_sites'] ),
		];
	}

	/**
	 * Finds posts with the same slug on every other network site.
	 *
	 * @param \WP_Post $post
	 * @param object[] $sites
	 *
	 * @return array
	 */
	private function get_all_matching_posts( $post, $sites ): array {
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
	private function cross_reference_with_link( $all_matching_sites, $link ): array {
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
	 * Builds the HTML title string for the admin bar menu node.
	 *
	 * @param array $sync_data
	 *
	 * @return string
	 */
	private function get_menu_title( array $sync_data ): string {
		$linked_count   = count( $sync_data['linked_sites'] );
		$unlinked_count = count( $sync_data['unlinked_sites'] );
		$total_count    = $sync_data['total_sites'];

		if ( $total_count === 0 ) {
			return '<span class="ab-icon dashicons dashicons-networking"></span> Bolt Sync: No Matches';
		}

		if ( $linked_count > 0 ) {
			$status_icon  = 'dashicons-yes-alt';
			$status_class = 'bolt-sync-linked';
		} else {
			$status_icon  = 'dashicons-dismiss';
			$status_class = 'bolt-sync-unlinked';
		}

		return sprintf(
			'<span class="ab-icon dashicons %s %s"></span> Bolt Sync: %d/%d Linked',
			$status_icon,
			$status_class,
			$linked_count,
			$total_count
		);
	}

	/**
	 * Builds the tooltip string for the admin bar menu node.
	 *
	 * @param array $sync_data
	 *
	 * @return string
	 */
	private function get_menu_tooltip( array $sync_data ): string {
		$linked_count   = count( $sync_data['linked_sites'] );
		$unlinked_count = count( $sync_data['unlinked_sites'] );

		if ( $sync_data['total_sites'] === 0 ) {
			return 'No matching posts found on other sites';
		}

		$tooltip = "Bolt Sync Status:\n";
		$tooltip .= "- {$linked_count} sites linked\n";
		$tooltip .= "- {$unlinked_count} sites with matching posts but not linked";

		return $tooltip;
	}

	/**
	 * Appends linked/unlinked site nodes as children of the Bolt Sync menu node.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 * @param array         $sync_data
	 *
	 * @return void
	 */
	private function add_submenu_items( $wp_admin_bar, array $sync_data ): void {
		$linked_sites   = $sync_data['linked_sites'];
		$unlinked_sites = $sync_data['unlinked_sites'];

		// Add linked sites section header
		if ( ! empty( $linked_sites ) ) {
			$wp_admin_bar->add_menu( [
				'parent' => 'bolt-sync-sync-status',
				'id'     => 'bolt-sync-linked-header',
				'title'  => '<strong>Linked Sites (' . count( $linked_sites ) . ')</strong>',
				'href'   => false,
				'meta'   => [ 'class' => 'bolt-sync-section-header' ],
			] );

			foreach ( $linked_sites as $blog_id => $site ) {
				$this->add_site_menu_item( $wp_admin_bar, $site, 'linked', $blog_id );
			}
		}

		// Add unlinked sites section header
		if ( ! empty( $unlinked_sites ) ) {
			$wp_admin_bar->add_menu( [
				'parent' => 'bolt-sync-sync-status',
				'id'     => 'bolt-sync-unlinked-header',
				'title'  => '<strong>Unlinked Matches (' . count( $unlinked_sites ) . ')</strong>',
				'href'   => false,
				'meta'   => [ 'class' => 'bolt-sync-section-header bolt-sync-unlinked-header' ],
			] );

			foreach ( $unlinked_sites as $blog_id => $site ) {
				$this->add_site_menu_item( $wp_admin_bar, $site, 'unlinked', $blog_id );
			}
		}

		// Add message if no sites found
		if ( empty( $linked_sites ) && empty( $unlinked_sites ) ) {
			$wp_admin_bar->add_menu( [
				'parent' => 'bolt-sync-sync-status',
				'id'     => 'bolt-sync-no-matches',
				'title'  => 'No matching posts found on other sites',
				'href'   => false,
				'meta'   => [ 'class' => 'bolt-sync-no-matches' ],
			] );
		}

		// Add footer note
		$wp_admin_bar->add_menu( [
			'parent' => 'bolt-sync-sync-status',
			'id'     => 'bolt-sync-refresh-note',
			'title'  => '<em>&nbsp;&nbsp;Refresh page to see updated links.</em>',
			'href'   => false,
			'meta'   => [ 'class' => 'bolt-sync-refresh-note' ],
		] );
	}

	/**
	 * Appends an edit link and preview sub-link for a single site to the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar
	 * @param array         $site
	 * @param string        $type  'linked' or 'unlinked'
	 * @param int           $blog_id
	 *
	 * @return void
	 */
	private function add_site_menu_item( $wp_admin_bar, array $site, string $type, int $blog_id ): void {
		$status_icon = $type === 'linked'
			? ( $site['post_status'] === 'publish' ? 'dashicons-yes-alt' : 'dashicons-edit' )
			: 'dashicons-dismiss';

		$status_class = $site['post_status'] === 'publish' ? 'published' : 'draft';
		if ( $type === 'unlinked' ) {
			$status_class .= ' unlinked';
		}

		$display_text = $site['site_path']
			? '/' . $site['site_path'] . '/ ./' . $site['post_slug'] . '/'
			: $site['site_name'] . '/ ./' . $site['post_slug'] . '/';

		$title = sprintf(
			'%s',
			esc_html( $display_text )
		);

		// Add edit link
		$wp_admin_bar->add_menu( [
			'parent' => 'bolt-sync-sync-status',
			'id'     => 'bolt-sync-site-edit-' . $blog_id,
			'title'  => $title,
			'href'   => $site['edit_link'],
			'meta'   => [
				'class'  => 'bolt-sync-site-item bolt-sync-site-' . $type,
				'target' => '_blank',
				'title'  => sprintf(
					'Edit %s (ID: %d) - Status: %s',
					esc_attr( $site['post_title'] ),
					$site['post_id'],
					ucfirst( $site['post_status'] )
				),
			],
		] );

		// Add view link as sub-item
		$wp_admin_bar->add_menu( [
			'parent' => 'bolt-sync-site-edit-' . $blog_id,
			'id'     => 'bolt-sync-site-view-' . $blog_id,
			'title'  => 'Preview Post',
			'href'   => $site['permalink'],
			'meta'   => [
				'class'  => 'bolt-sync-site-view',
				'target' => '_blank',
				'title'  => 'Preview: ' . esc_attr( $site['post_title'] ),
			],
		] );
	}

	/**
	 * Add CSS styles for admin bar
	 */
	public function add_admin_bar_styles(): void {
		if ( ! is_admin_bar_showing() || ! $this->is_edit_post_screen() ) {
			return;
		}

		echo '<style>
		#wp-admin-bar-bolt-sync-sync-status .ab-icon.dashicons-yes-alt.bolt-sync-linked:before {
			color: #00a32a !important;
		}

		#wp-admin-bar-bolt-sync-sync-status .ab-icon.dashicons-dismiss.bolt-sync-unlinked:before {
			color: #d63638 !important;
		}

		#wp-admin-bar-bolt-sync-sync-status .ab-icon.dashicons-networking:before {
			color: #646970 !important;
		}

		/* Section headers */
		.bolt-sync-section-header .ab-item {
			/*background: #f0f0f1 !important;
			color: #1d2327 !important;*/
			font-weight: 600 !important;
			border-bottom: 1px solid #dcdcde !important;
		}

		.bolt-sync-unlinked-header .ab-item {
			/*background: #fff4e6 !important;
			color: #b32d2e !important;*/
		}

		/* Site items */
		.bolt-sync-site-item .ab-item {
			display: block;
			padding-left: 12px !important;
			padding-right: 35px !important;
			font-size: 12px !important;
		}

		.bolt-sync-site-item.bolt-sync-site-linked .ab-item:hover {
			background: #e8f5e8 !important;
		}

		.bolt-sync-site-item.bolt-sync-site-unlinked .ab-item:hover {
			background: #fef2f2 !important;
		}

		/* Status icons in menu items */
		.bolt-sync-site-status-published {
			color: #00a32a !important;
		}

		.bolt-sync-site-status-draft {
			color: #dba617 !important;
		}

		.bolt-sync-site-status-published.unlinked,
		.bolt-sync-site-status-draft.unlinked {
			color: #d63638 !important;
		}

		/* Post status text */
		.bolt-sync-post-status {
			color: #646970 !important;
			font-size: 11px !important;
			font-style: italic !important;
		}

		/* View link sub-items */
		.bolt-sync-site-view .ab-item {
			padding-left: 50px !important;
			font-size: 11px !important;
			color: #646970 !important;
		}

		.bolt-sync-site-view .ab-item:hover {
			background: #f6f7f7 !important;
		}

		/* No matches message */
		.bolt-sync-no-matches .ab-item {
			color: #646970 !important;
			font-style: italic !important;
			cursor: default !important;
		}

		.bolt-sync-no-matches .ab-item:hover {
			background: none !important;
		}

		/* Ensure proper spacing for icons */
		#wp-admin-bar-bolt-sync-sync-status .dashicons {
			margin-top: 2px;
		}

		/* Refresh note */
		.bolt-sync-refresh-note .ab-item {
			color: #6c7781 !important;
			font-size: 11px !important;
			font-style: italic !important;
			background: transparent !important;
			border-top: 1px solid #dcdcde !important;
			padding: 6px 12px !important;
			cursor: default !important;
			opacity: 0.8;
		}

		/* Mobile responsiveness */
		@media screen and (max-width: 782px) {
			#wp-admin-bar-bolt-sync-sync-status .ab-item {
				font-size: 14px !important;
			}

			.bolt-sync-site-item .ab-item {
				font-size: 13px !important;
				padding-left: 20px !important;
			}

			.bolt-sync-site-view .ab-item {
				font-size: 12px !important;
				padding-left: 30px !important;
			}
		}
		</style>';
	}
}
