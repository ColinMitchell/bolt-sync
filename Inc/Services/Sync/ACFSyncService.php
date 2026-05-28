<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services\Sync;

/**
 * Sync Service - ACFSyncService - that handles syncing ACF fields/content to other posts on the network.
 */
class ACFSyncService {

	/**
	 * Sync ACF custom fields between source and target posts/terms.
	 *
	 * @param int $source_post_id Source post or term ID.
	 * @param int $target_post_id Target post or term ID.
	 * @param int $site_id Source site ID.
	 * @param int $target_site_id Target site ID.
	 *
	 * @return void
	 */
	public function sync_acf_fields( int $source_post_id, int $target_post_id, int $site_id, int $target_site_id ): void {
		if ( ! function_exists( 'get_fields' ) ) {
			return;
		}

		// Get all source data in one blog switch
		switch_to_blog( $site_id );

		$is_source_term    = term_exists( $source_post_id );
		$source_identifier = $is_source_term ? "term_{$source_post_id}" : $source_post_id;

		$acf_fields = get_fields( $source_identifier );

		// Get all field objects while we're still on source blog
		$field_objects = [];
		if ( ! empty( $acf_fields ) ) {
			foreach ( $acf_fields as $key => $value ) {
				$field_objects[ $key ] = get_field_object( $key, $source_identifier );
			}
		}

		restore_current_blog();

		if ( empty( $acf_fields ) ) {
			return;
		}

		// Check if target is a term
		switch_to_blog( $target_site_id );
		$is_target_term    = term_exists( $target_post_id );
		$target_identifier = $is_target_term ? "term_{$target_post_id}" : $target_post_id;
		restore_current_blog();

		// Disable ACF validation and verification during sync
		add_filter( 'acf/validate_value', '__return_true' );
		add_filter( 'acf/pre_save_post', '__return_false', 1 );

		// Temporarily bypass nonce verification
		$_POST['acf'] = isset( $_POST['acf'] ) ? sanitize_text_field( wp_unslash( $_POST['acf'] ) ) : [];

		wp_suspend_cache_invalidation( true );

		// Process all fields
		foreach ( $acf_fields as $field_name => $value ) {
			try {
				$field_object = $field_objects[ $field_name ] ?? null;
				$field_key    = $field_object['key'] ?? $field_name; // using $field_object['key'] makes it work with ACF Field Builder

				if ( ! $field_object ) {
					error_log( "Field object not found for key: {$field_name}" );
					continue;
				}

				$field_type = $field_object['type'] ?? '';

				// Process field based on type
				$processed_value = $this->process_field_value(
					$value,
					$field_object,
					$site_id,
					$target_site_id
				);

				switch_to_blog( $target_site_id );

				update_field( $field_key, $processed_value, $target_identifier );

				// Handle repeater field count fix
				if ( ! empty( $processed_value ) && $field_type === 'repeater' && ! $is_target_term ) {
					$this->fix_repeater_count( $target_post_id, $field_key, $processed_value );
				}

				restore_current_blog();

			} catch ( \Exception $e ) {
				restore_current_blog();
				error_log( "Error syncing field {$field_key}: " . $e->getMessage() );
				continue;
			}
		}

		wp_suspend_cache_invalidation( false );

		// Clear ACF cache for the target post
		switch_to_blog( $target_site_id );

		// Clear WordPress object cache for this post
		wp_cache_delete( $target_post_id, 'posts' );
		wp_cache_delete( $target_post_id, 'post_meta' );

		// Clear ACF's internal cache if the function exists
		if ( function_exists( 'acf_get_store' ) ) {
			acf_get_store( 'values' )->remove( $target_identifier );
		}

		restore_current_blog();

		// Re-enable ACF validation
		remove_filter( 'acf/validate_value', '__return_true' );
		remove_filter( 'acf/pre_save_post', '__return_false', 1 );

		error_log( "ACF fields synced for post ID {$target_post_id} on site {$target_site_id}" );
	}

	/**
	 * Process field value based on field type.
	 *
	 * @param mixed $value Field value.
	 * @param array $field_object Field object configuration.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return mixed Processed value.
	 */
	private function process_field_value( $value, array $field_object, int $source_site_id, int $target_site_id ): mixed {
		$field_type = $field_object['type'] ?? '';

		switch ( $field_type ) {
			case 'relationship':
			case 'page_link':
			case 'post_object':
				return $this->map_relationship_values( (array) $value, $source_site_id, $target_site_id );

			case 'image':
				return ! empty( $value ) ? $this->map_image_field( (array) $value, $source_site_id, $target_site_id ) : $value;

			case 'gallery':
				return ! empty( $value ) ? $this->map_gallery_field( (array) $value, $source_site_id, $target_site_id ) : $value;

			case 'file':
				return ! empty( $value ) ? $this->map_file_field( (array) $value, $source_site_id, $target_site_id ) : $value;

			case 'taxonomy':
				return ! empty( $value ) ? $this->map_select_taxonomy_field( (array) $value, $source_site_id, $target_site_id ) : $value;

			case 'user':
				return ! empty( $value ) ? $this->map_user_field( $value, $source_site_id, $target_site_id ) : $value;

			case 'link':
				return ! empty( $value ) ? $this->map_link_field( (array) $value, $source_site_id, $target_site_id ) : $value;

			case 'group':
				return $this->map_group_field( $value, $field_object, $source_site_id, $target_site_id );

			case 'repeater':
				return $this->map_repeater_field( $value, $field_object, $source_site_id, $target_site_id );

			case 'flexible_content':
				return $this->map_flexible_content_field( $value, $field_object, $source_site_id, $target_site_id );

			case 'wysiwyg':
			case 'textarea':
				return $this->map_content_field( $value, $source_site_id, $target_site_id );

			default:
				return $value;
		}
	}

	/**
	 * Map gallery field (multiple images).
	 *
	 * @param array $images Array of image data.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Mapped images.
	 */
	private function map_gallery_field( array $images, int $source_site_id, int $target_site_id ): array {
		$mapped_images = [];

		foreach ( $images as $image ) {
			if ( empty( $image ) ) {
				continue;
			}

			$mapped_image = $this->map_image_field( (array) $image, $source_site_id, $target_site_id );

			if ( ! empty( $mapped_image ) ) {
				$mapped_images[] = $mapped_image;
			}
		}

		return $mapped_images;
	}

	/**
	 * Map file field.
	 *
	 * @param array $file File data from ACF.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Mapped file data.
	 */
	private function map_file_field( array $file, int $source_site_id, int $target_site_id ): array {
		// Files work the same way as images in terms of attachment lookup
		return $this->map_image_field( $file, $source_site_id, $target_site_id );
	}

	/**
	 * Map image field.
	 *
	 * @param array $attachment Data from ACF stored data.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Mapped attachment data.
	 */
	private function map_image_field( array $attachment, int $source_site_id, int $target_site_id ): array {
		if ( empty( $attachment['name'] ) ) {
			return $attachment;
		}

		switch_to_blog( $target_site_id );

		$query = new \WP_Query( [
			'post_type'   => 'attachment',
			'name'        => $attachment['name'],
			'post_status' => 'inherit',
			'fields'      => 'ids',
			'numberposts' => 1,
		] );

		$target_attachment_id = null;
		if ( ! empty( $query->posts ) ) {
			$target_attachment_id = $query->posts[0];
		}

		restore_current_blog();

		if ( empty( $target_attachment_id ) ) {
			error_log( 'Cannot find attachment on site id: ' . $target_site_id . ' (name: ' . $attachment['name'] . ')' );

			return $attachment;
		}

		// Update both ID and id for compatibility
		$attachment['ID'] = $target_attachment_id;
		$attachment['id'] = $target_attachment_id;

		return $attachment;
	}

	/**
	 * Map user field values.
	 *
	 * @param mixed $value User ID(s) - can be int, array, or object.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return mixed Mapped user value(s).
	 */
	private function map_user_field( $value, int $source_site_id, int $target_site_id ) {
		// Handle multiple return formats
		$is_array     = is_array( $value );
		$user_ids     = $is_array ? $value : [ $value ];
		$mapped_users = [];

		foreach ( $user_ids as $user_id ) {
			if ( empty( $user_id ) ) {
				continue;
			}

			// Extract ID if it's a user object
			if ( is_object( $user_id ) && isset( $user_id->ID ) ) {
				$user_id = $user_id->ID;
			}

			switch_to_blog( $source_site_id );
			$user = get_userdata( $user_id );
			restore_current_blog();

			if ( ! $user ) {
				continue;
			}

			// Users exist across all sites in multisite, but verify they exist
			switch_to_blog( $target_site_id );
			$target_user = get_user_by( 'login', $user->user_login );
			restore_current_blog();

			if ( $target_user ) {
				$mapped_users[] = $target_user->ID;
			}
		}

		return $is_array ? $mapped_users : ( $mapped_users[0] ?? null );
	}

	/**
	 * Map link field (ACF link field with url, title, target).
	 *
	 * @param array $link Link field data.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Mapped link data.
	 */
	private function map_link_field( array $link, int $source_site_id, int $target_site_id ): array {
		if ( empty( $link['url'] ) ) {
			return $link;
		}

		// Get source site URL
		switch_to_blog( $source_site_id );
		$source_url = get_site_url();
		restore_current_blog();

		// Get target site URL
		switch_to_blog( $target_site_id );
		$target_url = get_site_url();
		restore_current_blog();

		// Replace source URL with target URL if it's an internal link
		if ( strpos( $link['url'], $source_url ) === 0 ) {
			$link['url'] = str_replace( $source_url, $target_url, $link['url'] );
		}

		return $link;
	}

	/**
	 * Map group field (nested fields).
	 *
	 * @param mixed $value Group field value.
	 * @param array $field_object Field object configuration.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return mixed Processed group value.
	 */
	private function map_group_field( $value, array $field_object, int $source_site_id, int $target_site_id ): mixed {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return $value;
		}

		$sub_fields = $field_object['sub_fields'] ?? [];

		if ( empty( $sub_fields ) ) {
			return $value;
		}

		// Create lookup array for sub fields
		$sub_fields_lookup = [];
		foreach ( $sub_fields as $sub_field ) {
			$sub_fields_lookup[ $sub_field['name'] ] = $sub_field;
		}

		// Process each field in the group
		foreach ( $value as $key => $field_value ) {
			if ( isset( $sub_fields_lookup[ $key ] ) ) {
				$value[ $key ] = $this->process_field_value(
					$field_value,
					$sub_fields_lookup[ $key ],
					$source_site_id,
					$target_site_id
				);
			}
		}

		return $value;
	}

	/**
	 * Map repeater field (array of rows with sub fields).
	 *
	 * @param mixed $value Repeater field value.
	 * @param array $field_object Field object configuration.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Processed repeater value.
	 */
	private function map_repeater_field( mixed $value, array $field_object, int $source_site_id, int $target_site_id ): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		$sub_fields = $field_object['sub_fields'] ?? [];

		if ( empty( $sub_fields ) ) {
			return $value;
		}

		// Create lookup array for sub fields
		$sub_fields_lookup = [];
		foreach ( $sub_fields as $sub_field ) {
			$sub_fields_lookup[ $sub_field['name'] ] = $sub_field;
		}

		// Process each row in the repeater
		foreach ( $value as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			foreach ( $row as $field_key => $field_value ) {
				if ( isset( $sub_fields_lookup[ $field_key ] ) ) {
					$value[ $row_index ][ $field_key ] = $this->process_field_value(
						$field_value,
						$sub_fields_lookup[ $field_key ],
						$source_site_id,
						$target_site_id
					);
				}
			}
		}

		return $value;
	}

	/**
	 * Map flexible content field (layouts with sub fields).
	 *
	 * @param mixed $value Flexible content value.
	 * @param array $field_object Field object configuration.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Processed flexible content value.
	 */
	private function map_flexible_content_field( $value, array $field_object, int $source_site_id, int $target_site_id ): array {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return [];
		}

		$layouts = $field_object['layouts'] ?? [];

		if ( empty( $layouts ) ) {
			return $value;
		}

		// Create lookup array for layouts and their sub fields
		$layouts_lookup = [];
		foreach ( $layouts as $layout ) {
			$sub_fields_lookup = [];
			foreach ( $layout['sub_fields'] as $sub_field ) {
				$sub_fields_lookup[ $sub_field['name'] ] = $sub_field;
			}
			$layouts_lookup[ $layout['name'] ] = $sub_fields_lookup;
		}

		// Process each layout row
		foreach ( $value as $row_index => $row ) {
			if ( empty( $row['acf_fc_layout'] ) || ! isset( $layouts_lookup[ $row['acf_fc_layout'] ] ) ) {
				continue;
			}

			$layout_fields = $layouts_lookup[ $row['acf_fc_layout'] ];

			foreach ( $row as $field_key => $field_value ) {
				if ( $field_key === 'acf_fc_layout' ) {
					continue;
				}

				if ( isset( $layout_fields[ $field_key ] ) ) {
					$value[ $row_index ][ $field_key ] = $this->process_field_value(
						$field_value,
						$layout_fields[ $field_key ],
						$source_site_id,
						$target_site_id
					);
				}
			}
		}

		return $value;
	}

	/**
	 * Map content fields (WYSIWYG, textarea) to replace internal URLs and shortcodes.
	 *
	 * @param mixed $content Content value.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return string Processed content.
	 */
	private function map_content_field( $content, int $source_site_id, int $target_site_id ): string {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return is_string( $content ) ? $content : '';
		}

		// Get source and target site URLs
		switch_to_blog( $source_site_id );
		$source_url        = get_site_url();
		$source_upload_url = wp_upload_dir()['baseurl'];
		restore_current_blog();

		switch_to_blog( $target_site_id );
		$target_url        = get_site_url();
		$target_upload_url = wp_upload_dir()['baseurl'];
		restore_current_blog();

		// Replace site URLs
		$content = str_replace( $source_url, $target_url, $content );

		// Replace upload URLs
		$content = str_replace( $source_upload_url, $target_upload_url, $content );

		// Replace attachment IDs in img tags and links if needed
		// This is a more advanced operation that would require parsing HTML
		// For now, URL replacement should handle most cases

		return $content;
	}

	/**
	 * Map relationship field values to the target site by matching post IDs.
	 *
	 * @param array $values Array of post IDs.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Mapped relationship values.
	 */
	private function map_relationship_values( array $values, int $source_site_id, int $target_site_id ): array {
		if ( empty( $values ) ) {
			return [];
		}

		$mapped_values = [];

		foreach ( $values as $post_id ) {
			if ( empty( $post_id ) ) {
				continue;
			}

			// Handle post objects (some relationship fields return objects)
			if ( is_object( $post_id ) && isset( $post_id->ID ) ) {
				$post_id = $post_id->ID;
			}

			// Switch to the source site to get the post details
			switch_to_blog( $source_site_id );

			// Get the front page ID from the SOURCE site
			$source_front_page_id = (int) get_option( 'page_on_front' );

			// Get the post object from the source site
			$source_post = get_post( $post_id );

			if ( ! $source_post ) {
				restore_current_blog();
				continue;
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
				// Add the target post ID to the mapped values
				$mapped_values[] = $target_post->ID;
			} else {
				$front_page_note = $is_front_page ? ' (front page)' : '';
				error_log( "Could not find matching post for '{$post_name}' (type: {$post_type}){$front_page_note} on target site {$target_site_id}" );
			}
		}

		return $mapped_values;
	}

	/**
	 * Map taxonomy select field values to the target site by matching term_id.
	 *
	 * @param array $values Array of term_id's.
	 * @param int   $source_site_id Source site ID.
	 * @param int   $target_site_id Target site ID.
	 *
	 * @return array Mapped values.
	 */
	private function map_select_taxonomy_field( array $values, int $source_site_id, int $target_site_id ): array {
		if ( empty( $values ) ) {
			return [];
		}

		$mapped_values = [];

		foreach ( $values as $taxonomy_id ) {
			// Handle term objects
			if ( is_object( $taxonomy_id ) && isset( $taxonomy_id->term_id ) ) {
				$taxonomy_id = $taxonomy_id->term_id;
			}

			// Switch to the source site to get the taxonomy details
			switch_to_blog( $source_site_id );

			// Get the taxonomy object from the source site
			$source_taxonomy = get_term( $taxonomy_id );

			if ( ! $source_taxonomy || is_wp_error( $source_taxonomy ) ) {
				restore_current_blog();
				continue;
			}

			$taxonomy_slug = $source_taxonomy->slug;
			$taxonomy_type = $source_taxonomy->taxonomy;
			restore_current_blog();

			// Switch to target site to find matching term
			switch_to_blog( $target_site_id );
			$target_taxonomy = get_term_by( 'slug', $taxonomy_slug, $taxonomy_type );
			restore_current_blog();

			if ( $target_taxonomy && ! is_wp_error( $target_taxonomy ) ) {
				// Add the target term_id to the mapped values
				$mapped_values[] = $target_taxonomy->term_id;
			} else {
				error_log( "Taxonomy term (slug: {$taxonomy_slug}, type: {$taxonomy_type}) does not exist on target site: {$target_site_id}" );
			}
		}

		return $mapped_values;
	}

	/**
	 * Fix repeater field count in postmeta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field_key Field key.
	 * @param array  $value Repeater value.
	 *
	 * @return void
	 */
	private function fix_repeater_count( int $post_id, string $field_key, array $value ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->postmeta,
			[ 'meta_value' => count( $value ) ],
			[
				'post_id'  => $post_id,
				'meta_key' => $field_key,
			],
			[ '%d' ],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Determine if a field is a relationship field.
	 *
	 * @param int    $post_id The post or term ID.
	 * @param string $field_key The field key to check.
	 * @param int    $site_id The site ID.
	 *
	 * @return bool True if the field is a relationship field, false otherwise.
	 */
	private function is_relationship_field( int $post_id, string $field_key, int $site_id ): bool {
		$relationship_field_types = [
			'relationship',
			'page_link',
			'post_object',
		];

		switch_to_blog( $site_id );

		if ( term_exists( $post_id ) ) {
			$field = get_field_object( $field_key, "term_{$post_id}" );
		} else {
			$field = get_field_object( $field_key, $post_id );
		}

		restore_current_blog();

		// Check if the field exists and is of type 'relationship'
		if ( $field && isset( $field['type'] ) && in_array( $field['type'], $relationship_field_types, true ) ) {
			return true;
		}

		return false;
	}
}
