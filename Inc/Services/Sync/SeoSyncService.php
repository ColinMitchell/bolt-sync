<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services\Sync;

/**
 * Sync Service — SeoSyncService
 *
 * Copies all Yoast SEO meta from source to target, replacing internal URLs
 * (canonical, OG, Twitter image URLs, etc.) with the equivalent target-site domain.
 */
class SeoSyncService {

	/** Yoast meta keys that contain URLs and need domain replacement. */
	private const URL_KEYS = [
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_opengraph-image',
		'_yoast_wpseo_twitter-image',
		'_yoast_wpseo_opengraph-url',
	];

	/** All Yoast meta keys to sync. */
	private const META_KEYS = [
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_focuskeywords',
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_opengraph-image',
		'_yoast_wpseo_opengraph-url',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
		'_yoast_wpseo_twitter-image',
		'_yoast_wpseo_schema_graph',
	];

	/**
	 * Copies all Yoast SEO meta from the source post to the target post,
	 * replacing the source domain with the target domain in URL-type keys.
	 *
	 * @param int $source_post_id
	 * @param int $target_post_id
	 * @param int $source_site_id
	 * @param int $target_site_id
	 *
	 * @return void
	 */
	public function sync_yoast_seo_meta( int $source_post_id, int $target_post_id, int $source_site_id, int $target_site_id ): void {
		switch_to_blog( $source_site_id );
		$source_url = trailingslashit( get_site_url() );

		$meta_values = [];
		foreach ( self::META_KEYS as $key ) {
			$meta_values[ $key ] = get_post_meta( $source_post_id, $key, true );
		}

		restore_current_blog();

		switch_to_blog( $target_site_id );
		$target_url = trailingslashit( get_site_url() );

		foreach ( $meta_values as $key => $value ) {
			if ( empty( $value ) ) {
				continue;
			}

			if ( in_array( $key, self::URL_KEYS, true ) && is_string( $value ) ) {
				$value = str_replace( $source_url, $target_url, $value );
			}

			update_post_meta( $target_post_id, $key, $value );
		}

		restore_current_blog();
	}
}
