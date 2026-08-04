<?php
/**
 * HTTP response headers manager
 *
 * Single choke point for every HTTP response header the plugin emits on the
 * front end — F6 Link headers, F8 security headers, etc.
 * (agent-readiness-spec Phase 0.2)
 *
 * ## Sources of headers (merged at emit time)
 *
 * 1. The `llmagnet_agent_headers` option — an allowlist of stored entries:
 *    `[ 'name' => 'X-Content-Type-Options', 'value' => 'nosniff', 'enabled' => bool ]`.
 *    Managed by the Agent Ready UI (Phase E) / security-headers feature (F8).
 * 2. Runtime headers added via Http_Headers::add_header() — for per-request
 *    dynamic values such as F6 `Link: <{permalink}.md>; rel="alternate"`.
 * 3. The `llmagnet_http_headers` filter, applied to the final list so
 *    feature classes can append without holding the instance.
 *
 * ## Guarantees
 *
 * - Front-end only (`! is_admin()`); hooked on `send_headers`.
 * - Never overrides an existing header: emission is skipped entirely when
 *   `headers_sent()`, and per header when the name already appears in
 *   `headers_list()` (host / CDN / security plugin wins). The Agent-Ready
 *   audit reports who is serving it.
 * - Header names and values are validated/sanitized before emission
 *   (no CR/LF — header-injection safe).
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Front-end HTTP headers manager
 */
class Http_Headers {

    /**
     * Option holding the stored header allowlist (see Agent_Readiness_Options).
     */
    const OPTION = 'llmagnet_agent_headers';

    /**
     * Runtime (per-request) headers registered via add_header().
     *
     * @var array<int, array{name: string, value: string}>
     */
    private $runtime_headers = [];

    /**
     * Initialize hooks
     *
     * @return void
     */
    public function init(): void {
        add_action( 'send_headers', [ $this, 'emit' ] );
    }

    /**
     * Register a dynamic header for the current request
     *
     * Intended for per-request values that cannot live in the stored option
     * (e.g. F6 Link headers referencing the current permalink). Must be
     * called before `send_headers` fires.
     *
     * @param string $name  Header name (RFC 7230 token, e.g. 'Link').
     * @param string $value Header value.
     * @return bool True when accepted, false when the name/value is invalid.
     */
    public function add_header( string $name, string $value ): bool {
        $name  = self::sanitize_header_name( $name );
        $value = self::sanitize_header_value( $value );

        if ( '' === $name || '' === $value ) {
            return false;
        }

        $this->runtime_headers[] = [
            'name'  => $name,
            'value' => $value,
        ];

        return true;
    }

    /**
     * send_headers callback — emit all enabled headers
     *
     * @return void
     */
    public function emit(): void {
        if ( is_admin() || headers_sent() ) {
            return;
        }

        $headers = $this->get_emittable_headers();
        if ( empty( $headers ) ) {
            return;
        }

        $existing = self::get_existing_header_names();

        foreach ( $headers as $header ) {
            $key = strtolower( $header['name'] );

            // Never override a header already set by other code / the server.
            if ( isset( $existing[ $key ] ) ) {
                continue;
            }

            // replace=false so multiple entries of the same name from our own
            // list (e.g. several Link headers) all go out.
            header( $header['name'] . ': ' . $header['value'], false );
        }
    }

    /**
     * Build the final list of headers to emit
     *
     * Merges stored enabled entries, runtime headers, and the
     * `llmagnet_http_headers` filter; sanitizes everything.
     *
     * @return array<int, array{name: string, value: string}>
     */
    public function get_emittable_headers(): array {
        $headers = [];

        $stored = get_option( self::OPTION, [] );
        if ( is_array( $stored ) ) {
            foreach ( $stored as $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['enabled'] ) ) {
                    continue;
                }
                $name  = self::sanitize_header_name( $entry['name'] ?? '' );
                $value = self::sanitize_header_value( $entry['value'] ?? '' );
                if ( '' === $name || '' === $value ) {
                    continue;
                }
                $headers[] = [
                    'name'  => $name,
                    'value' => $value,
                ];
            }
        }

        foreach ( $this->runtime_headers as $entry ) {
            $headers[] = $entry;
        }

        /**
         * Filter the headers the plugin is about to emit on the front end.
         *
         * Entries are arrays: [ 'name' => string, 'value' => string ].
         * Skip-if-already-present still applies after this filter.
         *
         * @param array $headers List of header entries.
         */
        $headers = apply_filters( 'llmagnet_http_headers', $headers );
        if ( ! is_array( $headers ) ) {
            return [];
        }

        // Re-validate filtered output — same injection-safety bar for everyone.
        $clean = [];
        foreach ( $headers as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $name  = self::sanitize_header_name( $entry['name'] ?? '' );
            $value = self::sanitize_header_value( $entry['value'] ?? '' );
            if ( '' === $name || '' === $value ) {
                continue;
            }
            $clean[] = [
                'name'  => $name,
                'value' => $value,
            ];
        }

        return $clean;
    }

    /**
     * Whether a header (by name) is already queued for the response
     *
     * Useful for the Agent-Ready audit to report "served by host/other plugin".
     *
     * @param string $name Header name (case-insensitive).
     * @return bool
     */
    public static function is_header_already_set( string $name ): bool {
        $existing = self::get_existing_header_names();
        return isset( $existing[ strtolower( trim( $name ) ) ] );
    }

    /**
     * Lowercased set of header names already queued via headers_list()
     *
     * @return array<string, true>
     */
    private static function get_existing_header_names(): array {
        $names = [];
        foreach ( headers_list() as $raw ) {
            $pos = strpos( $raw, ':' );
            if ( false === $pos ) {
                continue;
            }
            $names[ strtolower( trim( substr( $raw, 0, $pos ) ) ) ] = true;
        }
        return $names;
    }

    /**
     * Validate a header name (RFC 7230 token subset)
     *
     * @param mixed $name Raw name.
     * @return string Valid name or ''.
     */
    public static function sanitize_header_name( $name ): string {
        if ( ! is_string( $name ) ) {
            return '';
        }
        $name = trim( $name );
        return preg_match( '/^[A-Za-z0-9-]+$/', $name ) ? $name : '';
    }

    /**
     * Sanitize a header value — strip CR/LF (header injection) and control chars
     *
     * @param mixed $value Raw value.
     * @return string Sanitized value (may be '').
     */
    public static function sanitize_header_value( $value ): string {
        if ( is_numeric( $value ) ) {
            $value = (string) $value;
        }
        if ( ! is_string( $value ) ) {
            return '';
        }
        $value = preg_replace( '/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
        return trim( (string) $value );
    }
}
