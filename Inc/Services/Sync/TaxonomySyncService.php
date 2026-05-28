<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services\Sync;

/**
 * Sync Service - TaxonomySyncService - Handles functionality for syncing taxonomies between sites.
 */
class TaxonomySyncService {

	/**
	 * Synchronize post terms between sites.
	 *
	 * @param int $source_post_id Source post ID.
	 * @param int $target_post_id Target post ID.
	 * @param int $source_site_id Source site ID.
	 * @param int $target_site_id Target site ID.
	 *
	 * @return array
	 */
	public function sync_post_terms( int $source_post_id, int $target_post_id, int $source_site_id, int $target_site_id ): array {
		$result = [
			'success' => false,
			'taxonomies_processed' => 0,
			'terms_synchronized' => 0,
			'terms_failed' => 0,
			'errors' => [],
		];

		// Switch to source site to get taxonomies and terms
		switch_to_blog( $source_site_id );

		// Get post type and its taxonomies
		$post_type = get_post_type( $source_post_id );
		if ( ! $post_type ) {
			restore_current_blog();
			$result['errors'][] = "Source post ID {$source_post_id} not found or has no post type.";
			return $result;
		}

		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		if ( empty( $taxonomies ) ) {
			restore_current_blog();
			$result['success'] = true; // No taxonomies is not an error
			return $result;
		}

		$result['taxonomies_processed'] = count( $taxonomies );

		// Process each taxonomy
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $source_post_id, $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				if ( is_wp_error( $terms ) ) {
					$result['errors'][] = "Error getting terms for taxonomy {$taxonomy}: " . $terms->get_error_message();
					$result['terms_failed']++;
				}
				continue;
			}

			// First, collect all terms data including the term hierarchy
			$terms_data = [];
			$term_mapping = []; // Map from source term ID to target term ID

			foreach ( $terms as $term ) {
				$terms_data[] = [
					'name' => $term->name,
					'slug' => $term->slug,
					'description' => $term->description,
					'parent' => $term->parent,
					'term_id' => $term->term_id,
				];
			}

			// Switch to target site before processing terms
			restore_current_blog();
			switch_to_blog( $target_site_id );

			// Check if taxonomy exists in target site
			if ( ! taxonomy_exists( $taxonomy ) ) {
				$result['errors'][] = "Taxonomy {$taxonomy} does not exist in target site.";
				$result['terms_failed'] += count( $terms_data );
				restore_current_blog();
				continue;
			}

			// First, create or identify all terms without setting parents
			foreach ( $terms_data as $term_data ) {
				// Check if term exists in the target site
				$existing_term = get_term_by( 'slug', $term_data['slug'], $taxonomy );

				if ( ! $existing_term ) {
					// Create the term without parent (we'll update parents later)
					$new_term = wp_insert_term(
						$term_data['name'],
						$taxonomy,
						[
							'slug' => $term_data['slug'],
							'description' => $term_data['description'],
							// Parent will be set in the next loop
						]
					);

					if ( is_wp_error( $new_term ) ) {
						$result['errors'][] = "Failed to create term {$term_data['name']}: " . $new_term->get_error_message();
						$result['terms_failed']++;
						continue;
					}

					$term_mapping[ $term_data['term_id'] ] = $new_term['term_id'];
					$result['terms_synchronized']++;
				} else {
					$term_mapping[ $term_data['term_id'] ] = $existing_term->term_id;
					$result['terms_synchronized']++;
				}
			}

			// Now, update the parent relationships
			foreach ( $terms_data as $term_data ) {
				if ( $term_data['parent'] > 0 && isset( $term_mapping[ $term_data['parent'] ] ) && isset( $term_mapping[ $term_data['term_id'] ] ) ) {
					$target_term_id = $term_mapping[ $term_data['term_id'] ];
					$target_parent_id = $term_mapping[ $term_data['parent'] ];

					$update_result = wp_update_term(
						$target_term_id,
						$taxonomy,
						[ 'parent' => $target_parent_id ]
					);

					if ( is_wp_error( $update_result ) ) {
						$result['errors'][] = "Failed to update parent for term ID {$target_term_id}: " . $update_result->get_error_message();
					}
				}
			}

			// Collect target term IDs for assignment
			$term_ids = array_values( $term_mapping );

			// Assign terms to the target post
			if ( ! empty( $term_ids ) ) {
				$set_terms_result = wp_set_post_terms( $target_post_id, $term_ids, $taxonomy );

				if ( is_wp_error( $set_terms_result ) ) {
					$result['errors'][] = "Error setting terms for taxonomy {$taxonomy}: " . $set_terms_result->get_error_message();
					$result['terms_failed'] += count( $term_ids );
				}
			}

			restore_current_blog();
		}

		// Restore to original blog if needed
		if ( get_current_blog_id() !== $source_site_id ) {
			restore_current_blog();
		}

		// Mark as successful if at least some terms were synchronized
		$result['success'] = $result['terms_synchronized'] > 0;

		return $result;
	}
}
