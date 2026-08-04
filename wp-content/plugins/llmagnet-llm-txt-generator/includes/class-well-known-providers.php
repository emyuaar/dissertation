<?php
/**
 * Well-known endpoint providers (agent-readiness-spec Feature 3)
 *
 * Registers all five Feature-3 providers on the Well_Known router
 * (Phase 0.1) via the `llmagnet_register_well_known_providers` action:
 *
 * | Path                              | Task | Content                                            |
 * |-----------------------------------|------|-----------------------------------------------------|
 * | /.well-known/agent-card.json      | D3-6 | A2A-style card from llmagnet_agent_card + site data |
 * | /.well-known/agent-skills         | D3-6 | JSON index of the public Agent_Skills_Registry      |
 * | /.well-known/mcp.json             | D3-7 | MCP server card, sourced LIVE from MCP_Tools (D1+D2)|
 * | /.well-known/security.txt         | D3-8 | RFC 9116 (Contact, auto-refreshed Expires, Canonical)|
 * | /.well-known/change-password      | D3-9 | 302 → wp_lostpassword_url()                         |
 *
 * Every provider declines (returns null → 404) unless the `well_known`
 * feature toggle is enabled in Agent_Readiness_Options — all agent-readiness
 * features ship OFF. The mcp.json provider additionally declines when the
 * MCP server itself is disabled. Physical-file precedence (a real file at
 * the path on disk) is enforced by the router before any callback runs.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Agent_Skills_Registry' ) ) {
    require_once __DIR__ . '/class-agent-skills-registry.php';
}

/**
 * Providers for the Feature-3 well-known endpoints.
 */
class Well_Known_Providers {

    /**
     * Stored Expires timestamp for security.txt (autoload off).
     *
     * Self-refreshing: regenerated to now + 1 year whenever fewer than 30
     * days remain (RFC 9116 §2.5.5 wants Expires < 1 year in the future).
     * No cron dependency — the refresh happens lazily on render.
     */
    const OPTION_SECURITY_TXT_EXPIRES = 'llmagnet_security_txt_expires';

    /**
     * Shared MCP tool registry (dependency D1) for the mcp.json card.
     *
     * @var MCP_Tools|null
     */
    private $mcp_tools;

    /**
     * Public skill registry for agent-card.json / agent-skills.
     *
     * @var Agent_Skills_Registry|null
     */
    private $skills_registry;

    /**
     * Both dependencies are optional and lazily constructed when omitted
     * (both have usable no-argument constructors). Main should inject the
     * shared instances: `$this->mcp->get_tools_registry()` and the
     * Agent_Skills_Registry it owns.
     *
     * @param MCP_Tools|null             $mcp_tools       Shared MCP tool registry.
     * @param Agent_Skills_Registry|null $skills_registry Public skill registry.
     */
    public function __construct( $mcp_tools = null, $skills_registry = null ) {
        $this->mcp_tools       = $mcp_tools instanceof MCP_Tools ? $mcp_tools : null;
        $this->skills_registry = $skills_registry instanceof Agent_Skills_Registry ? $skills_registry : null;
    }

    /**
     * Hook provider registration
     *
     * Must run before `init` priority 20 (when Well_Known fires the
     * registration action) — instantiating from Main::init_components()
     * (init priority 10) satisfies that.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'llmagnet_register_well_known_providers', [ $this, 'register_providers' ] );
    }

    /**
     * Register all Feature-3 providers on the router
     *
     * @return void
     */
    public function register_providers(): void {
        Well_Known::register(
            '.well-known/agent-card.json',
            [ $this, 'render_agent_card' ],
            'application/json; charset=utf-8'
        );

        Well_Known::register(
            '.well-known/agent-skills',
            [ $this, 'render_agent_skills' ],
            'application/json; charset=utf-8'
        );

        Well_Known::register(
            '.well-known/mcp.json',
            [ $this, 'render_mcp_card' ],
            'application/json; charset=utf-8'
        );

        Well_Known::register(
            '.well-known/security.txt',
            [ $this, 'render_security_txt' ],
            'text/plain; charset=utf-8'
        );

        // Redirect provider: the callback issues the 302 itself and exits,
        // so the router's 200/body path is never reached. cache_max_age 0
        // is moot but documents intent.
        Well_Known::register(
            '.well-known/change-password',
            [ $this, 'redirect_change_password' ],
            'text/plain; charset=utf-8',
            [ 'cache_max_age' => 0 ]
        );
    }

    // ── 3.1 agent-card.json ────────────────────────────────────────────────────

    /**
     * Render /.well-known/agent-card.json (A2A-style card, spec §3.1)
     *
     * Editable fields come from the llmagnet_agent_card option; empty fields
     * fall back to site data. Skills mirror the agent-skills entries (same
     * registry, defined once — spec §4.2).
     *
     * @return string|null JSON body, or null to decline (feature off).
     */
    public function render_agent_card() {
        if ( ! $this->feature_enabled() ) {
            return null;
        }

        $card = Agent_Readiness_Options::get( Agent_Readiness_Options::OPTION_AGENT_CARD );

        $name        = ( is_array( $card ) && '' !== (string) ( $card['name'] ?? '' ) ) ? $card['name'] : get_bloginfo( 'name' );
        $description = ( is_array( $card ) && '' !== (string) ( $card['description'] ?? '' ) ) ? $card['description'] : get_bloginfo( 'description' );

        $capabilities = [ 'streaming' => false ];
        if ( is_array( $card ) && ! empty( $card['capabilities'] ) && is_array( $card['capabilities'] ) ) {
            $capabilities = array_merge( $capabilities, $card['capabilities'] );
        }

        $skills = [];
        foreach ( $this->get_skills_registry()->get_skills_for_surface( 'card' ) as $skill ) {
            $skills[] = [
                'id'          => $skill['id'],
                'name'        => $skill['title'],
                'description' => $skill['description'],
            ];
        }

        $body = [
            'protocolVersion'    => '0.3.0',
            'name'               => $name,
            'description'        => $description,
            'url'                => home_url( '/' ),
            'preferredTransport' => 'JSONRPC',
            'capabilities'       => $capabilities,
            'defaultInputModes'  => [ 'text/plain' ],
            'defaultOutputModes' => [ 'text/plain', 'text/markdown' ],
            'skills'             => $skills,
            'documentationUrl'   => home_url( '/llms.txt' ),
        ];

        if ( is_array( $card ) && '' !== (string) ( $card['contact'] ?? '' ) ) {
            $body['contact'] = $card['contact'];
        }

        return $this->encode( $body );
    }

    // ── 3.2 agent-skills ───────────────────────────────────────────────────────

    /**
     * Render /.well-known/agent-skills (JSON skill index, spec §3.2)
     *
     * The discovery-file format is not yet standardized (open question 5);
     * we ship JSON mirroring the agent-card skills with endpoint + auth
     * detail per entry.
     *
     * @return string|null JSON body, or null to decline (feature off).
     */
    public function render_agent_skills() {
        if ( ! $this->feature_enabled() ) {
            return null;
        }

        $skills = [];
        foreach ( $this->get_skills_registry()->get_skills() as $skill ) {
            $skills[] = [
                'id'           => $skill['id'],
                'name'         => $skill['title'],
                'description'  => $skill['description'],
                'endpoint'     => $skill['endpoint'],
                'auth'         => $skill['auth'],
                'input_schema' => $skill['input_schema'],
                'surfaces'     => array_keys( array_filter( $skill['surfaces'] ) ),
            ];
        }

        $body = [
            'version'   => 1,
            'name'      => get_bloginfo( 'name' ),
            'url'       => home_url( '/' ),
            'generated' => gmdate( 'c' ),
            'skills'    => $skills,
        ];

        return $this->encode( $body );
    }

    // ── 3.3 mcp.json — MCP server card ─────────────────────────────────────────

    /**
     * Render /.well-known/mcp.json (MCP server card, spec §3.3 / mcp-ai §E2)
     *
     * Sourced LIVE from the shared MCP_Tools registry (dependencies D1+D2) —
     * tool names/titles/descriptions only, never schemas — plus the endpoint
     * URL and an auth hint derived from llmagnet_mcp_settings. The card can
     * therefore never drift from the running server.
     *
     * @return string|null JSON body, or null to decline (feature off or MCP disabled).
     */
    public function render_mcp_card() {
        if ( ! $this->feature_enabled() || ! class_exists( __NAMESPACE__ . '\\MCP' ) ) {
            return null;
        }

        $settings = MCP::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            // No running server — publishing a card would be a lie.
            return null;
        }

        // Auth hint: bearer tokens / application passwords always work;
        // public access modes additionally allow anonymous (rate-limited)
        // calls to the eligible read tools.
        $authentication = [ 'bearer', 'basic' ];
        if ( in_array( $settings['access_mode'], [ 'public_content', 'public_read' ], true ) ) {
            array_unshift( $authentication, 'none' );
        }

        $registry = $this->get_mcp_tools();
        $tools    = [];
        foreach ( $registry->get_definitions() as $id => $def ) {
            if ( ! $registry->is_available( $id ) ) {
                continue;
            }
            $tools[] = [
                'name'        => $id,
                'title'       => isset( $def['title'] ) ? (string) $def['title'] : $id,
                'description' => isset( $def['description'] ) ? (string) $def['description'] : '',
            ];
        }

        $versions = MCP::PROTOCOL_VERSIONS;

        $body = [
            'name'             => 'LLMagnet MCP',
            'description'      => sprintf( 'MCP server for %s — AI visibility analytics and published-content access.', get_bloginfo( 'name' ) ),
            'endpoint'         => rest_url( 'llmagnet/mcp/v1' ),
            'protocolVersion'  => end( $versions ),
            'protocolVersions' => $versions,
            'transport'        => 'http',
            'authentication'   => $authentication,
            'tools'            => $tools,
        ];

        return $this->encode( $body );
    }

    // ── 3.4 security.txt ───────────────────────────────────────────────────────

    /**
     * Render /.well-known/security.txt (RFC 9116, spec §3.4)
     *
     * Generated from the admin email (Contact override: the agent-card
     * `contact` field when it is an email address or URL). Expires is
     * auto-refreshed (see OPTION_SECURITY_TXT_EXPIRES). A physical file at
     * the path wins — the router enforces that before this runs.
     *
     * @return string|null Body, or null to decline (feature off).
     */
    public function render_security_txt() {
        if ( ! $this->feature_enabled() ) {
            return null;
        }

        $contact = (string) get_option( 'admin_email' );

        $card = Agent_Readiness_Options::get( Agent_Readiness_Options::OPTION_AGENT_CARD );
        if ( is_array( $card ) && '' !== (string) ( $card['contact'] ?? '' ) ) {
            $override = trim( (string) $card['contact'] );
            if ( is_email( $override ) || preg_match( '#^https?://#i', $override ) ) {
                $contact = $override;
            }
        }

        if ( '' === $contact ) {
            return null;
        }

        $lines = [
            'Contact: ' . ( is_email( $contact ) ? 'mailto:' . $contact : $contact ),
            'Expires: ' . $this->get_security_txt_expires(),
            'Canonical: ' . home_url( '/.well-known/security.txt' ),
            'Preferred-Languages: ' . str_replace( '_', '-', get_locale() ),
        ];

        /**
         * Filter the generated security.txt lines.
         *
         * @param string[] $lines One RFC 9116 field per entry.
         */
        $lines = apply_filters( 'llmagnet_security_txt_lines', $lines );
        if ( ! is_array( $lines ) || [] === $lines ) {
            return null;
        }

        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Auto-refreshing Expires value for security.txt
     *
     * Regenerates to now + 1 year whenever the stored timestamp has fewer
     * than 30 days remaining (or was never set).
     *
     * @return string Internet date-time (RFC 3339, UTC).
     */
    private function get_security_txt_expires(): string {
        $ts = (int) get_option( self::OPTION_SECURITY_TXT_EXPIRES, 0 );

        if ( $ts < time() + 30 * DAY_IN_SECONDS ) {
            $ts = time() + YEAR_IN_SECONDS;
            update_option( self::OPTION_SECURITY_TXT_EXPIRES, $ts, false );
        }

        return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
    }

    // ── 3.5 change-password ────────────────────────────────────────────────────

    /**
     * /.well-known/change-password → 302 to the WP password-reset URL (spec §3.5)
     *
     * The callback short-circuits the router by issuing the redirect and
     * exiting itself; returning null (feature off) lets WordPress 404.
     *
     * @return null
     */
    public function redirect_change_password() {
        if ( ! $this->feature_enabled() ) {
            return null;
        }

        wp_safe_redirect( wp_lostpassword_url(), 302 );
        exit;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    /**
     * Whether the well_known feature toggle is enabled (default OFF)
     *
     * @return bool
     */
    private function feature_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'well_known' );
    }

    /**
     * Lazily resolve the skill registry
     *
     * @return Agent_Skills_Registry
     */
    private function get_skills_registry(): Agent_Skills_Registry {
        if ( null === $this->skills_registry ) {
            $this->skills_registry = new Agent_Skills_Registry();
        }
        return $this->skills_registry;
    }

    /**
     * Lazily resolve the MCP tool registry
     *
     * @return MCP_Tools
     */
    private function get_mcp_tools(): MCP_Tools {
        if ( null === $this->mcp_tools ) {
            if ( ! class_exists( __NAMESPACE__ . '\\MCP_Tools' ) ) {
                require_once __DIR__ . '/class-mcp-tools.php';
            }
            $this->mcp_tools = new MCP_Tools();
        }
        return $this->mcp_tools;
    }

    /**
     * Encode a body as pretty, slash-preserving JSON
     *
     * @param array $body Body data.
     * @return string|null JSON, or null when encoding fails.
     */
    private function encode( array $body ) {
        $json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        return is_string( $json ) ? $json . "\n" : null;
    }
}
