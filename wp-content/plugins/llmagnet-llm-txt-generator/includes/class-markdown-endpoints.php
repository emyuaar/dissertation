<?php
/**
 * Markdown source endpoints (agent-readiness-spec Feature 5 — FA-1/FA-2)
 *
 * Serves any exposable post/page/product as clean, front-mattered markdown:
 *
 * 1. `.md` suffix — `{permalink}.md` handled at `template_redirect` via
 *    request-URI inspection (no per-post rewrite rules). Default path when
 *    the `markdown_endpoints` feature toggle is ON. Cache-safe (separate URL).
 * 2. Content negotiation — `Accept: text/markdown` on the normal permalink
 *    returns markdown with `Vary: Accept`. Ships default OFF behind the
 *    separate `llmagnet_markdown_conneg` option because page caches that
 *    ignore `Vary` would poison HTML with markdown; the Agent Ready UI
 *    shows a warning when a known cache plugin is detected
 *    ({@see Markdown_Endpoints::detect_cache_plugin()}).
 *
 * Exposure rules are MCP_Tools' (dependency D7 semantics): published +
 * public type included in llms.txt settings + no password, plus the
 * `_llmagnet_exclude_from_llms` opt-out. Anything else falls through to
 * WordPress' natural 404.
 *
 * Conversion uses the shared Markdown_Converter with the Generator's exact
 * pipeline (raw post_content + shortcode processing), so the body is
 * identical to the post's /llms-docs/ export.
 *
 * Everything no-ops while the `markdown_endpoints` toggle is OFF (default).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Markdown_Converter' ) ) {
    require_once __DIR__ . '/class-markdown-converter.php';
}

/**
 * `.md` permalink suffix + `Accept: text/markdown` negotiation.
 */
class Markdown_Endpoints {

    /**
     * Content-negotiation opt-in (bool option, default false, autoload off).
     */
    const OPTION_CONNEG = 'llmagnet_markdown_conneg';

    /**
     * Shared MCP tool registry (exposure rules source).
     *
     * @var MCP_Tools|null
     */
    private $mcp_tools;

    /**
     * Generator (settings: full_content, llms_txt_show_author).
     *
     * @var Generator|null
     */
    private $generator;

    /**
     * Dependencies are optional and lazily constructed when omitted. Main
     * should inject the shared instances.
     *
     * @param MCP_Tools|null $mcp_tools Shared MCP tool registry.
     * @param Generator|null $generator Generator instance.
     */
    public function __construct( $mcp_tools = null, $generator = null ) {
        $this->mcp_tools = $mcp_tools instanceof MCP_Tools ? $mcp_tools : null;
        $this->generator = $generator instanceof Generator ? $generator : null;
    }

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        // Priority 2: after the Well_Known router (0), before
        // redirect_canonical (10) can 301 the .md URL away.
        add_action( 'template_redirect', [ $this, 'maybe_serve_markdown' ], 2 );

        // <link rel="alternate" type="text/markdown"> next to the existing
        // llms.txt link tag (Main::add_llms_txt_link_tag, also wp_head).
        add_action( 'wp_head', [ $this, 'add_markdown_link_tag' ] );
    }

    /**
     * Whether the markdown_endpoints feature toggle is on (default OFF).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'markdown_endpoints' );
    }

    /**
     * Whether content negotiation is opted in (default OFF — cache caveat).
     *
     * @return bool
     */
    public static function conneg_enabled(): bool {
        return (bool) get_option( self::OPTION_CONNEG, false );
    }

    /**
     * Detect a known full-page cache plugin (for the conneg warning).
     *
     * @return string|null Plugin display name, or null when none detected.
     */
    public static function detect_cache_plugin(): ?string {
        $checks = [
            'WP Rocket'           => defined( 'WP_ROCKET_VERSION' ),
            'W3 Total Cache'      => defined( 'W3TC' ),
            'WP Super Cache'      => defined( 'WPCACHEHOME' ) || function_exists( 'wp_cache_serve_cache_file' ),
            'LiteSpeed Cache'     => defined( 'LSCWP_V' ) || class_exists( '\LiteSpeed\Core' ),
            'WP Fastest Cache'    => class_exists( 'WpFastestCache' ),
            'Cache Enabler'       => class_exists( 'Cache_Enabler' ),
            'Hummingbird'         => defined( 'WPHB_VERSION' ),
            'SiteGround Optimizer' => defined( '\SiteGround_Optimizer\VERSION' ),
            'Breeze'              => defined( 'BREEZE_VERSION' ),
            'Comet Cache'         => class_exists( 'comet_cache' ),
        ];

        foreach ( $checks as $label => $active ) {
            if ( $active ) {
                return $label;
            }
        }

        return null;
    }

    /**
     * template_redirect worker: serve `.md` requests and negotiate Accept.
     *
     * @return void
     */
    public function maybe_serve_markdown(): void {
        if ( is_admin() || ! self::is_enabled() ) {
            return;
        }

        // 1. `.md` suffix path (default ON with the feature).
        $post = $this->resolve_md_request();
        if ( $post instanceof \WP_Post ) {
            $this->serve( $post );
            // serve() exits.
        }

        // 2. Content negotiation on the normal permalink (opt-in).
        if ( ! self::conneg_enabled() || ! is_singular() ) {
            return;
        }

        // Always vary singular HTML responses on Accept once conneg is on,
        // so well-behaved caches keep HTML and markdown apart.
        if ( ! headers_sent() ) {
            header( 'Vary: Accept', false );
        }

        $accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a literal token only.
        if ( false === stripos( $accept, 'text/markdown' ) ) {
            return;
        }

        $queried = get_queried_object();
        if ( $queried instanceof \WP_Post && $this->is_exposable( $queried ) ) {
            $this->serve( $queried );
        }
    }

    /**
     * The .md URL for a post, or null when it must not be advertised.
     *
     * Used by the wp_head link tag and the F6.1 Link headers.
     *
     * @param \WP_Post|int|null $post Post.
     * @return string|null
     */
    public function get_md_url( $post ): ?string {
        if ( ! self::is_enabled() ) {
            return null;
        }

        $post = get_post( $post );
        if ( ! $post instanceof \WP_Post || ! $this->is_exposable( $post ) ) {
            return null;
        }

        $permalink = get_permalink( $post );
        if ( ! is_string( $permalink ) || '' === $permalink || false !== strpos( $permalink, '?' ) ) {
            // Plain permalinks (?p=N) cannot carry a meaningful .md suffix.
            return null;
        }

        return untrailingslashit( $permalink ) . '.md';
    }

    /**
     * wp_head: advertise the current page's markdown alternate (spec F5).
     *
     * @return void
     */
    public function add_markdown_link_tag(): void {
        if ( ! is_singular() ) {
            return;
        }

        $md_url = $this->get_md_url( get_queried_object() );
        if ( null === $md_url ) {
            return;
        }

        echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '">' . "\n";
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * Resolve the current request to a post when it is a valid `.md` request.
     *
     * Returns null (→ WordPress' natural 404) for non-.md requests and for
     * .md requests on unpublished / excluded / non-exposable content.
     *
     * @return \WP_Post|null
     */
    private function resolve_md_request(): ?\WP_Post {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return null;
        }

        $request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized and matched against permalinks below.
        if ( ! is_string( $request_path ) || '' === $request_path ) {
            return null;
        }

        $request_path = rawurldecode( $request_path );
        if ( strlen( $request_path ) <= 3 || '.md' !== strtolower( substr( $request_path, -3 ) ) ) {
            return null;
        }

        $clean_path = substr( $request_path, 0, -3 );

        // Strip the subdirectory prefix for installs not at the domain root.
        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $relative  = $clean_path;
        if ( is_string( $home_path ) && '/' !== $home_path && 0 === strpos( $relative, $home_path ) ) {
            $relative = '/' . ltrim( substr( $relative, strlen( $home_path ) ), '/' );
        }

        if ( '' === trim( $relative, '/' ) ) {
            return null;
        }

        $url = home_url( $relative );

        $post_id = url_to_postid( $url );
        if ( ! $post_id ) {
            $post_id = url_to_postid( trailingslashit( $url ) );
        }

        // Fallback for post types url_to_postid() cannot resolve (some CPT
        // permastructs, e.g. products): query by slug across exposable
        // types, then verify by exact permalink match.
        if ( ! $post_id ) {
            $post_id = $this->resolve_by_slug( $relative, $url );
        }

        if ( ! $post_id ) {
            return null;
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post || ! $this->is_exposable( $post ) ) {
            return null;
        }

        return $post;
    }

    /**
     * Slug-based fallback resolver, verified by permalink equality.
     *
     * @param string $relative Site-relative clean path (no .md).
     * @param string $url      Absolute clean URL.
     * @return int Post ID, or 0.
     */
    private function resolve_by_slug( string $relative, string $url ): int {
        $slug = sanitize_title( basename( untrailingslashit( $relative ) ) );
        if ( '' === $slug ) {
            return 0;
        }

        $candidates = get_posts(
            [
                'name'             => $slug,
                'post_type'        => $this->mcp_tools()->exposable_post_types(),
                'post_status'      => 'publish',
                'posts_per_page'   => 5,
                'suppress_filters' => false,
            ]
        );

        $target = untrailingslashit( $url );
        foreach ( $candidates as $candidate ) {
            $permalink = get_permalink( $candidate );
            if ( is_string( $permalink ) && untrailingslashit( $permalink ) === $target ) {
                return (int) $candidate->ID;
            }
        }

        return 0;
    }

    /**
     * Exposure check: MCP_Tools rules + the per-post llms.txt exclude meta.
     *
     * @param \WP_Post $post Post.
     * @return bool
     */
    private function is_exposable( \WP_Post $post ): bool {
        if ( ! $this->mcp_tools()->is_post_exposable( $post ) ) {
            return false;
        }
        return '1' !== get_post_meta( $post->ID, '_llmagnet_exclude_from_llms', true );
    }

    /**
     * Send the markdown response and exit.
     *
     * @param \WP_Post $post Post.
     * @return void
     */
    private function serve( \WP_Post $post ): void {
        $body = $this->render_markdown( $post );

        status_header( 200 );

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/markdown; charset=utf-8' );
            header( 'Vary: Accept', false );
            // Keep the duplicate-content alternate out of search indexes and
            // point clients back at the canonical HTML page.
            header( 'X-Robots-Tag: noindex' );
            header( 'Link: <' . esc_url_raw( get_permalink( $post ) ) . '>; rel="canonical"', false );
            header( 'Cache-Control: public, max-age=300' );
        }

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown body, not HTML.
        exit;
    }

    /**
     * Front-mattered markdown for a post (spec F5: title, canonical URL,
     * date, author — author respects llms_txt_show_author).
     *
     * @param \WP_Post $post Post.
     * @return string
     */
    public function render_markdown( \WP_Post $post ): string {
        $settings = $this->generator()->get_settings();

        $title     = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $permalink = get_permalink( $post );

        $front = [
            'title: "' . str_replace( '"', '\\"', $title ) . '"',
            'url: ' . $permalink,
            'date: ' . get_the_date( 'Y-m-d', $post ),
            'modified: ' . get_the_modified_date( 'Y-m-d', $post ),
        ];

        if ( ! empty( $settings['llms_txt_show_author'] ) ) {
            $author = get_the_author_meta( 'display_name', (int) $post->post_author );
            if ( is_string( $author ) && '' !== $author ) {
                $front[] = 'author: "' . str_replace( '"', '\\"', $author ) . '"';
            }
        }

        // Same content pipeline as the Generator's /llms-docs/ export:
        // raw post_content (or excerpt when full_content is off) through the
        // shared converter with shortcode processing.
        if ( ! empty( $settings['full_content'] ) ) {
            $content = $post->post_content;
        } else {
            $content = $post->post_excerpt ?: wp_trim_words( $post->post_content, 55, '...' );
        }

        $markdown = Markdown_Converter::convert( (string) $content, true );

        return "---\n" . implode( "\n", $front ) . "\n---\n\n"
            . '# ' . $title . "\n\n"
            . $markdown . "\n";
    }

    // ── Lazy dependencies ──────────────────────────────────────────────────────

    /**
     * @return MCP_Tools
     */
    private function mcp_tools(): MCP_Tools {
        if ( ! $this->mcp_tools instanceof MCP_Tools ) {
            if ( ! class_exists( __NAMESPACE__ . '\\MCP_Tools' ) ) {
                require_once __DIR__ . '/class-mcp-tools.php';
            }
            $this->mcp_tools = new MCP_Tools();
        }
        return $this->mcp_tools;
    }

    /**
     * @return Generator
     */
    private function generator(): Generator {
        if ( ! $this->generator instanceof Generator ) {
            $this->generator = new Generator();
        }
        return $this->generator;
    }
}
