<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

/**
 * Validates data consistency between linked posts across sites.
 */
class BoltSyncValidator {

	/**
	 * @var Core
	 */
	private Core $core;

	/**
	 * @var array
	 */
	private array $validation_issues = [];

	/**
	 * @var float
	 */
	private float $content_diff_threshold = 99.1;

	/**
	 * @var string
	 */
	public string $cache_key = 'validation_checked_%s-%d';

	/**
	 * @var int
	 */
	public int $cache_expiration = 0;

	/**
	 * @var array
	 */
	private array $compare_basic_fields = [ 'title', 'status', 'type' ];

	/**
	 * @var bool
	 */
	private bool $enable_content_comparison = true;

	/**
	 * @var int
	 */
	private int $string_comparison_max_length = 30000;

	/**
	 * @var string
	 */
	private string $string_comparison_method = 'similar_text';

	/**
	 * @param Core $core
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * Returns the cached validation result for the given post on the current site,
	 * or null if no cached result exists. Never triggers a fresh validation run.
	 *
	 * @param int $post_id
	 *
	 * @return array|null
	 */
	public function get_cached_result( int $post_id ): array|null {
		$cache_key = sprintf( $this->cache_key, $post_id, get_current_blog_id() );
		$cache     = wp_cache_get( $cache_key, 'bolt_sync' );

		return $cache !== false ? $cache : null;
	}

	/**
	 * Validates a post and all its linked counterparts.
	 * Results are cached permanently (until cleared by save_post or sync completion).
	 *
	 * @param int  $post_id
	 * @param bool $cached Whether to return a cached result if available.
	 *
	 * @return array
	 */
	public function validate_post_sync( int $post_id, bool $cached = true ): array {
		$cache_key = sprintf( $this->cache_key, $post_id, get_current_blog_id() );
		$cache     = wp_cache_get( $cache_key, 'bolt_sync' );

		if ( $cache !== false && $cached ) {
			return $cache;
		}

		$this->validation_issues = [];

		$link_id = $this->core->get_link_id_from_post( $post_id );

		if ( ! $link_id ) {
			return [
				'valid'   => true,
				'message' => 'Post is not linked to any other posts.',
			];
		}

		$link = $this->core->get_link( $link_id );

		if ( ! $link || ! $link->active ) {
			return [
				'valid'   => false,
				'message' => 'Link is inactive or not found.',
			];
		}

		$current_site_id = get_current_blog_id();
		$source_post_id  = null;
		$source_site_id  = null;

		foreach ( $link->link_info as $link_info ) {
			if ( (int) $link_info->blog_id === $current_site_id && (int) $link_info->post_id === $post_id ) {
				$source_post_id = (int) $link_info->post_id;
				$source_site_id = (int) $link_info->blog_id;
				break;
			}
		}

		if ( ! $source_post_id || ! $source_site_id ) {
			return [
				'valid'   => false,
				'message' => 'Could not determine source post.',
			];
		}

		wp_suspend_cache_invalidation( true );

		switch_to_blog( $source_site_id );
		$source_post = get_post( $source_post_id );
		$source_data = $this->get_post_data( $source_post_id );
		restore_current_blog();

		if ( ! $source_post ) {
			wp_suspend_cache_invalidation( false );

			return [
				'valid'   => false,
				'message' => 'Source post not found.',
			];
		}

		foreach ( $link->link_info as $link_info ) {
			if ( (int) $link_info->blog_id === $source_site_id || ! $link_info->active || empty( $link_info->post_id ) ) {
				continue;
			}

			switch_to_blog( (int) $link_info->blog_id );
			$target_data = $this->get_post_data( (int) $link_info->post_id );
			restore_current_blog();

			$this->compare_post_data( $source_data, $target_data, $source_site_id, (int) $link_info->blog_id, $source_post_id, (int) $link_info->post_id );
		}

		wp_suspend_cache_invalidation( false );

		$is_valid = empty( $this->validation_issues );

		$response = [
			'valid'   => $is_valid,
			'issues'  => $this->validation_issues,
			'message' => $is_valid ? 'All linked posts are in sync.' : 'Sync issues detected.',
		];

		error_log( sprintf(
			'BoltSync Validation — Post %d (Site %d): %s. Issues: %d.',
			$post_id,
			get_current_blog_id(),
			$is_valid ? 'VALID' : 'INVALID',
			count( $this->validation_issues )
		) );

		wp_cache_set( $cache_key, $response, 'bolt_sync', $this->cache_expiration );

		return $response;
	}

	/**
	 * Clears the validation cache for all sites for a given post.
	 * Called automatically via the bolt_sync_after_sync action.
	 */
	public function clear_cache_by_post_id( int $post_id ): void {
		foreach ( $this->core->get_sites() as $site ) {
			wp_cache_delete( sprintf( $this->cache_key, $post_id, $site->blog_id ), 'bolt_sync' );
		}
	}

	// -------------------------------------------------------------------------
	// Data collection
	// -------------------------------------------------------------------------

	/**
	 * Gathers all comparable data for a post.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	private function get_post_data( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return [];
		}

		return [
			'title'        => $post->post_title,
			'content'      => $post->post_content,
			'status'       => $post->post_status,
			'type'         => $post->post_type,
			'slug'         => $post->post_name,
			'thumbnail_id' => get_post_thumbnail_id( $post_id ),
			'acf_fields'   => function_exists( 'get_fields' ) ? get_fields( $post_id ) : [],
			'taxonomies'   => $this->get_post_taxonomies( $post_id ),
			'yoast_meta'   => $this->get_yoast_meta( $post_id ),
		];
	}

	/**
	 * Returns a structured array of term slugs and names for every taxonomy on the post.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	private function get_post_taxonomies( int $post_id ): array {
		$post_type     = get_post_type( $post_id );
		$taxonomy_data = [];

		foreach ( get_object_taxonomies( $post_type, 'names' ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy );

			if ( ! is_wp_error( $terms ) ) {
				$taxonomy_data[ $taxonomy ] = array_map( static fn( $t ) => [
					'slug' => $t->slug,
					'name' => $t->name,
				], $terms );
			}
		}

		return $taxonomy_data;
	}

	/**
	 * Reads the Yoast SEO meta values compared across sites.
	 *
	 * @param int $post_id
	 *
	 * @return array
	 */
	private function get_yoast_meta( int $post_id ): array {
		$fields = [
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
			'_yoast_wpseo_canonical',
		];

		$meta = [];
		foreach ( $fields as $field ) {
			$meta[ $field ] = get_post_meta( $post_id, $field, true );
		}

		return $meta;
	}

	// -------------------------------------------------------------------------
	// Comparison
	// -------------------------------------------------------------------------

	/**
	 * Compares source and target post data and appends any discrepancies to $validation_issues.
	 *
	 * @param array $source_data
	 * @param array $target_data
	 * @param int   $source_site_id
	 * @param int   $target_site_id
	 * @param int   $source_post_id
	 * @param int   $target_post_id
	 *
	 * @return void
	 */
	private function compare_post_data( array $source_data, array $target_data, int $source_site_id, int $target_site_id, int $source_post_id, int $target_post_id ): void {
		foreach ( $this->compare_basic_fields as $field ) {
			if ( ( $source_data[ $field ] ?? null ) !== ( $target_data[ $field ] ?? null ) ) {
				$this->add_issue( 'basic', $field, $source_site_id, $target_site_id, $source_post_id, $target_post_id, $source_data[ $field ] ?? '', $target_data[ $field ] ?? '' );
			}
		}

		$source_content = trim( $source_data['content'] ?? '' );
		$target_content = trim( $target_data['content'] ?? '' );

		if ( $this->enable_content_comparison && $source_content !== '' && $target_content !== '' ) {
			$norm_source = substr( $this->normalize_content_for_comparison( $source_content ), 0, $this->string_comparison_max_length );
			$norm_target = substr( $this->normalize_content_for_comparison( $target_content ), 0, $this->string_comparison_max_length );

			$percent = 100.0;

			if ( $this->string_comparison_method === 'similar_text' ) {
				similar_text( $norm_source, $norm_target, $percent );
				$percent = round( $percent, 2 );
			} elseif ( $this->string_comparison_method === 'levenshtein' ) {
				$max_len  = max( strlen( $norm_source ), strlen( $norm_target ) );
				$distance = levenshtein( $norm_source, $norm_target );
				$percent  = $max_len > 0 ? round( ( 1 - $distance / $max_len ) * 100, 2 ) : 100.0;
			}

			if ( $percent < $this->content_diff_threshold ) {
				$this->add_issue(
					'content', 'post_content',
					$source_site_id, $target_site_id,
					$source_post_id, $target_post_id,
					$this->truncate_value( $source_data['content'] ),
					$this->truncate_value( $target_data['content'] ),
					'Content similarity: ' . round( $percent, 1 ) . '%'
				);
			}
		}

		if ( ! empty( $source_data['thumbnail_id'] ) && empty( $target_data['thumbnail_id'] ) ) {
			$this->add_issue( 'thumbnail', 'featured_image', $source_site_id, $target_site_id, $source_post_id, $target_post_id, 'exists', 'missing' );
		} elseif ( ! empty( $source_data['thumbnail_id'] ) && ! empty( $target_data['thumbnail_id'] ) ) {
			switch_to_blog( $source_site_id );
			$source_thumb = get_post( $source_data['thumbnail_id'] );
			restore_current_blog();

			switch_to_blog( $target_site_id );
			$target_thumb = get_post( $target_data['thumbnail_id'] );
			restore_current_blog();

			if ( ! $target_thumb || ( $source_thumb && $source_thumb->post_name !== $target_thumb->post_name ) ) {
				$this->add_issue( 'thumbnail', 'featured_image', $source_site_id, $target_site_id, $source_post_id, $target_post_id );
			}
		}

		if ( ! empty( $source_data['acf_fields'] ) || ! empty( $target_data['acf_fields'] ) ) {
			$this->compare_acf_fields(
				(array) ( $source_data['acf_fields'] ?? [] ),
				(array) ( $target_data['acf_fields'] ?? [] ),
				$source_site_id, $target_site_id,
				$source_post_id, $target_post_id
			);
		}

		$this->compare_taxonomies( $source_data['taxonomies'] ?? [], $target_data['taxonomies'] ?? [], $source_site_id, $target_site_id, $source_post_id, $target_post_id );

		foreach ( $source_data['yoast_meta'] ?? [] as $key => $value ) {
			if ( $value !== ( $target_data['yoast_meta'][ $key ] ?? null ) ) {
				$this->add_issue( 'yoast', $key, $source_site_id, $target_site_id, $source_post_id, $target_post_id, $value, $target_data['yoast_meta'][ $key ] ?? '' );
			}
		}
	}

	/**
	 * Strips dynamic IDs from post content so structural comparison isn't skewed by attachment/post ID differences.
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	private function normalize_content_for_comparison( string $content ): string {
	// Replace all numeric IDs in common WordPress patterns
	$patterns = [
			'/wp-image-\d+/',           // Image classes: wp-image-123
			'/wp-att-\d+/',             // Attachment classes
			'/id="attachment_\d+"/',    // Attachment IDs
			'/data-id="\d+"/',          // Data ID attributes
			'/post-\d+/',               // Post classes
			'/page-id-\d+/',            // Page ID classes
			'/attachment-\d+x\d+/',     // Attachment size classes
			'/\[gallery ids="[\d,]+"/', // Gallery shortcode IDs
			'/href="[^"]*\?p=\d+"/',    // Post links with ?p=ID
			'/"id":\d+/',               // JSON ID fields
		];

		$replacements = [
			'wp-image-ID',
			'wp-att-ID',
			'id="attachment_ID"',
			'data-id="ID"',
			'post-ID',
			'page-id-ID',
			'attachment-IDxID',
			'[gallery ids="IDS"',
			'href="?p=ID"',
			'"id":ID',
		];

		return (string) preg_replace( $patterns, $replacements, $content );
	}

	/**
	 * Compares ACF field values between source and target, skipping mapped/relational field types.
	 *
	 * @param array $source_fields
	 * @param array $target_fields
	 * @param int   $source_site_id
	 * @param int   $target_site_id
	 * @param int   $source_post_id
	 * @param int   $target_post_id
	 *
	 * @return void
	 */
	private function compare_acf_fields( array $source_fields, array $target_fields, int $source_site_id, int $target_site_id, int $source_post_id, int $target_post_id ): void {
		$excluded_fields = (array) apply_filters( 'bolt_sync_validator_excluded_acf_fields', [] );

		$source_fields = $this->remove_excluded_fields( $source_fields, $excluded_fields );
		$target_fields = $this->remove_excluded_fields( $target_fields, $excluded_fields );

		$source_keys = array_keys( $source_fields );
		$target_keys = array_keys( $target_fields );

		foreach ( array_diff( $source_keys, $target_keys ) as $key ) {
			if ( $this->field_has_value( $source_fields[ $key ] ) ) {
				$this->add_issue( 'acf', $key, $source_site_id, $target_site_id, $source_post_id, $target_post_id, 'exists', 'missing' );
			}
		}

		foreach ( array_diff( $target_keys, $source_keys ) as $key ) {
			if ( $this->field_has_value( $target_fields[ $key ] ) ) {
				$this->add_issue( 'acf', $key, $source_site_id, $target_site_id, $source_post_id, $target_post_id, 'missing', 'exists' );
			}
		}

		switch_to_blog( $source_site_id );
		$field_objects = [];
		foreach ( $source_keys as $key ) {
			$field_objects[ $key ] = get_field_object( $key, $source_post_id );
		}
		restore_current_blog();

		$mapped_types = [ 'relationship', 'page_link', 'post_object', 'image', 'gallery', 'file', 'user', 'taxonomy' ];

		foreach ( $source_keys as $key ) {
			if ( ! isset( $target_fields[ $key ] ) ) {
				continue;
			}

			$field_type = $field_objects[ $key ]['type'] ?? null;

			if ( $field_type && in_array( $field_type, $mapped_types, true ) ) {
				continue;
			}

			if ( ! $this->field_has_value( $source_fields[ $key ] ) && ! $this->field_has_value( $target_fields[ $key ] ) ) {
				continue;
			}

			if ( is_array( $source_fields[ $key ] ) || is_object( $source_fields[ $key ] ) ) {
				$norm_s = $this->normalize_array_for_comparison( $source_fields[ $key ] );
				$norm_t = $this->normalize_array_for_comparison( $target_fields[ $key ] );

				if ( serialize( $norm_s ) !== serialize( $norm_t ) ) {
					$this->add_issue( 'acf', $key, $source_site_id, $target_site_id, $source_post_id, $target_post_id );
				}
			} elseif ( $source_fields[ $key ] !== $target_fields[ $key ] ) {
				$this->add_issue( 'acf', $key, $source_site_id, $target_site_id, $source_post_id, $target_post_id, $source_fields[ $key ], $target_fields[ $key ] );
			}
		}
	}

	/**
	 * Compares taxonomy term slugs between source and target posts.
	 *
	 * @param array $source_taxonomies
	 * @param array $target_taxonomies
	 * @param int   $source_site_id
	 * @param int   $target_site_id
	 * @param int   $source_post_id
	 * @param int   $target_post_id
	 *
	 * @return void
	 */
	private function compare_taxonomies( array $source_taxonomies, array $target_taxonomies, int $source_site_id, int $target_site_id, int $source_post_id, int $target_post_id ): void {
		foreach ( $source_taxonomies as $taxonomy => $source_terms ) {
			if ( ! isset( $target_taxonomies[ $taxonomy ] ) ) {
				$this->add_issue( 'taxonomy', $taxonomy, $source_site_id, $target_site_id, $source_post_id, $target_post_id, 'has terms', 'no terms' );
				continue;
			}

			$source_slugs = array_column( $source_terms, 'slug' );
			$target_slugs = array_column( $target_taxonomies[ $taxonomy ], 'slug' );
			sort( $source_slugs );
			sort( $target_slugs );

			if ( $source_slugs !== $target_slugs ) {
				$this->add_issue( 'taxonomy', $taxonomy, $source_site_id, $target_site_id, $source_post_id, $target_post_id, implode( ', ', $source_slugs ), implode( ', ', $target_slugs ) );
			}
		}
	}

	/**
	 * Recursively removes excluded field keys from an ACF fields array.
	 *
	 * @param array $fields
	 * @param array $excluded
	 *
	 * @return array
	 */
	private function remove_excluded_fields( array $fields, array $excluded ): array {
		foreach ( $excluded as $key ) {
			unset( $fields[ $key ] );
		}

		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$fields[ $key ] = $this->remove_excluded_fields( $value, $excluded );
			}
		}

		return $fields;
	}

	/**
	 * Returns true if the value is non-empty (not null, false, empty string, or empty array).
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	private function field_has_value( mixed $value ): bool {
		if ( is_null( $value ) || $value === false ) {
			return false;
		}

		if ( is_string( $value ) && trim( $value ) === '' ) {
			return false;
		}

		if ( is_array( $value ) && empty( $value ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Recursively normalises an ACF field value for comparison, coercing falsy scalars to empty string.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	private function normalize_array_for_comparison( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( fn( $v ) => $this->normalize_array_for_comparison( $v ), $value );
		}

		if ( is_object( $value ) ) {
			return $value;
		}

		if ( $value === false || $value === 0 || $value === '0' || $value === '' ) {
			return '';
		}

		return $value;
	}

	/**
	 * Appends a validation issue to the issues list.
	 *
	 * @param string $type
	 * @param string $field
	 * @param int    $source_site_id
	 * @param int    $target_site_id
	 * @param int    $source_post_id
	 * @param int    $target_post_id
	 * @param mixed  $source_value
	 * @param mixed  $target_value
	 * @param string $message
	 *
	 * @return void
	 */
	private function add_issue( string $type, string $field, int $source_site_id, int $target_site_id, int $source_post_id, int $target_post_id, mixed $source_value = null, mixed $target_value = null, string $message = '' ): void {
		$this->validation_issues[] = [
			'type'           => $type,
			'field'          => $field,
			'source_site_id' => $source_site_id,
			'target_site_id' => $target_site_id,
			'source_post_id' => $source_post_id,
			'target_post_id' => $target_post_id,
			'source_value'   => $source_value,
			'target_value'   => $target_value,
			'message'        => $message,
		];
	}

	/**
	 * Truncates a scalar value for display in a validation issue message.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	private function truncate_value( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '[complex value]';
		}

		$str = (string) $value;

		return strlen( $str ) > 50 ? substr( $str, 0, 47 ) . '...' : $str;
	}
}
