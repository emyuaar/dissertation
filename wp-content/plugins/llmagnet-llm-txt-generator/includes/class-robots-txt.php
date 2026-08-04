<?php
/**
 * Robots.txt integration class
 *
 * Two independent mechanisms:
 *
 * 1. Legacy llms.txt reference (`llms-txt:` directive) — injected via the
 *    `robots_txt` filter or appended once to a physical robots.txt file.
 *    Behavior unchanged (agent-readiness-spec F2: "leave the legacy
 *    llms-txt line logic untouched").
 *
 * 2. Agent Rules managed block (agent-readiness-spec Feature 2) — named
 *    AI-crawler allow/deny groups + Content-Signal + Sitemap line, all
 *    contained between sentinel comments:
 *
 *        # BEGIN LLMagnet Agent Rules
 *        ...
 *        # END LLMagnet Agent Rules
 *
 *    Replace-not-append: every render/write first strips any existing
 *    sentinel block, so re-runs can never duplicate. Applies to BOTH the
 *    `robots_txt` filter path and physical-file writes.
 *
 *    Gated by Agent_Readiness_Options::is_feature_enabled('robots_ai_rules')
 *    (default OFF). When Seo_Plugin_Detector::owns_robots() reports another
 *    plugin owns robots.txt output, the filter path is disabled and only the
 *    explicit physical sentinel-block injection (REST, behind a UI confirm)
 *    is offered (F2 coexistence).
 *
 *    Bot list is sourced from Bot_Registry (dependency D5) — registry bot
 *    names map to their published robots.txt User-agent tokens. The
 *    final directives list is filterable via `llmagnet_ai_bot_directives`.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Bot_Registry' ) ) {
    require_once __DIR__ . '/class-bot-registry.php';
}

class Robots_Txt {

    /**
     * Sentinel opening the managed agent-rules block.
     */
    const AGENT_BLOCK_BEGIN = '# BEGIN LLMagnet Agent Rules';

    /**
     * Sentinel closing the managed agent-rules block.
     */
    const AGENT_BLOCK_END = '# END LLMagnet Agent Rules';

    /**
     * Per-bot allow/deny map option. Shape: directive key => 'allow'|'deny'.
     * Missing keys default to 'allow' (we are a visibility plugin — blocking
     * is opt-in per bot). Autoload off (written with update_option(..., false)).
     */
    const OPTION_AI_BOTS = 'llmagnet_robots_ai_bots';

    /**
     * Content-Signal switches option (D3-3 / spec F2 + open question OD-1).
     *
     * Shape: [ 'enabled' => bool, 'search' => bool, 'ai_input' => bool, 'ai_train' => bool ].
     *
     * OD-1 resolution (conservative default, recorded 2026-06-12): the ENTIRE
     * Content-Signal block defaults OFF ('enabled' => false) — no signal is
     * emitted until the user explicitly enables it. When enabled, the UI
     * defaults are search=yes, ai-input=yes, ai-train=no (the most
     * conservative stance for rights holders). Revisit if product decides
     * visibility-first (ai-train=yes) instead.
     */
    const OPTION_CONTENT_SIGNAL = 'llmagnet_robots_content_signal';

    /**
     * Bot_Registry canonical name => published robots.txt User-agent tokens.
     *
     * Only registry bots present in this map produce directives, and only
     * while they remain in Bot_Registry::get_bots() — drop a bot from the
     * registry and its robots.txt group disappears too (dependency D5).
     *
     * @var array<string, string[]>
     */
    private const REGISTRY_ROBOTS_TOKENS = [
        'ChatGPT'           => [ 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User' ],
        'Claude'            => [ 'ClaudeBot', 'Claude-User', 'Claude-SearchBot' ],
        'Gemini'            => [ 'Google-Extended' ],
        'Perplexity'        => [ 'PerplexityBot', 'Perplexity-User' ],
        'Llama'             => [ 'meta-externalagent' ],
        'Mistral'           => [ 'MistralAI-User' ],
        // Phase D gate: former SUPPLEMENTAL_ROBOTS_TOKENS entries, absorbed
        // into Bot_Registry under the same canonical names (registry tail =
        // same robots.txt group order, same policy option keys).
        'Applebot-Extended' => [ 'Applebot-Extended' ],
        'Bytespider'        => [ 'Bytespider' ],
        'CCBot'             => [ 'CCBot' ],
        'Amazonbot'         => [ 'Amazonbot' ],
        'Cohere'            => [ 'cohere-ai' ],
    ];

    /**
     * Initialize hooks
     *
     * @return void
     */
    public function init(): void {
        if ( get_option( 'llmagnet_robots_txt_inject', true ) ) {
            add_filter( 'robots_txt', [ $this, 'inject_via_filter' ], 10, 2 );
        }

        // Agent Rules managed block (F2). Always hooked at a later priority
        // (after every other robots_txt contributor has run, so the
        // strip/re-append and "Sitemap: present?" checks see the final
        // output); the callback self-gates on the feature toggle and on
        // SEO-plugin robots ownership.
        add_filter( 'robots_txt', [ $this, 'inject_agent_rules_via_filter' ], 20, 2 );

        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes(): void {
        register_rest_route( 'llm-analytics/v1', '/robots-status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_status' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'llm-analytics/v1', '/robots-inject', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_inject' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        // Agent Rules physical-file management (F2). This is the explicit,
        // confirmed path offered when an SEO plugin owns robots.txt output
        // and the filter path is disabled — and also works standalone.
        register_rest_route( 'llm-analytics/v1', '/robots-agent-rules', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_agent_rules' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'action' => [
                    'type'     => 'string',
                    'enum'     => [ 'apply', 'remove' ],
                    'required' => true,
                ],
            ],
        ] );
    }

    /**
     * REST callback: get robots.txt status
     *
     * @return \WP_REST_Response
     */
    public function rest_get_status(): \WP_REST_Response {
        return new \WP_REST_Response( $this->get_status(), 200 );
    }

    /**
     * REST callback: inject llms.txt reference into physical robots.txt
     *
     * @return \WP_REST_Response
     */
    public function rest_inject(): \WP_REST_Response {
        $status = $this->get_status();
        
        // If already has reference (via filter or file), mark as success
        if ( $status['has_llms_reference'] ) {
            return new \WP_REST_Response( [
                'success' => true,
                'method'  => $status['injection_method'],
                'message' => 'llms.txt reference is already present in robots.txt',
                'status'  => $status,
            ], 200 );
        }
        
        // Try to inject into physical file
        $result = $this->inject_into_physical();

        if ( $result ) {
            return new \WP_REST_Response( [
                'success' => true,
                'method'  => 'file',
                'status'  => $this->get_status(),
            ], 200 );
        }

        // If physical write failed, check if filter method is enabled
        if ( get_option( 'llmagnet_robots_txt_inject', true ) ) {
            return new \WP_REST_Response( [
                'success' => true,
                'method'  => 'filter',
                'message' => 'Using WordPress filter method (no physical file write needed)',
                'status'  => $this->get_status(),
            ], 200 );
        }

        return new \WP_REST_Response( [
            'success' => false,
            'message' => 'Could not write to robots.txt. File may not exist or is not writable.',
        ], 400 );
    }

    /**
     * REST callback: apply/remove the agent-rules sentinel block in the physical robots.txt
     *
     * @param \WP_REST_Request $request Request with an 'action' of apply|remove.
     * @return \WP_REST_Response
     */
    public function rest_agent_rules( \WP_REST_Request $request ): \WP_REST_Response {
        $action = $request->get_param( 'action' );

        if ( 'apply' === $action && ! $this->agent_rules_enabled() ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'Enable the robots.txt AI rules feature before applying the block.', 'llmagnet-llm-txt-generator' ),
            ], 400 );
        }

        $result = 'remove' === $action
            ? $this->remove_agent_rules_from_physical()
            : $this->apply_agent_rules_to_physical();

        if ( ! $result ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => __( 'Could not write to robots.txt. File may not exist or is not writable.', 'llmagnet-llm-txt-generator' ),
            ], 400 );
        }

        return new \WP_REST_Response( [
            'success' => true,
            'action'  => $action,
            'status'  => $this->get_status(),
        ], 200 );
    }

    /**
     * WordPress filter callback — append llms-txt directive to virtual robots.txt
     *
     * @param string $output Current robots.txt content
     * @param bool   $public Whether the site is public
     * @return string
     */
    public function inject_via_filter( string $output, bool $public ): string {
        if ( ! $public ) {
            return $output;
        }

        $llms_url = home_url( '/llms.txt' );

        if ( strpos( $output, 'llms.txt' ) === false ) {
            $output .= "\n# LLMagnet AI Visibility\nllms-txt: " . esc_url( $llms_url ) . "\n";
        }

        return $output;
    }

    /**
     * Append llms-txt directive to a physical robots.txt file
     *
     * Only called on explicit user action (REST endpoint).
     *
     * @return bool
     */
    public function inject_into_physical(): bool {
        $path = ABSPATH . 'robots.txt';

        if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
            return false;
        }

        $content = Filesystem_Helper::get_contents( $path );
        if ( $content === false ) {
            return false;
        }

        if ( strpos( $content, 'llms.txt' ) !== false ) {
            return true;
        }

        $addition = "\n# LLMagnet AI Visibility\nllms-txt: " . home_url( '/llms.txt' ) . "\n";

        return Filesystem_Helper::put_contents( $path, $content . $addition );
    }

    // ── Agent Rules managed block (agent-readiness-spec Feature 2) ─────────────

    /**
     * Whether the robots.txt AI rules feature is enabled
     *
     * Defaults OFF per Agent_Readiness_Options (every agent-readiness
     * feature ships disabled).
     *
     * @return bool
     */
    public function agent_rules_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'robots_ai_rules' );
    }

    /**
     * Whether another plugin owns robots.txt output (F2 coexistence)
     *
     * @return bool
     */
    public function robots_owned_externally(): bool {
        return class_exists( __NAMESPACE__ . '\\Seo_Plugin_Detector' )
            && Seo_Plugin_Detector::owns_robots();
    }

    /**
     * WordPress filter callback — manage the agent-rules sentinel block in virtual robots.txt
     *
     * Replace-not-append: any pre-existing sentinel block is stripped first,
     * then a fresh block is appended only when the feature is enabled, the
     * site is public, and no other plugin owns robots.txt output. Running
     * the filter twice therefore never duplicates, and disabling the toggle
     * removes exactly the managed block.
     *
     * @param string $output Current robots.txt content.
     * @param bool   $public Whether the site is public.
     * @return string
     */
    public function inject_agent_rules_via_filter( string $output, bool $public ): string {
        $output = $this->strip_agent_block( $output );

        if ( ! $public || ! $this->agent_rules_enabled() ) {
            return $output;
        }

        // Coexistence (F2): when an SEO plugin owns robots.txt output we stay
        // out of the filter; only the explicit physical injection is offered.
        if ( $this->robots_owned_externally() ) {
            return $output;
        }

        return rtrim( $output ) . "\n\n" . $this->build_agent_rules_block( $output ) . "\n";
    }

    /**
     * Write the agent-rules sentinel block into the physical robots.txt
     *
     * Strips any previous block before appending the fresh one
     * (replace-not-append, unlike the legacy llms-txt appender above).
     * Only called on explicit user action (REST endpoint).
     *
     * @return bool
     */
    public function apply_agent_rules_to_physical(): bool {
        $path = ABSPATH . 'robots.txt';

        if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
            return false;
        }

        $content = Filesystem_Helper::get_contents( $path );
        if ( false === $content ) {
            return false;
        }

        $content = rtrim( $this->strip_agent_block( $content ) );
        $content .= "\n\n" . $this->build_agent_rules_block( $content ) . "\n";

        return Filesystem_Helper::put_contents( $path, $content );
    }

    /**
     * Remove the agent-rules sentinel block from the physical robots.txt
     *
     * Removes exactly the managed block; everything else is preserved.
     *
     * @return bool
     */
    public function remove_agent_rules_from_physical(): bool {
        $path = ABSPATH . 'robots.txt';

        if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
            return false;
        }

        $content = Filesystem_Helper::get_contents( $path );
        if ( false === $content ) {
            return false;
        }

        $stripped = $this->strip_agent_block( $content );
        if ( $stripped === $content ) {
            return true; // Nothing to remove.
        }

        return Filesystem_Helper::put_contents( $path, rtrim( $stripped ) . "\n" );
    }

    /**
     * Build the managed sentinel block body
     *
     * @param string $context_output The robots.txt content the block will be
     *                               appended to (already stripped of any
     *                               previous managed block) — used to decide
     *                               whether a Sitemap: line is needed.
     * @return string Block including BEGIN/END sentinels, no trailing newline.
     */
    public function build_agent_rules_block( string $context_output = '' ): string {
        $lines = [ self::AGENT_BLOCK_BEGIN ];

        // Per-bot allow/deny groups (D3-2, sourced from Bot_Registry — D5).
        foreach ( $this->get_agent_directives() as $directive ) {
            if ( empty( $directive['tokens'] ) || ! is_array( $directive['tokens'] ) ) {
                continue;
            }
            foreach ( $directive['tokens'] as $token ) {
                $lines[] = 'User-agent: ' . $token;
            }
            $lines[] = ( isset( $directive['policy'] ) && 'deny' === $directive['policy'] ) ? 'Disallow: /' : 'Allow: /';
            $lines[] = '';
        }

        // Content-Signal (D3-3) — entire block defaults OFF per OD-1 resolution.
        $signal = $this->get_content_signal_settings();
        if ( $signal['enabled'] ) {
            $lines[] = sprintf(
                'Content-Signal: search=%s, ai-input=%s, ai-train=%s',
                $signal['search'] ? 'yes' : 'no',
                $signal['ai_input'] ? 'yes' : 'no',
                $signal['ai_train'] ? 'yes' : 'no'
            );
            $lines[] = '';
        }

        // Sitemap reference (D3-5) — only when absent everywhere else.
        if ( false === stripos( $context_output, 'sitemap:' ) ) {
            $lines[] = 'Sitemap: ' . $this->get_sitemap_url();
            $lines[] = '';
        }

        // Schemamap reference (F6.2 — FA-4): listed while the schemamap
        // endpoint is live. Comment-prefixed because `schemamap:` is not a
        // robots.txt directive any parser is required to understand.
        if ( class_exists( __NAMESPACE__ . '\\Schemamap' ) && Schemamap::is_enabled() ) {
            $lines[] = '# Schemamap: ' . home_url( '/' . Schemamap::PATH );
            $lines[] = '';
        }

        while ( '' === end( $lines ) ) {
            array_pop( $lines );
        }
        $lines[] = self::AGENT_BLOCK_END;

        return implode( "\n", $lines );
    }

    /**
     * Directives list: which bots get a robots.txt group and with what policy
     *
     * Sourced from Bot_Registry (dependency D5): registry bot names are
     * mapped to their published User-agent tokens; a registry bot with no
     * known robots token is skipped, and a bot removed from the registry
     * disappears here too.
     *
     * Filterable via `llmagnet_ai_bot_directives` (spec F2) so the list can
     * be corrected without a release.
     *
     * @return array<string, array{bot: ?string, tokens: string[], policy: string}>
     */
    public function get_agent_directives(): array {
        $policies   = get_option( self::OPTION_AI_BOTS, [] );
        $policies   = is_array( $policies ) ? $policies : [];
        $policy_for = static function ( string $key ) use ( $policies ): string {
            return ( isset( $policies[ $key ] ) && 'deny' === $policies[ $key ] ) ? 'deny' : 'allow';
        };

        $directives = [];

        foreach ( array_keys( Bot_Registry::get_bots() ) as $bot_name ) {
            if ( empty( self::REGISTRY_ROBOTS_TOKENS[ $bot_name ] ) ) {
                continue;
            }
            $directives[ $bot_name ] = [
                'bot'    => $bot_name,
                'tokens' => self::REGISTRY_ROBOTS_TOKENS[ $bot_name ],
                'policy' => $policy_for( $bot_name ),
            ];
        }

        /**
         * Filter the robots.txt agent directives list.
         *
         * @param array $directives Directive entries keyed by bot/crawler name:
         *                          [ 'bot' => ?string, 'tokens' => string[], 'policy' => 'allow'|'deny' ].
         */
        $directives = apply_filters( 'llmagnet_ai_bot_directives', $directives );

        return is_array( $directives ) ? $directives : [];
    }

    /**
     * Content-Signal switch settings merged over defaults
     *
     * OD-1 conservative resolution: 'enabled' defaults false (no signal is
     * emitted at all until the user turns it on); once enabled the switch
     * defaults are search=yes, ai-input=yes, ai-train=no.
     *
     * @return array{enabled: bool, search: bool, ai_input: bool, ai_train: bool}
     */
    public function get_content_signal_settings(): array {
        $defaults = [
            'enabled'  => false,
            'search'   => true,
            'ai_input' => true,
            'ai_train' => false,
        ];

        $stored = get_option( self::OPTION_CONTENT_SIGNAL, [] );
        if ( ! is_array( $stored ) ) {
            return $defaults;
        }

        $settings = [];
        foreach ( $defaults as $key => $default ) {
            $settings[ $key ] = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : $default;
        }

        return $settings;
    }

    /**
     * Sitemap URL to reference from the managed block
     *
     * Respects SEO-plugin sitemap ownership (D3-5): points at the owning
     * plugin's sitemap index when one is detected, otherwise at core's
     * wp-sitemap.xml.
     *
     * @return string
     */
    public function get_sitemap_url(): string {
        if ( class_exists( __NAMESPACE__ . '\\Seo_Plugin_Detector' ) ) {
            switch ( Seo_Plugin_Detector::owns( 'sitemap' ) ) {
                case 'yoast':
                case 'rankmath':
                    return home_url( '/sitemap_index.xml' );
                case 'aioseo':
                case 'tsf':
                    return home_url( '/sitemap.xml' );
                case 'seopress':
                    return home_url( '/sitemaps.xml' );
            }
        }

        return home_url( '/wp-sitemap.xml' );
    }

    /**
     * Strip every managed sentinel block (and any dangling opener) from content
     *
     * @param string $content robots.txt content.
     * @return string
     */
    private function strip_agent_block( string $content ): string {
        $begin = preg_quote( self::AGENT_BLOCK_BEGIN, '/' );
        $end   = preg_quote( self::AGENT_BLOCK_END, '/' );

        // Complete blocks (lazy match handles multiple occurrences).
        $stripped = preg_replace( '/\h*' . $begin . '.*?' . $end . '\h*\R?/s', '', $content );
        if ( is_string( $stripped ) ) {
            $content = $stripped;
        }

        // Dangling opener with no closer (corrupted manual edit): strip to EOF.
        $stripped = preg_replace( '/\h*' . $begin . '(?:(?!' . $end . ').)*$/s', '', $content );
        if ( is_string( $stripped ) ) {
            $content = $stripped;
        }

        return $content;
    }

    /**
     * Get current robots.txt status
     *
     * @return array{has_physical_file: bool, has_llms_reference: bool, injection_method: string, robots_txt_url: string, agent_rules: array}
     */
    public function get_status(): array {
        $physical_path      = ABSPATH . 'robots.txt';
        $has_physical       = file_exists( $physical_path );
        $has_reference      = false;
        $physical_has_block = false;

        if ( $has_physical ) {
            $content = file_get_contents( $physical_path );
            if ( $content !== false ) {
                $has_reference      = strpos( $content, 'llms.txt' ) !== false;
                $physical_has_block = strpos( $content, self::AGENT_BLOCK_BEGIN ) !== false;
            }
        } else {
            $virtual       = apply_filters( 'robots_txt', '', (bool) get_option( 'blog_public' ) );
            $has_reference = strpos( $virtual, 'llms.txt' ) !== false;
        }

        $inject_enabled = get_option( 'llmagnet_robots_txt_inject', true );

        $owned_externally = $this->robots_owned_externally();
        $owner_label      = null;
        if ( $owned_externally && class_exists( __NAMESPACE__ . '\\Seo_Plugin_Detector' ) ) {
            $owner_label = Seo_Plugin_Detector::get_owner_label( 'robots_txt' );
        }

        return [
            'has_physical_file'  => $has_physical,
            'has_llms_reference' => $has_reference,
            'injection_method'   => $inject_enabled ? ( $has_physical ? 'file' : 'filter' ) : 'none',
            'robots_txt_url'     => home_url( '/robots.txt' ),
            'agent_rules'        => [
                'enabled'            => $this->agent_rules_enabled(),
                'seo_robots_owner'   => $owner_label,
                // Filter path is active only when nothing else owns robots
                // and there is no physical file (a physical file bypasses
                // the robots_txt filter entirely).
                'filter_active'      => $this->agent_rules_enabled() && ! $owned_externally && ! $has_physical,
                'physical_has_block' => $physical_has_block,
                'content_signal'     => $this->get_content_signal_settings(),
                'sitemap_url'        => $this->get_sitemap_url(),
                'directives'         => $this->get_agent_directives(),
            ],
        ];
    }
}
