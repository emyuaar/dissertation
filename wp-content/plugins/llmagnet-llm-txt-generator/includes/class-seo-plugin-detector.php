<?php
/**
 * SEO / feature plugin detector
 *
 * Detects Yoast SEO, Rank Math, All in One SEO, SEOPress, and The SEO
 * Framework and exposes per-capability ownership queries so LLMagnet
 * never double-emits what another plugin already owns
 * (agent-readiness-spec Phase 0.4).
 *
 * Capabilities: titles, meta_description, canonical, open_graph, sitemap,
 * robots_txt, breadcrumb_schema, indexnow.
 *
 * Consumed by:
 * - Agent-Ready audit (F1): mark checks "handled by {plugin}" instead of fail.
 * - OG / canonical verify-or-fill (F7): never emit when a plugin owns it.
 * - robots.txt AI rules (F2): coexistence mode when robots.txt is owned.
 * - IndexNow (F8): auto-disable when Rank Math / AIOSEO IndexNow is active.
 *
 * Detection results are cached in a transient (same approach as
 * `llmagnet_woo_active`) and invalidated on plugin (de)activation. The
 * capability map is filterable via `llmagnet_seo_plugin_capabilities`.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detector for SEO plugins and their capability ownership
 */
class Seo_Plugin_Detector {

    /**
     * Transient caching the list of active SEO plugin slugs.
     */
    const TRANSIENT = 'llmagnet_seo_plugins_active';

    /**
     * Known capability keys.
     *
     * @var string[]
     */
    const CAPABILITIES = [
        'titles',
        'meta_description',
        'canonical',
        'open_graph',
        'sitemap',
        'robots_txt',
        'breadcrumb_schema',
        'json_ld',
        'indexnow',
    ];

    /**
     * Plugin slugs in ownership-priority order with display labels.
     *
     * When several SEO plugins are active (rare but possible), the first
     * active one in this order is reported as the capability owner.
     *
     * @var array<string, string>
     */
    private static $plugins = [
        'yoast'    => 'Yoast SEO',
        'rankmath' => 'Rank Math SEO',
        'aioseo'   => 'All in One SEO',
        'seopress' => 'SEOPress',
        'tsf'      => 'The SEO Framework',
    ];

    /**
     * Initialize cache-invalidation hooks
     *
     * Optional but recommended: keeps the transient honest when plugins are
     * switched on/off without waiting for expiry.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'activated_plugin', [ __CLASS__, 'clear_detection_cache' ] );
        add_action( 'deactivated_plugin', [ __CLASS__, 'clear_detection_cache' ] );
    }

    /**
     * Get active SEO plugins as slug => label
     *
     * @param bool $skip_cache Force fresh detection.
     * @return array<string, string>
     */
    public static function get_active( bool $skip_cache = false ): array {
        if ( ! $skip_cache ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) {
                return array_intersect_key( self::$plugins, array_flip( $cached ) );
            }
        }

        $active = [];
        foreach ( array_keys( self::$plugins ) as $slug ) {
            if ( self::detect( $slug ) ) {
                $active[] = $slug;
            }
        }

        set_transient( self::TRANSIENT, $active, HOUR_IN_SECONDS );

        return array_intersect_key( self::$plugins, array_flip( $active ) );
    }

    /**
     * Whether a specific SEO plugin is active
     *
     * @param string $slug Plugin slug (yoast|rankmath|aioseo|seopress|tsf).
     * @return bool
     */
    public static function is_active( string $slug ): bool {
        $active = self::get_active();
        return isset( $active[ $slug ] );
    }

    /**
     * Whether any of the known SEO plugins is active
     *
     * @return bool
     */
    public static function any_active(): bool {
        return [] !== self::get_active();
    }

    /**
     * Uncached detection by class/function/constant existence
     *
     * @param string $slug Plugin slug.
     * @return bool
     */
    public static function detect( string $slug ): bool {
        switch ( $slug ) {
            case 'yoast':
                return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
            case 'rankmath':
                return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
            case 'aioseo':
                return defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' );
            case 'seopress':
                return defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' );
            case 'tsf':
                return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'tsf' ) || function_exists( 'the_seo_framework' );
            default:
                return false;
        }
    }

    /**
     * Capability map: plugin slug => capability => bool
     *
     * Built per call (cheap array work) so dynamic bits — premium editions,
     * optional modules — reflect the current install. Filterable via
     * `llmagnet_seo_plugin_capabilities` so the map can be corrected
     * without a release.
     *
     * @return array<string, array<string, bool>>
     */
    public static function get_capability_map(): array {
        $map = [
            'yoast' => [
                'titles'            => true,
                'meta_description'  => true,
                'canonical'         => true,
                'open_graph'        => true,
                'sitemap'           => true,
                'robots_txt'        => false, // File editor only (manual tool) — no managed output.
                'breadcrumb_schema' => true,
                'json_ld'           => true,
                'indexnow'          => defined( 'WPSEO_PREMIUM_VERSION' ),
            ],
            'rankmath' => [
                'titles'            => true,
                'meta_description'  => true,
                'canonical'         => true,
                'open_graph'        => true,
                'sitemap'           => true,
                'robots_txt'        => true, // Edits virtual robots.txt output.
                'breadcrumb_schema' => true,
                'json_ld'           => true,
                'indexnow'          => self::rankmath_instant_indexing_active(),
            ],
            'aioseo' => [
                'titles'            => true,
                'meta_description'  => true,
                'canonical'         => true,
                'open_graph'        => true,
                'sitemap'           => true,
                'robots_txt'        => true, // Robots.txt editor hooks the robots_txt filter.
                'breadcrumb_schema' => true,
                'json_ld'           => true,
                'indexnow'          => class_exists( '\AIOSEO\Plugin\Addons\IndexNow\IndexNow' ) || function_exists( 'aioseo_index_now' ),
            ],
            'seopress' => [
                'titles'            => true,
                'meta_description'  => true,
                'canonical'         => true,
                'open_graph'        => true,
                'sitemap'           => true,
                'robots_txt'        => defined( 'SEOPRESS_PRO_VERSION' ), // robots.txt editor is Pro.
                'breadcrumb_schema' => defined( 'SEOPRESS_PRO_VERSION' ),
                'json_ld'           => true,
                'indexnow'          => false,
            ],
            'tsf' => [
                'titles'            => true,
                'meta_description'  => true,
                'canonical'         => true,
                'open_graph'        => true,
                'sitemap'           => true,
                'robots_txt'        => false,
                'breadcrumb_schema' => false,
                'json_ld'           => true,
                'indexnow'          => false,
            ],
        ];

        /**
         * Filter the per-plugin capability ownership map.
         *
         * @param array $map plugin slug => [ capability => bool ].
         */
        $filtered = apply_filters( 'llmagnet_seo_plugin_capabilities', $map );

        return is_array( $filtered ) ? $filtered : $map;
    }

    /**
     * Which active plugin owns a capability
     *
     * @param string $capability One of self::CAPABILITIES.
     * @return string|false Owning plugin slug, or false when unowned.
     */
    public static function owns( string $capability ) {
        $active = self::get_active();
        if ( [] === $active ) {
            return false;
        }

        $map = self::get_capability_map();

        foreach ( array_keys( $active ) as $slug ) {
            if ( ! empty( $map[ $slug ][ $capability ] ) ) {
                return $slug;
            }
        }

        return false;
    }

    /**
     * Human-readable label of the plugin owning a capability
     *
     * For audit findings: "handled by {plugin}".
     *
     * @param string $capability One of self::CAPABILITIES.
     * @return string|null Plugin label, or null when unowned.
     */
    public static function get_owner_label( string $capability ): ?string {
        $slug = self::owns( $capability );
        return false !== $slug && isset( self::$plugins[ $slug ] ) ? self::$plugins[ $slug ] : null;
    }

    /**
     * Whether another plugin owns robots.txt output
     *
     * @return bool
     */
    public static function owns_robots(): bool {
        return false !== self::owns( 'robots_txt' );
    }

    /**
     * Whether another plugin owns the XML sitemap
     *
     * @return bool
     */
    public static function owns_sitemap(): bool {
        return false !== self::owns( 'sitemap' );
    }

    /**
     * Whether another plugin owns Open Graph tags
     *
     * @return bool
     */
    public static function owns_og(): bool {
        return false !== self::owns( 'open_graph' );
    }

    /**
     * Whether another plugin owns rel="canonical"
     *
     * @return bool
     */
    public static function owns_canonical(): bool {
        return false !== self::owns( 'canonical' );
    }

    /**
     * Whether another plugin owns document titles
     *
     * @return bool
     */
    public static function owns_titles(): bool {
        return false !== self::owns( 'titles' );
    }

    /**
     * Whether another plugin owns the meta description
     *
     * @return bool
     */
    public static function owns_meta_description(): bool {
        return false !== self::owns( 'meta_description' );
    }

    /**
     * Whether another plugin owns BreadcrumbList schema
     *
     * @return bool
     */
    public static function owns_breadcrumb_schema(): bool {
        return false !== self::owns( 'breadcrumb_schema' );
    }

    /**
     * Whether another plugin owns JSON-LD / Schema.org output
     *
     * @return bool
     */
    public static function owns_json_ld(): bool {
        return false !== self::owns( 'json_ld' );
    }

    /**
     * Human-readable label of the plugin owning JSON-LD output
     *
     * @return string|null
     */
    public static function get_json_ld_owner_label(): ?string {
        return self::get_owner_label( 'json_ld' );
    }

    /**
     * Whether another plugin owns IndexNow pinging
     *
     * @return bool
     */
    public static function owns_indexnow(): bool {
        return false !== self::owns( 'indexnow' );
    }

    /**
     * Clear the cached detection result
     *
     * @return void
     */
    public static function clear_detection_cache(): void {
        delete_transient( self::TRANSIENT );
    }

    /**
     * Whether Rank Math's Instant Indexing (IndexNow) module is enabled
     *
     * Falls back to true when Rank Math is active but the helper is
     * unavailable (older versions bundle the module).
     *
     * @return bool
     */
    private static function rankmath_instant_indexing_active(): bool {
        if ( ! self::detect( 'rankmath' ) ) {
            return false;
        }

        if ( class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' ) ) {
            return (bool) \RankMath\Helper::is_module_active( 'instant-indexing' );
        }

        return true;
    }
}
