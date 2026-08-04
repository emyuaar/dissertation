<?php
/**
 * Opt-in event-driven email alerts (improvement plan P3-3).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends immediate alert emails when notable bot-traffic events occur.
 */
class Event_Alerts {

	const OPTION = 'llmagnet_event_alerts';

	/**
	 * Analytics instance (table name).
	 *
	 * @var Analytics|null
	 */
	private $analytics;

	/**
	 * Constructor.
	 *
	 * @param Analytics|null $analytics Analytics instance.
	 */
	public function __construct( Analytics $analytics = null ) {
		$this->analytics = $analytics;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'llmagnet_bot_visit_logged', [ $this, 'handle_bot_visit' ], 10, 1 );
		add_action( 'llmagnet_weekly_analytics_report', [ $this, 'maybe_send_traffic_drop_alert' ], 5 );
	}

	/**
	 * Default alert toggles (all opt-in).
	 *
	 * @return array<string, bool>
	 */
	public static function defaults(): array {
		return [
			'product_crawled'   => false,
			'traffic_drop'      => false,
			'new_bot_detected'  => false,
		];
	}

	/**
	 * Merged alert settings.
	 *
	 * @return array<string, bool>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Sanitize alert toggles from REST/form input.
	 *
	 * @param array $input Raw input.
	 * @return array<string, bool>
	 */
	public static function sanitize_settings( array $input ): array {
		$out = self::defaults();
		foreach ( array_keys( $out ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Handle a logged bot visit.
	 *
	 * @param array $visit Visit payload from Analytics.
	 * @return void
	 */
	public function handle_bot_visit( array $visit ): void {
		$settings = self::get_settings();

		if ( ! empty( $settings['new_bot_detected'] ) && ! empty( $visit['is_new_bot'] ) ) {
			$bot = isset( $visit['bot_name'] ) ? (string) $visit['bot_name'] : 'Unknown';
			$this->send_alert(
				'new_bot_' . sanitize_key( $bot ),
				sprintf(
					/* translators: %s: bot name */
					__( 'New AI bot detected: %s', 'llmagnet-llm-txt-generator' ),
					$bot
				),
				sprintf(
					/* translators: 1: bot name, 2: page path */
					__( '%1$s was detected for the first time on your site, visiting %2$s.', 'llmagnet-llm-txt-generator' ),
					$bot,
					isset( $visit['page_path'] ) ? (string) $visit['page_path'] : '/'
				)
			);
		}

		if ( ! empty( $settings['product_crawled'] ) ) {
			$this->maybe_product_crawled_alert( $visit );
		}
	}

	/**
	 * Weekly cron: alert when bot traffic drops more than 40% week-over-week.
	 *
	 * @return void
	 */
	public function maybe_send_traffic_drop_alert(): void {
		$settings = self::get_settings();
		if ( empty( $settings['traffic_drop'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'llm_bot_visits';

		$recent = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);
		$prior  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND visit_time < DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);

		if ( $prior < 5 || $recent >= $prior * 0.6 ) {
			return;
		}

		$drop_pct = round( ( ( $prior - $recent ) / $prior ) * 100 );

		$this->send_alert(
			'traffic_drop_weekly',
			__( 'AI bot traffic dropped sharply', 'llmagnet-llm-txt-generator' ),
			sprintf(
				/* translators: 1: percent drop, 2: recent count, 3: prior count */
				__( 'Bot visits fell %1$d%% week-over-week (%2$d visits in the last 7 days vs %3$d the week before). Review your llms.txt, robots.txt, and recent content changes.', 'llmagnet-llm-txt-generator' ),
				$drop_pct,
				$recent,
				$prior
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Alert when a recently published product is crawled within one hour.
	 *
	 * @param array $visit Visit payload.
	 * @return void
	 */
	private function maybe_product_crawled_alert( array $visit ): void {
		$path = isset( $visit['page_path'] ) ? (string) $visit['page_path'] : '';
		if ( $path === '' ) {
			return;
		}

		$url     = home_url( ltrim( $path, '/' ) );
		$post_id = url_to_postid( $url );
		if ( ! $post_id || get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return;
		}

		$published = strtotime( $post->post_date_gmt . ' GMT' );
		$visit_ts  = isset( $visit['visit_time'] ) ? strtotime( (string) $visit['visit_time'] ) : time();
		if ( $visit_ts - $published > HOUR_IN_SECONDS ) {
			return;
		}

		$bot = isset( $visit['bot_name'] ) ? (string) $visit['bot_name'] : __( 'An AI bot', 'llmagnet-llm-txt-generator' );

		$this->send_alert(
			'product_crawled_' . $post_id,
			sprintf(
				/* translators: %s: product title */
				__( 'Your new product was crawled: %s', 'llmagnet-llm-txt-generator' ),
				get_the_title( $post_id )
			),
			sprintf(
				/* translators: 1: bot name, 2: product title */
				__( '%1$s crawled your product "%2$s" within an hour of publish — a strong signal that AI systems are discovering new catalog items quickly.', 'llmagnet-llm-txt-generator' ),
				$bot,
				get_the_title( $post_id )
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Send a deduplicated HTML alert email.
	 *
	 * @param string $dedupe_key Transient key suffix.
	 * @param string $subject    Email subject line.
	 * @param string $body       Plain message body.
	 * @param int    $dedupe_ttl Seconds before the same alert can fire again.
	 * @return void
	 */
	private function send_alert( string $dedupe_key, string $subject, string $body, int $dedupe_ttl = DAY_IN_SECONDS ): void {
		$transient = 'llmagnet_alert_' . md5( $dedupe_key );
		if ( get_transient( $transient ) ) {
			return;
		}

		$recipients = $this->get_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$full_subject = sprintf(
			/* translators: 1: site name, 2: alert subject */
			__( '[%1$s] LLMagnet alert — %2$s', 'llmagnet-llm-txt-generator' ),
			$site_name,
			$subject
		);

		$html = '<html><body style="font-family:sans-serif;line-height:1.5;color:#1a1a1a;">';
		$html .= '<p>' . esc_html( $body ) . '</p>';
		$html .= '<p style="margin-top:24px;"><a href="' . esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-bot-analytics' ) ) . '">' . esc_html__( 'Open Bot Analytics →', 'llmagnet-llm-txt-generator' ) . '</a></p>';
		$html .= '</body></html>';

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		foreach ( $recipients as $to ) {
			wp_mail( $to, $full_subject, $html, $headers );
		}

		set_transient( $transient, 1, $dedupe_ttl );
	}

	/**
	 * Alert recipients — report email list, else site admin email.
	 *
	 * @return string[]
	 */
	private function get_recipients(): array {
		$raw = get_option( 'llmagnet_report_email', get_bloginfo( 'admin_email' ) );
		$emails = array_map( 'trim', explode( ',', (string) $raw ) );
		$valid  = [];

		foreach ( $emails as $email ) {
			$sanitized = sanitize_email( $email );
			if ( is_email( $sanitized ) ) {
				$valid[] = $sanitized;
			}
		}

		return $valid;
	}
}
