<?php
/**
 * Discovery Link headers + feed-link restoration (agent-readiness-spec F6.1/F6.3 — FA-3, FA-5)
 *
 * Emits HTTP Link headers advertising the site's machine resources on
 * front-end responses (spec F6.1):
 *
 *     Link: </llms.txt>; rel="llms-txt"; type="text/plain",
 *           </.well-known/agent-card.json>; rel="agent-card"; type="application/json",
 *           </schemamap.xml>; rel="schemamap"; type="application/xml",
 *           <{permalink}.md>; rel="alternate"; type="text/markdown"
 *
 * - Site-wide entries go through the Http_Headers manager (Phase 0.2) via
 *   its `llmagnet_http_headers` filter — sanitized + skip-if-present rules
 *   apply, emitted on `send_headers`.
 * - The per-permalink `.md` alternate needs the resolved main query, which
 *   does not exist yet when `send_headers` fires (WP::main() order:
 *   parse_request → send_headers → query_posts). It is therefore emitted on
 *   the `wp` action (still pre-output) as an additional Link header, using
 *   Http_Headers' sanitizers. Multiple Link headers are valid per RFC 8288.
 * - agent-card / schemamap entries appear only while the `well_known`
 *   toggle is on; the markdown alternate only with `markdown_endpoints` on
 *   and the post exposable.
 *
 * Also owns the F6.3 feed-link restoration toggle
 * (`llmagnet_restore_feed_links`, default OFF): when enabled it re-adds
 * `add_theme_support( 'automatic-feed-links' )` so core's wp_head
 * `feed_links` / `feed_links_extra` callbacks emit the RSS alternate links
 * a theme removed. No generation — feeds themselves are core's.
 *
 * Everything no-ops while the `link_headers` toggle is OFF (default),
 * except the independent feed-restore option.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * F6.1 Link headers + F6.3 feed-links restore.
 */
class Link_Headers {

    /**
     * Feed-link restoration toggle (bool option, default false, autoload off).
     */
    const OPTION_RESTORE_FEED_LINKS = 'llmagnet_restore_feed_links';

    /**
     * Markdown endpoints component (for the per-post .md URL).
     *
     * @var Markdown_Endpoints|null
     */
    private $markdown_endpoints;

    /**
     * @param Markdown_Endpoints|null $markdown_endpoints Markdown endpoints component.
     */
    public function __construct( $markdown_endpoints = null ) {
        $this->markdown_endpoints = $markdown_endpoints instanceof Markdown_Endpoints ? $markdown_endpoints : null;
    }

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        // Site-wide Link header via the Http_Headers manager (send_headers).
        add_filter( 'llmagnet_http_headers', [ $this, 'filter_http_headers' ] );

        // Singular .md alternate — needs the main query (see class docblock).
        add_action( 'wp', [ $this, 'emit_markdown_alternate' ], 1 );

        // F6.3: restore the theme's automatic feed links when opted in.
        if ( get_option( self::OPTION_RESTORE_FEED_LINKS, false ) ) {
            add_theme_support( 'automatic-feed-links' );
        }
    }

    /**
     * Whether the link_headers feature toggle is on (default OFF).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'link_headers' );
    }

    /**
     * llmagnet_http_headers filter: append the site-wide Link header.
     *
     * @param array $headers Header entries.
     * @return array
     */
    public function filter_http_headers( $headers ) {
        if ( ! is_array( $headers ) ) {
            $headers = [];
        }

        if ( is_admin() || ! self::is_enabled() ) {
            return $headers;
        }

        $parts = [
            '<' . home_url( '/llms.txt' ) . '>; rel="llms-txt"; type="text/plain"',
        ];

        if ( class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'well_known' ) ) {
            $parts[] = '<' . home_url( '/.well-known/agent-card.json' ) . '>; rel="agent-card"; type="application/json"';
            $parts[] = '<' . home_url( '/schemamap.xml' ) . '>; rel="schemamap"; type="application/xml"';
        }

        $headers[] = [
            'name'  => 'Link',
            'value' => implode( ', ', $parts ),
        ];

        return $headers;
    }

    /**
     * `wp` action: append the singular markdown-alternate Link header.
     *
     * @return void
     */
    public function emit_markdown_alternate(): void {
        if ( is_admin() || headers_sent() || ! self::is_enabled() || ! is_singular() ) {
            return;
        }

        $md_url = $this->markdown_endpoints()->get_md_url( get_queried_object() );
        if ( null === $md_url ) {
            return;
        }

        $value = Http_Headers::sanitize_header_value(
            '<' . $md_url . '>; rel="alternate"; type="text/markdown"'
        );
        if ( '' === $value ) {
            return;
        }

        header( 'Link: ' . $value, false );
    }

    /**
     * @return Markdown_Endpoints
     */
    private function markdown_endpoints(): Markdown_Endpoints {
        if ( ! $this->markdown_endpoints instanceof Markdown_Endpoints ) {
            if ( ! class_exists( __NAMESPACE__ . '\\Markdown_Endpoints' ) ) {
                require_once __DIR__ . '/class-markdown-endpoints.php';
            }
            $this->markdown_endpoints = new Markdown_Endpoints();
        }
        return $this->markdown_endpoints;
    }
}
