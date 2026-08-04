<?php
/**
 * Privacy & data governance.
 *
 * Centralizes the plugin's GDPR / privacy compliance layer:
 * - Telemetry (Mixpanel usage analytics) opt-in consent storage + REST endpoints.
 *   NOTE: Brevo contact sync + lifecycle (product/activity) emails are
 *   operational, not marketing, and are NOT gated by this consent.
 * - Data-retention setting + daily pruning of the bot analytics tables.
 * - WordPress personal data exporters/erasers for attribution session data.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy class
 */
class Privacy {
	/**
	 * Option storing the telemetry (Mixpanel usage analytics) opt-in consent.
	 * Default: off. Does NOT control Brevo, which is operational and always on.
	 *
	 * @var string
	 */
	const TELEMETRY_OPTION = 'llmagnet_telemetry_consent';

	/**
	 * Option storing the analytics data-retention window in days. 0 = keep forever.
	 *
	 * @var string
	 */
	const RETENTION_OPTION = 'llmagnet_data_retention_days';

	/**
	 * Cron hook for the daily analytics-pruning task.
	 *
	 * @var string
	 */
	const PRUNE_CRON_HOOK = 'llmagnet_privacy_data_prune';

	/**
	 * Default retention window (days) for bot visit / click logs.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 365;

	/**
	 * Allowed retention values (days). 0 disables pruning.
	 *
	 * @var array
	 */
	const ALLOWED_RETENTION_DAYS = [ 0, 30, 90, 180, 365 ];

	/**
	 * Rows deleted per DELETE statement while pruning.
	 *
	 * @var int
	 */
	const PRUNE_BATCH_SIZE = 5000;

	/**
	 * Max DELETE batches per table per cron run (bounds long-running queries).
	 *
	 * @var int
	 */
	const PRUNE_MAX_BATCHES = 10;

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', [ $this, 'register_settings' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// Pruning callback + self-healing schedule (activation hooks don't re-run on upgrades).
		add_action( self::PRUNE_CRON_HOOK, [ $this, 'prune_analytics_tables' ] );
		add_action( 'init', [ $this, 'maybe_schedule_prune_event' ] );

		// Core privacy tools (Tools → Export/Erase Personal Data).
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/* ---------------------------------------------------------------------
	 * Consent / settings accessors
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the site owner has opted in to Mixpanel usage analytics.
	 *
	 * Brevo contact sync + lifecycle emails are operational and not gated by this.
	 *
	 * @return bool
	 */
	public static function is_telemetry_enabled() {
		return (bool) get_option( self::TELEMETRY_OPTION, false );
	}

	/**
	 * Persist the telemetry opt-in choice (option stored with autoload off).
	 *
	 * @param bool $enabled Whether telemetry is allowed.
	 *
	 * @return void
	 */
	public static function set_telemetry_enabled( $enabled ) {
		$value = $enabled ? 1 : 0;
		if ( false === add_option( self::TELEMETRY_OPTION, $value, '', 'no' ) ) {
			update_option( self::TELEMETRY_OPTION, $value );
		}
	}

	/**
	 * Current retention window in days (0 = keep forever).
	 *
	 * @return int
	 */
	public static function get_retention_days() {
		$days = get_option( self::RETENTION_OPTION, null );
		if ( null === $days || false === $days ) {
			return self::DEFAULT_RETENTION_DAYS;
		}
		return max( 0, intval( $days ) );
	}

	/**
	 * Persist the retention window (option stored with autoload off).
	 *
	 * @param int $days Retention window in days; must be in ALLOWED_RETENTION_DAYS.
	 *
	 * @return bool Whether the value was accepted.
	 */
	public static function set_retention_days( $days ) {
		$days = intval( $days );
		if ( ! in_array( $days, self::ALLOWED_RETENTION_DAYS, true ) ) {
			return false;
		}
		if ( false === add_option( self::RETENTION_OPTION, $days, '', 'no' ) ) {
			update_option( self::RETENTION_OPTION, $days );
		}
		return true;
	}

	/**
	 * Register options via the Settings API (sanitization + REST schema).
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'llmagnet_privacy',
			self::TELEMETRY_OPTION,
			[
				'type'              => 'boolean',
				'description'       => __( 'Allow LLMagnet to collect Mixpanel usage analytics from the admin dashboard.', 'llmagnet-llm-txt-generator' ),
				'sanitize_callback' => function ( $value ) {
					return rest_sanitize_boolean( $value ) ? 1 : 0;
				},
				'default'           => 0,
				'show_in_rest'      => false,
			]
		);

		register_setting(
			'llmagnet_privacy',
			self::RETENTION_OPTION,
			[
				'type'              => 'integer',
				'description'       => __( 'Number of days to retain AI bot visit and click logs (0 keeps them forever).', 'llmagnet-llm-txt-generator' ),
				'sanitize_callback' => function ( $value ) {
					$value = intval( $value );
					return in_array( $value, self::ALLOWED_RETENTION_DAYS, true ) ? $value : self::DEFAULT_RETENTION_DAYS;
				},
				'default'           => self::DEFAULT_RETENTION_DAYS,
				'show_in_rest'      => false,
			]
		);

		register_setting(
			'llmagnet_privacy',
			Attribution::TRACKING_OPTION,
			[
				'type'              => 'boolean',
				'description'       => __( 'Enable LLM attribution tracking (first-party cookie on visitors arriving from AI platforms).', 'llmagnet-llm-txt-generator' ),
				'sanitize_callback' => function ( $value ) {
					return rest_sanitize_boolean( $value ) ? 1 : 0;
				},
				'default'           => 1,
				'show_in_rest'      => false,
			]
		);
	}

	/* ---------------------------------------------------------------------
	 * REST endpoints (used by the onboarding wizard + future settings UI)
	 * ------------------------------------------------------------------ */

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route( 'llm-analytics/v1', '/privacy/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_settings' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( 'llm-analytics/v1', '/privacy/settings', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_update_settings' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => [
				'telemetry_enabled'    => [
					'required' => false,
					'type'     => 'boolean',
				],
				'retention_days'       => [
					'required'          => false,
					'type'              => 'integer',
					'validate_callback' => function ( $param ) {
						return in_array( intval( $param ), self::ALLOWED_RETENTION_DAYS, true );
					},
				],
				'attribution_enabled'  => [
					'required' => false,
					'type'     => 'boolean',
				],
			],
		] );
	}

	/**
	 * GET /privacy/settings
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_get_settings() {
		return new \WP_REST_Response( $this->get_settings_payload(), 200 );
	}

	/**
	 * POST /privacy/settings — partial update of privacy settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_update_settings( \WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'telemetry_enabled' ) ) {
			$enabled = rest_sanitize_boolean( $request->get_param( 'telemetry_enabled' ) );
			self::set_telemetry_enabled( $enabled );

			// Opportunistic Brevo re-sync so the contact's telemetry preference is
			// reflected promptly. Brevo itself is operational and runs regardless
			// of this consent (see Lifecycle_Emails::maybe_schedule_site_identity_sync).
			if ( $enabled && ! wp_next_scheduled( 'llmagnet_brevo_site_identity_sync' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'llmagnet_brevo_site_identity_sync' );
			}
		}

		if ( null !== $request->get_param( 'retention_days' ) ) {
			self::set_retention_days( intval( $request->get_param( 'retention_days' ) ) );
		}

		if ( null !== $request->get_param( 'attribution_enabled' ) ) {
			$attribution = rest_sanitize_boolean( $request->get_param( 'attribution_enabled' ) ) ? 1 : 0;
			if ( false === add_option( Attribution::TRACKING_OPTION, $attribution, '', 'no' ) ) {
				update_option( Attribution::TRACKING_OPTION, $attribution );
			}
		}

		return new \WP_REST_Response(
			array_merge( [ 'success' => true ], $this->get_settings_payload() ),
			200
		);
	}

	/**
	 * Current privacy settings payload.
	 *
	 * @return array
	 */
	private function get_settings_payload() {
		return [
			'telemetry_enabled'   => self::is_telemetry_enabled(),
			'retention_days'      => self::get_retention_days(),
			'attribution_enabled' => Attribution::is_tracking_enabled(),
		];
	}

	/* ---------------------------------------------------------------------
	 * Data retention: daily pruning of analytics tables
	 * ------------------------------------------------------------------ */

	/**
	 * Ensure the daily prune event is scheduled (self-healing for upgrades).
	 *
	 * @return void
	 */
	public function maybe_schedule_prune_event() {
		if ( wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( strtotime( 'tomorrow 02:30:00' ), 'daily', self::PRUNE_CRON_HOOK );
	}

	/**
	 * Delete bot visit / page click rows older than the retention window.
	 *
	 * Runs daily via cron. Deletes in bounded batches to avoid long table locks
	 * on large sites.
	 *
	 * @return int Total rows deleted.
	 */
	public function prune_analytics_tables() {
		$days = self::get_retention_days();
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;

		// Internal whitelist: table/column names are never user input.
		$targets = [
			[
				'table'  => $wpdb->prefix . 'llm_bot_visits',
				'column' => 'visit_time',
			],
			[
				'table'  => $wpdb->prefix . 'llm_bot_page_clicks',
				'column' => 'click_time',
			],
		];

		$total_deleted = 0;

		foreach ( $targets as $target ) {
			if ( ! $this->table_exists( $target['table'] ) ) {
				continue;
			}

			for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; $batch++ ) {
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$target['table']} WHERE {$target['column']} < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$days,
						self::PRUNE_BATCH_SIZE
					)
				);

				if ( ! $deleted ) {
					break;
				}

				$total_deleted += intval( $deleted );

				if ( intval( $deleted ) < self::PRUNE_BATCH_SIZE ) {
					break;
				}
			}
		}

		return $total_deleted;
	}

	/* ---------------------------------------------------------------------
	 * WordPress privacy exporters / erasers (attribution sessions)
	 * ------------------------------------------------------------------ */

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 *
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['llmagnet-attribution-sessions'] = [
			'exporter_friendly_name' => __( 'LLMagnet AI Attribution Sessions', 'llmagnet-llm-txt-generator' ),
			'callback'               => [ $this, 'export_attribution_sessions' ],
		];
		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 *
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['llmagnet-attribution-sessions'] = [
			'eraser_friendly_name' => __( 'LLMagnet AI Attribution Sessions', 'llmagnet-llm-txt-generator' ),
			'callback'             => [ $this, 'erase_attribution_sessions' ],
		];
		return $erasers;
	}

	/**
	 * Export attribution sessions linked to the given email address.
	 *
	 * Sessions carry no direct identifiers (random session ID, AI source,
	 * landing page, UTM values); they become personal data only once linked
	 * to a WooCommerce order, so resolution goes email → orders → events →
	 * session IDs.
	 *
	 * @param string $email_address Email address being exported.
	 * @param int    $page          Page (unused; result set is small and returned at once).
	 *
	 * @return array
	 */
	public function export_attribution_sessions( $email_address, $page = 1 ) {
		$sessions = $this->get_sessions_for_email( $email_address );

		$export_items = [];
		foreach ( $sessions as $session ) {
			$export_items[] = [
				'group_id'    => 'llmagnet_attribution_sessions',
				'group_label' => __( 'LLMagnet AI Attribution Sessions', 'llmagnet-llm-txt-generator' ),
				'item_id'     => 'llmagnet-attribution-session-' . $session['id'],
				'data'        => [
					[
						'name'  => __( 'Session ID', 'llmagnet-llm-txt-generator' ),
						'value' => $session['session_id'],
					],
					[
						'name'  => __( 'AI platform source', 'llmagnet-llm-txt-generator' ),
						'value' => $session['bot_source'],
					],
					[
						'name'  => __( 'Landing page', 'llmagnet-llm-txt-generator' ),
						'value' => $session['landing_page'],
					],
					[
						'name'  => __( 'UTM medium', 'llmagnet-llm-txt-generator' ),
						'value' => $session['utm_medium'],
					],
					[
						'name'  => __( 'UTM campaign', 'llmagnet-llm-txt-generator' ),
						'value' => $session['utm_campaign'],
					],
					[
						'name'  => __( 'First visit', 'llmagnet-llm-txt-generator' ),
						'value' => $session['first_touch'],
					],
					[
						'name'  => __( 'Last activity', 'llmagnet-llm-txt-generator' ),
						'value' => $session['last_activity'],
					],
					[
						'name'  => __( 'Converted to order', 'llmagnet-llm-txt-generator' ),
						'value' => $session['is_converted'] ? __( 'Yes', 'llmagnet-llm-txt-generator' ) : __( 'No', 'llmagnet-llm-txt-generator' ),
					],
				],
			];
		}

		return [
			'data' => $export_items,
			'done' => true,
		];
	}

	/**
	 * Erase attribution sessions linked to the given email address.
	 *
	 * Deletes matching rows from wp_llm_attribution_sessions and detaches the
	 * session ID from the related product event rows.
	 *
	 * @param string $email_address Email address being erased.
	 * @param int    $page          Page (unused).
	 *
	 * @return array
	 */
	public function erase_attribution_sessions( $email_address, $page = 1 ) {
		global $wpdb;

		$sessions = $this->get_sessions_for_email( $email_address );

		$items_removed = 0;

		if ( ! empty( $sessions ) ) {
			$sessions_table = $wpdb->prefix . 'llm_attribution_sessions';
			$events_table   = $wpdb->prefix . 'llm_product_events';

			$session_ids  = wp_list_pluck( $sessions, 'session_id' );
			$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );

			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$sessions_table} WHERE session_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$session_ids
				)
			);
			$items_removed = $deleted ? intval( $deleted ) : 0;

			if ( $this->table_exists( $events_table ) ) {
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$events_table} SET session_id = NULL WHERE session_id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$session_ids
					)
				);
			}
		}

		return [
			'items_removed'  => $items_removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Resolve attribution session rows linked to an email address.
	 *
	 * @param string $email_address Email address.
	 *
	 * @return array Array of session rows (ARRAY_A).
	 */
	private function get_sessions_for_email( $email_address ) {
		$email_address = sanitize_email( $email_address );
		if ( empty( $email_address ) || ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		global $wpdb;

		$sessions_table = $wpdb->prefix . 'llm_attribution_sessions';
		$events_table   = $wpdb->prefix . 'llm_product_events';

		if ( ! $this->table_exists( $sessions_table ) || ! $this->table_exists( $events_table ) ) {
			return [];
		}

		$order_ids = wc_get_orders(
			[
				'billing_email' => $email_address,
				'limit'         => -1,
				'return'        => 'ids',
			]
		);

		if ( empty( $order_ids ) ) {
			return [];
		}

		$order_placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		$session_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT session_id FROM {$events_table} WHERE session_id IS NOT NULL AND session_id != '' AND order_id IN ($order_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_ids
			)
		);

		if ( empty( $session_ids ) ) {
			return [];
		}

		$session_placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$sessions_table} WHERE session_id IN ($session_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_ids
			),
			ARRAY_A
		);

		return $rows ? $rows : [];
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Fully prefixed table name.
	 *
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
	}
}
