<?php
/**
 * Small helpers extracted from Admin to keep class-admin.php maintainable.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin hook / page slug mapping for the React shell.
 */
class Admin_WP_Helper {

	/**
	 * Map WP admin screen hook to ?page= slug for the React admin shell.
	 *
	 * @param string $hook Current admin hook.
	 * @return string|null
	 */
	public static function wp_page_from_hook( string $hook ): ?string {
		$map = [
			'toplevel_page_llmagnet-ai-seo-optimizer'          => 'llmagnet-ai-seo-optimizer',
			'llmagnet_page_llmagnet-ai-seo-overview'           => 'llmagnet-ai-seo-overview',
			'llmagnet_page_llmagnet-ai-seo-pages'              => 'llmagnet-ai-seo-pages',
			'llmagnet_page_llmagnet-ai-seo-products'           => 'llmagnet-ai-seo-products',
			'llmagnet_page_llmagnet-ai-seo-bot-analytics'      => 'llmagnet-ai-seo-bot-analytics',
			'llmagnet_page_llmagnet-ai-seo-reports'            => 'llmagnet-ai-seo-reports',
			'llmagnet_page_llmagnet-ai-seo-content-settings'   => 'llmagnet-ai-seo-content-settings',
			'llmagnet_page_llmagnet-ai-seo-schema-jsonld'      => 'llmagnet-ai-seo-schema-jsonld',
			'llmagnet_page_llmagnet-ai-seo-agent-ready'        => 'llmagnet-ai-seo-agent-ready',
			'llmagnet_page_llmagnet-ai-seo-mcp'                => 'llmagnet-ai-seo-mcp',
			'llmagnet_page_llmagnet-ai-seo-system-information' => 'llmagnet-ai-seo-system-information',
		];
		return $map[ $hook ] ?? null;
	}

	/**
	 * Deep link that opens the details drawer for a post on the plugin's
	 * Pages/Products analytics page (adoption plan Phase 0.3). The React
	 * side reads the `llmagnet_drawer` query param on mount.
	 *
	 * @param int $post_id Post ID.
	 * @return string Admin URL.
	 */
	public static function drawer_url( int $post_id ): string {
		$page = ( 'product' === get_post_type( $post_id ) )
			? 'llmagnet-ai-seo-products'
			: 'llmagnet-ai-seo-pages';

		return add_query_arg(
			[
				'page'            => $page,
				'llmagnet_drawer' => absint( $post_id ),
			],
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Check if the current user has premium access (Freemius), falling back
	 * to an admin-capability check when the SDK is unavailable. Canonical
	 * version of the private helper that used to live in class-admin.php.
	 *
	 * @return bool
	 */
	public static function is_premium_user(): bool {
		// Check if Freemius is available and user has premium access
		if ( function_exists( 'lltg_fs' ) ) {
			$fs = lltg_fs();
			return $fs->can_use_premium_code();
		}

		// Fallback to admin check if Freemius is not available
		return current_user_can( 'manage_options' );
	}

	/**
	 * Freemius plan payload shared by the admin localize payloads.
	 *
	 * Canonical version of the identical copies that used to live in
	 * class-admin.php, class-dashboard-widget.php and class-elementor.php
	 * (improvement plan P2-1).
	 *
	 * @return array Plan data including name, title, and status.
	 */
	public static function get_plan_data(): array {
		$plan_data = [
			'is_premium' => false,
			'plan_name'  => 'free',
			'plan_title' => 'Free Version',
			'is_paying'  => false,
			'is_trial'   => false,
		];

		if ( function_exists( 'lltg_fs' ) ) {
			$fs = lltg_fs();

			// Determine plan name and title.
			// Check plans in order: enterprise (highest) -> plus -> pro -> free (default).
			if ( $fs->is_plan( 'enterprise' ) ) {
				$plan_name  = 'enterprise';
				$plan_title = 'Enterprise Version';
			} elseif ( $fs->is_plan( 'plus' ) ) {
				$plan_name  = 'plus';
				$plan_title = 'Plus Version';
			} elseif ( $fs->is_plan( 'pro' ) ) {
				$plan_name  = 'pro';
				$plan_title = 'Pro Version';
			} else {
				$plan_name  = 'free';
				$plan_title = 'Free Version';
			}

			$plan_data = [
				'is_premium' => $fs->is_premium(),
				'plan_name'  => $plan_name,
				'plan_title' => $plan_title,
				'is_paying'  => $fs->is_paying(),
				'is_trial'   => $fs->is_trial(),
			];
		}

		return $plan_data;
	}

	/**
	 * Whether the ?page= slug belongs to this plugin admin UI.
	 *
	 * @param string $wp_page Page slug.
	 * @return bool
	 */
	public static function is_plugin_wp_page( string $wp_page ): bool {
		$valid = [
			'llmagnet-ai-seo-optimizer',
			'llmagnet-ai-seo-overview',
			'llmagnet-ai-seo-pages',
			'llmagnet-ai-seo-products',
			'llmagnet-ai-seo-bot-analytics',
			'llmagnet-ai-seo-reports',
			'llmagnet-ai-seo-content-settings',
			'llmagnet-ai-seo-schema-jsonld',
			'llmagnet-ai-seo-agent-ready',
			'llmagnet-ai-seo-mcp',
			'llmagnet-ai-seo-system-information',
		];
		return in_array( $wp_page, $valid, true );
	}
}
