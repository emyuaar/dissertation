<?php
/**
 * Elementor Integration class
 *
 * When Elementor is active, shows an LLMagnet score button in the Elementor
 * editor (top-bar DOM injection with a floating-button fallback) that opens
 * the existing Page/Product drawer over the editor for the document being
 * edited.
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — Feature 4 (Phase F, Lane F-B).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor editor integration.
 *
 * Everything no-ops when Elementor is absent: the two hooks this class
 * registers (`elementor/editor/after_enqueue_scripts` and
 * `elementor/editor/footer`) are fired by Elementor itself, so without
 * Elementor neither callback ever runs and no assets are enqueued. The
 * enqueue callback additionally re-checks `is_active()` defensively.
 */
class Elementor_Integration {

	/**
	 * Script/style handle for the Elementor editor bundle.
	 */
	const ASSET_HANDLE = 'llmagnet-elementor';

	/**
	 * Whether Elementor is active.
	 *
	 * Note: this intentionally lives here and not in Admin_WP_Helper (the
	 * spec's original suggestion) — that helper class is a shared hot file.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return did_action( 'elementor/loaded' ) > 0
			|| defined( 'ELEMENTOR_VERSION' )
			|| class_exists( '\\Elementor\\Plugin' );
	}

	/**
	 * Register hooks. Both hooks are Elementor-fired, so this is a clean
	 * no-op on sites without Elementor.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'elementor/editor/footer', [ $this, 'render_editor_root' ] );
	}

	/**
	 * The post being edited in the Elementor editor
	 * (post.php?post=N&action=elementor).
	 *
	 * @return int
	 */
	private function get_editor_post_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context detection; Elementor has already capability-checked the editor request.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		return $post_id;
	}

	/**
	 * Enqueue the editor bundle + styles + bootstrap payload inside the
	 * Elementor editor only.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		$post_id = $this->get_editor_post_id();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post_type = (string) get_post_type( $post_id );

		$script_path = LLMAGNET_AISEO_PLUGIN_DIR . 'assets/react-build/js/elementor.js';
		if ( ! file_exists( $script_path ) ) {
			// Bundle not built yet (pre-gate) — degrade silently.
			return;
		}

		wp_enqueue_style(
			self::ASSET_HANDLE . '-css',
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/css/index.css',
			[],
			LLMAGNET_AISEO_VERSION
		);

		// Drawer/button isolation overrides for Elementor + WP admin chrome (see
		// src/styles/elementor-isolation.css — emitted as elementor-main.css).
		$isolation_css = LLMAGNET_AISEO_PLUGIN_DIR . 'assets/react-build/css/elementor-main.css';
		if ( file_exists( $isolation_css ) ) {
			wp_enqueue_style(
				self::ASSET_HANDLE . '-isolation',
				LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/css/elementor-main.css',
				[ self::ASSET_HANDLE . '-css' ],
				LLMAGNET_AISEO_VERSION
			);
		}

		wp_enqueue_script(
			self::ASSET_HANDLE,
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/js/elementor.js',
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

		$drawer_url = '';
		if ( current_user_can( 'manage_options' ) && class_exists( __NAMESPACE__ . '\\Admin_WP_Helper' ) ) {
			$drawer_url = Admin_WP_Helper::drawer_url( $post_id );
		}

		// Elementor localizes its own global `wpApiSettings`; ours rides
		// inside llmagnetElementorData and the JS only fills the global in
		// if it is missing (never overwrites — spec Feature 4 caveat).
		wp_localize_script(
			self::ASSET_HANDLE,
			'llmagnetElementorData',
			[
				'postId'        => $post_id,
				'postType'      => $post_type,
				'restRoot'      => esc_url_raw( rest_url() ),
				'restNamespace' => 'llm-analytics/v1',
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'planData'      => $this->get_plan_data(),
				'drawerUrl'     => $drawer_url,
				'pluginUrl'     => LLMAGNET_AISEO_PLUGIN_URL,
				'isRtl'         => is_rtl(),
				'i18n'          => [
					'buttonLabel'   => __( 'AI Visibility', 'llmagnet-llm-txt-generator' ),
					/* translators: %d: score 0-100 */
					'scoreLabel'    => __( 'AI visibility score %d of 100', 'llmagnet-llm-txt-generator' ),
					'noScore'       => __( 'AI visibility score not calculated yet', 'llmagnet-llm-txt-generator' ),
					'unsavedNotice' => __( 'You have unsaved Elementor changes. Save your Elementor changes first — description edits saved here could be overwritten by your next Elementor save.', 'llmagnet-llm-txt-generator' ),
					'dismiss'       => __( 'Dismiss', 'llmagnet-llm-txt-generator' ),
				],
			]
		);

		// JS translation files for wp.i18n (__()) strings in the bundle
		// (improvement plan P2-4.2; harmless until .json files ship).
		wp_set_script_translations( self::ASSET_HANDLE, 'llmagnet-llm-txt-generator' );
		I18n_Script_Translations::inject_module_translations();
	}

	/**
	 * Mount root printed in the Elementor editor footer (the editor
	 * document, not the preview iframe). The bundle renders the top-bar
	 * button (portal), the floating fallback button and the drawer into it.
	 *
	 * @return void
	 */
	public function render_editor_root() {
		if ( ! self::is_active() ) {
			return;
		}
		$post_id = $this->get_editor_post_id();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		echo '<div id="llmagnet-elementor-root" class="llmagnet-surface"></div>';
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
}
