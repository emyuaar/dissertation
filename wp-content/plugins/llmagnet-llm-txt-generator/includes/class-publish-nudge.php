<?php
/**
 * Publish Nudge class
 *
 * After publishing a post whose AI visibility score is below 50, shows a
 * single dismissible admin notice: "This post scores N for AI visibility —
 * fixes available." Strictly once per post; the shown/dismiss state is
 * stored in user meta. No generic recurring notices (notice fatigue
 * actively hurts adoption — spec §5.6).
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — §5.6 (Phase F, Lane F-B).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Once-per-post low-score nudge after publish.
 *
 * Flow: `transition_post_status` (→ publish) stamps a pending flag in post
 * meta and queues the post for priority score backfill. The notice renders
 * later — on the post's edit screen or the list table — only once a score
 * exists and is below the threshold, then the flag is cleared so the
 * notice can never repeat for that post. The score itself is never
 * computed in a render path.
 */
class Publish_Nudge {

	/**
	 * Post meta: Unix timestamp of the publish event awaiting a nudge
	 * decision. Hidden (underscore prefix). Deleted once decided.
	 */
	const PENDING_META = '_llmagnet_publish_nudge';

	/**
	 * User meta: array of post IDs the nudge was already shown for
	 * (spec: "dismiss stored in user meta", strictly once per post).
	 */
	const SHOWN_USER_META = 'llmagnet_nudge_shown_posts';

	/**
	 * Scores below this trigger the nudge (spec §5.6).
	 */
	const SCORE_THRESHOLD = 50;

	/**
	 * Pending flags older than this are abandoned — a nudge weeks after
	 * publish would just be noise.
	 */
	const PENDING_TTL = WEEK_IN_SECONDS;

	/**
	 * Register hooks. Notices are admin-only; the publish listener also
	 * runs for front-end/REST/CLI publishes.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'transition_post_status', [ $this, 'on_publish' ], 10, 3 );
		if ( is_admin() ) {
			add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
		}
	}

	/**
	 * Stamp a pending-nudge flag on first publish and queue the post for
	 * priority score backfill (new posts have no persisted score yet).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, $this->get_post_types(), true ) ) {
			return;
		}
		// Strictly once per post — a post that already got its nudge (any
		// user) never gets re-flagged on later re-publishes.
		if ( $this->was_shown_for_post( $post->ID ) ) {
			return;
		}

		update_post_meta( $post->ID, self::PENDING_META, time() );
		$this->queue_for_priority_backfill( $post->ID );
	}

	/**
	 * Render the nudge on the post's edit screen or its list table —
	 * the screens a publisher lands on right after publishing.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}

		$post_id = 0;

		if ( 'post' === $screen->base && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
			$candidate = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $candidate && $this->nudge_decision( $candidate ) ) {
				$post_id = $candidate;
			}
		} elseif ( 'edit' === $screen->base && in_array( $screen->post_type, $this->get_post_types(), true ) ) {
			$post_id = $this->find_pending_for_list( $screen->post_type );
		}

		if ( ! $post_id ) {
			return;
		}

		$row = Score_Store::get( $post_id );
		if ( null === $row ) {
			return;
		}

		$this->mark_shown( $post_id );

		$score = (int) $row['score'];
		$title = get_the_title( $post_id );

		$fix_link = '';
		if ( current_user_can( 'manage_options' ) && class_exists( __NAMESPACE__ . '\\Admin_WP_Helper' ) ) {
			$fix_link = sprintf(
				' <a href="%s">%s</a>',
				esc_url( Admin_WP_Helper::drawer_url( $post_id ) ),
				esc_html__( 'See the fixes', 'llmagnet-llm-txt-generator' )
			);
		}

		printf(
			'<div class="notice notice-warning is-dismissible llmagnet-publish-nudge"><p>%s%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: post title, 2: score 0-100 */
					__( '"%1$s" scores %2$d of 100 for AI visibility — a few quick fixes can improve how AI assistants see it.', 'llmagnet-llm-txt-generator' ),
					wp_strip_all_tags( (string) $title ),
					$score
				)
			),
			$fix_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
		);
	}

	/**
	 * Decide whether the nudge should fire for a post, clearing the
	 * pending flag whenever a final decision is possible.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True = render the nudge now.
	 */
	private function nudge_decision( int $post_id ): bool {
		$pending = (int) get_post_meta( $post_id, self::PENDING_META, true );
		if ( ! $pending ) {
			return false;
		}

		if ( ( time() - $pending ) > self::PENDING_TTL ) {
			delete_post_meta( $post_id, self::PENDING_META );
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( $this->was_shown_for_user( $post_id ) ) {
			return false;
		}

		$row = class_exists( __NAMESPACE__ . '\\Score_Store' ) ? Score_Store::get( $post_id ) : null;
		if ( null === $row ) {
			// Score not computed yet — keep waiting (the priority backfill
			// usually lands within minutes of publish).
			return false;
		}

		if ( (int) $row['score'] >= self::SCORE_THRESHOLD ) {
			// Healthy score — never nudge for this post.
			delete_post_meta( $post_id, self::PENDING_META );
			return false;
		}

		return true;
	}

	/**
	 * Newest pending-nudge post of a type, for list-table screens. Cheap:
	 * a meta-EXISTS query that matches at most a handful of rows (flags
	 * are short-lived), capped at 5; at most ONE notice is shown.
	 *
	 * @param string $post_type Post type of the list table.
	 * @return int Post ID or 0.
	 */
	private function find_pending_for_list( string $post_type ): int {
		$query = new \WP_Query( [
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => 5,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'meta_query'             => [
				[
					'key'     => self::PENDING_META,
					'compare' => 'EXISTS',
				],
			],
		] );

		foreach ( array_map( 'intval', $query->posts ) as $post_id ) {
			if ( $this->nudge_decision( $post_id ) ) {
				return $post_id;
			}
		}

		return 0;
	}

	/**
	 * Record that the nudge was rendered: per-user meta (spec) + clear the
	 * post's pending flag so it is strictly once per post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function mark_shown( int $post_id ) {
		delete_post_meta( $post_id, self::PENDING_META );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$shown = get_user_meta( $user_id, self::SHOWN_USER_META, true );
		if ( ! is_array( $shown ) ) {
			$shown = [];
		}
		$shown[] = $post_id;
		// Keep the list bounded.
		$shown = array_slice( array_values( array_unique( array_map( 'absint', $shown ) ) ), -200 );
		update_user_meta( $user_id, self::SHOWN_USER_META, $shown );
	}

	/**
	 * Whether the current user already saw the nudge for this post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function was_shown_for_user( int $post_id ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$shown = get_user_meta( $user_id, self::SHOWN_USER_META, true );
		return is_array( $shown ) && in_array( $post_id, array_map( 'absint', $shown ), true );
	}

	/**
	 * Whether the nudge already fired for this post for ANY user (the
	 * pending flag is deleted on first render, so "no flag + post in the
	 * current user's shown list" is the only cheap signal we keep; for
	 * re-publish suppression the absence of the flag is enough combined
	 * with the current user's list).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function was_shown_for_post( int $post_id ): bool {
		return $this->was_shown_for_user( $post_id );
	}

	/**
	 * Post types eligible for the nudge — the score-surface set.
	 *
	 * @return array
	 */
	private function get_post_types(): array {
		return class_exists( __NAMESPACE__ . '\\Score_Store' )
			? Score_Store::get_post_types()
			: [ 'post', 'page' ];
	}

	/**
	 * Queue the post at the head of the Score_Store priority backfill
	 * queue + schedule a near-term one-off run, mirroring what the
	 * /scores endpoint does for nulls (Score_Store's queue helper is
	 * private; constants are public API).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function queue_for_priority_backfill( int $post_id ) {
		if ( ! class_exists( __NAMESPACE__ . '\\Score_Store' ) ) {
			return;
		}

		$queue = get_option( Score_Store::PRIORITY_QUEUE_OPTION, [] );
		if ( ! is_array( $queue ) ) {
			$queue = [];
		}
		$queue = array_values( array_unique( array_merge( [ absint( $post_id ) ], array_map( 'absint', $queue ) ) ) );
		$queue = array_slice( $queue, 0, 2 * Score_Store::MAX_BATCH_IDS );

		update_option( Score_Store::PRIORITY_QUEUE_OPTION, $queue, false );

		// WP dedupes identical single events within 10 minutes — safe.
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Score_Store::BACKFILL_EVENT );
	}
}
