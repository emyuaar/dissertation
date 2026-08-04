<?php
/**
 * Security header toggles (agent-readiness-spec F8.1 — FA-7)
 *
 * Five individually toggleable response headers, emitted through the
 * Http_Headers manager (Phase 0.2) so the skip-if-present rule applies to
 * every one of them — host/CDN/security-plugin headers always win and the
 * audit reports who serves them.
 *
 * | Toggle key   | Header                                | Default value                                  |
 * |--------------|---------------------------------------|------------------------------------------------|
 * | nosniff      | X-Content-Type-Options                | nosniff                                        |
 * | referrer     | Referrer-Policy                       | strict-origin-when-cross-origin                |
 * | permissions  | Permissions-Policy                    | camera=(), microphone=(), geolocation=()       |
 * | hsts         | Strict-Transport-Security             | max-age=31536000 (+; includeSubDomains opt-in) |
 * | csp_report   | Content-Security-Policy-Report-Only   | generated starter policy (see below)           |
 *
 * Per spec the first three default ON *when the security_headers feature
 * toggle is enabled*; hsts/csp_report default OFF even then. The master
 * `security_headers` feature toggle itself ships OFF, so a fresh install
 * emits nothing.
 *
 * HSTS extra rules: only ever emitted on HTTPS requests; includeSubDomains
 * is a separate explicit opt-in (UI carries warning copy — it can break
 * non-HTTPS subdomains for months).
 *
 * CSP-Report-Only: a starter policy generated from the asset origins found
 * on the homepage (script/style/img/font hosts), stored at generation time.
 * NEVER auto-promoted to enforcing — promotion is a manual copy-paste
 * instruction in the audit report.
 *
 * Option `llmagnet_security_headers` (autoload off):
 * [ 'nosniff' => bool, 'referrer' => bool, 'permissions' => bool,
 *   'hsts' => bool, 'hsts_subdomains' => bool, 'csp_report' => bool,
 *   'csp_policy' => string ]
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * F8.1 security headers via Http_Headers.
 */
class Security_Headers {

    /**
     * Per-header toggle option (autoload off).
     */
    const OPTION = 'llmagnet_security_headers';

    /**
     * Defaults applied when the master feature toggle is ON.
     *
     * @var array
     */
    const DEFAULTS = [
        'nosniff'         => true,
        'referrer'        => true,
        'permissions'     => true,
        'hsts'            => false,
        'hsts_subdomains' => false,
        'csp_report'      => false,
        'csp_policy'      => '',
    ];

    /**
     * Wire hooks.
     *
     * @return void
     */
    public function init(): void {
        add_filter( 'llmagnet_http_headers', [ $this, 'filter_http_headers' ] );
    }

    /**
     * Whether the security_headers feature toggle is on (default OFF).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return class_exists( __NAMESPACE__ . '\\Agent_Readiness_Options' )
            && Agent_Readiness_Options::is_feature_enabled( 'security_headers' );
    }

    /**
     * Per-header settings merged over defaults.
     *
     * @return array
     */
    public static function get_settings(): array {
        $stored = get_option( self::OPTION, [] );
        return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] );
    }

    /**
     * llmagnet_http_headers filter: append enabled security headers.
     *
     * Http_Headers re-sanitizes and applies skip-if-present after this.
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

        foreach ( self::build_headers() as $entry ) {
            $headers[] = $entry;
        }

        return $headers;
    }

    /**
     * The header entries this feature currently wants to emit.
     *
     * Public so the audit can compare intent vs. observed response.
     *
     * @return array<int, array{name: string, value: string}>
     */
    public static function build_headers(): array {
        $settings = self::get_settings();
        $entries  = [];

        if ( ! empty( $settings['nosniff'] ) ) {
            $entries[] = [
                'name'  => 'X-Content-Type-Options',
                'value' => 'nosniff',
            ];
        }

        if ( ! empty( $settings['referrer'] ) ) {
            $entries[] = [
                'name'  => 'Referrer-Policy',
                'value' => 'strict-origin-when-cross-origin',
            ];
        }

        if ( ! empty( $settings['permissions'] ) ) {
            $entries[] = [
                'name'  => 'Permissions-Policy',
                'value' => 'camera=(), microphone=(), geolocation=()',
            ];
        }

        // HSTS: HTTPS responses only — emitting it over HTTP is meaningless
        // and emitting it on a partly-HTTP site can lock visitors out.
        if ( ! empty( $settings['hsts'] ) && is_ssl() ) {
            $value = 'max-age=31536000';
            if ( ! empty( $settings['hsts_subdomains'] ) ) {
                $value .= '; includeSubDomains';
            }
            $entries[] = [
                'name'  => 'Strict-Transport-Security',
                'value' => $value,
            ];
        }

        if ( ! empty( $settings['csp_report'] ) ) {
            $policy = is_string( $settings['csp_policy'] ) ? trim( $settings['csp_policy'] ) : '';
            if ( '' === $policy ) {
                $policy = self::fallback_csp_policy();
            }
            if ( '' !== $policy ) {
                $entries[] = [
                    'name'  => 'Content-Security-Policy-Report-Only',
                    'value' => $policy,
                ];
            }
        }

        return $entries;
    }

    /**
     * Generate a starter CSP from the homepage's asset origins and store it.
     *
     * Called from the settings REST endpoint when csp_report is switched on
     * (never on the front-end request path — it does a loopback fetch).
     *
     * @return string The generated policy ('' on fetch failure).
     */
    public static function generate_starter_csp(): string {
        $response = wp_remote_get(
            home_url( '/' ),
            [
                'timeout'   => 10,
                'sslverify' => false, // Local/dev certs; loopback to self.
            ]
        );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return '';
        }

        $origins = [
            'script' => [],
            'style'  => [],
            'img'    => [],
            'font'   => [],
        ];

        if ( preg_match_all( '/<script\b[^>]*src=["\']([^"\']+)["\']/i', $body, $m ) ) {
            $origins['script'] = $m[1];
        }
        if ( preg_match_all( '/<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\']/i', $body, $m ) ) {
            $origins['style'] = $m[1];
        }
        if ( preg_match_all( '/<link\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']stylesheet["\']/i', $body, $m ) ) {
            $origins['style'] = array_merge( $origins['style'], $m[1] );
        }
        if ( preg_match_all( '/<img\b[^>]*src=["\']([^"\']+)["\']/i', $body, $m ) ) {
            $origins['img'] = $m[1];
        }

        $directives = [ "default-src 'self'" ];
        $map        = [
            'script' => "script-src 'self' 'unsafe-inline'",
            'style'  => "style-src 'self' 'unsafe-inline'",
            'img'    => "img-src 'self' data:",
            'font'   => "font-src 'self' data:",
        ];

        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );

        foreach ( $map as $kind => $base ) {
            $hosts = [];
            foreach ( $origins[ $kind ] as $url ) {
                $host = wp_parse_url( $url, PHP_URL_HOST );
                if ( is_string( $host ) && '' !== $host && $host !== $home_host && ! in_array( $host, $hosts, true ) ) {
                    $hosts[] = $host;
                }
            }
            $directives[] = $base . ( $hosts ? ' ' . implode( ' ', array_map( 'esc_attr', $hosts ) ) : '' );
        }

        $policy = implode( '; ', $directives );
        $policy = Http_Headers::sanitize_header_value( $policy );

        $settings               = self::get_settings();
        $settings['csp_policy'] = $policy;
        update_option( self::OPTION, $settings, false );

        return $policy;
    }

    /**
     * Conservative same-origin policy used when no generated one is stored.
     *
     * @return string
     */
    private static function fallback_csp_policy(): string {
        return "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:";
    }
}
