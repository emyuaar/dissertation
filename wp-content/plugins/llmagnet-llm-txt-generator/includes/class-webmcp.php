<?php
/**
 * WebMCP: automatic site preparation (agent-readiness-spec Feature 4)
 *
 * When the `webmcp` toggle is on (default OFF) and the install is on a
 * Pro+ plan, every front-end page enqueues `assets/webmcp.js`, which
 * registers the site's public agent skills on `navigator.modelContext`
 * (feature-detecting both proposed API shapes). Zero per-tool setup —
 * tools come from Agent_Skills_Registry.
 *
 * This class also owns the backing public, read-only REST routes under
 * `llm-analytics/v1/public/*` (E3-5):
 *
 * - GET  /public/search?q=          — search published content
 * - GET  /public/content?url=       — page content as markdown
 * - GET  /public/recent?type=&limit=— recent published items
 * - GET  /public/site-info          — machine-readable site facts
 * - GET  /public/products?q=        — Woo product search (Plus+ only)
 * - POST /public/agent-event        — WebMCP usage beacon → wp_llm_bot_visits (E3-8)
 *
 * Content-exposure rules are STRICT and reused from MCP_Tools (dependency
 * D7): only published, public-post-type, llms.txt-included, non-password
 * content is ever served (`is_post_exposable()` / `exposable_post_types()`).
 * All routes are per-IP rate-limited via transients (60 req/min) and the
 * whole surface 404s while the feature is off.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WebMCP loader + public REST backend.
 */
class Webmcp {

    /**
     * Script handle for the front-end loader.
     */
    const SCRIPT_HANDLE = 'llmagnet-webmcp';

    /**
     * REST namespace of the public routes.
     */
    const REST_NAMESPACE = 'llm-analytics/v1';

    /**
     * Per-IP request budget per minute on public routes.
     */
    const RATE_LIMIT = 60;

    /**
     * Bot name used for agent-event analytics rows (E3-8, spec §4.4).
     */
    const AGENT_BOT_NAME = 'WebMCP Agent';

    /** @var MCP_Tools|null */
    private $mcp_tools;

    /** @var Agent_Skills_Registry|null */
    private $skills_registry;

    /**
     * Dependencies are optional; missing ones are lazily constructed.
     *
     * @param MCP_Tools|null             $mcp_tools       Shared MCP tool registry (content-exposure helpers).
     * @param Agent_Skills_Registry|null $skills_registry Public skills registry.
     */
    public function __construct( $mcp_tools = null, $skills_registry = null ) {
        $this->mcp_tools       = $mcp_tools;
        $this->skills_registry = $skills_registry;
    }

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_loader' ] );
        add_action( 'rest_api_init', [ $this, 'register_public_routes' ] );
    }

    /**
     * Whether WebMCP is live: toggle on (default OFF) + Pro+ plan, server-side.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        if ( ! Agent_Readiness_Options::is_feature_enabled( 'webmcp' ) ) {
            return false;
        }
        if ( ! function_exists( 'lltg_fs' ) ) {
            return false;
        }
        $fs = lltg_fs();
        return (bool) ( $fs->can_use_premium_code() || $fs->is_trial() );
    }

    /**
     * Whether the install may expose Woo commerce tools (Plus/Enterprise).
     *
     * @return bool
     */
    private function has_commerce_plan(): bool {
        if ( ! function_exists( 'lltg_fs' ) ) {
            return false;
        }
        $fs = lltg_fs();
        return $fs->is_plan( 'plus' ) || $fs->is_plan( 'enterprise' );
    }

    /**
     * Whether WooCommerce is active.
     *
     * @return bool
     */
    private function woo_active(): bool {
        return class_exists( 'WooCommerce', false ) || function_exists( 'wc_get_product' );
    }

    // ── Loader enqueue (E3-6 wiring, E3-7 config) ─────────────────────────────

    /**
     * Enqueue assets/webmcp.js on the front end when the feature is live.
     *
     * @return void
     */
    public function maybe_enqueue_loader(): void {
        if ( is_admin() || ! $this->is_enabled() ) {
            return;
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            LLMAGNET_AISEO_PLUGIN_URL . 'assets/webmcp.js',
            [],
            defined( 'LLMAGNET_AISEO_VERSION' ) ? LLMAGNET_AISEO_VERSION : '1.0',
            [
                'in_footer' => true,
                'strategy'  => 'defer',
            ]
        );

        wp_add_inline_script(
            self::SCRIPT_HANDLE,
            'window.llmagnetWebmcp = ' . wp_json_encode( $this->get_loader_config() ) . ';',
            'before'
        );

        wp_enqueue_script( self::SCRIPT_HANDLE );
    }

    /**
     * Loader config: public endpoints only, no nonces, no user data.
     *
     * @return array
     */
    public function get_loader_config(): array {
        $tools = [];
        foreach ( $this->skills_registry()->get_skills_for_surface( 'webmcp' ) as $skill ) {
            $tools[] = [
                'id'           => $skill['id'],
                'description'  => $skill['description'],
                'input_schema' => $skill['input_schema'],
                'endpoint'     => [
                    'method' => $skill['endpoint']['method'],
                    'url'    => $skill['endpoint']['url'],
                ],
            ];
        }

        $add_to_cart = $this->woo_active()
            && $this->has_commerce_plan()
            && (bool) get_option( Agent_Audit::OPTION_ADD_TO_CART, false );

        $config = [
            'site'      => get_bloginfo( 'name' ),
            'restBase'  => esc_url_raw( rest_url( Agent_Skills_Registry::PUBLIC_REST_BASE ) ),
            'beaconUrl' => esc_url_raw( rest_url( Agent_Skills_Registry::PUBLIC_REST_BASE . '/agent-event' ) ),
            'postId'    => is_singular() ? (int) get_queried_object_id() : 0,
            'postType'  => is_singular() ? (string) get_post_type( get_queried_object_id() ) : '',
            'woo'       => $this->woo_active(),
            'tools'     => $tools,
        ];

        // add_to_cart (spec §4.3): client-side write tool against Woo's own
        // Store API. Separate explicit opt-in, default OFF.
        if ( $add_to_cart ) {
            $config['addToCart'] = true;
            $config['cartApi']   = esc_url_raw( rest_url( 'wc/store/v1/cart/add-item' ) );
        }

        return $config;
    }

    // ── Public REST routes (E3-5) ─────────────────────────────────────────────

    /**
     * Register the llm-analytics/v1/public/* routes.
     *
     * @return void
     */
    public function register_public_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/public/search',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_search' ],
                'permission_callback' => [ $this, 'public_permission' ],
                'args'                => [
                    'q'     => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'type'    => 'integer',
                        'default' => 10,
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/public/content',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_content' ],
                'permission_callback' => [ $this, 'public_permission' ],
                'args'                => [
                    'url' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'esc_url_raw',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/public/recent',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_recent' ],
                'permission_callback' => [ $this, 'public_permission' ],
                'args'                => [
                    'type'  => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'limit' => [
                        'type'    => 'integer',
                        'default' => 10,
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/public/site-info',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_site_info' ],
                'permission_callback' => [ $this, 'public_permission' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/public/products',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_products' ],
                'permission_callback' => [ $this, 'products_permission' ],
                'args'                => [
                    'q'     => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'type'    => 'integer',
                        'default' => 10,
                        'minimum' => 1,
                        'maximum' => 20,
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/public/agent-event',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_agent_event' ],
                'permission_callback' => [ $this, 'public_permission' ],
                'args'                => [
                    'tool' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'url'  => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'esc_url_raw',
                    ],
                ],
            ]
        );
    }

    /**
     * Shared permission for all public routes: feature live + per-IP rate limit.
     *
     * Returns 404 while the feature is off so the surface is invisible
     * (everything defaults OFF), and 429 when the budget is exhausted.
     *
     * @return true|\WP_Error
     */
    public function public_permission() {
        if ( ! $this->is_enabled() ) {
            return new \WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', [ 'status' => 404 ] );
        }
        if ( ! $this->check_rate_limit() ) {
            return new \WP_Error( 'rate_limited', 'Too many requests. Try again in a minute.', [ 'status' => 429 ] );
        }
        return true;
    }

    /**
     * Products route permission: public rules + Woo + Plus/Enterprise plan.
     *
     * @return true|\WP_Error
     */
    public function products_permission() {
        $base = $this->public_permission();
        if ( true !== $base ) {
            return $base;
        }
        if ( ! $this->woo_active() || ! $this->has_commerce_plan() ) {
            return new \WP_Error( 'rest_no_route', 'No route was found matching the URL and request method.', [ 'status' => 404 ] );
        }
        return true;
    }

    /**
     * Per-IP transient rate limiter (spec §4.3: 60 req/min).
     *
     * @return bool True when the request is within budget.
     */
    private function check_rate_limit(): bool {
        $limit = (int) apply_filters( 'llmagnet_public_rate_limit', self::RATE_LIMIT );
        if ( $limit <= 0 ) {
            return true;
        }

        $key   = 'llmagnet_pubrl_' . md5( $this->client_ip() );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    /**
     * Client IP for rate limiting (REMOTE_ADDR only — spec open question 3
     * accepts transient-per-IP for v1).
     *
     * @return string
     */
    private function client_ip(): string {
        return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
    }

    /**
     * GET /public/search — search published, exposable content.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function rest_search( \WP_REST_Request $request ): \WP_REST_Response {
        $query = (string) $request->get_param( 'q' );
        $limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

        $wp_query = new \WP_Query(
            [
                's'                   => $query,
                'post_type'           => $this->mcp_tools()->exposable_post_types(),
                'post_status'         => 'publish',
                'has_password'        => false,
                'posts_per_page'      => $limit,
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
            ]
        );

        $results = [];
        foreach ( $wp_query->posts as $post ) {
            if ( ! $this->mcp_tools()->is_post_exposable( $post ) ) {
                continue;
            }
            $results[] = [
                'title'   => get_the_title( $post ),
                'url'     => get_permalink( $post ),
                'excerpt' => wp_strip_all_tags( get_the_excerpt( $post ) ),
                'type'    => $post->post_type,
            ];
        }

        return rest_ensure_response(
            [
                'query'   => $query,
                'results' => $results,
            ]
        );
    }

    /**
     * GET /public/content — a page as markdown (exposure rules enforced
     * inside MCP_Tools::tool_get_content_markdown, dependency D7).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_content( \WP_REST_Request $request ) {
        $url = (string) $request->get_param( 'url' );

        // Only resolve URLs on this site.
        $host      = wp_parse_url( home_url(), PHP_URL_HOST );
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $url_host ) || strtolower( (string) $host ) !== strtolower( $url_host ) ) {
            return new \WP_Error( 'invalid_url', 'The "url" argument must be a URL on this site.', [ 'status' => 400 ] );
        }

        $result = $this->mcp_tools()->tool_get_content_markdown( [ 'url' => $url ] );
        if ( is_wp_error( $result ) ) {
            $result->add_data( [ 'status' => 'invalid_arguments' === $result->get_error_code() ? 400 : 404 ] );
            return $result;
        }

        // Never leak internal ids on the anonymous surface.
        unset( $result['post_id'] );

        return rest_ensure_response( $result );
    }

    /**
     * GET /public/recent — recent published, exposable items.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_recent( \WP_REST_Request $request ) {
        $type  = (string) $request->get_param( 'type' );
        $limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

        $allowed_types = $this->mcp_tools()->exposable_post_types();
        if ( '' !== $type && ! in_array( $type, $allowed_types, true ) ) {
            return new \WP_Error( 'invalid_type', 'Unknown or non-public post type.', [ 'status' => 400 ] );
        }

        $wp_query = new \WP_Query(
            [
                'post_type'           => '' !== $type ? $type : $allowed_types,
                'post_status'         => 'publish',
                'has_password'        => false,
                'posts_per_page'      => $limit,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
            ]
        );

        $items = [];
        foreach ( $wp_query->posts as $post ) {
            if ( ! $this->mcp_tools()->is_post_exposable( $post ) ) {
                continue;
            }
            $items[] = [
                'title' => get_the_title( $post ),
                'url'   => get_permalink( $post ),
                'type'  => $post->post_type,
                'date'  => get_the_date( 'Y-m-d', $post ),
            ];
        }

        return rest_ensure_response( [ 'items' => $items ] );
    }

    /**
     * GET /public/site-info — machine-readable site facts, no user data.
     *
     * @return \WP_REST_Response
     */
    public function rest_site_info(): \WP_REST_Response {
        return rest_ensure_response(
            [
                'name'        => get_bloginfo( 'name' ),
                'tagline'     => get_bloginfo( 'description' ),
                'url'         => home_url( '/' ),
                'language'    => get_bloginfo( 'language' ),
                'llms_txt'    => home_url( '/llms.txt' ),
                'feeds'       => [
                    'rss2' => get_feed_link( 'rss2' ),
                    'atom' => get_feed_link( 'atom' ),
                ],
                'woocommerce' => $this->woo_active(),
            ]
        );
    }

    /**
     * GET /public/products — Woo product search (Plus+ plans, spec §4.3).
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function rest_products( \WP_REST_Request $request ): \WP_REST_Response {
        $query = (string) $request->get_param( 'q' );
        $limit = max( 1, min( 20, (int) $request->get_param( 'limit' ) ) );

        $wp_query = new \WP_Query(
            [
                's'                   => $query,
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'has_password'        => false,
                'posts_per_page'      => $limit,
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
            ]
        );

        $results = [];
        foreach ( $wp_query->posts as $post ) {
            $row = [
                'title' => get_the_title( $post ),
                'url'   => get_permalink( $post ),
            ];
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $post->ID );
                if ( $product ) {
                    if ( ! $product->is_visible() ) {
                        continue;
                    }
                    $row['price']        = $product->get_price();
                    $row['currency']     = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
                    $row['stock_status'] = $product->get_stock_status();
                }
            }
            $results[] = $row;
        }

        return rest_ensure_response(
            [
                'query'   => $query,
                'results' => $results,
            ]
        );
    }

    /**
     * POST /public/agent-event — WebMCP usage beacon → wp_llm_bot_visits (E3-8).
     *
     * Mirrors Analytics::log_bot_visit(): same table/columns, same
     * per-bot+path rate-limit transient pattern (B1), so the existing
     * dashboards pick up agent activity with no schema change.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public function rest_agent_event( \WP_REST_Request $request ): \WP_REST_Response {
        $tool = (string) $request->get_param( 'tool' );
        $url  = (string) $request->get_param( 'url' );

        // Only accept tool ids we actually expose (+ the client-side cart tool).
        $known = array_keys( $this->skills_registry()->get_skills_for_surface( 'webmcp' ) );
        $known[] = 'add_to_cart';
        if ( ! in_array( $tool, $known, true ) ) {
            return rest_ensure_response( [ 'logged' => false ] );
        }

        // Page path: only same-host URLs, path component only.
        $page_path = '/';
        if ( '' !== $url ) {
            $host     = wp_parse_url( home_url(), PHP_URL_HOST );
            $url_host = wp_parse_url( $url, PHP_URL_HOST );
            $path     = wp_parse_url( $url, PHP_URL_PATH );
            if ( is_string( $url_host ) && strtolower( (string) $host ) === strtolower( $url_host ) && is_string( $path ) && '' !== $path ) {
                $page_path = wp_strip_all_tags( $path );
            }
        }

        $logged = $this->log_agent_visit( $tool, $page_path );

        return rest_ensure_response( [ 'logged' => $logged ] );
    }

    /**
     * Insert a WebMCP Agent row into wp_llm_bot_visits, rate-limited.
     *
     * @param string $tool      Invoked tool id (stored in page_title context).
     * @param string $page_path Page path the agent acted on.
     * @return bool Whether a row was written.
     */
    private function log_agent_visit( string $tool, string $page_path ): bool {
        global $wpdb;

        // Same windowed rate limit as Analytics::log_bot_visit() (B1-6),
        // keyed by bot+path+tool so a crawl/invoke storm cannot hammer the DB.
        $window = (int) apply_filters( 'llmagnet_bot_visit_rate_limit_window', 5 * MINUTE_IN_SECONDS );
        if ( $window > 0 ) {
            $key = 'llmagnet_bv_' . md5( self::AGENT_BOT_NAME . '|' . $page_path . '|' . $tool );
            if ( get_transient( $key ) ) {
                return false;
            }
            set_transient( $key, 1, $window );
        }

        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'WebMCP';

        $page_title = 'WebMCP: ' . $tool;
        $post_id    = url_to_postid( home_url( $page_path ) );
        if ( $post_id ) {
            $title = get_the_title( $post_id );
            if ( '' !== $title ) {
                $page_title = $title;
            }
        }

        if ( function_exists( 'mb_substr' ) ) {
            $page_path  = mb_substr( $page_path, 0, 500, 'UTF-8' );
            $page_title = mb_substr( $page_title, 0, 500, 'UTF-8' );
        } else {
            $page_path  = substr( $page_path, 0, 500 );
            $page_title = substr( $page_title, 0, 500 );
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'llm_bot_visits',
            [
                'bot_name'   => self::AGENT_BOT_NAME,
                'user_agent' => $user_agent,
                'page_path'  => $page_path,
                'page_title' => $page_title,
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        return false !== $result;
    }

    // ── Lazy dependencies ─────────────────────────────────────────────────────

    /**
     * @return MCP_Tools
     */
    private function mcp_tools(): MCP_Tools {
        if ( ! $this->mcp_tools instanceof MCP_Tools ) {
            $this->mcp_tools = new MCP_Tools();
        }
        return $this->mcp_tools;
    }

    /**
     * @return Agent_Skills_Registry
     */
    private function skills_registry(): Agent_Skills_Registry {
        if ( ! $this->skills_registry instanceof Agent_Skills_Registry ) {
            $this->skills_registry = new Agent_Skills_Registry();
        }
        return $this->skills_registry;
    }
}
