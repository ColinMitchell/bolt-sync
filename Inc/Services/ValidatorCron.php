<?php
declare( strict_types=1 );

namespace BoltSync\Inc\Services;

/**
 * Background validation of linked posts via WP-Cron.
 */
class ValidatorCron {

	/**
	 * @var string
	 */
	private string $cron_hook = 'bolt_sync_validate_posts_batch';

	/**
	 * @var string
	 */
	private string $progress_option = 'bolt_sync_validation_progress';

	/**
	 * @var string
	 */
	private string $last_run_option = 'bolt_sync_validation_last_run';

	/**
	 * @var int
	 */
	private int $batch_size = 10;

	/**
	 * @var Core
	 */
	private Core $core;

	/**
	 * @var BoltSyncValidator
	 */
	private BoltSyncValidator $validator;

	/**
	 * @param Core              $core
	 * @param BoltSyncValidator $validator
	 */
	public function __construct( Core $core, BoltSyncValidator $validator ) {
		$this->core      = $core;
		$this->validator = $validator;
	}

	/**
	 * Registers the cron hook and schedules the validation batch job if not already scheduled.
	 * Only runs on the main site.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! is_main_site() ) {
			return;
		}

		add_action( $this->cron_hook, [ $this, 'process_batch' ] );

		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			wp_schedule_event( time(), '20minutes', $this->cron_hook );
		}
	}

	/**
	 * Processes a single batch of linked posts for validation, then advances the progress offset.
	 * Resets progress when all posts have been validated.
	 *
	 * @return void
	 */
	public function process_batch(): void {
		$progress = $this->get_progress();
		$posts    = $this->get_posts_to_validate( $progress['offset'], $this->batch_size );

		if ( empty( $posts ) ) {
			$this->reset_progress();

			return;
		}

		$processed = 0;

		foreach ( $posts as $post ) {
			switch_to_blog( (int) $post->site_id );
			$result = $this->validator->validate_post_sync( (int) $post->post_id, true );
			restore_current_blog();

			$processed++;

			if ( ! $result['valid'] ) {
				error_log( sprintf(
					'BoltSync Validation Cron: Post %d (Site %d) — issues found.',
					$post->post_id,
					$post->site_id
				) );
			}
		}

		$new_offset = $progress['offset'] + $processed;
		$this->update_progress( $new_offset, $progress['total_posts'] );

		error_log( sprintf( 'BoltSync Validation Cron: Processed %d/%d posts.', $new_offset, $progress['total_posts'] ) );
	}

	/**
	 * Queries all linked posts across every site and returns a paginated slice.
	 *
	 * @param int $offset
	 * @param int $limit
	 *
	 * @return array
	 */
	private function get_posts_to_validate( int $offset, int $limit ): array {
		$posts = [];

		foreach ( $this->core->get_sites() as $site ) {
			switch_to_blog( (int) $site->blog_id );

			$query = new \WP_Query( [
				'post_type'              => 'any',
				'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
				'meta_key'               => 'bolt_sync_link_id',
				'fields'                 => 'ids',
				'posts_per_page'         => 10000,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			] );

			foreach ( $query->posts as $post_id ) {
				$posts[] = (object) [
					'post_id' => $post_id,
					'site_id' => $site->blog_id,
				];
			}

			restore_current_blog();
		}

		return array_slice( $posts, $offset, $limit );
	}

	/**
	 * Retrieves the current validation progress from the site option.
	 * Initialises total_posts count when starting a new cycle (offset === 0).
	 *
	 * @return array
	 */
	private function get_progress(): array {
		$progress = get_site_option( $this->progress_option, [
			'offset'      => 0,
			'total_posts' => 0,
			'started_at'  => null,
		] );

		if ( $progress['offset'] === 0 ) {
			$progress['total_posts'] = $this->count_total_posts();
			$progress['started_at']  = current_time( 'mysql' );
			$this->update_progress( 0, $progress['total_posts'] );
		}

		return $progress;
	}

	/**
	 * Persists the current progress offset and total post count to the site option.
	 *
	 * @param int $offset
	 * @param int $total
	 *
	 * @return void
	 */
	private function update_progress( int $offset, int $total ): void {
		$existing = get_site_option( $this->progress_option, [] );

		update_site_option( $this->progress_option, [
			'offset'      => $offset,
			'total_posts' => $total,
			'started_at'  => $existing['started_at'] ?? current_time( 'mysql' ),
			'updated_at'  => current_time( 'mysql' ),
		] );
	}

	/**
	 * Clears the progress option and records the last completed run timestamp.
	 *
	 * @return void
	 */
	private function reset_progress(): void {
		update_site_option( $this->last_run_option, current_time( 'mysql' ) );
		delete_site_option( $this->progress_option );
	}

	/**
	 * Counts the total number of linked posts across all sites in the network.
	 *
	 * @return int
	 */
	private function count_total_posts(): int {
		global $wpdb;

		$total = 0;

		foreach ( $this->core->get_sites() as $site ) {
			switch_to_blog( (int) $site->blog_id );

			$count = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT p.ID)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'bolt_sync_link_id'
				   AND p.post_status IN ('publish', 'draft', 'pending', 'private')"
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			restore_current_blog();

			$total += $count;
		}

		return $total;
	}

	/**
	 * Returns the current validation cron status including progress percentage and next scheduled run.
	 *
	 * @return array
	 */
	public function get_status(): array {
		$progress = get_site_option( $this->progress_option );
		$last_run = get_site_option( $this->last_run_option );

		if ( ! $progress ) {
			return [
				'running'        => false,
				'last_completed' => $last_run,
				'next_run'       => wp_next_scheduled( $this->cron_hook ),
			];
		}

		$percentage = $progress['total_posts'] > 0
			? round( ( $progress['offset'] / $progress['total_posts'] ) * 100, 1 )
			: 0;

		return [
			'running'    => true,
			'offset'     => $progress['offset'],
			'total'      => $progress['total_posts'],
			'percentage' => $percentage,
			'started_at' => $progress['started_at'],
			'updated_at' => $progress['updated_at'] ?? null,
			'next_run'   => wp_next_scheduled( $this->cron_hook ),
		];
	}

	/**
	 * Unschedules the next pending validation batch cron event if one exists.
	 *
	 * @return void
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( $this->cron_hook );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $this->cron_hook );
		}
	}
}
