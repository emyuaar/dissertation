<?php
/**
 * Admin Bar class
 *
 * "AI Visibility · 87" admin-bar node (front-end + admin): on a singular
 * front-end view or post edit screen it shows that post's persisted score
 * (meta read — free) linking to its drawer deep-link; elsewhere it shows
 * the site score linking to the main dashboard page.
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — §5.1 (Phase F, Lane F-B).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-bar score node.
 *
 * Performance contract: NEVER computes a score in the render path. Per-post
 * scores are `Score_Store::get()` meta reads; the site score is the latest
 * stored row from the visibility-scores table, cached in a short transient.
 */
class Admin_Bar {

	/**
	 * Admin-bar node id.
	 */
	const NODE_ID = 'llmagnet-score';

	/**
	 * Transient caching the latest stored site score.
	 */
	const SITE_SCORE_TRANSIENT = 'llmagnet_admin_bar_site_score';

	/**
	 * Site-score cache lifetime.
	 */
	const SITE_SCORE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_bar_menu', [ $this, 'add_node' ], 90 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
	}

	/**
	 * Add the score node (spec §5.1).
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_node( $wp_admin_bar ) {
		/**
		 * Filter whether the LLMagnet admin-bar score node is shown.
		 * The node is read-only and capability-gated, but sites can turn
		 * the surface off entirely with this filter.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'llmagnet_show_admin_bar_node', true ) ) {
			return;
		}

		$context = $this->resolve_context();
		if ( null === $context ) {
			return;
		}

		$wp_admin_bar->add_node(
			[
				'id'    => self::NODE_ID,
				'title' => $this->node_title( $context['score'] ),
				'href'  => $context['href'] ? $context['href'] : false,
				'meta'  => [
					'title' => null === $context['score']
						? __( 'AI Visibility — score not calculated yet', 'llmagnet-llm-txt-generator' )
						/* translators: %d: score 0-100 */
						: sprintf( __( 'AI Visibility — score %d of 100', 'llmagnet-llm-txt-generator' ), $context['score'] ),
				],
			]
		);
	}

	/**
	 * Decide what the node shows for the current request, or null to hide.
	 *
	 * - Singular front-end view / post.php edit screen → that post's
	 *   persisted score; requires `edit_post` on it (the C1-4 read
	 *   capability decision); drawer deep-link when the user can reach the
	 *   drawer pages (manage_options).
	 * - Elsewhere → site score, `manage_options` only (matches the
	 *   main dashboard page the node links to).
	 *
	 * @return array{score: int|null, href: string}|null
	 */
	private function resolve_context(): ?array {
		$post_id = $this->current_post_id();

		if ( $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return null;
			}

			$row  = class_exists( __NAMESPACE__ . '\\Score_Store' ) ? Score_Store::get( $post_id ) : null;
			$href = '';
			if ( current_user_can( 'manage_options' ) && class_exists( __NAMESPACE__ . '\\Admin_WP_Helper' ) ) {
				$href = Admin_WP_Helper::drawer_url( $post_id );
			}

			return [
				'score' => null !== $row ? (int) $row['score'] : null,
				'href'  => $href,
			];
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		return [
			'score' => $this->get_site_score(),
			'href'  => admin_url( 'admin.php?page=llmagnet-ai-seo-optimizer' ),
		];
	}

	/**
	 * The post the current request is about, if any: a published singular
	 * front-end view, or the post.php edit screen.
	 *
	 * @return int 0 when the request is not post-scoped.
	 */
	private function current_post_id(): int {
		$post_id = 0;

		if ( ! is_admin() ) {
			if ( is_singular() ) {
				$post_id = (int) get_queried_object_id();
			}
		} else {
			global $pagenow;
			if ( 'post.php' === $pagenow && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
				$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		if ( ! $post_id ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 0;
		}

		$types = class_exists( __NAMESPACE__ . '\\Score_Store' )
			? Score_Store::get_post_types()
			: [ 'post', 'page' ];
		if ( ! in_array( $post->post_type, $types, true ) ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * Latest STORED site visibility score (30d). Reads the scores table
	 * written by the existing score cron — never computes — and caches the
	 * result briefly because the admin bar renders on every page view.
	 *
	 * @return int|null
	 */
	private function get_site_score(): ?int {
		$cached = get_transient( self::SITE_SCORE_TRANSIENT );
		if ( false !== $cached ) {
			return '' === $cached ? null : (int) $cached;
		}

		$score = null;
		if ( class_exists( __NAMESPACE__ . '\\Visibility_Score' ) ) {
			$row = ( new Visibility_Score() )->get_latest_score( 30 );
			if ( is_array( $row ) && isset( $row['score'] ) ) {
				$score = max( 0, min( 100, (int) round( (float) $row['score'] ) ) );
			}
		}

		// Cache "no score yet" too ('' sentinel) so empty sites stay cheap.
		set_transient( self::SITE_SCORE_TRANSIENT, null === $score ? '' : $score, self::SITE_SCORE_TTL );

		return $score;
	}

	/**
	 * Node title markup: color-banded dot + "AI Visibility · N".
	 *
	 * @param int|null $score Score or null (never computed).
	 * @return string
	 */
	private function node_title( ?int $score ): string {
		if ( null === $score ) {
			$band = 'none';
			$text = __( 'AI Visibility · —', 'llmagnet-llm-txt-generator' );
		} else {
			if ( $score >= 90 ) {
				$band = 'green';
			} elseif ( $score >= 50 ) {
				$band = 'orange';
			} else {
				$band = 'red';
			}
			/* translators: %d: score 0-100 */
			$text = sprintf( __( 'AI Visibility · %d', 'llmagnet-llm-txt-generator' ), (int) $score );
		}

		return '<span class="llmagnet-ab-dot llmagnet-ab-dot--' . esc_attr( $band ) . '" aria-hidden="true"></span>'
			. '<span class="llmagnet-ab-label">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Tiny inline CSS riding on the core admin-bar stylesheet (front + admin).
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! is_admin_bar_showing() || ! wp_style_is( 'admin-bar', 'registered' ) ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wp-admin-bar-' . self::NODE_ID . ' .llmagnet-ab-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-inline-end:6px;vertical-align:middle;background:#9ca3af}'
			. '#wp-admin-bar-' . self::NODE_ID . ' .llmagnet-ab-dot--green{background:#22c55e}'
			. '#wp-admin-bar-' . self::NODE_ID . ' .llmagnet-ab-dot--orange{background:#f97316}'
			. '#wp-admin-bar-' . self::NODE_ID . ' .llmagnet-ab-dot--red{background:#ef4444}'
			. '#wp-admin-bar-' . self::NODE_ID . ' .llmagnet-ab-label{vertical-align:middle}'
		);
	}
}
