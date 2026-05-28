<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services\Sync;

use Roots\WPConfig\Config;
use BoltSync\Inc\Services\Core;

/**
 * Sync Service - ContentSyncService - Handles sync functionality for all post content.
 */
class ContentSyncService {

	/**
	 * @var ACFSyncService
	 */
	private ACFSyncService $acf_sync_service;

	/**
	 * @var Core|null
	 */
	private ?Core $core = null;

	/**
	 * @param ACFSyncService $acf_sync_service
	 */
	public function __construct( ACFSyncService $acf_sync_service ) {
		$this->acf_sync_service = $acf_sync_service;
	}

	/**
	 * Injects the Core service after construction (avoids circular dependency).
	 *
	 * @param Core $core
	 *
	 * @return void
	 */
	public function set_core( Core $core ): void {
		$this->core = $core;
	}

	/**
	 * Finds inline links in the content, checks if the target site has that same post, and replaces it.
	 *
	 * @param string $content
	 * @param int    $source_post_id
	 * @param int    $target_post_id
	 * @param int    $site_id
	 * @param int    $target_site_id
	 *
	 * @return string
	 */
	public function sync_inline_links( string $content, int $source_post_id, int $target_post_id, int $site_id, int $target_site_id ): string {

		$source_site = $this->core->get_site_by_id( $site_id );
		$target_site = $this->core->get_site_by_id( $target_site_id );

		$source_base_url = rtrim( $source_site->site_url, '/' );
		$target_base_url = rtrim( $target_site->site_url, '/' );

		// Match both absolute and relative internal links - capture the full opening tag
		$pattern = '/<a\s([^>]*href=["\']((?:' . preg_quote( $source_base_url, '/' ) . ')?\/[^"\']+)["\'][^>]*)>(.*?)<\/a>/i';

		$content = preg_replace_callback( $pattern, function ( $matches ) use ( $source_site, $target_site, $source_base_url, $target_base_url, $target_site_id ) {
			$full_attributes = $matches[1]; // All attributes including href::: href="http://localhost:3000/en/insights/shares/3-stocks-to-watch/"
			$original_url    = $matches[2]; // Just the URL::: http://localhost:3000/en/insights/shares/3-stocks-to-watch/
			$link_text       = $matches[3]; // Link content::: Read More

			// Transform the URL
			if ( str_starts_with( $original_url, 'http' ) && filter_var( $original_url, FILTER_VALIDATE_URL ) ) {
				$target_url = str_replace( $source_base_url, $target_base_url, $original_url );
			} elseif ( str_contains( $original_url, $source_site->path ) ) {
				$target_url = Config::get( 'NEXTJS_URL' ) . str_replace( $source_site->path, $target_site->path, $original_url );
			} else {
				$target_url = $target_base_url . $original_url;
			}

			switch_to_blog( $target_site_id );

			$target_url = apply_filters( 'bolt_sync_inline_link_url', $target_url );

			$post_id = url_to_postid( $target_url );

			$post_id = apply_filters( 'bolt_sync_inline_link_post_id', $post_id, $target_url );

			restore_current_blog();

			if ( $post_id ) {
				$target_url = trailingslashit( $target_url );

				// Replace the href in the original attributes while preserving everything else
				$updated_attributes = preg_replace(
					'/href=["\'][^"\']*["\']/',
					'href="' . esc_url( $target_url ) . '"',
					$full_attributes
				);

				return '<a ' . $updated_attributes . '>' . $link_text . '</a>';
			}

			return $link_text; // Remove broken link
		}, $content );

		switch_to_blog( $target_site_id );
		$content = parse_blocks( $content );
		$content = serialize_blocks( $content );

		$result = wp_update_post( [
			'ID'           => $target_post_id,
			'post_content' => $content,
		] );

		restore_current_blog();

		return $content;
	}

	/**
	 * Syncs the media image blocks in the editor with the same attachment on the current site.
	 *
	 * @param string $content
	 * @param int    $source_post_id
	 * @param int    $target_post_id
	 * @param int    $site_id
	 * @param int    $target_site_id
	 *
	 * @return string
	 */
	public function sync_inline_media_blocks( string $content, int $source_post_id, int $target_post_id, int $site_id, int $target_site_id ): string {
		/**
		 * EX: <!-- wp:image {"id":9904,"sizeSlug":"large","linkDestination":"none"} -->
		 * <figure class="wp-block-image size-large"><img src="https://cdn.example.com/2025/04/my-image-1024x469.jpg" alt="" class="wp-image-9904"/></figure>
		 * <!-- /wp:image -->
		 * [1] - {"id":9904,"sizeSlug":"large","linkDestination":"none"}
		 * [2] - 9904
		 * [3] - https://cdn.example.com/2025/04/my-image-1024x469.jpg
		 * [4] - class="wp-image-9904
		 */
		preg_match_all( '/<!-- wp:image\s+({.*?"id":(\d+).*?}) -->.*?<img.*?src="([^"]+)"[^>]+(class="[^"]*\d+)[^"]*".*?\/>.*?<!-- \/wp:image -->/s', $content, $matches, PREG_SET_ORDER );

		if ( empty( $matches ) ) {
			return $content;
		}

		foreach ( $matches as $match ) {
			$block_content = $match[0];
			$attachment_id = $match[2];

			switch_to_blog( $site_id );
			$original_attachment = get_post( $attachment_id );
			restore_current_blog();

			if ( empty( $original_attachment ) ) {
				restore_current_blog();
				continue;
			}

			switch_to_blog( $target_site_id );

			$query = new \WP_Query( [
				'post_type'   => 'attachment',
				'name'        => $original_attachment->post_name,
				'post_status' => 'inherit',
				'fields'      => 'ids',
				'numberposts' => 1,
			] );

			if ( empty( $query->posts ) ) {
				error_log( 'Cannot find attachment on target site: ' . $original_attachment->post_name );
				restore_current_blog();
				continue;
			}

			$new_id = $query->posts[0];

			if ( $new_id ) {
				// Replace the old ID in the block comment JSON
				$new_json = str_replace( '"id":' . $attachment_id, '"id":' . $new_id, $match[1] );

				// Replace class="wp-image-9904 with new ID
				$new_classname = str_replace( $attachment_id, (string) $new_id, $match[4] );

				// Replace {"id":9904,"sizeSlug":"large","linkDestination":"none"} with new ID
				$block_content = str_replace( $match[1], $new_json, $block_content );

				// Replace classname
				$block_content = str_replace( $match[4], $new_classname, $block_content );

				// Finally replace the original block content with new block content.
				$content = str_replace( $match[0], $block_content, $content );
			}

			restore_current_blog();
		}

		switch_to_blog( $target_site_id );
		wp_update_post( [
			'ID'           => $target_post_id,
			'post_content' => $content,
		] );
		restore_current_blog();

		return $content;
	}

	/**
	 * Syncs the featured image (post thumbnail) between posts across sites.
	 *
	 * @param int $source_post_id The source post ID.
	 * @param int $target_post_id The target post ID.
	 * @param int $source_site_id The source site ID.
	 * @param int $target_site_id The target site ID.
	 *
	 * @return bool True if thumbnail was synced successfully, false otherwise
	 */
	/**
	 * Syncs the featured image between sites.
	 * Returns true on success, WP_Error with a warning if the attachment cannot be mapped
	 * (the caller should log the warning but continue syncing other fields).
	 *
	 * @return true|\WP_Error
	 */
	public function sync_post_thumbnail( int $source_post_id, int $target_post_id, int $source_site_id, int $target_site_id ): bool|\WP_Error {
		switch_to_blog( $source_site_id );

		$thumbnail_id = get_post_thumbnail_id( $source_post_id );

		if ( ! $thumbnail_id ) {
			restore_current_blog();

			return true; // No thumbnail to sync — not an error.
		}

		$source_attachment = get_post( $thumbnail_id );

		if ( ! $source_attachment ) {
			restore_current_blog();

			return new \WP_Error(
				'bolt_sync_attachment_not_found',
				sprintf( 'Featured image (ID %d) not found on source site %d.', $thumbnail_id, $source_site_id )
			);
		}

		$attachment_name = $source_attachment->post_name;
		$attachment_guid = basename( $source_attachment->guid );

		restore_current_blog();

		switch_to_blog( $target_site_id );

		// Primary: match by post_name (slug).
		$query = new \WP_Query( [
			'post_type'              => 'attachment',
			'name'                   => $attachment_name,
			'post_status'            => 'inherit',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		] );

		if ( ! empty( $query->posts ) ) {
			set_post_thumbnail( $target_post_id, $query->posts[0] );
			restore_current_blog();

			return true;
		}

		// Fallback: match by guid basename.
		$fallback = new \WP_Query( [
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'fields'                 => 'ids',
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [ [
				'key'     => '_wp_attached_file',
				'value'   => $attachment_guid,
				'compare' => 'LIKE',
			] ],
		] );

		if ( ! empty( $fallback->posts ) ) {
			set_post_thumbnail( $target_post_id, $fallback->posts[0] );
			restore_current_blog();

			return true;
		}

		restore_current_blog();

		return new \WP_Error(
			'bolt_sync_attachment_unmapped',
			sprintf(
				'Featured image "%s" could not be mapped to target site %d. Post sync continued without it.',
				$attachment_name,
				$target_site_id
			)
		);
	}

	/**
	 * Syncs ACF block field attachment IDs and relationship fields between sites.
	 *
	 * @param string $content The post content containing blocks.
	 * @param int    $source_post_id Source post ID.
	 * @param int    $target_post_id Target post ID.
	 * @param int    $source_site_id Source site ID.
	 * @param int    $target_site_id Target site ID.
	 *
	 * @return string Updated content with mapped attachment IDs and relationship fields.
	 */
	public function sync_acf_block_attachments( string $content, int $source_post_id, int $target_post_id, int $source_site_id, int $target_site_id ): string {
		// Parse blocks from content
		$blocks = parse_blocks( $content );

		if ( empty( $blocks ) ) {
			return $content;
		}

		// Recursively process blocks (handles nested blocks)
		$blocks = $this->process_blocks_for_attachments( $blocks, $source_site_id, $target_site_id );

		// Serialize blocks back to content
		$updated_content = serialize_blocks( $blocks );

		// Update the target post
		switch_to_blog( $target_site_id );
		wp_update_post( [
			'ID'           => $target_post_id,
			'post_content' => $updated_content,
		] );
		restore_current_blog();

		return $updated_content;
	}

	/**
	 * Recursively process blocks to map attachment IDs and relationship fields.
	 *
	 * @param array $blocks Array of parsed blocks.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Processed blocks with mapped attachments and relationships.
	 */
	private function process_blocks_for_attachments( array $blocks, int $source_site_id, int $target_site_id ): array {
		foreach ( $blocks as &$block ) {
			// Skip empty blocks
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			// Process ACF block data if it exists
			if ( ! empty( $block['attrs']['data'] ) && is_array( $block['attrs']['data'] ) ) {
				$block['attrs']['data'] = $this->map_block_fields( $block['attrs']['data'], $source_site_id, $target_site_id );
			}

			// Recursively process inner blocks
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->process_blocks_for_attachments( $block['innerBlocks'], $source_site_id, $target_site_id );
			}
		}

		return $blocks;
	}

	/**
	 * Map attachment IDs and relationship fields in block data recursively.
	 *
	 * @param array $data Block data array.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Updated block data with mapped attachment IDs and relationship fields.
	 */
	private function map_block_fields( array $data, int $source_site_id, int $target_site_id ): array {
		foreach ( $data as $key => &$value ) {
			// Skip meta fields (fields starting with underscore)
			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			// Recursively process arrays
			if ( is_array( $value ) ) {
				$value = $this->map_block_fields( $value, $source_site_id, $target_site_id );
				continue;
			}

			// Check if this is a numeric value (could be attachment or relationship)
			if ( is_numeric( $value ) ) {
				$numeric_value = (int) $value;

				// Get the field key from the corresponding meta field
				$field_key = $data[ "_{$key}" ] ?? null;

				if ( $field_key ) {
					// Get field object to determine type
					switch_to_blog( $source_site_id );
					$field_object = get_field_object( $field_key );
					restore_current_blog();

					if ( $field_object && isset( $field_object['type'] ) ) {
						$field_type = $field_object['type'];

						// Handle relationship fields
						if ( in_array( $field_type, [ 'relationship', 'post_object', 'page_link' ], true ) ) {
							$mapped_values = $this->map_relationship_field_value( $numeric_value, $source_site_id, $target_site_id );
							if ( ! empty( $mapped_values ) ) {
								$value = is_array( $mapped_values ) ? $mapped_values[0] : $mapped_values;
							}
							continue;
						}

						// Handle attachment fields
						if ( in_array( $field_type, [ 'image', 'file', 'gallery' ], true ) ) {
							$value = $this->map_attachment_id( $numeric_value, $source_site_id, $target_site_id, $key );
							continue;
						}
					}
				}

				// Fallback: If no field key, try to determine by field name pattern
				if ( $this->is_attachment_field( $key ) ) {
					$value = $this->map_attachment_id( $numeric_value, $source_site_id, $target_site_id, $key );
				} elseif ( $this->is_relationship_field( $key ) ) {
					$mapped_values = $this->map_relationship_field_value( $numeric_value, $source_site_id, $target_site_id );
					if ( ! empty( $mapped_values ) ) {
						$value = is_array( $mapped_values ) ? $mapped_values[0] : $mapped_values;
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Map a single relationship field value (post ID) to target site.
	 *
	 * @param int $post_id Source post ID.
	 * @param int $source_site_id Source site ID.
	 * @param int $target_site_id Target site ID.
	 *
	 * @return array|null Mapped post ID or null if not found.
	 */
	private function map_relationship_field_value( int $post_id, int $source_site_id, int $target_site_id ): ?array {
		// Switch to the source site to get the post details
		switch_to_blog( $source_site_id );

		// Get the front page ID from the SOURCE site
		$source_front_page_id = (int) get_option( 'page_on_front' );

		// Get the post object from the source site
		$source_post = get_post( $post_id );

		if ( ! $source_post ) {
			restore_current_blog();

			return null;
		}

		// Check if the source post id is the front page on the SOURCE site
		$is_front_page = $post_id === $source_front_page_id;

		$post_name = $source_post->post_name;
		$post_type = $source_post->post_type;
		restore_current_blog();

		// Switch to target site to find matching post
		switch_to_blog( $target_site_id );

		if ( $is_front_page ) {
			$target_front_page_id = (int) get_option( 'page_on_front' );
			$target_post          = get_post( $target_front_page_id );
		} else {
			$target_post = get_page_by_path( $post_name, OBJECT, $post_type );
		}

		restore_current_blog();

		if ( $target_post ) {
			return [ $target_post->ID ];
		} else {
			$front_page_note = $is_front_page ? ' (front page)' : '';
			error_log( "ACF Block: Could not find matching post for '{$post_name}' (type: {$post_type}){$front_page_note} on target site {$target_site_id}" );
		}

		return null;
	}

	/**
	 * Map a single attachment ID to target site.
	 *
	 * @param int    $attachment_id Source attachment ID.
	 * @param int    $source_site_id Source site ID.
	 * @param int    $target_site_id Target site ID.
	 * @param string $field_key Field key for logging.
	 *
	 * @return int Original or mapped attachment ID.
	 */
	private function map_attachment_id( int $attachment_id, int $source_site_id, int $target_site_id, string $field_key ): int {
		// Get attachment name from source site
		switch_to_blog( $source_site_id );
		$source_attachment = get_post( $attachment_id );
		restore_current_blog();

		if ( ! $source_attachment || $source_attachment->post_type !== 'attachment' ) {
			return $attachment_id;
		}

		// Find matching attachment on target site
		switch_to_blog( $target_site_id );
		$query = new \WP_Query( [
			'post_type'   => 'attachment',
			'name'        => $source_attachment->post_name,
			'post_status' => 'inherit',
			'fields'      => 'ids',
			'numberposts' => 1,
		] );

		if ( ! empty( $query->posts ) ) {
			$mapped_id = $query->posts[0];
			restore_current_blog();

			return $mapped_id;
		} else {
			error_log( "Cannot find attachment on target site {$target_site_id}: {$source_attachment->post_name} (source ID: {$attachment_id}, field: {$field_key})" );
		}

		restore_current_blog();

		return $attachment_id;
	}

	/**
	 * Check if a field key likely represents an attachment/image field.
	 *
	 * @param string $key Field key.
	 *
	 * @return bool True if field likely contains an attachment ID.
	 */
	private function is_attachment_field( string $key ): bool {
		// Common patterns for image/attachment fields
		$patterns = [
			'image',
			'Image',
			'media',
			'Media',
			'attachment',
			'Attachment',
			'file',
			'File',
			'gallery',
			'Gallery',
			'photo',
			'Photo',
			'picture',
			'Picture',
		];

		foreach ( $patterns as $pattern ) {
			if ( str_contains( $key, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a field key likely represents a relationship field.
	 *
	 * @param string $key Field key.
	 *
	 * @return bool True if field likely contains a relationship/post ID.
	 */
	private function is_relationship_field( string $key ): bool {
		// Common patterns for relationship fields
		$patterns = [
			'insight',
			'post',
			'page',
			'related',
			'link',
			'cta',
			'reference',
			'select',
		];

		foreach ( $patterns as $pattern ) {
			if ( str_contains( strtolower( $key ), strtolower( $pattern ) ) ) {
				return true;
			}
		}

		return false;
	}
}
