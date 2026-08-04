<?php
/**
 * Score Store class
 *
 * Persists the per-post visibility score (0-100) into post meta so it can
 * drive sortable list-table columns, editor panels and other admin surfaces
 * without computing scores synchronously in page renders.
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — Phase 0.1 / 0.2.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-post visibility score storage, backfill and batch read endpoint.
 *
 * Write path: `Page_Details::calculate_visibility_score()` and
 * `Product_Details::calculate_visibility_score()` fire the
 * `llmagnet_post_score_calculated` action for the canonical 30-day score;
 * this class listens and persists it. Scores are refreshed by the hourly
 * `llmagnet_score_backfill` cron (scheduled in class-cron.php) and marked
 * stale on `save_post`.
 */
class Score_Store {

	/**
	 * Meta key: headline score (int 0-100). Hidden (underscore prefix).
	 */
	const META_SCORE = '_llmagnet_score';

	/**
	 * Meta key: Unix timestamp of the last score write. `0` = explicitly
	 * marked stale (kept numeric instead of deleted so the backfill query
	 * can match it with a `<` comparison and sort oldest-first).
	 */
	const META_UPDATED = '_llmagnet_score_updated';

	/**
	 * Cron hook for the hourly backfill (registered in class-cron.php).
	 */
	const BACKFILL_EVENT = 'llmagnet_score_backfill';

	/**
	 * Option holding post IDs queued for priority backfill (autoload off).
	 */
	const PRIORITY_QUEUE_OPTION = 'llmagnet_score_priority_queue';

	/**
	 * Posts computed per backfill run. Keeps a single run cheap on shared
	 * hosting; a 500-post site fully backfills in under a day.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Maximum IDs accepted by the batch endpoint / kept in the queue.
	 */
	const MAX_BATCH_IDS = 100;

	/**
	 * Register hooks. Idempotent — safe to call from both class-cron.php
	 * and class-main.php; only the first call registers anything.
	 *
	 * @return void
	 */
	public static function boot() {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'llmagnet_post_score_calculated', [ __CLASS__, 'on_score_calculated' ], 10, 2 );
		add_action( 'save_post', [ __CLASS__, 'mark_stale_on_save' ], 10, 2 );
		add_action( self::BACKFILL_EVENT, [ __CLASS__, 'run_backfill' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );

		// Self-healing schedule for sites that were already active when this
		// shipped (activation-time scheduling lives in Cron::schedule_event()).
		if ( ! wp_next_scheduled( self::BACKFILL_EVENT ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::BACKFILL_EVENT );
		}
	}

	/**
	 * Persist both meta keys for a post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $score   Score 0-100.
	 * @return void
	 */
	public static function save( int $post_id, int $score ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}
		update_post_meta( $post_id, self::META_SCORE, max( 0, min( 100, $score ) ) );
		update_post_meta( $post_id, self::META_UPDATED, time() );
	}

	/**
	 * Action listener for `llmagnet_post_score_calculated`.
	 *
	 * @param int $post_id Post ID.
	 * @param int $score   Calculated headline score.
	 * @return void
	 */
	public static function on_score_calculated( $post_id, $score ) {
		self::save( (int) $post_id, (int) $score );
	}

	/**
	 * Read the persisted score for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null ['score' => int, 'updated' => int] or null if never computed.
	 */
	public static function get( int $post_id ) {
		$score = get_post_meta( $post_id, self::META_SCORE, true );
		if ( '' === $score || false === $score ) {
			return null;
		}
		return [
			'score'   => max( 0, min( 100, (int) $score ) ),
			'updated' => (int) get_post_meta( $post_id, self::META_UPDATED, true ),
		];
	}

	/**
	 * Whether the persisted score is missing or older than the TTL.
	 *
	 * @param int $post_id Post ID.
	 * @param int $ttl     Max age in seconds (default 1 day).
	 * @return bool
	 */
	public static function is_stale( int $post_id, int $ttl = DAY_IN_SECONDS ): bool {
		$row = self::get( $post_id );
		if ( null === $row ) {
			return true;
		}
		return ( time() - $row['updated'] ) > $ttl;
	}

	/**
	 * Mark a post stale on save so the next backfill run refreshes it.
	 * Never recomputes synchronously inside save_post.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return void
	 */
	public static function mark_stale_on_save( $post_id, $post = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::get_post_types(), true ) ) {
			return;
		}
		// Only posts that already have a score need the stale marker; posts
		// without one are picked up by the "never computed" backfill query.
		if ( metadata_exists( 'post', $post_id, self::META_SCORE ) ) {
			update_post_meta( $post_id, self::META_UPDATED, 0 );
		}
	}

	/**
	 * Post types eligible for persisted scores.
	 *
	 * @return array
	 */
	public static function get_post_types(): array {
		$types = [ 'post', 'page' ];
		if ( class_exists( __NAMESPACE__ . '\\WooCommerce' ) && WooCommerce::is_active() ) {
			$types[] = 'product';
		}
		return (array) apply_filters( 'llmagnet_score_post_types', $types );
	}

	/**
	 * Hourly backfill: consume the priority queue first, then up to the
	 * batch size of published posts whose score is missing or older than
	 * 24 hours (oldest first).
	 *
	 * @return void
	 */
	public static function run_backfill() {
		$batch = (int) apply_filters( 'llmagnet_score_backfill_batch_size', self::BATCH_SIZE );
		if ( $batch < 1 ) {
			return;
		}

		$ids = self::consume_priority_queue( $batch );

		if ( count( $ids ) < $batch ) {
			$ids = array_merge( $ids, self::query_missing( $batch - count( $ids ), $ids ) );
		}

		if ( count( $ids ) < $batch ) {
			$ids = array_merge( $ids, self::query_stale( $batch - count( $ids ), $ids ) );
		}

		foreach ( array_unique( $ids ) as $post_id ) {
			self::compute( (int) $post_id );
		}
	}

	/**
	 * Pop up to $limit IDs off the priority queue option.
	 *
	 * @param int $limit Max IDs to take.
	 * @return array
	 */
	private static function consume_priority_queue( int $limit ): array {
		$queue = get_option( self::PRIORITY_QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return [];
		}

		$queue = array_values( array_unique( array_filter( array_map( 'absint', $queue ) ) ) );
		$taken = array_slice( $queue, 0, $limit );
		$rest  = array_slice( $queue, $limit );

		if ( empty( $rest ) ) {
			delete_option( self::PRIORITY_QUEUE_OPTION );
		} else {
			update_option( self::PRIORITY_QUEUE_OPTION, $rest, false );
		}

		return $taken;
	}

	/**
	 * Published posts that have never been scored, oldest IDs first.
	 *
	 * @param int   $limit   Max posts.
	 * @param array $exclude IDs already picked this run.
	 * @return array
	 */
	private static function query_missing( int $limit, array $exclude ): array {
		$args = [
			'post_type'              => self::get_post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'     => self::META_SCORE,
					'compare' => 'NOT EXISTS',
				],
			],
		];
		if ( ! empty( $exclude ) ) {
			$args['post__not_in'] = $exclude;
		}
		$query = new \WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Published posts whose score is older than 24h (or marked stale = 0),
	 * oldest first.
	 *
	 * @param int   $limit   Max posts.
	 * @param array $exclude IDs already picked this run.
	 * @return array
	 */
	private static function query_stale( int $limit, array $exclude ): array {
		$args = [
			'post_type'              => self::get_post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => $limit,
			'orderby'                => 'meta_value_num',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_key'               => self::META_UPDATED,
			'meta_query'             => [
				[
					'key'     => self::META_UPDATED,
					'value'   => time() - DAY_IN_SECONDS,
					'compare' => '<',
					'type'    => 'NUMERIC',
				],
			],
		];
		if ( ! empty( $exclude ) ) {
			$args['post__not_in'] = $exclude;
		}
		$query = new \WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Compute (and thereby persist, via the action listener) the canonical
	 * 30-day score for one post. Reuses the transient cache inside the
	 * details classes, so cron doesn't duplicate work done by drawer opens.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function compute( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::get_post_types(), true ) ) {
			return;
		}

		if ( 'product' === $post->post_type ) {
			if ( function_exists( 'wc_get_product' ) && class_exists( __NAMESPACE__ . '\\Product_Details' ) ) {
				( new Product_Details() )->calculate_visibility_score( $post_id, 30 );
			}
			return;
		}

		( new Page_Details() )->calculate_visibility_score( $post_id, 30 );
	}

	/**
	 * Register the batch score read endpoint.
	 *
	 * CAPABILITY DECISION (adoption plan Phase 0.2 cap-note, task C1-4):
	 * score READS use `edit_posts` (+ per-post `edit_post` checks) so that
	 * editors and shop managers — who see list tables and the block editor —
	 * can read scores. Scores contain no sensitive data (bot visit counts +
	 * content checks). All WRITE/fix endpoints keep the existing
	 * `manage_options` requirement. Reuse this decision for every score-read
	 * surface (list-table column, Gutenberg panel, Elementor, widget).
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		register_rest_route( 'llm-analytics/v1', '/scores', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_scores_endpoint' ],
			'permission_callback' => function () {
				// Read-only surface: edit_posts, not manage_options (C1-4).
				return current_user_can( 'edit_posts' );
			},
			'args'                => [
				'post_ids' => [
					'required'          => true,
					'type'              => 'string',
					'description'       => __( 'Comma-separated post IDs.', 'llmagnet-llm-txt-generator' ),
					'sanitize_callback' => 'sanitize_text_field',
				],
				'context'  => [
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	/**
	 * GET /llm-analytics/v1/scores?post_ids=1,2,3
	 *
	 * Reads meta only — never computes inline. IDs without a stored score
	 * are returned as null and queued at the head of the backfill queue,
	 * plus an immediate one-off cron event so first paint of a busy list
	 * table populates within minutes rather than hours.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public static function get_scores_endpoint( $request ) {
		$raw = (string) $request->get_param( 'post_ids' );
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
		$ids = array_slice( $ids, 0, self::MAX_BATCH_IDS );

		if ( empty( $ids ) ) {
			return new \WP_REST_Response( [
				'success' => false,
				'error'   => __( 'No valid post IDs provided.', 'llmagnet-llm-txt-generator' ),
				'code'    => 'invalid_post_ids',
			], 400 );
		}

		$scores   = [];
		$to_queue = [];

		foreach ( $ids as $post_id ) {
			// Per-post capability semantics (C1-4): only expose scores for
			// posts the current user can edit.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$scores[ (string) $post_id ] = null;
				continue;
			}

			$row = self::get( $post_id );
			if ( null === $row ) {
				$scores[ (string) $post_id ] = null;
				$post                        = get_post( $post_id );
				if ( $post && 'publish' === $post->post_status && in_array( $post->post_type, self::get_post_types(), true ) ) {
					$to_queue[] = $post_id;
				}
				continue;
			}

			$scores[ (string) $post_id ] = [
				'score' => $row['score'],
				'stale' => self::is_stale( $post_id ),
			];
		}

		if ( ! empty( $to_queue ) ) {
			self::queue_for_backfill( $to_queue );
		}

		return new \WP_REST_Response( [ 'scores' => (object) $scores ], 200 );
	}

	/**
	 * Push IDs onto the priority backfill queue and schedule a near-term
	 * one-off run (the hourly event may be up to an hour away).
	 *
	 * @param array $ids Post IDs to queue.
	 * @return void
	 */
	private static function queue_for_backfill( array $ids ) {
		$queue = get_option( self::PRIORITY_QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}
		// New IDs go to the head — these are posts a user is looking at now.
		$queue = array_values( array_unique( array_merge( array_map( 'absint', $ids ), array_map( 'absint', $queue ) ) ) );
		$queue = array_slice( $queue, 0, 2 * self::MAX_BATCH_IDS );

		update_option( self::PRIORITY_QUEUE_OPTION, $queue, false );

		// WP dedupes identical single events within 10 minutes — safe to call.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::BACKFILL_EVENT );
	}
}
