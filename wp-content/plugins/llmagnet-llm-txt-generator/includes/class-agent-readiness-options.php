<?php
/**
 * Agent-readiness options scaffolding
 *
 * Registers and seeds every option introduced by the agent-readiness spec
 * (Phase 0.3) with sane defaults, sanitization callbacks, and
 * autoload => false:
 *
 * | Option                          | Type   | Purpose                                              |
 * |---------------------------------|--------|------------------------------------------------------|
 * | llmagnet_agent_readiness        | array  | Master feature toggles (webmcp, well_known, …)       |
 * | llmagnet_agent_card             | array  | Editable agent-card fields (name, description, …)    |
 * | llmagnet_agent_headers          | array  | Header entries consumed by Http_Headers              |
 * | llmagnet_agent_audit_last       | array  | Last audit result (internal storage, not a setting)  |
 * | llmagnet_agent_audit_history    | array  | Rolling last 12 audit summaries (internal storage)   |
 * | llmagnet_indexnow_key           | string | Generated IndexNow key                               |
 *
 * The audit options are internal storage written by Agent_Audit (Phase E),
 * so they are seeded but intentionally NOT registered as settings (no
 * sanitize_option filter that could mangle internal writes).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Options registration / defaults / sanitization for agent readiness
 */
class Agent_Readiness_Options {

    /**
     * Master feature toggles option.
     */
    const OPTION_READINESS = 'llmagnet_agent_readiness';

    /**
     * Editable agent-card fields option.
     */
    const OPTION_AGENT_CARD = 'llmagnet_agent_card';

    /**
     * Http_Headers allowlist option.
     */
    const OPTION_HEADERS = 'llmagnet_agent_headers';

    /**
     * Last audit result option (internal storage).
     */
    const OPTION_AUDIT_LAST = 'llmagnet_agent_audit_last';

    /**
     * Rolling audit history option (internal storage).
     */
    const OPTION_AUDIT_HISTORY = 'llmagnet_agent_audit_history';

    /**
     * IndexNow key option.
     */
    const OPTION_INDEXNOW_KEY = 'llmagnet_indexnow_key';

    /**
     * Settings group used with register_setting().
     */
    const SETTINGS_GROUP = 'llmagnet_agent_readiness';

    /**
     * Option tracking whether defaults have been seeded for this schema version.
     */
    const SCHEMA_VERSION_OPTION = 'llmagnet_agent_readiness_schema';

    /**
     * Bump when defaults/shape change to re-run seeding.
     */
    const SCHEMA_VERSION = '1';

    /**
     * Feature toggle keys inside OPTION_READINESS.
     *
     * @var string[]
     */
    const FEATURE_TOGGLES = [
        'webmcp',
        'well_known',
        'markdown_endpoints',
        'link_headers',
        'security_headers',
        'indexnow',
        'og_fill',
        'robots_ai_rules',
    ];

    /**
     * Register settings and seed defaults
     *
     * Called from Main::init_components(), which runs on `init` — a valid
     * point for register_setting().
     *
     * @return void
     */
    public function init(): void {
        $this->register_settings();
        $this->maybe_seed_defaults();
    }

    /**
     * Register the user-editable options as settings with sanitization
     *
     * Audit options are internal storage and deliberately not registered.
     *
     * @return void
     */
    public function register_settings(): void {
        register_setting( self::SETTINGS_GROUP, self::OPTION_READINESS, [
            'type'              => 'array',
            'description'       => __( 'LLMagnet agent-readiness feature toggles', 'llmagnet-llm-txt-generator' ),
            'sanitize_callback' => [ __CLASS__, 'sanitize_readiness' ],
            'show_in_rest'      => false,
            'default'           => self::get_defaults( self::OPTION_READINESS ),
        ] );

        register_setting( self::SETTINGS_GROUP, self::OPTION_AGENT_CARD, [
            'type'              => 'array',
            'description'       => __( 'LLMagnet agent-card fields', 'llmagnet-llm-txt-generator' ),
            'sanitize_callback' => [ __CLASS__, 'sanitize_agent_card' ],
            'show_in_rest'      => false,
            'default'           => self::get_defaults( self::OPTION_AGENT_CARD ),
        ] );

        register_setting( self::SETTINGS_GROUP, self::OPTION_HEADERS, [
            'type'              => 'array',
            'description'       => __( 'LLMagnet front-end HTTP header allowlist', 'llmagnet-llm-txt-generator' ),
            'sanitize_callback' => [ __CLASS__, 'sanitize_headers' ],
            'show_in_rest'      => false,
            'default'           => self::get_defaults( self::OPTION_HEADERS ),
        ] );

        register_setting( self::SETTINGS_GROUP, self::OPTION_INDEXNOW_KEY, [
            'type'              => 'string',
            'description'       => __( 'LLMagnet IndexNow key', 'llmagnet-llm-txt-generator' ),
            'sanitize_callback' => [ __CLASS__, 'sanitize_indexnow_key' ],
            'show_in_rest'      => false,
            'default'           => '',
        ] );
    }

    /**
     * Seed all options once per schema version with autoload => false
     *
     * add_option() is a no-op for existing options, so user values survive.
     *
     * @return void
     */
    public function maybe_seed_defaults(): void {
        if ( get_option( self::SCHEMA_VERSION_OPTION ) === self::SCHEMA_VERSION ) {
            return;
        }

        $seed = [
            self::OPTION_READINESS,
            self::OPTION_AGENT_CARD,
            self::OPTION_HEADERS,
            self::OPTION_AUDIT_LAST,
            self::OPTION_AUDIT_HISTORY,
            self::OPTION_INDEXNOW_KEY,
        ];

        foreach ( $seed as $option ) {
            add_option( $option, self::get_defaults( $option ), '', false );
        }

        update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
    }

    /**
     * Default value for an agent-readiness option
     *
     * @param string $option Option name (one of the OPTION_* constants).
     * @return array|string
     */
    public static function get_defaults( string $option ) {
        switch ( $option ) {
            case self::OPTION_READINESS:
                // Every feature ships OFF — they are enabled per-feature as
                // Phases D–F land (and per plan gating).
                return array_fill_keys( self::FEATURE_TOGGLES, false );

            case self::OPTION_AGENT_CARD:
                return [
                    'name'         => '',
                    'description'  => '',
                    'contact'      => '',
                    'capabilities' => [],
                ];

            case self::OPTION_HEADERS:
                // Entry shape consumed by Http_Headers:
                // [ 'name' => string, 'value' => string, 'enabled' => bool ].
                // F8 seeds its security-header defaults when that feature lands.
                return [];

            case self::OPTION_AUDIT_LAST:
            case self::OPTION_AUDIT_HISTORY:
                return [];

            case self::OPTION_INDEXNOW_KEY:
                return '';

            default:
                return [];
        }
    }

    /**
     * Get an agent-readiness option merged over its defaults
     *
     * @param string $option Option name (one of the OPTION_* constants).
     * @return array|string
     */
    public static function get( string $option ) {
        $defaults = self::get_defaults( $option );
        $value    = get_option( $option, $defaults );

        if ( is_array( $defaults ) ) {
            return is_array( $value ) ? array_merge( $defaults, $value ) : $defaults;
        }

        return is_string( $value ) ? $value : $defaults;
    }

    /**
     * Whether a feature toggle inside llmagnet_agent_readiness is enabled
     *
     * @param string $feature One of FEATURE_TOGGLES.
     * @return bool
     */
    public static function is_feature_enabled( string $feature ): bool {
        $readiness = self::get( self::OPTION_READINESS );
        return ! empty( $readiness[ $feature ] );
    }

    /**
     * Get the IndexNow key, generating and persisting one when missing
     *
     * Key format per the IndexNow protocol: 8–128 hexadecimal characters.
     *
     * @return string
     */
    public static function get_or_create_indexnow_key(): string {
        $key = get_option( self::OPTION_INDEXNOW_KEY, '' );
        if ( is_string( $key ) && '' !== $key ) {
            return $key;
        }

        $key = bin2hex( random_bytes( 16 ) ); // 32 hex chars.
        update_option( self::OPTION_INDEXNOW_KEY, $key, false );

        return $key;
    }

    /**
     * Sanitize the master toggles array
     *
     * Unknown keys are dropped; known keys cast to bool.
     *
     * @param mixed $value Raw value.
     * @return array<string, bool>
     */
    public static function sanitize_readiness( $value ): array {
        $clean = array_fill_keys( self::FEATURE_TOGGLES, false );

        if ( is_array( $value ) ) {
            foreach ( self::FEATURE_TOGGLES as $toggle ) {
                if ( array_key_exists( $toggle, $value ) ) {
                    $clean[ $toggle ] = (bool) $value[ $toggle ];
                }
            }
        }

        return $clean;
    }

    /**
     * Sanitize the agent-card fields array
     *
     * @param mixed $value Raw value.
     * @return array{name: string, description: string, contact: string, capabilities: array}
     */
    public static function sanitize_agent_card( $value ): array {
        $clean = self::get_defaults( self::OPTION_AGENT_CARD );

        if ( ! is_array( $value ) ) {
            return $clean;
        }

        if ( isset( $value['name'] ) && is_string( $value['name'] ) ) {
            $clean['name'] = sanitize_text_field( $value['name'] );
        }
        if ( isset( $value['description'] ) && is_string( $value['description'] ) ) {
            $clean['description'] = sanitize_textarea_field( $value['description'] );
        }
        if ( isset( $value['contact'] ) && is_string( $value['contact'] ) ) {
            $clean['contact'] = sanitize_text_field( $value['contact'] );
        }
        if ( isset( $value['capabilities'] ) && is_array( $value['capabilities'] ) ) {
            $capabilities = [];
            foreach ( $value['capabilities'] as $key => $capability ) {
                if ( is_scalar( $capability ) ) {
                    $capabilities[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $capability );
                }
            }
            $clean['capabilities'] = $capabilities;
        }

        return $clean;
    }

    /**
     * Sanitize the Http_Headers allowlist entries
     *
     * Invalid entries (bad name, empty value after sanitization) are dropped.
     *
     * @param mixed $value Raw value.
     * @return array<int, array{name: string, value: string, enabled: bool}>
     */
    public static function sanitize_headers( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }

        $clean = [];
        foreach ( $value as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $name   = Http_Headers::sanitize_header_name( $entry['name'] ?? '' );
            $header = Http_Headers::sanitize_header_value( $entry['value'] ?? '' );
            if ( '' === $name || '' === $header ) {
                continue;
            }
            $clean[] = [
                'name'    => $name,
                'value'   => $header,
                'enabled' => ! empty( $entry['enabled'] ),
            ];
        }

        return $clean;
    }

    /**
     * Sanitize the IndexNow key (8–128 chars, hex digits and dashes)
     *
     * @param mixed $value Raw value.
     * @return string Valid key or ''.
     */
    public static function sanitize_indexnow_key( $value ): string {
        if ( ! is_string( $value ) ) {
            return '';
        }

        $value = trim( $value );
        if ( ! preg_match( '/^[a-fA-F0-9-]{8,128}$/', $value ) ) {
            return '';
        }

        return $value;
    }
}
