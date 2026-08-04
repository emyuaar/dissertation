<?php
/**
 * /schemamap.xml provider (agent-readiness-spec F6.2 — FA-4)
 *
 * An XML index mapping each URL with published JSON-LD to its schema
 * types:
 *
 *     <schemamap>
 *       <url loc="https://site/about/" types="Organization,WebPage" lastmod="..."/>
 *     </schemamap>
 *
 * Data sources:
 * - The Schema wizard's published graph (`llmagnet_schema_published_ld`,
 *   emitted site-wide on wp_head when enabled) → mapped to the home URL.
 * - SEO-plugin/theme-emitted schema detected by the last Schema scan
 *   (`llmagnet_schema_last_scan`.pages[].types_found).
 *
 * Served by the Phase 0.1 Well-Known router at the site root. Regenerated
 * daily on the EXISTING `llmagnet_ai_seo_daily_event` cron hook alongside
 * llms.txt — no class-cron.php edit, no new schedule (the event is already
 * scheduled by Cron); a lazy rebuild on render self-heals a missing/stale
 * cache. Gated by the `well_known` feature toggle (default OFF) like the
 * other discovery endpoints; the daily callback no-ops while the toggle is
 * off, so a dark install does zero work. Listed in the robots.txt sentinel
 * block (Robots_Txt) and the F6.1 Link header.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * schemamap.xml generation + provider.
 */
class Schemamap {

    /**
     * Root-relative path served by the Well-Known router.
     */
    const PATH = 'schemamap.xml';

    /**
     * Cache option: [ 'generated' => ts, 'entries' => [ loc => [ 'types' => string[], 'lastmod' => 'Y-m-d' ] ] ].
     * Autoload off.
     */
    const OPTION_CACHE = 'llmagnet_schemamap_cache';

    /**
     * Rebuild lazily when the cache is older than this (the daily cron
     * normally refreshes it first).
     */
    const STALE_AFTER = 2 * DAY_IN_SECONDS;

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'llmagnet_register_well_known_providers', [ $this, 'register_provider' ] );

        // Daily regeneration alongside llms.txt (existing cron event — F6.2).
        add_action( 'llmagnet_ai_seo_daily_event', [ $this, 'regenerate_if_enabled' ], 20 );
    }

    /**
     * Whether schemamap is active (rides the well_known discovery toggle).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'well_known' );
    }

    /**
     * Register the router provider.
     *
     * @return void
     */
    public function register_provider(): void {
        Well_Known::register(
            self::PATH,
            [ $this, 'render' ],
            'application/xml; charset=utf-8',
            [ 'noindex' => true ]
        );
    }

    /**
     * Provider callback: XML body, or null to decline (feature off).
     *
     * @return string|null
     */
    public function render() {
        if ( ! self::is_enabled() ) {
            return null;
        }

        $cache = get_option( self::OPTION_CACHE, [] );
        if (
            ! is_array( $cache )
            || ! isset( $cache['generated'], $cache['entries'] )
            || ( time() - (int) $cache['generated'] ) > self::STALE_AFTER
        ) {
            $cache = $this->regenerate();
        }

        $entries = is_array( $cache['entries'] ) ? $cache['entries'] : [];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<schemamap generated="' . esc_attr( gmdate( 'c', (int) $cache['generated'] ) ) . '">' . "\n";
        foreach ( $entries as $loc => $entry ) {
            $types = isset( $entry['types'] ) && is_array( $entry['types'] ) ? $entry['types'] : [];
            if ( empty( $types ) ) {
                continue;
            }
            $xml .= sprintf(
                '  <url loc="%s" types="%s"%s/>' . "\n",
                esc_url( $loc ),
                esc_attr( implode( ',', $types ) ),
                ! empty( $entry['lastmod'] ) ? ' lastmod="' . esc_attr( $entry['lastmod'] ) . '"' : ''
            );
        }
        $xml .= '</schemamap>' . "\n";

        return $xml;
    }

    /**
     * Daily cron callback — zero work while the feature is dark.
     *
     * @return void
     */
    public function regenerate_if_enabled(): void {
        if ( self::is_enabled() ) {
            $this->regenerate();
        }
    }

    /**
     * Rebuild and store the entry cache.
     *
     * @return array{generated: int, entries: array}
     */
    public function regenerate(): array {
        $entries = [];

        // 1. Schema wizard published graph (site-wide wp_head emission) → home URL.
        foreach ( $this->published_graph_types() as $type ) {
            $this->add_entry( $entries, home_url( '/' ), $type, null );
        }

        // 2. Last schema scan: per-URL detected types (covers SEO-plugin/theme markup).
        $scan = get_option( 'llmagnet_schema_last_scan', [] );
        if ( is_array( $scan ) && ! empty( $scan['pages'] ) && is_array( $scan['pages'] ) ) {
            foreach ( $scan['pages'] as $page ) {
                if ( ! is_array( $page ) || empty( $page['ok'] ) || empty( $page['url'] ) ) {
                    continue;
                }
                $types = isset( $page['types_found'] ) && is_array( $page['types_found'] ) ? $page['types_found'] : [];
                foreach ( $types as $type ) {
                    if ( is_string( $type ) && '' !== $type ) {
                        $this->add_entry( $entries, (string) $page['url'], $type, $this->lastmod_for_url( (string) $page['url'] ) );
                    }
                }
            }
        }

        $cache = [
            'generated' => time(),
            'entries'   => $entries,
        ];
        update_option( self::OPTION_CACHE, $cache, false );

        return $cache;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * Add a type to a URL entry (deduplicated).
     *
     * @param array       $entries Entries by reference.
     * @param string      $loc     URL.
     * @param string      $type    Schema type.
     * @param string|null $lastmod Y-m-d, or null.
     * @return void
     */
    private function add_entry( array &$entries, string $loc, string $type, ?string $lastmod ): void {
        $loc  = esc_url_raw( $loc );
        $type = preg_replace( '/[^A-Za-z0-9_:.-]/', '', $type );
        if ( '' === $loc || '' === $type ) {
            return;
        }

        if ( ! isset( $entries[ $loc ] ) ) {
            $entries[ $loc ] = [ 'types' => [], 'lastmod' => $lastmod ];
        }
        if ( ! in_array( $type, $entries[ $loc ]['types'], true ) ) {
            $entries[ $loc ]['types'][] = $type;
        }
        if ( null !== $lastmod && empty( $entries[ $loc ]['lastmod'] ) ) {
            $entries[ $loc ]['lastmod'] = $lastmod;
        }
    }

    /**
     * Types in the Schema wizard's published (and enabled) JSON-LD graph.
     *
     * @return string[]
     */
    private function published_graph_types(): array {
        $settings = get_option( 'llmagnet_schema_settings', [] );
        if ( is_array( $settings ) && array_key_exists( 'enabled', $settings ) && empty( $settings['enabled'] ) ) {
            return [];
        }

        $published = get_option( 'llmagnet_schema_published_ld', '' );
        if ( ! is_string( $published ) || '' === $published ) {
            return [];
        }

        $data = json_decode( $published, true );
        if ( ! is_array( $data ) ) {
            return [];
        }

        $nodes = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : [ $data ];
        $types = [];
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
                continue;
            }
            foreach ( (array) $node['@type'] as $type ) {
                if ( is_string( $type ) && '' !== $type && ! in_array( $type, $types, true ) ) {
                    $types[] = $type;
                }
            }
        }

        return $types;
    }

    /**
     * lastmod for a URL via its post (when resolvable).
     *
     * @param string $url URL.
     * @return string|null Y-m-d, or null.
     */
    private function lastmod_for_url( string $url ): ?string {
        $post_id = url_to_postid( $url );
        if ( ! $post_id ) {
            return null;
        }

        $modified = get_post_modified_time( 'Y-m-d', true, $post_id );
        return is_string( $modified ) ? $modified : null;
    }
}
