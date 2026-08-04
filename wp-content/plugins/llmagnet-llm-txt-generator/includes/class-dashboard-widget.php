<?php
/**
 * Dashboard Widget class
 *
 * WP dashboard (index.php) widget: site visibility score gauge + 30-day
 * AI-visits trend + CTA into the plugin, plus the "At a Glance"
 * "N LLM visits (30d)" item.
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — Feature 3 + §5.5
 * (Phase D, Lane D1).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "LLMagnet — AI Visibility" dashboard widget + At a Glance item.
 *
 * All data is fetched client-side from existing REST endpoints
 * (/visibility-score, /visibility/timeline, /stats) — no new queries in
 * the dashboard render. The React entry lazy-loads recharts after first
 * paint so the dashboard stays light.
 */
class Dashboard_Widget {

	/**
	 * Dashboard widget id.
	 */
	const WIDGET_ID = 'llmagnet_dashboard_widget';

	/**
	 * Script/style handle for the widget bundle.
	 */
	const ASSET_HANDLE = 'llmagnet-wp-dashboard-widget';

	/**
	 * Transient caching the 30d visit count for the At a Glance item.
	 */
	const GLANCE_TRANSIENT = 'llmagnet_glance_visits_30d';

	/**
	 * Generator instance (optional — used to resolve the llms.txt root path).
	 *
	 * @var Generator|null
	 */
	private $generator;

	/**
	 * Constructor.
	 *
	 * @param Generator|null $generator Generator instance.
	 */
	public function __construct( $generator = null ) {
		$this->generator = $generator;
	}

	/**
	 * Register hooks. Admin-only — instantiate from Main behind is_admin().
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_dashboard_setup', [ $this, 'register_widget' ] );
		add_filter( 'dashboard_glance_items', [ $this, 'add_glance_item' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the dashboard widget for admins only (the endpoints the
	 * widget consumes are manage_options — matching capability).
	 *
	 * @return void
	 */
	public function register_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'LLMagnet — AI Visibility', 'llmagnet-llm-txt-generator' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Widget body: React mount root + skeleton shown until the bundle paints.
	 *
	 * @return void
	 */
	public function render() {
		?>
		<div class="llmagnet-surface" id="llmagnet-dashboard-widget-root">
			<div class="llmagnet-wpdw-skeleton" aria-hidden="true">
				<span class="llmagnet-wpdw-skeleton__gauge"></span>
				<span class="llmagnet-wpdw-skeleton__line"></span>
				<span class="llmagnet-wpdw-skeleton__chart"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * "At a Glance" item: "N LLM visits (30d)" → Bot Analytics (§5.5).
	 *
	 * @param array $items Glance items.
	 * @return array
	 */
	public function add_glance_item( $items ) {
		// Same capability as the Bot Analytics page the item links to.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $items;
		}

		$count = $this->get_visits_30d();

		$items[] = sprintf(
			'<a class="llmagnet-glance" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=llmagnet-ai-seo-bot-analytics' ) ),
			esc_html(
				sprintf(
					/* translators: %s: formatted number of LLM bot visits in the last 30 days */
					__( '%s LLM visits (30d)', 'llmagnet-llm-txt-generator' ),
					number_format_i18n( $count )
				)
			)
		);

		return $items;
	}

	/**
	 * Total LLM bot visits in the last 30 days. Cheap indexed COUNT,
	 * cached for 10 minutes so frequent dashboard loads stay free.
	 *
	 * @return int
	 */
	private function get_visits_30d(): int {
		$cached = get_transient( self::GLANCE_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'llm_bot_visits';

		$count = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate count, cached below.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix-derived.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)" );
		}

		set_transient( self::GLANCE_TRANSIENT, $count, 10 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Enqueue the widget bundle + styles only on the dashboard (index.php)
	 * and only when the widget actually registered.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'index.php' !== $hook ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Glance icon + skeleton styles (tiny, inline).
		wp_register_style( self::ASSET_HANDLE . '-inline', false, [], LLMAGNET_AISEO_VERSION );
		wp_enqueue_style( self::ASSET_HANDLE . '-inline' );
		wp_add_inline_style( self::ASSET_HANDLE . '-inline', self::inline_css() );

		wp_enqueue_style(
			self::ASSET_HANDLE . '-css',
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/css/index.css',
			[],
			LLMAGNET_AISEO_VERSION
		);

		wp_enqueue_script(
			self::ASSET_HANDLE,
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/js/wp-dashboard-widget.js',
			[ 'wp-i18n' ],
			LLMAGNET_AISEO_VERSION,
			true
		);
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle, $src ) {
				if ( self::ASSET_HANDLE === $handle ) {
					return '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n";
				}
				return $tag;
			},
			10,
			3
		);

		wp_localize_script( self::ASSET_HANDLE, 'llmagnetWidgetData', $this->build_localize_data() );
		wp_localize_script(
			self::ASSET_HANDLE,
			'wpApiSettings',
			[
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			]
		);

		// JS translation files for wp.i18n (__()) strings in the bundle
		// (improvement plan P2-4.2; harmless until .json files ship).
		wp_set_script_translations( self::ASSET_HANDLE, 'llmagnet-llm-txt-generator' );
		I18n_Script_Translations::inject_module_translations();
	}

	/**
	 * Minimal bootstrap payload (Feature 3 spec). The widget fetches its
	 * own data client-side from existing REST endpoints.
	 *
	 * @return array
	 */
	private function build_localize_data(): array {
		$root_path       = $this->generator instanceof Generator
			? $this->generator->get_root_path()
			: trailingslashit( ABSPATH );
		$llms_txt_exists = file_exists( trailingslashit( $root_path ) . 'llms.txt' );

		$last_generated           = get_option( 'llmagnet_last_generated' );
		$last_generated_formatted = $last_generated
			? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_generated )
			: null;

		return [
			'overviewUrl'        => admin_url( 'admin.php?page=llmagnet-ai-seo-overview' ),
			'contentSettingsUrl' => admin_url( 'admin.php?page=llmagnet-ai-seo-content-settings' ),
			'pluginUrl'          => LLMAGNET_AISEO_PLUGIN_URL,
			'restNamespace'      => 'llm-analytics/v1',
			'planData'           => $this->get_plan_data(),
			'llmsTxtExists'      => $llms_txt_exists,
			'lastGenerated'      => $last_generated_formatted,
		];
	}

	/**
	 * Freemius plan payload — delegates to the shared canonical
	 * implementation (deduplicated in P2-1; this used to be a copy of the
	 * then-private Admin::get_plan_data()).
	 *
	 * @return array
	 */
	private function get_plan_data(): array {
		return Admin_WP_Helper::get_plan_data();
	}

	/**
	 * Inline CSS: At a Glance icon (plugin logo replaces the default
	 * dashicon) + widget skeleton loader.
	 *
	 * @return string
	 */
	private static function inline_css(): string {
		$icon = esc_url( LLMAGNET_AISEO_PLUGIN_URL . 'assets/llmagnet-icon.svg' );

		return '
#dashboard_right_now li a.llmagnet-glance::before { content: ""; background: url(' . $icon . ') no-repeat center / 16px 16px; width: 20px; height: 20px; margin: 0; }
.llmagnet-wpdw-skeleton { display: flex; flex-direction: column; gap: 12px; padding: 4px 0; }
.llmagnet-wpdw-skeleton__gauge { width: 64px; height: 64px; border-radius: 50%; }
.llmagnet-wpdw-skeleton__line { width: 60%; height: 14px; border-radius: 4px; }
.llmagnet-wpdw-skeleton__chart { width: 100%; height: 140px; border-radius: 8px; }
.llmagnet-wpdw-skeleton__gauge, .llmagnet-wpdw-skeleton__line, .llmagnet-wpdw-skeleton__chart { display: block; background: linear-gradient(90deg, #f0f0f1 25%, #e2e2e4 37%, #f0f0f1 63%); background-size: 400% 100%; animation: llmagnet-wpdw-shimmer 1.4s ease infinite; }
@keyframes llmagnet-wpdw-shimmer { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
';
	}
}
