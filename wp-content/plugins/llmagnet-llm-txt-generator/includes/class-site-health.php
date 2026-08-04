<?php
/**
 * Site Health class
 *
 * Adds LLMagnet tests to Tools → Site Health via the `site_status_tests`
 * filter: llms.txt exists/fresh, robots.txt references llms.txt, images
 * missing ALT text (direct tests), plus score coverage and bot tracking
 * (async REST tests — they run DB aggregates).
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — §5.4 (Phase F, Lane F-B).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health tests. Failed tests deep-link into the plugin's fix pages.
 */
class Site_Health {

	/**
	 * Generator instance (optional — llms.txt path + freshness).
	 *
	 * @var Generator|null
	 */
	private $generator;

	/**
	 * Robots_Txt instance (optional — llms.txt reference status).
	 *
	 * @var Robots_Txt|null
	 */
	private $robots_txt;

	/**
	 * Constructor.
	 *
	 * @param Generator|null  $generator  Generator instance.
	 * @param Robots_Txt|null $robots_txt Robots_Txt instance.
	 */
	public function __construct( $generator = null, $robots_txt = null ) {
		$this->generator  = $generator;
		$this->robots_txt = $robots_txt;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Shared badge for all LLMagnet tests.
	 *
	 * @return array
	 */
	private function badge(): array {
		return [
			'label' => __( 'AI Visibility', 'llmagnet-llm-txt-generator' ),
			'color' => 'purple',
		];
	}

	/**
	 * Register direct + async tests (spec §5.4).
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function register_tests( $tests ) {
		$tests['direct']['llmagnet_llms_txt'] = [
			'label' => __( 'llms.txt is generated and fresh', 'llmagnet-llm-txt-generator' ),
			'test'  => [ $this, 'test_llms_txt' ],
		];

		$tests['direct']['llmagnet_robots_llms'] = [
			'label' => __( 'robots.txt references llms.txt', 'llmagnet-llm-txt-generator' ),
			'test'  => [ $this, 'test_robots_reference' ],
		];

		$tests['direct']['llmagnet_image_alt'] = [
			'label' => __( 'Images have ALT text', 'llmagnet-llm-txt-generator' ),
			'test'  => [ $this, 'test_image_alt' ],
		];

		// DB-aggregate tests run async so the Site Health page render stays
		// cheap (the page fires async tests over REST after first paint).
		$tests['async']['llmagnet_score_coverage'] = [
			'label'    => __( 'AI visibility scores are calculated', 'llmagnet-llm-txt-generator' ),
			'test'     => rest_url( 'llm-analytics/v1/health/score-coverage' ),
			'has_rest' => true,
		];

		$tests['async']['llmagnet_bot_tracking'] = [
			'label'    => __( 'AI bot tracking is working', 'llmagnet-llm-txt-generator' ),
			'test'     => rest_url( 'llm-analytics/v1/health/bot-tracking' ),
			'has_rest' => true,
		];

		return $tests;
	}

	/**
	 * REST routes backing the async tests. Same capability core uses for
	 * its own async Site Health tests.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$permission = function () {
			return current_user_can( 'view_site_health_checks' );
		};

		register_rest_route( 'llm-analytics/v1', '/health/score-coverage', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'test_score_coverage' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( 'llm-analytics/v1', '/health/bot-tracking', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'test_bot_tracking' ],
			'permission_callback' => $permission,
		] );
	}

	/* ---------------------------------------------------------------------
	 * Direct tests
	 * ------------------------------------------------------------------- */

	/**
	 * llms.txt exists and was refreshed recently (generation runs daily;
	 * 7 days of slack tolerates paused crons before warning).
	 *
	 * @return array
	 */
	public function test_llms_txt(): array {
		$result = [
			'label'       => __( 'llms.txt is generated and fresh', 'llmagnet-llm-txt-generator' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => '<p>' . esc_html__( 'Your llms.txt file exists and is up to date, so AI assistants can discover your most important content.', 'llmagnet-llm-txt-generator' ) . '</p>',
			'actions'     => '',
			'test'        => 'llmagnet_llms_txt',
		];

		$root_path = $this->generator instanceof Generator
			? $this->generator->get_root_path()
			: trailingslashit( ABSPATH );
		$file      = trailingslashit( $root_path ) . 'llms.txt';

		if ( ! file_exists( $file ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'llms.txt has not been generated yet', 'llmagnet-llm-txt-generator' );
			$result['description'] = '<p>' . esc_html__( 'llms.txt tells AI assistants (ChatGPT, Claude, Perplexity and others) which content on your site matters. Without it, AI crawlers have to guess.', 'llmagnet-llm-txt-generator' ) . '</p>';
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-overview' ) ),
				esc_html__( 'Generate your llms.txt', 'llmagnet-llm-txt-generator' )
			);
			return $result;
		}

		$mtime = (int) @filemtime( $file );
		if ( $mtime && ( time() - $mtime ) > WEEK_IN_SECONDS ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'llms.txt has not been refreshed in over a week', 'llmagnet-llm-txt-generator' );
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: human-readable time since the last refresh */
				esc_html__( 'llms.txt was last written %s ago. The daily regeneration may not be running — check WP-Cron.', 'llmagnet-llm-txt-generator' ),
				esc_html( human_time_diff( $mtime ) )
			) . '</p>';
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-content-settings' ) ),
				esc_html__( 'Review content settings and regenerate', 'llmagnet-llm-txt-generator' )
			);
		}

		return $result;
	}

	/**
	 * robots.txt references llms.txt (data from Robots_Txt::get_status()).
	 *
	 * @return array
	 */
	public function test_robots_reference(): array {
		$result = [
			'label'       => __( 'robots.txt references llms.txt', 'llmagnet-llm-txt-generator' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => '<p>' . esc_html__( 'Your robots.txt points crawlers at your llms.txt file.', 'llmagnet-llm-txt-generator' ) . '</p>',
			'actions'     => '',
			'test'        => 'llmagnet_robots_llms',
		];

		if ( ! $this->robots_txt instanceof Robots_Txt ) {
			// No robots manager wired in — nothing meaningful to report.
			return $result;
		}

		$status = $this->robots_txt->get_status();
		if ( empty( $status['has_llms_reference'] ) ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'robots.txt does not reference llms.txt', 'llmagnet-llm-txt-generator' );
			$result['description'] = '<p>' . esc_html__( 'Adding an llms.txt line to robots.txt helps AI crawlers discover the file. LLMagnet can inject it for you.', 'llmagnet-llm-txt-generator' ) . '</p>';
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-content-settings' ) ),
				esc_html__( 'Enable the robots.txt reference', 'llmagnet-llm-txt-generator' )
			);
		}

		return $result;
	}

	/**
	 * Images missing ALT text (count maintained by the existing scanner in
	 * the `llmagnet_ai_seo_optimizer_images_without_alt` option).
	 *
	 * @return array
	 */
	public function test_image_alt(): array {
		$result = [
			'label'       => __( 'Images have ALT text', 'llmagnet-llm-txt-generator' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => '<p>' . esc_html__( 'No images missing ALT text were detected by the last scan.', 'llmagnet-llm-txt-generator' ) . '</p>',
			'actions'     => '',
			'test'        => 'llmagnet_image_alt',
		];

		$missing = get_option( 'llmagnet_ai_seo_optimizer_images_without_alt', [] );
		$count   = is_array( $missing ) ? count( $missing ) : 0;

		if ( $count > 0 ) {
			$result['status'] = 'recommended';
			$result['label']  = sprintf(
				/* translators: %d: number of images */
				_n( '%d image is missing ALT text', '%d images are missing ALT text', $count, 'llmagnet-llm-txt-generator' ),
				$count
			);
			$result['description'] = '<p>' . esc_html__( 'ALT text helps AI models and screen readers understand your images, and feeds the content-quality part of your AI visibility score.', 'llmagnet-llm-txt-generator' ) . '</p>';
			$result['actions']     = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-optimizer' ) ),
				esc_html__( 'Fix ALT texts in LLMagnet', 'llmagnet-llm-txt-generator' )
			);
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Async tests (REST)
	 * ------------------------------------------------------------------- */

	/**
	 * Share of published posts (score-eligible types) with a persisted
	 * AI visibility score. The hourly backfill should converge to ~100%.
	 *
	 * @return array Site Health result shape.
	 */
	public function test_score_coverage(): array {
		$result = [
			'label'       => __( 'AI visibility scores are calculated', 'llmagnet-llm-txt-generator' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => '',
			'actions'     => '',
			'test'        => 'llmagnet_score_coverage',
		];

		if ( ! class_exists( __NAMESPACE__ . '\\Score_Store' ) ) {
			$result['description'] = '<p>' . esc_html__( 'Score storage is unavailable.', 'llmagnet-llm-txt-generator' ) . '</p>';
			return $result;
		}

		global $wpdb;
		$types = Score_Store::get_post_types();
		if ( empty( $types ) ) {
			return $result;
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- aggregate counts over core tables; placeholders prepared.
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
			$types
		) );
		$scored = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
			 WHERE p.post_status = 'publish' AND p.post_type IN ({$placeholders})",
			array_merge( [ Score_Store::META_SCORE ], $types )
		) );
		// phpcs:enable

		if ( 0 === $total ) {
			$result['description'] = '<p>' . esc_html__( 'No published content to score yet.', 'llmagnet-llm-txt-generator' ) . '</p>';
			return $result;
		}

		$pct = (int) floor( ( $scored / $total ) * 100 );

		$result['description'] = '<p>' . sprintf(
			/* translators: 1: scored posts, 2: total posts, 3: percentage */
			esc_html__( '%1$d of %2$d published items have a persisted AI visibility score (%3$d%%).', 'llmagnet-llm-txt-generator' ),
			$scored,
			$total,
			$pct
		) . '</p>';

		if ( $pct < 80 ) {
			$result['status']       = 'recommended';
			$result['label']        = __( 'AI visibility scores are still being calculated', 'llmagnet-llm-txt-generator' );
			$result['description'] .= '<p>' . esc_html__( 'The hourly backfill computes a small batch per run, so coverage grows on its own. If this number never grows, WP-Cron may not be running.', 'llmagnet-llm-txt-generator' ) . '</p>';
		}

		return $result;
	}

	/**
	 * Bot tracking health: the visits table exists; report recent traffic.
	 *
	 * @return array Site Health result shape.
	 */
	public function test_bot_tracking(): array {
		$result = [
			'label'       => __( 'AI bot tracking is working', 'llmagnet-llm-txt-generator' ),
			'status'      => 'good',
			'badge'       => $this->badge(),
			'description' => '',
			'actions'     => '',
			'test'        => 'llmagnet_bot_tracking',
		];

		global $wpdb;
		$table = $wpdb->prefix . 'llm_bot_visits';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- table existence + indexed aggregate.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'AI bot tracking table is missing', 'llmagnet-llm-txt-generator' );
			$result['description'] = '<p>' . esc_html__( 'The bot-visits table was not created. Deactivate and reactivate LLMagnet to recreate it.', 'llmagnet-llm-txt-generator' ) . '</p>';
			return $result;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix-derived.
		$count_30d = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		// phpcs:enable

		if ( $count_30d > 0 ) {
			$result['description'] = '<p>' . sprintf(
				/* translators: %s: formatted number of visits */
				esc_html__( '%s AI bot visits were recorded in the last 30 days.', 'llmagnet-llm-txt-generator' ),
				esc_html( number_format_i18n( $count_30d ) )
			) . '</p>';
			$result['actions'] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-bot-analytics' ) ),
				esc_html__( 'View bot analytics', 'llmagnet-llm-txt-generator' )
			);
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'No AI bot visits recorded yet', 'llmagnet-llm-txt-generator' );
		$result['description'] = '<p>' . esc_html__( 'Tracking is set up, but no AI bot visits have been logged in the last 30 days. New sites can take a while to be discovered; generating llms.txt and keeping it referenced from robots.txt speeds this up. If your site sits behind a full-page cache or proxy that serves bots without hitting PHP, visits cannot be logged.', 'llmagnet-llm-txt-generator' ) . '</p>';
		$result['actions']     = sprintf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-overview' ) ),
			esc_html__( 'Open the LLMagnet overview', 'llmagnet-llm-txt-generator' )
		);

		return $result;
	}
}
