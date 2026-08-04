<?php
/**
 * Inject wp.i18n locale data for ES-module script bundles.
 *
 * WordPress wp_set_script_translations() attaches an inline script before the
 * registered handle, but our admin bundles use type="module" via script_loader_tag.
 * That filter runs after translations are printed and strips the inline
 * setLocaleData call — so React __() strings stay in English while PHP .mo
 * strings (WordPress menus) still translate.
 *
 * Fix: merge shipped {domain}-{locale}-*.json chunks and inject via
 * wp_add_inline_script( 'wp-i18n', … ) so data is present before modules run.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manual JS translation injection for module scripts.
 */
class I18n_Script_Translations {

	const TEXT_DOMAIN = 'llmagnet-llm-txt-generator';

	/**
	 * Whether locale data was already injected this request.
	 *
	 * @var bool
	 */
	private static $injected = false;

	/**
	 * Merge all shipped JSON translation chunks for the current locale and
	 * register them on wp-i18n.
	 *
	 * Safe to call alongside wp_set_script_translations() (harmless duplicate
	 * if core injection ever works for a handle). Idempotent per request.
	 *
	 * @return void
	 */
	public static function inject_module_translations(): void {
		if ( self::$injected ) {
			return;
		}
		wp_enqueue_script( 'wp-i18n' );

		$messages = self::load_merged_messages( self::TEXT_DOMAIN );
		if ( empty( $messages ) ) {
			return;
		}

		$payload = wp_json_encode(
			$messages,
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		if ( ! is_string( $payload ) || '' === $payload ) {
			return;
		}

		// wp.i18n.setLocaleData expects the inner "messages" map (including "").
		$inline = sprintf(
			'wp.i18n.setLocaleData( %1$s, %2$s );',
			$payload,
			wp_json_encode( self::TEXT_DOMAIN, JSON_UNESCAPED_UNICODE )
		);

		wp_add_inline_script( 'wp-i18n', $inline, 'after' );
		self::$injected = true;
	}

	/**
	 * Load and merge JSON translation files for a text domain.
	 *
	 * @param string $domain Text domain.
	 * @return array<string, mixed> Merged locale_data.messages map.
	 */
	private static function load_merged_messages( string $domain ): array {
		$lang_dir = LLMAGNET_AISEO_PLUGIN_DIR . 'languages/';
		if ( ! is_dir( $lang_dir ) ) {
			return [];
		}

		foreach ( self::locale_candidates() as $locale ) {
			$pattern = $lang_dir . $domain . '-' . $locale . '-*.json';
			$files   = glob( $pattern );
			if ( ! is_array( $files ) || empty( $files ) ) {
				continue;
			}

			$merged = [];
			foreach ( $files as $file ) {
				$data = self::read_json_file( $file );
				if ( null === $data ) {
					continue;
				}
				$chunk = $data['locale_data']['messages'] ?? null;
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				foreach ( $chunk as $msgid => $translations ) {
					$merged[ $msgid ] = $translations;
				}
			}

			if ( ! empty( $merged ) ) {
				return $merged;
			}
		}

		return [];
	}

	/**
	 * Locale fallbacks (WP may use he vs he_IL, es vs es_ES).
	 *
	 * @return list<string>
	 */
	private static function locale_candidates(): array {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$candidates = [ $locale ];

		$map = [
			'he'    => 'he_IL',
			'he_IL' => 'he',
			'es'    => 'es_ES',
			'es_ES' => 'es',
		];

		if ( isset( $map[ $locale ] ) ) {
			$candidates[] = $map[ $locale ];
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * @param string $path Absolute JSON path.
	 * @return array<string, mixed>|null
	 */
	private static function read_json_file( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}
}
