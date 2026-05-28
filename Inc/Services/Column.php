<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

use BoltSync\Inc\Services\Core;
use BoltSync\Inc\Plugin;

/**
 * Column Plugin Class
 */
final class Column {

	/**
	 * @var Plugin
	 */
	protected Plugin $plugin;

	/**
	 * @var Core
	 */
	protected Core $core;

	/**
	 * @param Plugin $plugin
	 * @param Core   $core
	 */
	public function __construct( Plugin $plugin, Core $core ) {
		$this->plugin = $plugin;
		$this->core   = $core;
	}

	/**
	 * Appends the Post Link column to the posts list table.
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function custom_posts_column( $columns ) {
		$columns['link'] = 'Post Link';

		return $columns;
	}

	/**
	 * Renders the link data for a post row in the Post Link column.
	 *
	 * @param string $column_name
	 * @param int    $post_id
	 *
	 * @return void
	 */
	public function custom_posts_column_content( $column_name, $post_id ): void {
		if ( $column_name !== 'link' ) {
			return;
		}

		$link_id = $this->core->get_link_id_from_post( $post_id );

		if ( ! $link_id ) {
			return;
		}

		$link = $this->core->get_link( $link_id );

		if ( ! $link ) {
			echo (int) $link_id;
			return;
		}

		// Return only active ones
		$link->link_info = array_filter( $link->link_info, function ( $obj ) {
			return $obj->active == true; // Keep objects where 'active' is true (1)
		} );

		$sites = $this->core->get_sites();

		$output = '';

		foreach ( $link->link_info as $link_info ) {
			if ( $link_info->blog_id === get_current_blog_id() ) {
				continue;
			}

			$site_info = array_filter($sites, function ( $site ) use ( $link_info ) {
				return $site->blog_id == $link_info->blog_id;
			});

			if ( ! $site_info ) {
				continue;
			}

			$site_info = reset( $site_info );

			switch_to_blog( $site_info->blog_id );
			$edit_link = get_edit_post_link( $link_info->post_id );

			restore_current_blog();

			$output .=
				"<p>
					<a href=\"{$edit_link}\" target=\"_blank\">
						<span
						class=\"dashicons dashicons-admin-links\"
						style=\"font-size:15px; width: 15px; height: 15px; vertical-align: middle;\">
						</span> {$site_info->path}
					</a>
				</p>";
		}

		echo esc_html( $output );
	}
}
