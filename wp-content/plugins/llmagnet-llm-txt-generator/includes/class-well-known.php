<?php
/**
 * Well-Known router class
 *
 * Single router for every `/.well-known/*` and root virtual file the plugin
 * serves (agent-card.json, agent-skills, mcp.json, security.txt,
 * schemamap.xml, IndexNow key file, …) so rewrite logic is never scattered
 * across feature classes. (agent-readiness-spec Phase 0.1)
 *
 * ## Provider API (for Phase D feature classes)
 *
 * Feature classes register a provider for a path on the
 * `llmagnet_register_well_known_providers` action (fired on `init`,
 * priority 20, right before rewrite rules are built):
 *
 *     add_action( 'llmagnet_register_well_known_providers', function () {
 *         \LLMagnet_AI_SEO_Optimizer\Well_Known::register(
 *             '.well-known/agent-card.json',
 *             [ $this, 'render_agent_card' ],   // returns body string, or null to decline
 *             'application/json; charset=utf-8'
 *         );
 *     } );
 *
 * Provider callbacks receive the matched path and must return the response
 * body as a string. Returning anything else (null/false/WP_Error) declines
 * the request and lets WordPress continue its normal flow (typically a 404).
 *
 * ## Guarantees
 *
 * - Physical-file precedence: if a real file exists in the web root at the
 *   requested path (e.g. a host-provisioned `.well-known/security.txt`),
 *   the router does nothing — the host file wins (mirrors the conflict
 *   style of Robots_Txt::inject_into_physical()).
 * - Works on "Plain" permalinks via a template_redirect request-URI
 *   fallback (no rewrite rules are involved there).
 * - Rewrite rules are flushed automatically (once) whenever the set of
 *   registered paths changes, including the first run after activation —
 *   no activation-hook wiring is strictly required.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Router for /.well-known/* and root virtual files
 */
class Well_Known {

    /**
     * Query var carrying the matched well-known path on pretty permalinks.
     */
    const QUERY_VAR = 'llmagnet_well_known';

    /**
     * Option storing a hash of the registered paths, used to detect when a
     * rewrite flush is needed. Autoload stays off (read once per request on
     * init only).
     */
    const RULES_HASH_OPTION = 'llmagnet_well_known_rules_hash';

    /**
     * Registered providers.
     *
     * Keyed by normalized path (no leading slash). Each entry:
     * - callback:      callable( string $path ): ?string — response body.
     * - content_type:  Content-Type header value.
     * - cache_max_age: seconds for Cache-Control: public, max-age=N.
     * - noindex:       whether to emit X-Robots-Tag: noindex.
     *
     * @var array<string, array{callback: callable, content_type: string, cache_max_age: int, noindex: bool}>
     */
    private static $providers = [];

    /**
     * Initialize hooks
     *
     * Safe to call from Main::init_components() (which runs on `init`
     * priority 10): all internal hooks use later `init` priorities or
     * later request phases.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'init', [ $this, 'add_rewrite_rules' ], 20 );
        add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 21 );
        add_filter( 'query_vars', [ $this, 'register_query_var' ] );
        // Priority 0: serve before redirect_canonical (priority 10) can
        // interfere with dotted/virtual paths.
        add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
    }

    /**
     * Register a provider for a well-known path
     *
     * @param string   $path         Path relative to the site root, e.g.
     *                               '.well-known/agent-card.json' or 'schemamap.xml'.
     *                               Leading slash is tolerated and stripped.
     * @param callable $provider     callable( string $path ): ?string returning the
     *                               response body, or a non-string to decline.
     * @param string   $content_type Content-Type header value, e.g. 'application/json; charset=utf-8'.
     * @param array    $args         Optional. {
     *     @type int  $cache_max_age Cache-Control max-age in seconds. Default 3600.
     *     @type bool $noindex       Emit `X-Robots-Tag: noindex`. Default true.
     * }
     * @return bool True when registered, false on invalid path/callback.
     */
    public static function register( string $path, callable $provider, string $content_type, array $args = [] ): bool {
        $path = self::normalize_path( $path );
        if ( '' === $path ) {
            return false;
        }

        self::$providers[ $path ] = [
            'callback'      => $provider,
            'content_type'  => $content_type,
            'cache_max_age' => isset( $args['cache_max_age'] ) ? max( 0, (int) $args['cache_max_age'] ) : 3600,
            'noindex'       => isset( $args['noindex'] ) ? (bool) $args['noindex'] : true,
        ];

        return true;
    }

    /**
     * Remove a registered provider
     *
     * @param string $path Path previously passed to register().
     * @return void
     */
    public static function unregister( string $path ): void {
        unset( self::$providers[ self::normalize_path( $path ) ] );
    }

    /**
     * Whether a provider is registered for a path
     *
     * @param string $path Path to check.
     * @return bool
     */
    public static function is_registered( string $path ): bool {
        return isset( self::$providers[ self::normalize_path( $path ) ] );
    }

    /**
     * Get all registered paths
     *
     * @return string[] Normalized paths (no leading slash).
     */
    public static function get_registered_paths(): array {
        return array_keys( self::$providers );
    }

    /**
     * Whether a physical file exists in the web root at the given path
     *
     * Public so the Agent-Ready audit (F1) can report "served by host"
     * instead of "served by LLMagnet".
     *
     * @param string $path Path relative to the site root.
     * @return bool
     */
    public static function physical_file_exists( string $path ): bool {
        $path = self::normalize_path( $path );
        if ( '' === $path ) {
            return false;
        }
        return file_exists( ABSPATH . $path );
    }

    /**
     * Register rewrite rules for every registered provider path
     *
     * Fires `llmagnet_register_well_known_providers` first so feature
     * classes have a guaranteed registration point before rules are built.
     *
     * @return void
     */
    public function add_rewrite_rules(): void {
        /**
         * Registration point for well-known providers.
         *
         * Feature classes (agent-card, mcp.json, security.txt, schemamap.xml,
         * IndexNow key file, …) should call Well_Known::register() here.
         */
        do_action( 'llmagnet_register_well_known_providers' );

        foreach ( array_keys( self::$providers ) as $path ) {
            add_rewrite_rule(
                '^' . preg_quote( $path ) . '$',
                'index.php?' . self::QUERY_VAR . '=' . rawurlencode( $path ),
                'top'
            );
        }
    }

    /**
     * Flush rewrite rules once when the set of registered paths changes
     *
     * Covers plugin activation/updates and new providers shipping in later
     * releases without requiring an activation-hook edit. A static
     * activate()/deactivate() pair is still provided for explicit wiring.
     *
     * @return void
     */
    public function maybe_flush_rewrite_rules(): void {
        $paths = self::get_registered_paths();
        sort( $paths );
        $hash = md5( implode( '|', $paths ) );

        if ( get_option( self::RULES_HASH_OPTION ) === $hash ) {
            return;
        }

        flush_rewrite_rules( false );
        update_option( self::RULES_HASH_OPTION, $hash, false );
    }

    /**
     * Activation handler — force a rules rebuild on next init
     *
     * Optional: maybe_flush_rewrite_rules() self-heals anyway. Call from the
     * plugin activation hook if explicit wiring is preferred.
     *
     * @return void
     */
    public static function activate(): void {
        delete_option( self::RULES_HASH_OPTION );
    }

    /**
     * Deactivation handler — drop our rules from the compiled rewrite set
     *
     * @return void
     */
    public static function deactivate(): void {
        delete_option( self::RULES_HASH_OPTION );
        flush_rewrite_rules( false );
    }

    /**
     * Expose the routing query var to WP_Query
     *
     * @param array $vars Public query vars.
     * @return array
     */
    public function register_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Serve a registered path when the current request matches one
     *
     * Match order:
     * 1. Rewrite-rule query var (pretty permalinks).
     * 2. Raw request URI (Plain permalinks, where rewrite rules never run
     *    and the request reaches template_redirect as a 404).
     *
     * @return void
     */
    public function maybe_serve(): void {
        $path = get_query_var( self::QUERY_VAR );
        $path = is_string( $path ) ? self::normalize_path( $path ) : '';

        if ( '' === $path || ! isset( self::$providers[ $path ] ) ) {
            $path = $this->match_request_path();
        }

        if ( '' === $path || ! isset( self::$providers[ $path ] ) ) {
            // A stale rewrite rule can still set our query var for a path
            // that is no longer registered (e.g. an IndexNow key file rule
            // surviving one request past key removal, before the rules-hash
            // self-heal flushes). Without this, WordPress resolves
            // index.php?llmagnet_well_known=… to the HOMEPAGE — force a 404
            // instead (extends the Phase D gate fix to unregistered paths).
            if ( '' !== get_query_var( self::QUERY_VAR ) ) {
                global $wp_query;
                $wp_query->set_404();
                status_header( 404 );
                nocache_headers();
            }
            return;
        }

        // Physical-file precedence: a real file in the web root wins.
        if ( self::physical_file_exists( $path ) ) {
            return;
        }

        $provider = self::$providers[ $path ];
        $body     = call_user_func( $provider['callback'], $path );

        if ( ! is_string( $body ) ) {
            // Provider declined (disabled toggle, missing data, …).
            // On pretty permalinks our rewrite rule already swallowed the
            // request (index.php?llmagnet_well_known=…), which WordPress
            // would otherwise resolve to the homepage — force a proper 404
            // instead (Phase D gate fix). The Plain-permalink fallback path
            // reaches template_redirect as a natural 404 already.
            if ( '' !== get_query_var( self::QUERY_VAR ) ) {
                global $wp_query;
                $wp_query->set_404();
                status_header( 404 );
                nocache_headers();
            }
            return;
        }

        status_header( 200 );

        if ( ! headers_sent() ) {
            header( 'Content-Type: ' . $provider['content_type'] );
            header( 'Cache-Control: public, max-age=' . $provider['cache_max_age'] );
            if ( $provider['noindex'] ) {
                header( 'X-Robots-Tag: noindex' );
            }
        }

        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- machine-readable body, providers own escaping for their content type.
        exit;
    }

    /**
     * Plain-permalink fallback: match the raw request URI against providers
     *
     * Handles subdirectory installs by stripping the home-URL path prefix.
     *
     * @return string Matched normalized path, or '' when no match.
     */
    private function match_request_path(): string {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }

        $request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized below.
        if ( ! is_string( $request_path ) || '' === $request_path ) {
            return '';
        }

        $request_path = rawurldecode( $request_path );

        // Strip the subdirectory prefix for installs not at the domain root.
        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( is_string( $home_path ) && '/' !== $home_path && 0 === strpos( $request_path, $home_path ) ) {
            $request_path = substr( $request_path, strlen( $home_path ) - 1 );
        }

        $request_path = self::normalize_path( $request_path );

        return isset( self::$providers[ $request_path ] ) ? $request_path : '';
    }

    /**
     * Normalize and validate a provider path
     *
     * Strips leading slashes, rejects traversal and characters outside the
     * conservative allowlist [A-Za-z0-9._/-].
     *
     * @param string $path Raw path.
     * @return string Normalized path, or '' when invalid.
     */
    private static function normalize_path( string $path ): string {
        $path = ltrim( trim( $path ), '/' );

        if ( '' === $path || false !== strpos( $path, '..' ) ) {
            return '';
        }

        if ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $path ) ) {
            return '';
        }

        return $path;
    }
}
