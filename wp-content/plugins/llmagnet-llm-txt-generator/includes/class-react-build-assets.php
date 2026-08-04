<?php
/**
 * Helpers for Vite-built ES module bundles under assets/react-build/.
 *
 * Elementor Cloud (and similar hosts) rewrite enqueued script URLs to
 * index.php?dynamic_asset=… which breaks relative import() resolution inside
 * ES modules. We force direct /wp-content/plugins/… URLs for our bundles.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * React/Vite asset URL normalization for managed WordPress hosts.
 */
class React_Build_Assets {

	/**
	 * Path fragment used to detect our react-build assets.
	 */
	const REACT_BUILD_PATH = 'llmagnet-llm-txt-generator/assets/react-build/';

	/**
	 * Admin wp_page slug => Vite entry filename (without .js).
	 */
	const ADMIN_PAGE_FILES = [
		'llmagnet-ai-seo-optimizer'        => 'dashboard',
		'llmagnet-ai-seo-overview'         => 'overview',
		'llmagnet-ai-seo-pages'            => 'pages',
		'llmagnet-ai-seo-products'         => 'products',
		'llmagnet-ai-seo-bot-analytics'    => 'bot-analytics',
		'llmagnet-ai-seo-reports'          => 'reports',
		'llmagnet-ai-seo-content-settings' => 'content-settings',
		'llmagnet-ai-seo-schema-jsonld'    => 'schema-jsonld',
		'llmagnet-ai-seo-agent-ready'      => 'agent-ready',
		'llmagnet-ai-seo-mcp'              => 'mcp',
		'llmagnet-ai-seo-system-information' => 'system-information',
	];

	/**
	 * Register filters.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'script_loader_src', [ self::class, 'force_direct_module_src' ], 999, 2 );
	}

	/**
	 * Base URL for JS entry/chunk files (trailing slash).
	 *
	 * @return string
	 */
	public static function js_base_url(): string {
		return LLMAGNET_AISEO_PLUGIN_URL . 'assets/react-build/js/';
	}

	/**
	 * Canonical URL for a react-build JS file under assets/react-build/js/.
	 *
	 * @param string $file Filename without directory (e.g. "dashboard" or "bot-traffic-chart.js").
	 * @return string
	 */
	public static function js_file_url( string $file ): string {
		$name = substr( $file, -3 ) === '.js' ? $file : $file . '.js';
		return add_query_arg( 'ver', LLMAGNET_AISEO_VERSION, self::js_base_url() . $name );
	}

	/**
	 * Map plugin admin wp_page slugs to direct entry bundle URLs.
	 *
	 * @return array<string, string>
	 */
	public static function admin_page_scripts(): array {
		$scripts = [];
		foreach ( self::ADMIN_PAGE_FILES as $slug => $file ) {
			$scripts[ $slug ] = self::js_file_url( $file );
		}
		return $scripts;
	}

	/**
	 * Replace dynamic_asset proxy URLs with direct plugin file URLs.
	 *
	 * @param string $src    Script src.
	 * @param string $handle Script handle (unused — match by URL path).
	 * @return string
	 */
	public static function force_direct_module_src( string $src, string $handle ): string {
		unset( $handle );

		$canonical = self::canonical_react_build_url( $src );
		return $canonical ?? $src;
	}

	/**
	 * Resolve a react-build script URL to the canonical direct plugin URL.
	 *
	 * @param string $src Script src (may use dynamic_asset or already be direct).
	 * @return string|null Canonical URL, or null when not our asset.
	 */
	public static function canonical_react_build_url( string $src ): ?string {
		if ( strpos( $src, self::REACT_BUILD_PATH ) === false
			&& strpos( $src, 'llmagnet-llm-txt-generator' ) === false ) {
			return null;
		}

		$path  = null;
		$ver   = null;

		if ( strpos( $src, 'dynamic_asset=' ) !== false ) {
			$query = wp_parse_url( $src, PHP_URL_QUERY );
			if ( ! is_string( $query ) ) {
				return null;
			}
			parse_str( $query, $params );
			if ( empty( $params['dynamic_asset'] ) || ! is_string( $params['dynamic_asset'] ) ) {
				return null;
			}
			$path = $params['dynamic_asset'];
			if ( ! empty( $params['ver'] ) ) {
				$ver = (string) $params['ver'];
			}
		} elseif ( preg_match( '#(/wp-content/plugins/llmagnet-llm-txt-generator/assets/react-build/[^?\s]+)#', $src, $matches ) ) {
			$path = $matches[1];
			if ( preg_match( '#[?&]ver=([^&]+)#', $src, $ver_match ) ) {
				$ver = $ver_match[1];
			}
		}

		if ( ! is_string( $path ) || strpos( $path, self::REACT_BUILD_PATH ) === false ) {
			return null;
		}

		$direct = home_url( $path );
		if ( $ver !== null && $ver !== '' ) {
			$direct = add_query_arg( 'ver', $ver, $direct );
		}

		return $direct;
	}
}
