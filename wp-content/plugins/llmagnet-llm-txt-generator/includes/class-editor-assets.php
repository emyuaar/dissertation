<?php
/**
 * Editor Assets class
 *
 * Enqueues the Gutenberg editor bundle (assets/react-build/js/editor.js,
 * built by vite.editor.config.ts) on block-editor screens of supported post
 * types. The bundle reuses WordPress' own React / @wordpress packages via
 * the src/wp-externals shims, so all the matching script handles are
 * declared as dependencies here.
 *
 * Spec: docs/admin-adoption-surfaces-plan.md — Feature 2.2 + 5.2
 * (Phase E, Lane E1).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block-editor bundle enqueue (hooked via enqueue_block_editor_assets so no
 * class-admin.php edit is needed).
 */
class Editor_Assets {

	/**
	 * Script handle for the editor bundle.
	 */
	const SCRIPT_HANDLE = 'llmagnet-editor';

	/**
	 * Register hooks. Admin-only — instantiate from Main behind is_admin().
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	/**
	 * Post types that get the editor panel — same set as the score surfaces
	 * (post, page, + product when WooCommerce is active).
	 *
	 * @return array
	 */
	public static function get_post_types(): array {
		$types = class_exists( __NAMESPACE__ . '\\Score_Store' )
			? Score_Store::get_post_types()
			: [ 'post', 'page' ];

		/**
		 * Filter the post types whose block editor loads the LLMagnet panel.
		 *
		 * @param array $types Post type names.
		 */
		return (array) apply_filters( 'llmagnet_editor_post_types', $types );
	}

	/**
	 * Enqueue the editor bundle + styles on supported block-editor screens.
	 *
	 * The bundle is an ES module (type="module" via script_loader_tag), so
	 * it is deferred and all classic-script dependencies below are
	 * guaranteed to have executed before it evaluates.
	 *
	 * @return void
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Site/widgets editors have no post_type — only post-editing screens.
		if ( ! $screen || empty( $screen->post_type ) || ! in_array( $screen->post_type, self::get_post_types(), true ) ) {
			return;
		}

		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$deps = [
			'wp-plugins',
			'wp-edit-post',
			'wp-data',
			'wp-components',
			'wp-core-data',
			'wp-element',
			'wp-i18n',
			'wp-api-fetch',
			'react',
			'react-dom',
		];
		// WP 6.6+ canonical slot-fill home + JSX runtime global; both exist
		// as registered handles only on newer cores, so add conditionally
		// (the wp-externals shims fall back gracefully when absent).
		if ( wp_script_is( 'wp-editor', 'registered' ) ) {
			$deps[] = 'wp-editor';
		}
		if ( wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
			$deps[] = 'react-jsx-runtime';
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/js/editor.js',
			$deps,
			LLMAGNET_AISEO_VERSION,
			true
		);
		add_filter(
			'script_loader_tag',
			function ( $tag, $handle, $src ) {
				if ( self::SCRIPT_HANDLE === $handle ) {
					return '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n";
				}
				return $tag;
			},
			10,
			3
		);

		$can_manage = current_user_can( 'manage_options' );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'llmagnetEditorData',
			[
				'postId'    => (int) $post->ID,
				'postType'  => $screen->post_type,
				'canManage' => $can_manage,
				// Drawer deep-link (E1-4) — consistent with the D1 column:
				// drawer pages require manage_options.
				'drawerUrl' => $can_manage ? Admin_WP_Helper::drawer_url( (int) $post->ID ) : '',
			]
		);

		// JS translation files for wp.i18n (__()) strings in the bundle —
		// see FD-5 for the full i18n sweep; harmless until .json files ship.
		wp_set_script_translations( self::SCRIPT_HANDLE, 'llmagnet-llm-txt-generator' );

		I18n_Script_Translations::inject_module_translations();

		// Shared build CSS so ScoreBadge / Tailwind utilities render
		// (preflight is disabled, so editor chrome is unaffected).
		wp_enqueue_style(
			self::SCRIPT_HANDLE . '-css',
			LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/css/index.css',
			[],
			LLMAGNET_AISEO_VERSION
		);
	}
}
