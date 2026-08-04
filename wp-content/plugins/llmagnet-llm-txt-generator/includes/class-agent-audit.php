<?php
/**
 * Agent-Ready Audit & Score (agent-readiness-spec Feature 1 + scoring §)
 *
 * A 7-domain sampled audit producing an Agent-Ready Score (0–100) and an
 * "Agent Ready" pass/fail flag. Features 2–8 of the spec are its
 * remediation actions; checks whose fix features are not built yet report
 * a `manual` / "coming soon" fix status.
 *
 * ## Check registry (E3-1)
 *
 * Each check entry (see {@see Agent_Audit::get_checks()}):
 * - `id`          (string)   snake_case check id.
 * - `domain`      (string)   one of the DOMAINS keys.
 * - `severity`    (string)   'blocking' | 'important' | 'nice' (3 / 2 / 1 points).
 * - `label`       (string)   Human-readable check name.
 * - `description` (string)   What the check verifies.
 * - `fix`         (array)    [ 'type' => 'auto'|'toggle'|'manual',
 *                              'action' => string|null  (apply_fix() action id),
 *                              'available' => bool      (false = "coming soon"),
 *                              'label' => string ].
 * - `test`        (callable) ( Agent_Audit_Context $ctx ): array — returns a
 *                            Finding fragment [ 'status' => pass|warn|fail|
 *                            handled_externally|not_applicable, 'details' => string ].
 *
 * ## Surfaces
 *
 * - REST (manage_options): POST `/agent-audit/run`, GET `/agent-audit/result`,
 *   POST `/agent-audit/fix`, GET|POST `/agent-audit/settings`.
 * - Weekly cron `llmagnet_agent_audit_weekly` (re-runs only after a first
 *   manual run has been stored — everything in this spec defaults OFF).
 * - WP-CLI `wp llmagnet agent-audit [--json]`.
 * - MCP tool `get_agent_readiness` via the `llmagnet_mcp_tools` filter (E3-10).
 *
 * Results are stored in the Phase C audit options
 * (`llmagnet_agent_audit_last` / `llmagnet_agent_audit_history`).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Phase F feature classes whose options/constants the fix actions and the
// settings REST endpoints use. Guarded so the audit keeps working no matter
// which subset Main has wired up (FA lane — gate wires instantiation).
foreach ( [ 'markdown-endpoints', 'link-headers', 'open-graph', 'security-headers', 'indexnow', 'schemamap' ] as $llmagnet_audit_dep ) {
    if ( file_exists( __DIR__ . '/class-' . $llmagnet_audit_dep . '.php' ) ) {
        require_once __DIR__ . '/class-' . $llmagnet_audit_dep . '.php';
    }
}
unset( $llmagnet_audit_dep );

/**
 * Lazy, memoized context shared by all checks in one audit pass.
 *
 * HTTP fetches happen at most once per URL per run, and a single-check
 * re-run (the Fix flow) only fetches what that check actually reads.
 */
class Agent_Audit_Context {

    /** @var array<string, array> Memoized HTTP responses keyed by URL. */
    private $responses = [];

    /** @var array<int, array>|null Sampled representative pages. */
    private $samples;

    /** @var array|null Robots_Txt::get_status() result. */
    private $robots_status;

    /** @var Robots_Txt */
    private $robots_txt;

    /**
     * @param Robots_Txt|null $robots_txt Robots.txt integration.
     */
    public function __construct( $robots_txt = null ) {
        $this->robots_txt = $robots_txt instanceof Robots_Txt ? $robots_txt : new Robots_Txt();
    }

    /**
     * Fetch a URL once (GET), normalized shape.
     *
     * @param string $url  URL to fetch.
     * @param array  $args Extra wp_remote_get args.
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function fetch( string $url, array $args = [] ): array {
        $cache_key = $url . '|' . md5( wp_json_encode( $args ) );
        if ( isset( $this->responses[ $cache_key ] ) ) {
            return $this->responses[ $cache_key ];
        }

        $response = wp_remote_get(
            $url,
            array_merge(
                [
                    'timeout'     => 5,
                    'redirection' => 3,
                    'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
                    'user-agent'  => 'LLMagnet-Agent-Audit/1.0; ' . home_url( '/' ),
                ],
                $args
            )
        );

        if ( is_wp_error( $response ) ) {
            $normalized = [ 'status' => 0, 'headers' => [], 'body' => '', 'error' => $response->get_error_message() ];
        } else {
            $headers = wp_remote_retrieve_headers( $response );
            $headers = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
            $normalized = [
                'status'  => (int) wp_remote_retrieve_response_code( $response ),
                'headers' => array_change_key_case( $headers, CASE_LOWER ),
                'body'    => substr( (string) wp_remote_retrieve_body( $response ), 0, 300000 ),
            ];
        }

        $this->responses[ $cache_key ] = $normalized;
        return $normalized;
    }

    /**
     * Lightweight HEAD request with no redirect following (for stable_urls).
     *
     * @param string $url URL.
     * @return array{status: int, location: string}
     */
    public function head_no_redirect( string $url ): array {
        $response = wp_remote_head(
            $url,
            [
                'timeout'     => 5,
                'redirection' => 0,
                'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
            ]
        );
        if ( is_wp_error( $response ) ) {
            return [ 'status' => 0, 'location' => '' ];
        }
        return [
            'status'   => (int) wp_remote_retrieve_response_code( $response ),
            'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
        ];
    }

    /**
     * Up to 4 sampled representative pages: home, latest post, a page, a product.
     *
     * @return array<int, array{type: string, url: string, status: int, headers: array, body: string}>
     */
    public function get_samples(): array {
        if ( null !== $this->samples ) {
            return $this->samples;
        }

        $targets = [ [ 'type' => 'home', 'url' => home_url( '/' ) ] ];

        foreach ( [ 'post', 'page' ] as $type ) {
            $posts = get_posts(
                [
                    'numberposts' => 1,
                    'post_type'   => $type,
                    'post_status' => 'publish',
                    'has_password' => false,
                ]
            );
            if ( ! empty( $posts ) ) {
                $targets[] = [ 'type' => $type, 'url' => get_permalink( $posts[0] ) ];
            }
        }

        if ( $this->woo_active() ) {
            $products = get_posts(
                [
                    'numberposts' => 1,
                    'post_type'   => 'product',
                    'post_status' => 'publish',
                ]
            );
            if ( ! empty( $products ) ) {
                $targets[] = [ 'type' => 'product', 'url' => get_permalink( $products[0] ) ];
            }
        }

        $samples = [];
        foreach ( $targets as $target ) {
            $samples[] = array_merge( $target, $this->fetch( $target['url'] ) );
        }

        $this->samples = $samples;
        return $samples;
    }

    /**
     * The home-page sample.
     *
     * @return array
     */
    public function get_home(): array {
        $samples = $this->get_samples();
        return $samples[0];
    }

    /**
     * Singular (non-home) samples only.
     *
     * @return array<int, array>
     */
    public function get_singular_samples(): array {
        return array_values(
            array_filter(
                $this->get_samples(),
                static function ( $sample ) {
                    return 'home' !== $sample['type'];
                }
            )
        );
    }

    /**
     * Robots_Txt::get_status(), memoized.
     *
     * @return array
     */
    public function robots_status(): array {
        if ( null === $this->robots_status ) {
            $this->robots_status = $this->robots_txt->get_status();
        }
        return $this->robots_status;
    }

    /**
     * Whether a feature toggle is on.
     *
     * @param string $feature Toggle key.
     * @return bool
     */
    public function feature_enabled( string $feature ): bool {
        return Agent_Readiness_Options::is_feature_enabled( $feature );
    }

    /**
     * Whether WooCommerce is active.
     *
     * @return bool
     */
    public function woo_active(): bool {
        return class_exists( 'WooCommerce', false ) || function_exists( 'wc_get_product' );
    }
}

/**
 * Agent-Ready audit engine, REST backend, cron, CLI, and MCP tool.
 */
class Agent_Audit {

    /**
     * REST namespace (existing analytics namespace per spec F1).
     */
    const REST_NAMESPACE = 'llm-analytics/v1';

    /**
     * Weekly cron hook.
     */
    const CRON_HOOK = 'llmagnet_agent_audit_weekly';

    /**
     * Option holding the WebMCP add_to_cart opt-in (spec §4.3 — default OFF).
     */
    const OPTION_ADD_TO_CART = 'llmagnet_webmcp_add_to_cart';

    /**
     * Domain ids => label + weight (scoring §).
     *
     * @var array<string, array{label: string, weight: int}>
     */
    const DOMAINS = [
        'discovery'     => [ 'label' => 'Agent Discovery & Identity', 'weight' => 25 ],
        'content'       => [ 'label' => 'Content Machine-Readability', 'weight' => 25 ],
        'html'          => [ 'label' => 'HTML Foundations', 'weight' => 15 ],
        'security'      => [ 'label' => 'Security', 'weight' => 15 ],
        'performance'   => [ 'label' => 'Performance', 'weight' => 10 ],
        'accessibility' => [ 'label' => 'Accessibility', 'weight' => 5 ],
        'wellknown'     => [ 'label' => 'Well-Known URIs & Protocol Discovery', 'weight' => 5 ],
    ];

    /**
     * Checks that must ALL pass (or be handled_externally) for the
     * "Agent Ready" boolean flag (scoring §).
     *
     * @var string[]
     */
    const FLAG_CHECKS = [
        'robots_ai_rules',
        'agent_card',
        'agent_skills',
        'jsonld_present',
        'llms_txt',
        'markdown_endpoints',
        'webmcp',
        'stable_urls',
        'https',
    ];

    /** @var Generator|null */
    private $generator;

    /** @var Robots_Txt|null */
    private $robots_txt;

    /** @var MCP_Tools|null */
    private $mcp_tools;

    /** @var Agent_Skills_Registry|null */
    private $skills_registry;

    /**
     * Dependencies are optional; missing ones are lazily constructed.
     *
     * @param Generator|null             $generator       Generator instance.
     * @param Robots_Txt|null            $robots_txt      Robots.txt integration.
     * @param MCP_Tools|null             $mcp_tools       Shared MCP tool registry.
     * @param Agent_Skills_Registry|null $skills_registry Public skills registry.
     */
    public function __construct( $generator = null, $robots_txt = null, $mcp_tools = null, $skills_registry = null ) {
        $this->generator       = $generator;
        $this->robots_txt      = $robots_txt;
        $this->mcp_tools       = $mcp_tools;
        $this->skills_registry = $skills_registry;
    }

    /**
     * Wire hooks: REST, weekly cron, WP-CLI, MCP registry filter.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Weekly re-audit (E3-3). Scheduled unconditionally; the callback
        // no-ops until a first audit has been stored, so a site that never
        // engaged with the feature does zero work.
        add_action( self::CRON_HOOK, [ $this, 'run_weekly_audit' ] );
        add_action( 'init', [ $this, 'maybe_schedule_weekly' ], 30 );

        // MCP tool get_agent_readiness (E3-10, scoring §).
        add_filter( 'llmagnet_mcp_tools', [ $this, 'register_mcp_tool' ], 10, 2 );

        // WP-CLI command (E3-3), guarded.
        if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
            \WP_CLI::add_command( 'llmagnet agent-audit', [ $this, 'cli_run' ] );
        }
    }

    // ── Check registry (E3-1) ─────────────────────────────────────────────────

    /**
     * All check definitions, filterable via `llmagnet_agent_audit_checks`.
     *
     * @return array<string, array>
     */
    public function get_checks(): array {
        $checks = array_merge(
            $this->checks_discovery(),
            $this->checks_content(),
            $this->checks_html(),
            $this->checks_security(),
            $this->checks_performance(),
            $this->checks_accessibility(),
            $this->checks_wellknown()
        );

        /**
         * Filter the Agent-Ready audit check registry.
         *
         * @param array       $checks Check definitions keyed by check id.
         * @param Agent_Audit $audit  Audit instance.
         */
        $checks = apply_filters( 'llmagnet_agent_audit_checks', $checks, $this );

        return is_array( $checks ) ? $checks : [];
    }

    /**
     * Fix descriptor helpers.
     *
     * @param string      $action Fix action id (apply_fix()).
     * @param string      $label  Button label.
     * @return array
     */
    private function fix_toggle( string $action, string $label ): array {
        return [ 'type' => 'toggle', 'action' => $action, 'available' => true, 'label' => $label ];
    }

    /**
     * Fix descriptor for an automated fix.
     *
     * @param string $action Fix action id.
     * @param string $label  Button label.
     * @return array
     */
    private function fix_auto( string $action, string $label ): array {
        return [ 'type' => 'auto', 'action' => $action, 'available' => true, 'label' => $label ];
    }

    /**
     * Fix descriptor for a manual (or not-yet-built, "coming soon") fix.
     *
     * @param string $label       Guidance label.
     * @param bool   $coming_soon Whether a future LLMagnet feature will automate it.
     * @return array
     */
    private function fix_manual( string $label, bool $coming_soon = false ): array {
        return [ 'type' => 'manual', 'action' => null, 'available' => false, 'label' => $label, 'coming_soon' => $coming_soon ];
    }

    /**
     * Domain 1 — Agent Discovery & Identity (weight 25).
     *
     * @return array<string, array>
     */
    private function checks_discovery(): array {
        $d = 'discovery';
        return [
            'robots_valid' => [
                'id' => 'robots_valid', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'robots.txt resolves and is parseable', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Agents start at robots.txt. It must resolve with HTTP 200 and contain valid directives.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Check your host/SEO plugin robots.txt configuration', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/robots.txt' ) );
                    if ( 200 !== $res['status'] ) {
                        return [ 'status' => 'fail', 'details' => sprintf( 'robots.txt returned HTTP %d.', $res['status'] ) ];
                    }
                    $parseable = (bool) preg_match( '/^\s*(user-agent|allow|disallow|sitemap|crawl-delay|llms-txt|content-signal|#)/im', $res['body'] );
                    return $parseable
                        ? [ 'status' => 'pass', 'details' => 'robots.txt resolves (200) and contains recognizable directives.' ]
                        : [ 'status' => 'warn', 'details' => 'robots.txt resolves but contains no recognizable directives.' ];
                },
            ],
            'robots_ai_rules' => [
                'id' => 'robots_ai_rules', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'Named AI crawler rules in robots.txt', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Explicit User-agent groups for AI crawlers (GPTBot, ClaudeBot, PerplexityBot, …) signal an agent-aware site.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_robots_ai_rules', __( 'Enable AI crawler rules', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $status = $ctx->robots_status();
                    $rules  = isset( $status['agent_rules'] ) ? $status['agent_rules'] : [];
                    if ( ! empty( $rules['filter_active'] ) || ! empty( $rules['physical_has_block'] ) ) {
                        return [ 'status' => 'pass', 'details' => 'LLMagnet agent-rules block is active in robots.txt.' ];
                    }
                    $res = $ctx->fetch( home_url( '/robots.txt' ) );
                    if ( preg_match( '/user-agent:\s*(gptbot|claudebot|perplexitybot|google-extended|ccbot)/i', $res['body'] ) ) {
                        return [ 'status' => 'handled_externally', 'details' => 'Named AI crawler rules found in robots.txt (managed outside LLMagnet).' ];
                    }
                    return [ 'status' => 'fail', 'details' => 'No named AI crawler rules found in robots.txt.' ];
                },
            ],
            'robots_content_signal' => [
                'id' => 'robots_content_signal', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Content-Signal directives', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Content-Signal (search / ai-input / ai-train) states your content-usage preferences for AI systems.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_content_signal', __( 'Enable Content-Signal', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/robots.txt' ) );
                    if ( false !== stripos( $res['body'], 'content-signal:' ) ) {
                        return [ 'status' => 'pass', 'details' => 'Content-Signal directive present in robots.txt.' ];
                    }
                    return [ 'status' => 'fail', 'details' => 'No Content-Signal directive in robots.txt.' ];
                },
            ],
            'sitemap_lastmod' => [
                'id' => 'sitemap_lastmod', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'XML sitemap with lastmod', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A sitemap with <lastmod> lets agents prioritize fresh content.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use an SEO plugin sitemap (Yoast, Rank Math, …) that emits lastmod', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $robots  = $ctx->robots_status();
                    $sitemap = isset( $robots['agent_rules']['sitemap_url'] ) ? $robots['agent_rules']['sitemap_url'] : home_url( '/wp-sitemap.xml' );
                    $res     = $ctx->fetch( $sitemap );
                    $owner   = Seo_Plugin_Detector::get_owner_label( 'sitemap' );
                    if ( 200 !== $res['status'] ) {
                        return [ 'status' => 'fail', 'details' => sprintf( 'Sitemap %s returned HTTP %d.', $sitemap, $res['status'] ) ];
                    }
                    if ( false !== stripos( $res['body'], '<lastmod' ) ) {
                        return $owner
                            ? [ 'status' => 'handled_externally', 'details' => sprintf( 'Sitemap with lastmod served by %s.', $owner ) ]
                            : [ 'status' => 'pass', 'details' => 'Sitemap resolves and includes <lastmod>.' ];
                    }
                    return [ 'status' => 'warn', 'details' => 'Sitemap resolves but entries carry no <lastmod>. Core wp-sitemap.xml omits it — an SEO plugin sitemap fixes this.' ];
                },
            ],
            'llms_txt' => [
                'id' => 'llms_txt', 'domain' => $d, 'severity' => 'important',
                'label' => __( '/llms.txt resolves', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'llms.txt is the entry point AI agents use to understand your site\'s content.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_auto( 'regenerate_llms_txt', __( 'Generate llms.txt', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/llms.txt' ) );
                    return 200 === $res['status'] && '' !== trim( $res['body'] )
                        ? [ 'status' => 'pass', 'details' => 'llms.txt resolves with content.' ]
                        : [ 'status' => 'fail', 'details' => sprintf( 'llms.txt returned HTTP %d.', $res['status'] ) ];
                },
            ],
            'llms_full_txt' => [
                'id' => 'llms_full_txt', 'domain' => $d, 'severity' => 'nice',
                'label' => __( '/llms-full.txt resolves', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'llms-full.txt carries full content for agents that want everything in one fetch.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_auto( 'generate_llms_full', __( 'Generate llms-full.txt', 'llmagnet-llm-txt-generator' ) ),
                'test' => function () {
                    return file_exists( trailingslashit( ABSPATH ) . 'llms-full.txt' )
                        ? [ 'status' => 'pass', 'details' => 'llms-full.txt exists.' ]
                        : [ 'status' => 'fail', 'details' => 'llms-full.txt has not been generated.' ];
                },
            ],
            'agent_card' => [
                'id' => 'agent_card', 'domain' => $d, 'severity' => 'important',
                'label' => __( '/.well-known/agent-card.json', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'An A2A-style agent card describing the site\'s machine-usable capabilities.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_well_known', __( 'Enable well-known endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => $this->well_known_test( '.well-known/agent-card.json' ),
            ],
            'agent_skills' => [
                'id' => 'agent_skills', 'domain' => $d, 'severity' => 'important',
                'label' => __( '/.well-known/agent-skills', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A machine-readable index of the skills agents can use on this site.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_well_known', __( 'Enable well-known endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => $this->well_known_test( '.well-known/agent-skills' ),
            ],
            'mcp_card' => [
                'id' => 'mcp_card', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'MCP server card discoverable', 'llmagnet-llm-txt-generator' ),
                'description' => __( '/.well-known/mcp.json advertises this site\'s MCP server to agents.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_well_known', __( 'Enable well-known endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => $this->well_known_test( '.well-known/mcp.json' ),
            ],
            'link_headers' => [
                'id' => 'link_headers', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Link headers advertise machine resources', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'HTTP Link headers pointing at llms.txt / the agent card make discovery free for any client.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_link_headers', __( 'Enable Link headers', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    $link = isset( $home['headers']['link'] ) ? ( is_array( $home['headers']['link'] ) ? implode( ', ', $home['headers']['link'] ) : (string) $home['headers']['link'] ) : '';
                    if ( false !== stripos( $link, 'llms-txt' ) || false !== stripos( $link, 'agent-card' ) ) {
                        return [ 'status' => 'pass', 'details' => 'Link header advertises machine resources.' ];
                    }
                    return [ 'status' => 'fail', 'details' => 'No llms-txt / agent-card Link header on the homepage response.' ];
                },
            ],
            'api_catalog' => [
                'id' => 'api_catalog', 'domain' => $d, 'severity' => 'nice',
                'label' => __( '/.well-known/api-catalog (RFC 9727)', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'An API catalog listing the site\'s programmatic interfaces.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Publish an api-catalog document on your host', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/.well-known/api-catalog' ) );
                    return 200 === $res['status']
                        ? [ 'status' => 'pass', 'details' => 'api-catalog resolves.' ]
                        : [ 'status' => 'fail', 'details' => 'No /.well-known/api-catalog (optional, RFC 9727).' ];
                },
            ],
            'dns_aid' => [
                'id' => 'dns_aid', 'domain' => $d, 'severity' => 'nice',
                'label' => __( '_agents DNS records', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'SVCB/HTTPS records under _agents.{domain} for DNS-level agent discovery.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Add _agents records at your DNS host', 'llmagnet-llm-txt-generator' ) ),
                'test' => function () {
                    $host = wp_parse_url( home_url(), PHP_URL_HOST );
                    if ( ! is_string( $host ) || '' === $host || ! function_exists( 'dns_get_record' ) ) {
                        return [ 'status' => 'not_applicable', 'details' => 'DNS lookup unavailable in this environment.' ];
                    }
                    $records = @dns_get_record( '_agents.' . $host, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- lookup of a likely-missing record.
                    return ! empty( $records )
                        ? [ 'status' => 'pass', 'details' => '_agents DNS record found.' ]
                        : [ 'status' => 'fail', 'details' => 'No _agents DNS record (emerging standard, optional).' ];
                },
            ],
        ];
    }

    /**
     * Domain 2 — Content Machine-Readability (weight 25).
     *
     * @return array<string, array>
     */
    private function checks_content(): array {
        $d = 'content';
        return [
            'jsonld_present' => [
                'id' => 'jsonld_present', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'JSON-LD on sampled pages', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Structured data is how agents understand entities on your pages.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use the Schema JSON-LD wizard', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $with = 0;
                    $total = 0;
                    foreach ( $ctx->get_samples() as $sample ) {
                        if ( 200 !== $sample['status'] ) {
                            continue;
                        }
                        $total++;
                        if ( false !== stripos( $sample['body'], 'application/ld+json' ) ) {
                            $with++;
                        }
                    }
                    if ( 0 === $total ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No pages could be sampled.' ];
                    }
                    if ( $with === $total ) {
                        return [ 'status' => 'pass', 'details' => sprintf( 'JSON-LD found on all %d sampled pages.', $total ) ];
                    }
                    return $with > 0
                        ? [ 'status' => 'warn', 'details' => sprintf( 'JSON-LD on %d of %d sampled pages.', $with, $total ) ]
                        : [ 'status' => 'fail', 'details' => 'No JSON-LD found on any sampled page.' ];
                },
            ],
            'schema_valid' => [
                'id' => 'schema_valid', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'Schema validates', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Invalid structured data is worse than none — agents discard it.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Run a scan on the Schema JSON-LD page', 'llmagnet-llm-txt-generator' ) ),
                'test' => function () {
                    $history = get_option( 'llmagnet_schema_scan_history', [] );
                    $last    = is_array( $history ) && ! empty( $history ) ? end( $history ) : null;
                    if ( ! is_array( $last ) || ! isset( $last['overall_score'] ) ) {
                        return [ 'status' => 'warn', 'details' => 'No schema scan has been run yet — run one from the Schema JSON-LD page.' ];
                    }
                    $score = (int) $last['overall_score'];
                    if ( $score >= 70 ) {
                        return [ 'status' => 'pass', 'details' => sprintf( 'Last schema scan scored %d/100.', $score ) ];
                    }
                    return [ 'status' => 'fail', 'details' => sprintf( 'Last schema scan scored %d/100 — fix the reported issues.', $score ) ];
                },
            ],
            'og_tags' => [
                'id' => 'og_tags', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'Open Graph tags', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'OG tags give agents (and link unfurlers) a canonical title/description/image per page.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_og_fill', __( 'Enable Open Graph fill', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    return $this->sampled_presence_test( $ctx, '/property=["\']og:(title|description)/i', 'Open Graph tags', 'open_graph' );
                },
            ],
            'markdown_endpoints' => [
                'id' => 'markdown_endpoints', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Markdown endpoints (.md)', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Per-page markdown lets agents read clean content without HTML parsing.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_markdown_endpoints', __( 'Enable markdown endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $singulars = $ctx->get_singular_samples();
                    if ( empty( $singulars ) ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No singular page available to sample.' ];
                    }
                    $url = untrailingslashit( $singulars[0]['url'] ) . '.md';
                    $res = $ctx->fetch( $url );
                    $type = isset( $res['headers']['content-type'] ) ? (string) ( is_array( $res['headers']['content-type'] ) ? reset( $res['headers']['content-type'] ) : $res['headers']['content-type'] ) : '';
                    if ( 200 === $res['status'] && false !== stripos( $type, 'markdown' ) ) {
                        return [ 'status' => 'pass', 'details' => 'Permalink .md suffix serves text/markdown.' ];
                    }
                    return [ 'status' => 'fail', 'details' => 'Permalink .md suffix does not serve markdown.' ];
                },
            ],
            'schemamap' => [
                'id' => 'schemamap', 'domain' => $d, 'severity' => 'important',
                'label' => __( '/schemamap.xml', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'An index mapping URLs to their published schema types.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_well_known', __( 'Enable well-known endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/schemamap.xml' ) );
                    return 200 === $res['status'] && false !== stripos( $res['body'], '<schemamap' )
                        ? [ 'status' => 'pass', 'details' => 'schemamap.xml resolves.' ]
                        : [ 'status' => 'fail', 'details' => 'No /schemamap.xml.' ];
                },
            ],
            'feeds_linked' => [
                'id' => 'feeds_linked', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'RSS feed published and linked', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Feeds remain the most widely supported machine-readable content channel.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_auto( 'restore_feed_links', __( 'Restore automatic feed links', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    return ( false !== stripos( $home['body'], 'application/rss+xml' ) )
                        ? [ 'status' => 'pass', 'details' => 'RSS alternate link present in the homepage head.' ]
                        : [ 'status' => 'fail', 'details' => 'No <link rel="alternate" type="application/rss+xml"> on the homepage.' ];
                },
            ],
            'feed_resolves' => [
                'id' => 'feed_resolves', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'RSS feed resolves with valid XML', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'The main feed URL must return HTTP 200 with parseable RSS/Atom XML, not an error page.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Check feed-disabling plugins or theme feed overrides', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( get_feed_link() );
                    if ( 200 !== $res['status'] ) {
                        return [ 'status' => 'fail', 'details' => sprintf( 'Main feed returned HTTP %d.', $res['status'] ) ];
                    }
                    $is_feed = (bool) preg_match( '/<(rss|feed|rdf:RDF)[\s>]/i', $res['body'] );
                    return $is_feed
                        ? [ 'status' => 'pass', 'details' => 'Main feed resolves with RSS/Atom XML.' ]
                        : [ 'status' => 'fail', 'details' => 'Main feed returns 200 but the body is not RSS/Atom XML.' ];
                },
            ],
            'ssr_content' => [
                'id' => 'ssr_content', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'Content renders without JavaScript', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Most agents read raw HTML; content that only exists after JS execution is invisible to them.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Ensure your theme/builder server-renders content', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $thin = [];
                    foreach ( $ctx->get_samples() as $sample ) {
                        if ( 200 !== $sample['status'] ) {
                            continue;
                        }
                        $body = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $sample['body'] );
                        $text = trim( wp_strip_all_tags( (string) $body ) );
                        if ( strlen( $text ) < 400 ) {
                            $thin[] = $sample['url'];
                        }
                    }
                    return empty( $thin )
                        ? [ 'status' => 'pass', 'details' => 'All sampled pages carry substantial server-rendered text.' ]
                        : [ 'status' => 'fail', 'details' => 'Thin server-rendered content on: ' . implode( ', ', $thin ) ];
                },
            ],
            'stable_urls' => [
                'id' => 'stable_urls', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'Stable internal URLs', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Sampled internal links must not 404 or chain through multiple redirects.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Fix broken links / redirect chains', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    $host = wp_parse_url( home_url(), PHP_URL_HOST );
                    preg_match_all( '/<a\s[^>]*href=["\']([^"\'#?]+)["\']/i', $home['body'], $matches );
                    $links = [];
                    foreach ( array_unique( $matches[1] ) as $href ) {
                        if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
                            $href = home_url( $href );
                        }
                        $link_host = wp_parse_url( $href, PHP_URL_HOST );
                        if ( $link_host === $host && false === strpos( $href, 'wp-admin' ) && false === strpos( $href, 'wp-login' ) ) {
                            $links[] = $href;
                        }
                        if ( count( $links ) >= 3 ) {
                            break;
                        }
                    }
                    if ( empty( $links ) ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No internal links found on the homepage to sample.' ];
                    }
                    $problems = [];
                    foreach ( $links as $link ) {
                        $first = $ctx->head_no_redirect( $link );
                        if ( $first['status'] >= 400 || 0 === $first['status'] ) {
                            $problems[] = sprintf( '%s → HTTP %d', $link, $first['status'] );
                            continue;
                        }
                        if ( $first['status'] >= 300 && '' !== $first['location'] ) {
                            $second = $ctx->head_no_redirect( $first['location'] );
                            if ( $second['status'] >= 300 && $second['status'] < 400 ) {
                                $problems[] = sprintf( '%s → redirect chain (>1 hop)', $link );
                            } elseif ( $second['status'] >= 400 || 0 === $second['status'] ) {
                                $problems[] = sprintf( '%s → redirects to HTTP %d', $link, $second['status'] );
                            }
                        }
                    }
                    return empty( $problems )
                        ? [ 'status' => 'pass', 'details' => sprintf( '%d sampled internal links resolve cleanly.', count( $links ) ) ]
                        : [ 'status' => 'fail', 'details' => implode( '; ', $problems ) ];
                },
            ],
            'breadcrumb_jsonld' => [
                'id' => 'breadcrumb_jsonld', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'BreadcrumbList markup', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Breadcrumb structured data tells agents where a page sits in your site hierarchy.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use the Schema JSON-LD wizard', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    foreach ( $ctx->get_singular_samples() as $sample ) {
                        if ( false !== stripos( $sample['body'], 'BreadcrumbList' ) ) {
                            $owner = Seo_Plugin_Detector::get_owner_label( 'breadcrumb_schema' );
                            return $owner
                                ? [ 'status' => 'handled_externally', 'details' => sprintf( 'BreadcrumbList emitted by %s.', $owner ) ]
                                : [ 'status' => 'pass', 'details' => 'BreadcrumbList markup found on sampled pages.' ];
                        }
                    }
                    return [ 'status' => 'fail', 'details' => 'No BreadcrumbList markup on sampled pages.' ];
                },
            ],
            'canonical' => [
                'id' => 'canonical', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'rel="canonical" on sampled pages', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Canonical URLs prevent agents from indexing duplicate variants.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_auto( 'enable_canonical_fill', __( 'Enable canonical fill', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    return $this->sampled_presence_test( $ctx, '/rel=["\']canonical["\']/i', 'Canonical link', 'canonical', true );
                },
            ],
            'canonical_self' => [
                'id' => 'canonical_self', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Canonical points at the page itself', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A canonical that points at a different host (or a stale URL) silently hands your content authority elsewhere.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Review canonical settings in your SEO plugin', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
                    $checked   = 0;
                    $offsite   = [];
                    foreach ( $ctx->get_singular_samples() as $sample ) {
                        if ( 200 !== $sample['status'] ) {
                            continue;
                        }
                        if ( ! preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $sample['body'], $m )
                            && ! preg_match( '/<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\']canonical["\']/i', $sample['body'], $m ) ) {
                            continue;
                        }
                        $checked++;
                        $canonical_host = wp_parse_url( $m[1], PHP_URL_HOST );
                        if ( is_string( $canonical_host ) && '' !== $canonical_host && $canonical_host !== $home_host ) {
                            $offsite[] = $sample['url'];
                        }
                    }
                    if ( 0 === $checked ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No canonical links found to verify (see the canonical presence check).' ];
                    }
                    return empty( $offsite )
                        ? [ 'status' => 'pass', 'details' => sprintf( 'Canonical URLs on %d sampled pages stay on this host.', $checked ) ]
                        : [ 'status' => 'fail', 'details' => 'Canonical points off-site on: ' . implode( ', ', $offsite ) ];
                },
            ],
        ];
    }

    /**
     * Domain 3 — HTML Foundations (weight 15).
     *
     * @return array<string, array>
     */
    private function checks_html(): array {
        $d = 'html';
        return [
            'doctype' => [
                'id' => 'doctype', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'HTML5 doctype', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A standards-mode doctype keeps parsers (human and agent) predictable.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    return preg_match( '/^\s*<!doctype html/i', $home['body'] )
                        ? [ 'status' => 'pass', 'details' => 'HTML5 doctype present.' ]
                        : [ 'status' => 'fail', 'details' => 'Homepage does not start with <!doctype html>.' ];
                },
            ],
            'html_lang' => [
                'id' => 'html_lang', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'html lang attribute', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'The lang attribute tells agents which language model/locale to apply.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme-level fix (language_attributes filter)', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    return preg_match( '/<html[^>]*\slang=["\'][a-z]{2}/i', $home['body'] )
                        ? [ 'status' => 'pass', 'details' => 'html lang attribute present.' ]
                        : [ 'status' => 'fail', 'details' => 'No lang attribute on <html>.' ];
                },
            ],
            'charset' => [
                'id' => 'charset', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Charset declared early', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'meta charset must appear in the first 1024 bytes for correct decoding.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    return false !== stripos( substr( $home['body'], 0, 1024 ), 'charset' )
                        ? [ 'status' => 'pass', 'details' => 'Charset declared within the first 1024 bytes.' ]
                        : [ 'status' => 'fail', 'details' => 'No charset declaration in the first 1024 bytes.' ];
                },
            ],
            'viewport' => [
                'id' => 'viewport', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Viewport meta (zoomable)', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A viewport meta without user-scalable=no keeps pages usable for everyone.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    if ( ! preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $home['body'] ) ) {
                        return [ 'status' => 'fail', 'details' => 'No viewport meta tag.' ];
                    }
                    if ( preg_match( '/user-scalable\s*=\s*(no|0)/i', $home['body'] ) ) {
                        return [ 'status' => 'warn', 'details' => 'Viewport present but disables user scaling (user-scalable=no).' ];
                    }
                    return [ 'status' => 'pass', 'details' => 'Viewport meta present and zoomable.' ];
                },
            ],
            'page_title' => [
                'id' => 'page_title', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'Unique page titles', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Every page needs a non-empty, unique <title>.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use an SEO plugin for title management', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $titles = [];
                    foreach ( $ctx->get_samples() as $sample ) {
                        if ( 200 !== $sample['status'] ) {
                            continue;
                        }
                        if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $sample['body'], $m ) ) {
                            $titles[] = trim( wp_strip_all_tags( $m[1] ) );
                        } else {
                            $titles[] = '';
                        }
                    }
                    if ( in_array( '', $titles, true ) ) {
                        return [ 'status' => 'fail', 'details' => 'A sampled page has an empty or missing <title>.' ];
                    }
                    if ( count( $titles ) !== count( array_unique( $titles ) ) ) {
                        return [ 'status' => 'warn', 'details' => 'Duplicate <title> values across sampled pages.' ];
                    }
                    $owner = Seo_Plugin_Detector::get_owner_label( 'titles' );
                    return $owner
                        ? [ 'status' => 'handled_externally', 'details' => sprintf( 'Titles present and unique (managed by %s).', $owner ) ]
                        : [ 'status' => 'pass', 'details' => 'Titles present and unique on all sampled pages.' ];
                },
            ],
            'meta_description' => [
                'id' => 'meta_description', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Meta description', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Meta descriptions give agents a page summary without reading the body.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use an SEO plugin for meta descriptions', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    return $this->sampled_presence_test( $ctx, '/<meta[^>]+name=["\']description["\']/i', 'Meta description', 'meta_description' );
                },
            ],
            'favicon' => [
                'id' => 'favicon', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Favicon / site icon', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A site icon is part of basic site identity for agents and browsers.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Set a Site Icon under Appearance → Customize', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    if ( has_site_icon() ) {
                        return [ 'status' => 'pass', 'details' => 'WordPress Site Icon is set.' ];
                    }
                    $home = $ctx->get_home();
                    return preg_match( '/<link[^>]+rel=["\'][^"\']*icon[^"\']*["\']/i', $home['body'] )
                        ? [ 'status' => 'pass', 'details' => 'Favicon link found in homepage head.' ]
                        : [ 'status' => 'fail', 'details' => 'No site icon / favicon detected.' ];
                },
            ],
            'theme_color' => [
                'id' => 'theme_color', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'theme-color meta', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'theme-color rounds out machine-readable site identity.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    return preg_match( '/<meta[^>]+name=["\']theme-color["\']/i', $home['body'] )
                        ? [ 'status' => 'pass', 'details' => 'theme-color meta present.' ]
                        : [ 'status' => 'fail', 'details' => 'No theme-color meta tag (optional).' ];
                },
            ],
            'heading_hierarchy' => [
                'id' => 'heading_hierarchy', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Heading hierarchy', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A single H1 with an orderly heading outline is how agents segment a page.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme/content-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $issues = [];
                    foreach ( $ctx->get_singular_samples() as $sample ) {
                        if ( 200 !== $sample['status'] ) {
                            continue;
                        }
                        $h1_count = preg_match_all( '/<h1[\s>]/i', $sample['body'] );
                        if ( 0 === $h1_count ) {
                            $issues[] = sprintf( '%s: no H1', $sample['url'] );
                        } elseif ( $h1_count > 1 ) {
                            $issues[] = sprintf( '%s: %d H1 elements', $sample['url'], $h1_count );
                        }
                    }
                    return empty( $issues )
                        ? [ 'status' => 'pass', 'details' => 'Sampled pages each have exactly one H1.' ]
                        : [ 'status' => 'warn', 'details' => implode( '; ', $issues ) ];
                },
            ],
            'soft_404' => [
                'id' => 'soft_404', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Real 404 responses', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Missing pages must answer HTTP 404, not a soft 200, or agents index junk.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Check theme 404 template / redirect plugins', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $probe = home_url( '/llmagnet-audit-404-probe-' . substr( md5( (string) wp_rand() ), 0, 10 ) . '/' );
                    $res   = $ctx->fetch( $probe );
                    if ( 404 === $res['status'] ) {
                        return [ 'status' => 'pass', 'details' => 'Unknown URLs return HTTP 404.' ];
                    }
                    return [ 'status' => 'fail', 'details' => sprintf( 'Unknown URL returned HTTP %d instead of 404 (soft-404).', $res['status'] ) ];
                },
            ],
            'indexnow' => [
                'id' => 'indexnow', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'IndexNow', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'IndexNow pings get fresh content to AI-backed search engines within minutes.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_indexnow', __( 'Enable IndexNow', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $owner = Seo_Plugin_Detector::get_owner_label( 'indexnow' );
                    if ( $owner ) {
                        return [ 'status' => 'handled_externally', 'details' => sprintf( 'IndexNow handled by %s.', $owner ) ];
                    }
                    if ( $ctx->feature_enabled( 'indexnow' ) ) {
                        return [ 'status' => 'pass', 'details' => 'LLMagnet IndexNow is enabled.' ];
                    }
                    return [ 'status' => 'fail', 'details' => 'No IndexNow integration detected.' ];
                },
            ],
        ];
    }

    /**
     * Domain 4 — Security (weight 15).
     *
     * @return array<string, array>
     */
    private function checks_security(): array {
        $d = 'security';
        return [
            'https' => [
                'id' => 'https', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'HTTPS', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Agents (and browsers) treat plain-HTTP sites as untrustworthy.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Enable HTTPS at your host', 'llmagnet-llm-txt-generator' ) ),
                'test' => function () {
                    return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME )
                        ? [ 'status' => 'pass', 'details' => 'Site URL uses HTTPS.' ]
                        : [ 'status' => 'fail', 'details' => 'Site URL is not HTTPS.' ];
                },
            ],
            'hsts' => $this->header_check( $d, 'hsts', 'strict-transport-security', __( 'Strict-Transport-Security', 'llmagnet-llm-txt-generator' ), 'important', 'enable_hsts' ),
            'nosniff' => $this->header_check( $d, 'nosniff', 'x-content-type-options', __( 'X-Content-Type-Options', 'llmagnet-llm-txt-generator' ), 'important', 'enable_security_headers' ),
            'referrer_policy' => $this->header_check( $d, 'referrer_policy', 'referrer-policy', __( 'Referrer-Policy', 'llmagnet-llm-txt-generator' ), 'important', 'enable_security_headers' ),
            'permissions_policy' => $this->header_check( $d, 'permissions_policy', 'permissions-policy', __( 'Permissions-Policy', 'llmagnet-llm-txt-generator' ), 'nice', 'enable_security_headers' ),
            'csp' => [
                'id' => 'csp', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Content-Security-Policy', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'A CSP (even report-only) demonstrates a hardened content pipeline.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_csp_report', __( 'Enable CSP report-only starter', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $headers = $ctx->get_home()['headers'];
                    if ( isset( $headers['content-security-policy'] ) || isset( $headers['content-security-policy-report-only'] ) ) {
                        return [ 'status' => 'pass', 'details' => 'CSP header present.' ];
                    }
                    return [ 'status' => 'warn', 'details' => 'No Content-Security-Policy header (optional but recommended).' ];
                },
            ],
            'mixed_content' => [
                'id' => 'mixed_content', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'No mixed content', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'http:// subresources on an HTTPS page break the security model.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Update hardcoded http:// asset URLs', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    if ( 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
                        return [ 'status' => 'not_applicable', 'details' => 'Site is not HTTPS.' ];
                    }
                    $offenders = [];
                    foreach ( $ctx->get_samples() as $sample ) {
                        if ( preg_match( '/(src|href)=["\']http:\/\/[^"\']+\.(js|css|png|jpe?g|gif|webp|svg|woff2?)["\']/i', $sample['body'] ) ) {
                            $offenders[] = $sample['url'];
                        }
                    }
                    return empty( $offenders )
                        ? [ 'status' => 'pass', 'details' => 'No mixed-content subresources on sampled pages.' ]
                        : [ 'status' => 'fail', 'details' => 'Mixed content on: ' . implode( ', ', $offenders ) ];
                },
            ],
            'security_txt' => [
                'id' => 'security_txt', 'domain' => $d, 'severity' => 'important',
                'label' => __( '/.well-known/security.txt (RFC 9116)', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'security.txt tells researchers (and agents) how to report vulnerabilities.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_well_known', __( 'Enable well-known endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => $this->well_known_test( '.well-known/security.txt' ),
            ],
            'web_bot_auth' => [
                'id' => 'web_bot_auth', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Web Bot Auth', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Cryptographic bot authentication (signed agents) is rolling out at the CDN level.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Requires host/CDN support (e.g. Cloudflare signed agents)', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $headers = $ctx->get_home()['headers'];
                    $cdn     = isset( $headers['cf-ray'] ) || isset( $headers['server'] ) && false !== stripos( (string) ( is_array( $headers['server'] ) ? reset( $headers['server'] ) : $headers['server'] ), 'cloudflare' );
                    return $cdn
                        ? [ 'status' => 'warn', 'details' => 'Cloudflare detected — Web Bot Auth may be available; verification support cannot be confirmed remotely.' ]
                        : [ 'status' => 'warn', 'details' => 'Web Bot Auth support could not be detected; ask your host/CDN about signed-agent verification.' ];
                },
            ],
            'cookie_attributes' => [
                'id' => 'cookie_attributes', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Cookie attributes', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Front-end cookies should carry Secure and SameSite attributes.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Review plugins that set front-end cookies', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $headers = $ctx->get_home()['headers'];
                    if ( ! isset( $headers['set-cookie'] ) ) {
                        return [ 'status' => 'pass', 'details' => 'Homepage sets no cookies for anonymous visitors.' ];
                    }
                    $cookies = is_array( $headers['set-cookie'] ) ? $headers['set-cookie'] : [ $headers['set-cookie'] ];
                    foreach ( $cookies as $cookie ) {
                        if ( false === stripos( (string) $cookie, 'samesite' ) ) {
                            return [ 'status' => 'warn', 'details' => 'A front-end cookie is missing the SameSite attribute.' ];
                        }
                    }
                    return [ 'status' => 'pass', 'details' => 'Front-end cookies carry SameSite attributes.' ];
                },
            ],
        ];
    }

    /**
     * Domain 5 — Performance (weight 10). Flag-level only in v1.
     *
     * @return array<string, array>
     */
    private function checks_performance(): array {
        $d = 'performance';
        return [
            'compression' => [
                'id' => 'compression', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Response compression', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'gzip/brotli keeps agent fetches (and crawl budgets) cheap.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Enable compression at your host', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/' ), [ 'decompress' => false, 'headers' => [ 'Accept-Encoding' => 'gzip, deflate, br' ] ] );
                    $enc = isset( $res['headers']['content-encoding'] ) ? (string) ( is_array( $res['headers']['content-encoding'] ) ? reset( $res['headers']['content-encoding'] ) : $res['headers']['content-encoding'] ) : '';
                    return '' !== $enc
                        ? [ 'status' => 'pass', 'details' => sprintf( 'Responses compressed (%s).', $enc ) ]
                        : [ 'status' => 'fail', 'details' => 'No Content-Encoding on the homepage response.' ];
                },
            ],
            'http2' => [
                'id' => 'http2', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'HTTP/2 or newer', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Multiplexed transport speeds up multi-resource fetches.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Host-level configuration', 'llmagnet-llm-txt-generator' ) ),
                'test' => function () {
                    // The WP HTTP API does not expose the negotiated protocol
                    // version; report honestly rather than guessing.
                    return [ 'status' => 'not_applicable', 'details' => 'Transport protocol cannot be verified from this server; check with your host or an external tool.' ];
                },
            ],
            'cache_control' => [
                'id' => 'cache_control', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Static asset caching', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Static assets should be cacheable so repeat fetches are free.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Configure cache headers at your host', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    if ( ! preg_match( '/(?:src|href)=["\']([^"\']+\.(?:css|js)(?:\?[^"\']*)?)["\']/i', $home['body'], $m ) ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No static asset found on the homepage to sample.' ];
                    }
                    $asset = $m[1];
                    if ( 0 === strpos( $asset, '//' ) ) {
                        $asset = ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'https:' : 'http:' ) . $asset;
                    } elseif ( 0 === strpos( $asset, '/' ) ) {
                        $asset = home_url( $asset );
                    }
                    $res = $ctx->fetch( $asset );
                    $cc  = isset( $res['headers']['cache-control'] ) ? (string) ( is_array( $res['headers']['cache-control'] ) ? reset( $res['headers']['cache-control'] ) : $res['headers']['cache-control'] ) : '';
                    return ( preg_match( '/max-age=([1-9]\d*)/', $cc ) || isset( $res['headers']['expires'] ) )
                        ? [ 'status' => 'pass', 'details' => sprintf( 'Static asset cacheable (Cache-Control: %s).', $cc ) ]
                        : [ 'status' => 'warn', 'details' => 'Sampled static asset has no caching headers.' ];
                },
            ],
            'image_formats' => [
                'id' => 'image_formats', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Modern image formats', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'WebP/AVIF keep pages light for agents and users alike.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use an image-optimization plugin', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $total  = 0;
                    $modern = 0;
                    foreach ( $ctx->get_samples() as $sample ) {
                        preg_match_all( '/<img\s[^>]*src=["\']([^"\']+)["\']/i', $sample['body'], $m );
                        foreach ( $m[1] as $src ) {
                            $total++;
                            if ( preg_match( '/\.(webp|avif)(\?|$)/i', $src ) ) {
                                $modern++;
                            }
                        }
                    }
                    if ( 0 === $total ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No images on sampled pages.' ];
                    }
                    $pct = (int) round( 100 * $modern / $total );
                    return $pct >= 50
                        ? [ 'status' => 'pass', 'details' => sprintf( '%d%% of sampled images use modern formats.', $pct ) ]
                        : [ 'status' => 'warn', 'details' => sprintf( 'Only %d%% of %d sampled images use WebP/AVIF.', $pct, $total ) ];
                },
            ],
            'render_blocking' => [
                'id' => 'render_blocking', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Render-blocking scripts', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Synchronous head scripts slow first paint for users and rendering agents.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Defer non-critical scripts (performance plugin)', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    $head = '';
                    if ( preg_match( '#<head\b.*?</head>#is', $home['body'], $m ) ) {
                        $head = $m[0];
                    }
                    preg_match_all( '/<script\b[^>]*src=[^>]*>/i', $head, $scripts );
                    $blocking = 0;
                    foreach ( $scripts[0] as $tag ) {
                        if ( ! preg_match( '/\b(async|defer|type=["\']module["\'])/i', $tag ) ) {
                            $blocking++;
                        }
                    }
                    if ( $blocking <= 3 ) {
                        return [ 'status' => 'pass', 'details' => sprintf( '%d render-blocking head scripts.', $blocking ) ];
                    }
                    return $blocking <= 8
                        ? [ 'status' => 'warn', 'details' => sprintf( '%d render-blocking head scripts.', $blocking ) ]
                        : [ 'status' => 'fail', 'details' => sprintf( '%d render-blocking head scripts.', $blocking ) ];
                },
            ],
        ];
    }

    /**
     * Domain 6 — Accessibility (weight 5).
     *
     * @return array<string, array>
     */
    private function checks_accessibility(): array {
        $d = 'accessibility';
        return [
            'image_alt' => [
                'id' => 'image_alt', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Image ALT text', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'ALT text is how agents (and screen readers) understand images.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Use the LLMagnet ALT-text manager on the dashboard', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $missing = 0;
                    $total   = 0;
                    foreach ( $ctx->get_samples() as $sample ) {
                        preg_match_all( '/<img\s[^>]*>/i', $sample['body'], $m );
                        foreach ( $m[0] as $tag ) {
                            $total++;
                            if ( ! preg_match( '/\salt=["\'][^"\']+["\']/i', $tag ) ) {
                                $missing++;
                            }
                        }
                    }
                    if ( 0 === $total ) {
                        return [ 'status' => 'not_applicable', 'details' => 'No images on sampled pages.' ];
                    }
                    return 0 === $missing
                        ? [ 'status' => 'pass', 'details' => sprintf( 'All %d sampled images have ALT text.', $total ) ]
                        : [ 'status' => 'fail', 'details' => sprintf( '%d of %d sampled images are missing ALT text.', $missing, $total ) ];
                },
            ],
            'form_labels' => [
                'id' => 'form_labels', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'Form labels', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Inputs need labels or aria-labels for agents to fill forms correctly.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme/form-plugin-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $unlabeled = 0;
                    foreach ( $ctx->get_samples() as $sample ) {
                        preg_match_all( '/<(?:input|textarea|select)\b[^>]*>/i', $sample['body'], $m );
                        foreach ( $m[0] as $tag ) {
                            if ( preg_match( '/type=["\'](hidden|submit|button|checkbox|radio)["\']/i', $tag ) ) {
                                continue;
                            }
                            $has_label = preg_match( '/(aria-label|aria-labelledby|placeholder|title)=/i', $tag );
                            $has_id    = preg_match( '/\sid=["\']([^"\']+)["\']/i', $tag, $idm );
                            if ( ! $has_label && ( ! $has_id || false === stripos( $sample['body'], 'for="' . $idm[1] . '"' ) ) ) {
                                $unlabeled++;
                            }
                        }
                    }
                    return 0 === $unlabeled
                        ? [ 'status' => 'pass', 'details' => 'No unlabeled form fields on sampled pages.' ]
                        : [ 'status' => 'warn', 'details' => sprintf( '%d form fields without an associated label.', $unlabeled ) ];
                },
            ],
            'landmarks' => [
                'id' => 'landmarks', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Landmark regions', 'llmagnet-llm-txt-generator' ),
                'description' => __( '<main>/<nav> landmarks help agents segment the page.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Theme-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    $has_main = preg_match( '/<main[\s>]|role=["\']main["\']/i', $home['body'] );
                    $has_nav  = preg_match( '/<nav[\s>]|role=["\']navigation["\']/i', $home['body'] );
                    if ( $has_main && $has_nav ) {
                        return [ 'status' => 'pass', 'details' => 'main + nav landmarks present.' ];
                    }
                    return [ 'status' => 'warn', 'details' => sprintf( 'Missing landmark(s): %s.', implode( ', ', array_filter( [ $has_main ? '' : 'main', $has_nav ? '' : 'nav' ] ) ) ) ];
                },
            ],
            'link_text' => [
                'id' => 'link_text', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Descriptive link text', 'llmagnet-llm-txt-generator' ),
                'description' => __( '"Click here" links carry zero information for agents scanning anchors.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Content-level fix', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $generic = 0;
                    foreach ( $ctx->get_samples() as $sample ) {
                        $generic += preg_match_all( '/<a\s[^>]*>\s*(click here|read more|here|learn more)\s*<\/a>/i', $sample['body'] );
                    }
                    return 0 === $generic
                        ? [ 'status' => 'pass', 'details' => 'No generic link text found.' ]
                        : [ 'status' => 'warn', 'details' => sprintf( '%d generic ("click here"-style) links on sampled pages.', $generic ) ];
                },
            ],
            'overlay_plugin' => [
                'id' => 'overlay_plugin', 'domain' => $d, 'severity' => 'blocking',
                'label' => __( 'No accessibility overlay', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Overlay widgets interfere with assistive tech and automated agents.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Remove the overlay; fix accessibility at the source', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $home = $ctx->get_home();
                    if ( preg_match( '/(accessibe|userway|equalweb|audioeye|truabilities|maxaccess)/i', $home['body'], $m ) ) {
                        return [ 'status' => 'fail', 'details' => sprintf( 'Accessibility overlay detected (%s).', strtolower( $m[1] ) ) ];
                    }
                    return [ 'status' => 'pass', 'details' => 'No accessibility overlay detected.' ];
                },
            ],
        ];
    }

    /**
     * Domain 7 — Well-Known URIs & Protocol Discovery (weight 5).
     *
     * @return array<string, array>
     */
    private function checks_wellknown(): array {
        $d = 'wellknown';
        return [
            'change_password' => [
                'id' => 'change_password', 'domain' => $d, 'severity' => 'nice',
                'label' => __( '/.well-known/change-password', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Password managers and agents use this redirect to find the reset flow.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_well_known', __( 'Enable well-known endpoints', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    if ( Well_Known::physical_file_exists( '.well-known/change-password' ) ) {
                        return [ 'status' => 'handled_externally', 'details' => 'Served by a physical file on the host.' ];
                    }
                    return $ctx->feature_enabled( 'well_known' )
                        ? [ 'status' => 'pass', 'details' => 'change-password redirect is active.' ]
                        : [ 'status' => 'fail', 'details' => 'change-password redirect is off (well-known endpoints disabled).' ];
                },
            ],
            'webmcp' => [
                'id' => 'webmcp', 'domain' => $d, 'severity' => 'important',
                'label' => __( 'WebMCP tools', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'navigator.modelContext tools make the site directly operable by browser agents.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_toggle( 'enable_webmcp', __( 'Enable WebMCP', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    if ( ! $ctx->feature_enabled( 'webmcp' ) ) {
                        return [ 'status' => 'fail', 'details' => 'WebMCP is disabled.' ];
                    }
                    $home = $ctx->get_home();
                    return ( false !== stripos( $home['body'], 'webmcp' ) )
                        ? [ 'status' => 'pass', 'details' => 'WebMCP loader enqueued on the homepage.' ]
                        : [ 'status' => 'warn', 'details' => 'WebMCP is enabled but the loader script was not found on the sampled homepage (plan gating or a caching layer may be hiding it).' ];
                },
            ],
            'oauth_discovery' => [
                'id' => 'oauth_discovery', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'OAuth discovery metadata', 'llmagnet-llm-txt-generator' ),
                'description' => __( '/.well-known/oauth-authorization-server for agent auth flows.', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Requires an OAuth provider plugin', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    $res = $ctx->fetch( home_url( '/.well-known/oauth-authorization-server' ) );
                    return 200 === $res['status']
                        ? [ 'status' => 'pass', 'details' => 'OAuth discovery metadata resolves.' ]
                        : [ 'status' => 'fail', 'details' => 'No OAuth discovery metadata (optional).' ];
                },
            ],
            'commerce_x402' => [
                'id' => 'commerce_x402', 'domain' => $d, 'severity' => 'nice',
                'label' => __( 'Agentic commerce (x402/MPP)', 'llmagnet-llm-txt-generator' ),
                'description' => __( 'Machine-payable endpoints for agent-initiated purchases (emerging).', 'llmagnet-llm-txt-generator' ),
                'fix' => $this->fix_manual( __( 'Emerging standard — no WordPress implementation yet', 'llmagnet-llm-txt-generator' ) ),
                'test' => function ( Agent_Audit_Context $ctx ) {
                    if ( ! $ctx->woo_active() || ! self::has_commerce_plan() ) {
                        return [ 'status' => 'not_applicable', 'details' => 'Requires WooCommerce and a Plus/Enterprise plan.' ];
                    }
                    return [ 'status' => 'fail', 'details' => 'No x402/MPP support detected (emerging standard, flag only).' ];
                },
            ],
        ];
    }

    /**
     * Shared test builder for router-served well-known paths.
     *
     * @param string $path Well-known path.
     * @return callable
     */
    private function well_known_test( string $path ): callable {
        return function ( Agent_Audit_Context $ctx ) use ( $path ) {
            if ( Well_Known::physical_file_exists( $path ) ) {
                return [ 'status' => 'handled_externally', 'details' => sprintf( '/%s is served by a physical file on the host.', $path ) ];
            }
            $res = $ctx->fetch( home_url( '/' . $path ) );
            if ( 200 === $res['status'] && '' !== trim( $res['body'] ) ) {
                return [ 'status' => 'pass', 'details' => sprintf( '/%s resolves.', $path ) ];
            }
            $hint = $ctx->feature_enabled( 'well_known' )
                ? sprintf( '/%s returned HTTP %d despite the toggle being on — check permalinks/MCP settings.', $path, $res['status'] )
                : sprintf( '/%s is not served (well-known endpoints disabled).', $path );
            return [ 'status' => 'fail', 'details' => $hint ];
        };
    }

    /**
     * Shared presence-regex test over sampled pages with SEO-plugin awareness.
     *
     * @param Agent_Audit_Context $ctx        Context.
     * @param string              $pattern    Regex tested against each sampled body.
     * @param string              $thing      Human label for evidence strings.
     * @param string              $capability Seo_Plugin_Detector capability key.
     * @param bool                $singular_only Only test singular samples.
     * @return array
     */
    private function sampled_presence_test( Agent_Audit_Context $ctx, string $pattern, string $thing, string $capability, bool $singular_only = false ): array {
        $samples = $singular_only ? $ctx->get_singular_samples() : $ctx->get_samples();
        $with    = 0;
        $total   = 0;
        foreach ( $samples as $sample ) {
            if ( 200 !== $sample['status'] ) {
                continue;
            }
            $total++;
            if ( preg_match( $pattern, $sample['body'] ) ) {
                $with++;
            }
        }
        if ( 0 === $total ) {
            return [ 'status' => 'not_applicable', 'details' => 'No pages could be sampled.' ];
        }
        $owner = Seo_Plugin_Detector::get_owner_label( $capability );
        if ( $with === $total ) {
            return $owner
                ? [ 'status' => 'handled_externally', 'details' => sprintf( '%s present on all sampled pages (handled by %s).', $thing, $owner ) ]
                : [ 'status' => 'pass', 'details' => sprintf( '%s present on all %d sampled pages.', $thing, $total ) ];
        }
        if ( $with > 0 ) {
            return [ 'status' => 'warn', 'details' => sprintf( '%s on %d of %d sampled pages.', $thing, $with, $total ) ];
        }
        return [ 'status' => 'fail', 'details' => sprintf( 'No %s found on sampled pages.', strtolower( $thing ) ) ];
    }

    /**
     * Header-presence check builder (F8.1 — fixes via Security_Headers).
     *
     * @param string $domain     Domain id.
     * @param string $id         Check id.
     * @param string $header     Lowercase header name.
     * @param string $label      Header display name.
     * @param string $severity   Severity.
     * @param string $fix_action apply_fix() action id ('' = manual).
     * @return array
     */
    private function header_check( string $domain, string $id, string $header, string $label, string $severity, string $fix_action = '' ): array {
        return [
            'id' => $id, 'domain' => $domain, 'severity' => $severity,
            /* translators: %s: HTTP header name. */
            'label' => sprintf( __( '%s header', 'llmagnet-llm-txt-generator' ), $label ),
            /* translators: %s: HTTP header name. */
            'description' => sprintf( __( 'The %s security header should be present on front-end responses.', 'llmagnet-llm-txt-generator' ), $label ),
            'fix' => '' !== $fix_action
                ? $this->fix_toggle( $fix_action, __( 'Enable security headers', 'llmagnet-llm-txt-generator' ) )
                : $this->fix_manual( __( 'Configure this header at your host', 'llmagnet-llm-txt-generator' ) ),
            'test' => function ( Agent_Audit_Context $ctx ) use ( $header, $label ) {
                $headers = $ctx->get_home()['headers'];
                if ( isset( $headers[ $header ] ) ) {
                    return [ 'status' => 'pass', 'details' => sprintf( '%s present (served by host or another plugin).', $label ) ];
                }
                if ( 'strict-transport-security' === $header && 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
                    return [ 'status' => 'not_applicable', 'details' => 'HSTS only applies to HTTPS sites.' ];
                }
                return [ 'status' => 'fail', 'details' => sprintf( 'No %s header on the homepage response.', $label ) ];
            },
        ];
    }

    // ── Audit runner + scoring (E3-2, E3-4) ───────────────────────────────────

    /**
     * Run the full audit, store, and return the result.
     *
     * @return array Full result (summary + findings).
     */
    public function run_audit(): array {
        // The sampled audit makes ~15 loopback HTTP requests; on slow hosts
        // that can brush against a low max_execution_time.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled function on some hosts.
        }

        $ctx      = new Agent_Audit_Context( $this->robots_txt() );
        $findings = [];

        foreach ( $this->get_checks() as $id => $check ) {
            $findings[ $id ] = $this->evaluate_check( $check, $ctx );
        }

        $result = $this->build_result( $findings );
        $this->store_result( $result );

        return $result;
    }

    /**
     * Evaluate a single check definition into a Finding.
     *
     * @param array               $check Check definition.
     * @param Agent_Audit_Context $ctx   Context.
     * @return array Finding.
     */
    private function evaluate_check( array $check, Agent_Audit_Context $ctx ): array {
        try {
            $outcome = call_user_func( $check['test'], $ctx );
        } catch ( \Throwable $e ) {
            $outcome = [ 'status' => 'warn', 'details' => 'Check failed to execute: ' . $e->getMessage() ];
        }

        if ( ! is_array( $outcome ) || ! isset( $outcome['status'] ) ) {
            $outcome = [ 'status' => 'warn', 'details' => 'Check returned no result.' ];
        }

        return [
            'id'          => $check['id'],
            'domain'      => $check['domain'],
            'severity'    => $check['severity'],
            'label'       => $check['label'],
            'description' => $check['description'],
            'status'      => $outcome['status'],
            'details'     => isset( $outcome['details'] ) ? (string) $outcome['details'] : '',
            'fix'         => $check['fix'],
        ];
    }

    /**
     * Re-run one check (used by the fix flow).
     *
     * @param string $check_id Check id.
     * @return array|\WP_Error Finding.
     */
    public function run_check( string $check_id ) {
        $checks = $this->get_checks();
        if ( ! isset( $checks[ $check_id ] ) ) {
            return new \WP_Error( 'unknown_check', 'Unknown check id.', [ 'status' => 404 ] );
        }
        $ctx = new Agent_Audit_Context( $this->robots_txt() );
        return $this->evaluate_check( $checks[ $check_id ], $ctx );
    }

    /**
     * Score findings into the stored result shape (scoring §).
     *
     * Points: blocking 3 / important 2 / nice 1. pass + handled_externally
     * earn full points, warn earns half, fail earns zero; not_applicable is
     * removed from the denominator. Domain score = earned/possible × 100;
     * total = Σ domain × weight.
     *
     * @param array<string, array> $findings Findings keyed by check id.
     * @return array Full result.
     */
    public function build_result( array $findings ): array {
        $points = [ 'blocking' => 3, 'important' => 2, 'nice' => 1 ];

        $domains = [];
        foreach ( self::DOMAINS as $domain_id => $meta ) {
            $earned   = 0.0;
            $possible = 0.0;
            $checked  = 0;
            foreach ( $findings as $finding ) {
                if ( $finding['domain'] !== $domain_id || 'not_applicable' === $finding['status'] ) {
                    continue;
                }
                $weight    = isset( $points[ $finding['severity'] ] ) ? $points[ $finding['severity'] ] : 1;
                $possible += $weight;
                $checked++;
                if ( in_array( $finding['status'], [ 'pass', 'handled_externally' ], true ) ) {
                    $earned += $weight;
                } elseif ( 'warn' === $finding['status'] ) {
                    $earned += $weight / 2;
                }
            }
            $domains[ $domain_id ] = [
                'id'     => $domain_id,
                'label'  => $meta['label'],
                'weight' => $meta['weight'],
                'score'  => $possible > 0 ? (int) round( 100 * $earned / $possible ) : 100,
                'checks' => $checked,
            ];
        }

        $score = 0.0;
        foreach ( $domains as $domain ) {
            $score += $domain['score'] * $domain['weight'] / 100;
        }

        $counts = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'handled_externally' => 0, 'not_applicable' => 0 ];
        foreach ( $findings as $finding ) {
            if ( isset( $counts[ $finding['status'] ] ) ) {
                $counts[ $finding['status'] ]++;
            }
        }

        // "Agent Ready" boolean flag (scoring §) — all flag checks must be
        // pass or handled_externally.
        $agent_ready = true;
        $flag_status = [];
        foreach ( self::FLAG_CHECKS as $flag_check ) {
            $status = isset( $findings[ $flag_check ] ) ? $findings[ $flag_check ]['status'] : 'fail';
            $flag_status[ $flag_check ] = $status;
            if ( ! in_array( $status, [ 'pass', 'handled_externally' ], true ) ) {
                $agent_ready = false;
            }
        }

        return [
            'generated_at' => time(),
            'score'        => (int) round( $score ),
            'agent_ready'  => $agent_ready,
            'flag_checks'  => $flag_status,
            'domains'      => array_values( $domains ),
            'counts'       => $counts,
            'findings'     => array_values( $findings ),
        ];
    }

    /**
     * Persist the result: full to _last, summary appended to _history (12 max).
     *
     * @param array $result Full result.
     * @return void
     */
    private function store_result( array $result ): void {
        update_option( Agent_Readiness_Options::OPTION_AUDIT_LAST, $result, false );

        $history = get_option( Agent_Readiness_Options::OPTION_AUDIT_HISTORY, [] );
        if ( ! is_array( $history ) ) {
            $history = [];
        }
        $history[] = $this->summarize( $result );
        $history   = array_slice( $history, -12 );
        update_option( Agent_Readiness_Options::OPTION_AUDIT_HISTORY, $history, false );
    }

    /**
     * Overview-card-ready summary shape (E3-4) — no findings payload.
     *
     * @param array|null $result Full result; null reads the stored one.
     * @return array|null Summary, or null when no audit has run yet.
     */
    public function summarize( $result = null ) {
        if ( null === $result ) {
            $result = get_option( Agent_Readiness_Options::OPTION_AUDIT_LAST, [] );
        }
        if ( ! is_array( $result ) || ! isset( $result['score'] ) ) {
            return null;
        }
        return [
            'generated_at' => isset( $result['generated_at'] ) ? (int) $result['generated_at'] : 0,
            'score'        => (int) $result['score'],
            'agent_ready'  => ! empty( $result['agent_ready'] ),
            'domains'      => array_map(
                static function ( $domain ) {
                    return [
                        'id'     => $domain['id'],
                        'label'  => $domain['label'],
                        'weight' => $domain['weight'],
                        'score'  => $domain['score'],
                    ];
                },
                isset( $result['domains'] ) && is_array( $result['domains'] ) ? $result['domains'] : []
            ),
            'counts'       => isset( $result['counts'] ) ? $result['counts'] : [],
        ];
    }

    // ── Fixes (E3-3) ──────────────────────────────────────────────────────────

    /**
     * Whether the current install may apply fixes (Pro+ gate, server-side).
     *
     * @return bool
     */
    public static function can_fix(): bool {
        if ( ! function_exists( 'lltg_fs' ) ) {
            return false;
        }
        $fs = lltg_fs();
        return (bool) ( $fs->can_use_premium_code() || $fs->is_trial() );
    }

    /**
     * Whether the install is on a commerce-capable plan (Plus/Enterprise).
     *
     * @return bool
     */
    public static function has_commerce_plan(): bool {
        if ( ! function_exists( 'lltg_fs' ) ) {
            return false;
        }
        $fs = lltg_fs();
        return $fs->is_plan( 'plus' ) || $fs->is_plan( 'enterprise' );
    }

    /**
     * Apply an automated fix and re-run its check (E3-3 fix endpoint backend).
     *
     * @param string $check_id Check id from the registry.
     * @return array|\WP_Error [ 'finding' => Finding, 'summary' => summary ].
     */
    public function apply_fix( string $check_id ) {
        $checks = $this->get_checks();
        if ( ! isset( $checks[ $check_id ] ) ) {
            return new \WP_Error( 'unknown_check', 'Unknown check id.', [ 'status' => 404 ] );
        }

        $fix = $checks[ $check_id ]['fix'];
        if ( empty( $fix['available'] ) || empty( $fix['action'] ) ) {
            return new \WP_Error( 'fix_unavailable', 'This check has no automated fix.', [ 'status' => 400 ] );
        }
        if ( ! self::can_fix() ) {
            return new \WP_Error( 'upgrade_required', 'One-click fixes require a Pro plan.', [ 'status' => 403 ] );
        }

        switch ( $fix['action'] ) {
            case 'enable_robots_ai_rules':
                $this->set_readiness_toggle( 'robots_ai_rules', true );
                break;

            case 'enable_content_signal':
                // Robots AI rules carry the Content-Signal block.
                $this->set_readiness_toggle( 'robots_ai_rules', true );
                $signal            = $this->robots_txt()->get_content_signal_settings();
                $signal['enabled'] = true;
                update_option( Robots_Txt::OPTION_CONTENT_SIGNAL, $signal, false );
                break;

            case 'enable_well_known':
                $this->set_readiness_toggle( 'well_known', true );
                break;

            case 'enable_webmcp':
                $this->set_readiness_toggle( 'webmcp', true );
                break;

            case 'regenerate_llms_txt':
                $generator = $this->generator();
                if ( method_exists( $generator, 'start_batched_generation' ) ) {
                    $generator->start_batched_generation();
                } else {
                    $generator->generate_all();
                }
                break;

            case 'generate_llms_full':
                $this->generator()->generate_llms_full();
                break;

            // ── Phase F fix actions (FA-5) ────────────────────────────────────

            case 'enable_markdown_endpoints':
                $this->set_readiness_toggle( 'markdown_endpoints', true );
                break;

            case 'enable_link_headers':
                $this->set_readiness_toggle( 'link_headers', true );
                break;

            case 'enable_og_fill':
                $this->set_readiness_toggle( 'og_fill', true );
                break;

            case 'enable_canonical_fill':
                // Canonical rides the F7 class but has its own opt-in option.
                update_option( Open_Graph::OPTION_CANONICAL_FILL, true, false );
                break;

            case 'restore_feed_links':
                update_option( Link_Headers::OPTION_RESTORE_FEED_LINKS, true, false );
                // Apply immediately so the re-run check sees the feed links.
                add_theme_support( 'automatic-feed-links' );
                break;

            case 'enable_security_headers':
                $this->set_readiness_toggle( 'security_headers', true );
                break;

            case 'enable_hsts':
                $this->set_readiness_toggle( 'security_headers', true );
                $sh_settings         = Security_Headers::get_settings();
                $sh_settings['hsts'] = true; // includeSubDomains stays an explicit UI opt-in.
                update_option( Security_Headers::OPTION, $sh_settings, false );
                break;

            case 'enable_csp_report':
                $this->set_readiness_toggle( 'security_headers', true );
                $sh_settings               = Security_Headers::get_settings();
                $sh_settings['csp_report'] = true;
                update_option( Security_Headers::OPTION, $sh_settings, false );
                // Build the starter policy from homepage asset origins now
                // (stores into csp_policy; falls back to same-origin if empty).
                Security_Headers::generate_starter_csp();
                break;

            case 'enable_indexnow':
                if ( IndexNow::handled_externally() ) {
                    return new \WP_Error( 'handled_externally', 'IndexNow is already provided by another SEO plugin.', [ 'status' => 400 ] );
                }
                $this->set_readiness_toggle( 'indexnow', true );
                Agent_Readiness_Options::get_or_create_indexnow_key();
                break;

            default:
                return new \WP_Error( 'unknown_fix_action', 'Unknown fix action.', [ 'status' => 400 ] );
        }

        // Re-run only this check and refresh the stored result with it.
        $finding = $this->run_check( $check_id );
        if ( is_wp_error( $finding ) ) {
            return $finding;
        }

        $stored = get_option( Agent_Readiness_Options::OPTION_AUDIT_LAST, [] );
        if ( is_array( $stored ) && ! empty( $stored['findings'] ) ) {
            $findings = [];
            foreach ( $stored['findings'] as $existing ) {
                $findings[ $existing['id'] ] = $existing;
            }
            $findings[ $check_id ] = $finding;
            $result                = $this->build_result( $findings );
            update_option( Agent_Readiness_Options::OPTION_AUDIT_LAST, $result, false );
            $summary = $this->summarize( $result );
        } else {
            $summary = null;
        }

        return [
            'finding' => $finding,
            'summary' => $summary,
        ];
    }

    /**
     * Flip one toggle inside llmagnet_agent_readiness.
     *
     * @param string $feature Toggle key.
     * @param bool   $value   New value.
     * @return void
     */
    private function set_readiness_toggle( string $feature, bool $value ): void {
        $readiness = Agent_Readiness_Options::get( Agent_Readiness_Options::OPTION_READINESS );
        if ( ! is_array( $readiness ) ) {
            $readiness = [];
        }
        $readiness[ $feature ] = $value;
        update_option( Agent_Readiness_Options::OPTION_READINESS, Agent_Readiness_Options::sanitize_readiness( $readiness ), false );
    }

    // ── REST (E3-3, E3-9 settings) ────────────────────────────────────────────

    /**
     * Register the audit REST routes (manage_options).
     *
     * @return void
     */
    public function register_rest_routes(): void {
        $admin_permission = static function () {
            return current_user_can( 'manage_options' );
        };

        register_rest_route(
            self::REST_NAMESPACE,
            '/agent-audit/run',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_run' ],
                'permission_callback' => $admin_permission,
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/agent-audit/result',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_result' ],
                'permission_callback' => $admin_permission,
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/agent-audit/fix',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_fix' ],
                'permission_callback' => $admin_permission,
                'args'                => [
                    'check_id' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/agent-audit/settings',
            [
                [
                    'methods'             => 'GET',
                    'callback'            => [ $this, 'rest_get_settings' ],
                    'permission_callback' => $admin_permission,
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => [ $this, 'rest_update_settings' ],
                    'permission_callback' => $admin_permission,
                ],
            ]
        );
    }

    /**
     * POST /agent-audit/run handler.
     *
     * @return \WP_REST_Response
     */
    public function rest_run(): \WP_REST_Response {
        $result = $this->run_audit();
        return rest_ensure_response( $this->result_for_plan( $result ) );
    }

    /**
     * GET /agent-audit/result handler.
     *
     * @return \WP_REST_Response
     */
    public function rest_result(): \WP_REST_Response {
        $result = get_option( Agent_Readiness_Options::OPTION_AUDIT_LAST, [] );
        if ( ! is_array( $result ) || ! isset( $result['score'] ) ) {
            return rest_ensure_response( [ 'has_result' => false ] );
        }
        return rest_ensure_response( $this->result_for_plan( $result ) );
    }

    /**
     * POST /agent-audit/fix handler.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_fix( \WP_REST_Request $request ) {
        $outcome = $this->apply_fix( (string) $request->get_param( 'check_id' ) );
        if ( is_wp_error( $outcome ) ) {
            return $outcome;
        }
        return rest_ensure_response( $outcome );
    }

    /**
     * Shape the result per Freemius plan (free = teaser: score + counts only).
     *
     * @param array $result Full result.
     * @return array
     */
    private function result_for_plan( array $result ): array {
        $result['has_result'] = true;
        $result['can_fix']    = self::can_fix();
        $result['history']    = array_values( (array) get_option( Agent_Readiness_Options::OPTION_AUDIT_HISTORY, [] ) );

        if ( ! self::can_fix() ) {
            // Free teaser per the gating table: score + domain breakdown +
            // counts visible, findings locked.
            $result['findings'] = [];
            $result['locked']   = true;
        } else {
            $result['locked'] = false;
        }

        return $result;
    }

    /**
     * GET /agent-audit/settings — toggles + WebMCP info for the Agent Ready page.
     *
     * @return \WP_REST_Response
     */
    public function rest_get_settings(): \WP_REST_Response {
        $readiness = Agent_Readiness_Options::get( Agent_Readiness_Options::OPTION_READINESS );

        $webmcp_tools = [];
        foreach ( $this->skills_registry()->get_skills_for_surface( 'webmcp' ) as $skill ) {
            $webmcp_tools[] = [
                'id'          => $skill['id'],
                'title'       => $skill['title'],
                'description' => $skill['description'],
                'endpoint'    => $skill['endpoint'],
            ];
        }

        $security = Security_Headers::get_settings();
        unset( $security['csp_policy'] ); // Long string; UI edits flags only.

        return rest_ensure_response(
            [
                'toggles'            => is_array( $readiness ) ? $readiness : [],
                'content_signal'     => $this->robots_txt()->get_content_signal_settings(),
                'webmcp_add_to_cart' => (bool) get_option( self::OPTION_ADD_TO_CART, false ),
                'can_fix'            => self::can_fix(),
                'has_commerce_plan'  => self::has_commerce_plan(),
                'woo_active'         => class_exists( 'WooCommerce', false ) || function_exists( 'wc_get_product' ),
                'webmcp'             => [
                    'tools'       => $webmcp_tools,
                    'public_base' => rest_url( Agent_Skills_Registry::PUBLIC_REST_BASE ),
                ],
                // Phase F sub-options (FA-2/5/6/7/8).
                'markdown_conneg'        => Markdown_Endpoints::conneg_enabled(),
                'cache_plugin'           => Markdown_Endpoints::detect_cache_plugin(),
                'restore_feed_links'     => (bool) get_option( Link_Headers::OPTION_RESTORE_FEED_LINKS, false ),
                'canonical_fill'         => (bool) get_option( Open_Graph::OPTION_CANONICAL_FILL, false ),
                'meta_description_fill'  => (bool) get_option( Open_Graph::OPTION_METADESC_FILL, false ),
                'security_headers'       => $security,
                'indexnow'               => [
                    'handled_externally' => IndexNow::handled_externally(),
                    'owner'              => Seo_Plugin_Detector::get_owner_label( 'indexnow' ),
                    'key_url'            => Agent_Readiness_Options::is_feature_enabled( 'indexnow' )
                        ? home_url( '/' . Agent_Readiness_Options::get_or_create_indexnow_key() . '.txt' )
                        : null,
                    'log'                => IndexNow::get_log(),
                ],
            ]
        );
    }

    /**
     * POST /agent-audit/settings — write toggles (Pro+ server-side gate).
     *
     * Accepts: { toggles: {feature: bool}, content_signal: {…}, webmcp_add_to_cart: bool }.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_update_settings( \WP_REST_Request $request ) {
        if ( ! self::can_fix() ) {
            return new \WP_Error( 'upgrade_required', 'Changing agent-readiness settings requires a Pro plan.', [ 'status' => 403 ] );
        }

        $toggles = $request->get_param( 'toggles' );
        if ( is_array( $toggles ) ) {
            $readiness = Agent_Readiness_Options::get( Agent_Readiness_Options::OPTION_READINESS );
            $readiness = is_array( $readiness ) ? $readiness : [];
            foreach ( Agent_Readiness_Options::FEATURE_TOGGLES as $feature ) {
                if ( array_key_exists( $feature, $toggles ) ) {
                    $readiness[ $feature ] = (bool) $toggles[ $feature ];
                }
            }
            update_option( Agent_Readiness_Options::OPTION_READINESS, Agent_Readiness_Options::sanitize_readiness( $readiness ), false );
        }

        $signal_in = $request->get_param( 'content_signal' );
        if ( is_array( $signal_in ) ) {
            $signal = $this->robots_txt()->get_content_signal_settings();
            foreach ( [ 'enabled', 'search', 'ai_input', 'ai_train' ] as $key ) {
                if ( array_key_exists( $key, $signal_in ) ) {
                    $signal[ $key ] = (bool) $signal_in[ $key ];
                }
            }
            update_option( Robots_Txt::OPTION_CONTENT_SIGNAL, $signal, false );
        }

        $add_to_cart = $request->get_param( 'webmcp_add_to_cart' );
        if ( null !== $add_to_cart ) {
            update_option( self::OPTION_ADD_TO_CART, (bool) $add_to_cart, false );
        }

        // Phase F boolean sub-options (FA-2/5/6).
        $bool_options = [
            'markdown_conneg'       => Markdown_Endpoints::OPTION_CONNEG,
            'restore_feed_links'    => Link_Headers::OPTION_RESTORE_FEED_LINKS,
            'canonical_fill'        => Open_Graph::OPTION_CANONICAL_FILL,
            'meta_description_fill' => Open_Graph::OPTION_METADESC_FILL,
        ];
        foreach ( $bool_options as $param => $option ) {
            $value = $request->get_param( $param );
            if ( null !== $value ) {
                update_option( $option, (bool) $value, false );
            }
        }

        // Security-header flags (FA-7). csp_policy is regenerated, not edited.
        $security_in = $request->get_param( 'security_headers' );
        if ( is_array( $security_in ) ) {
            $security = Security_Headers::get_settings();
            $had_csp  = ! empty( $security['csp_report'] );
            foreach ( [ 'nosniff', 'referrer', 'permissions', 'hsts', 'hsts_subdomains', 'csp_report' ] as $key ) {
                if ( array_key_exists( $key, $security_in ) ) {
                    $security[ $key ] = (bool) $security_in[ $key ];
                }
            }
            update_option( Security_Headers::OPTION, $security, false );
            if ( ! $had_csp && ! empty( $security['csp_report'] ) && '' === trim( (string) $security['csp_policy'] ) ) {
                Security_Headers::generate_starter_csp();
            }
        }

        return $this->rest_get_settings();
    }

    // ── Cron (E3-3) ───────────────────────────────────────────────────────────

    /**
     * Schedule the weekly re-audit when missing.
     *
     * @return void
     */
    public function maybe_schedule_weekly(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
        }
    }

    /**
     * Weekly cron callback — re-runs only when a previous audit exists.
     *
     * @return void
     */
    public function run_weekly_audit(): void {
        $last = get_option( Agent_Readiness_Options::OPTION_AUDIT_LAST, [] );
        if ( ! is_array( $last ) || ! isset( $last['score'] ) ) {
            return; // Never run manually — stay idle (everything defaults OFF).
        }
        $this->run_audit();
    }

    /**
     * Clear the weekly schedule (for uninstall cleanup wiring).
     *
     * @return void
     */
    public static function clear_scheduled_events(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // ── WP-CLI (E3-3) ─────────────────────────────────────────────────────────

    /**
     * `wp llmagnet agent-audit [--json]` — run the audit from the CLI.
     *
     * @param array $args       Positional args (unused).
     * @param array $assoc_args Associative args.
     * @return void
     */
    public function cli_run( $args = [], $assoc_args = [] ) {
        $result = $this->run_audit();

        if ( isset( $assoc_args['json'] ) ) {
            \WP_CLI::line( (string) wp_json_encode( $result ) );
            return;
        }

        \WP_CLI::line( sprintf( 'Agent-Ready Score: %d/100', $result['score'] ) );
        \WP_CLI::line( sprintf( 'Agent Ready flag: %s', $result['agent_ready'] ? 'YES' : 'no' ) );
        foreach ( $result['domains'] as $domain ) {
            \WP_CLI::line( sprintf( '  %-40s %3d/100 (weight %d%%)', $domain['label'], $domain['score'], $domain['weight'] ) );
        }
        $fails = array_filter(
            $result['findings'],
            static function ( $finding ) {
                return 'fail' === $finding['status'];
            }
        );
        \WP_CLI::line( sprintf( '%d checks failing.', count( $fails ) ) );
        foreach ( $fails as $finding ) {
            \WP_CLI::line( sprintf( '  [%s/%s] %s — %s', $finding['domain'], $finding['severity'], $finding['id'], $finding['details'] ) );
        }
        \WP_CLI::success( 'Audit stored.' );
    }

    // ── MCP tool (E3-10) ──────────────────────────────────────────────────────

    /**
     * Register the `get_agent_readiness` tool on the shared MCP registry.
     *
     * @param array     $definitions Tool definitions.
     * @param MCP_Tools $registry    Registry instance.
     * @return array
     */
    public function register_mcp_tool( $definitions, $registry = null ) {
        if ( ! is_array( $definitions ) ) {
            return $definitions;
        }

        $definitions['get_agent_readiness'] = [
            'id'              => 'get_agent_readiness',
            'title'           => 'Get Agent Readiness',
            'description'     => 'Returns the latest Agent-Ready audit summary for this site: the 0-100 Agent-Ready Score, the boolean "agent ready" flag, per-domain scores (discovery, content machine-readability, HTML foundations, security, performance, accessibility, well-known URIs), and pass/warn/fail counts. Returns an error if no audit has been run yet — ask the site owner to run one from the Agent Ready admin page.',
            'annotations'     => [
                'title'           => 'Get Agent Readiness',
                'readOnlyHint'    => true,
                'destructiveHint' => false,
                'idempotentHint'  => true,
                'openWorldHint'   => false,
            ],
            'input_schema'    => [
                'type'                 => 'object',
                'properties'           => new \stdClass(),
                'additionalProperties' => false,
            ],
            'output_schema'   => [
                'type'       => 'object',
                'properties' => [
                    'generated_at' => [ 'type' => 'integer', 'description' => 'Unix timestamp of the audit run.' ],
                    'score'        => [ 'type' => 'integer', 'description' => 'Agent-Ready Score, 0-100.' ],
                    'agent_ready'  => [ 'type' => 'boolean', 'description' => 'Whether all Agent-Ready flag checks pass.' ],
                    'domains'      => [
                        'type'        => 'array',
                        'description' => 'Per-domain scores.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'Domain id.' ],
                                'label'  => [ 'type' => 'string', 'description' => 'Domain label.' ],
                                'weight' => [ 'type' => 'integer', 'description' => 'Domain weight (percent).' ],
                                'score'  => [ 'type' => 'integer', 'description' => 'Domain score, 0-100.' ],
                            ],
                        ],
                    ],
                    'counts'       => [ 'type' => 'object', 'description' => 'Finding counts by status (pass/warn/fail/handled_externally/not_applicable).' ],
                ],
            ],
            'scope'           => 'read',
            'public_eligible' => 'read',
            'plan'            => 'pro',
            'available'       => [ __CLASS__, 'can_fix' ],
            'permission'      => null,
            'execute'         => [ $this, 'tool_get_agent_readiness' ],
        ];

        return $definitions;
    }

    /**
     * get_agent_readiness executor.
     *
     * @return array|\WP_Error
     */
    public function tool_get_agent_readiness() {
        $summary = $this->summarize();
        if ( null === $summary ) {
            return new \WP_Error( 'no_audit', 'No Agent-Ready audit has been run yet. Run one from the Agent Ready admin page or via `wp llmagnet agent-audit`.' );
        }
        return $summary;
    }

    // ── Lazy dependencies ─────────────────────────────────────────────────────

    /**
     * @return Generator
     */
    private function generator(): Generator {
        if ( ! $this->generator instanceof Generator ) {
            $this->generator = new Generator();
        }
        return $this->generator;
    }

    /**
     * @return Robots_Txt
     */
    private function robots_txt(): Robots_Txt {
        if ( ! $this->robots_txt instanceof Robots_Txt ) {
            $this->robots_txt = new Robots_Txt();
        }
        return $this->robots_txt;
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
