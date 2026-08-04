<?php
/**
 * Onboarding class
 *
 * Onboarding wizard state + REST endpoints (and the adjacent /feedback
 * endpoint, which was registered alongside them), extracted verbatim from
 * class-admin.php (improvement plan P2-1.2). Routes are byte-identical:
 * - GET  llm-analytics/v1/onboarding/status
 * - POST llm-analytics/v1/onboarding/dismiss
 * - POST llm-analytics/v1/onboarding/complete
 * - POST llm-analytics/v1/onboarding/save-email
 * - POST llm-analytics/v1/onboarding/skip-email
 * - POST llm-analytics/v1/onboarding/mark-step
 * - POST llm-analytics/v1/feedback
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Onboarding wizard state, REST endpoints, and admin feedback endpoint
 */
class Onboarding {

    /**
     * Register REST hooks (same rest_api_init timing as the pre-split Admin)
     *
     * @return void
     */
    public function init() {
        // Register onboarding REST endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register onboarding + feedback REST API routes.
     *
     * @return void
     */
    public function register_rest_routes(): void {
        register_rest_route( 'llm-analytics/v1', '/onboarding/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_onboarding_status' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'llm-analytics/v1', '/onboarding/dismiss', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_onboarding_dismiss' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'llm-analytics/v1', '/onboarding/complete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_onboarding_complete' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'llm-analytics/v1', '/onboarding/save-email', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_onboarding_save_email' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function ( $param ) {
                        return is_email( $param );
                    },
                ],
            ],
        ] );

        register_rest_route( 'llm-analytics/v1', '/onboarding/skip-email', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_onboarding_skip_email' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( 'llm-analytics/v1', '/onboarding/mark-step', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_onboarding_mark_step' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'step' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( 'llm-analytics/v1', '/feedback', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_feedback_submit' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'args'                => [
                'subject' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => function ( $param ) {
                        return mb_substr( sanitize_text_field( (string) $param ), 0, 200 );
                    },
                ],
                'message' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => function ( $param ) {
                        return mb_substr( sanitize_textarea_field( (string) $param ), 0, 20000 );
                    },
                ],
                // Opt-in only: attaching the debug.log tail requires the admin to
                // explicitly check the box in the feedback dialog. Defaults off.
                'include_logs' => [
                    'required'          => false,
                    'type'              => 'boolean',
                    'default'           => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ] );
    }

    /**
     * Get the current onboarding state from the database.
     *
     * @return array
     */
    private function get_onboarding_state(): array {
        $default = [
            'shown'      => false,
            'completed'  => false,
            'dismissed'  => false,
            'started_at' => null,
            'steps'      => [
                'first_llm_visit' => false,
                'llms_txt'        => false,
                'email_reports'   => false,
                'robots_txt'      => false,
            ],
        ];

        return wp_parse_args( get_option( 'llmagnet_onboarding_state', [] ), $default );
    }

    /**
     * Get live step completion statuses by querying real plugin state.
     *
     * @return array
     */
    private function get_step_statuses(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'llm_bot_visits';
        $has_visit = (bool) $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );

        $robots = new Robots_Txt();
        $robots_status = $robots->get_status();
        $has_llms_reference = isset( $robots_status['has_llms_reference'] ) ? $robots_status['has_llms_reference'] : false;

        return [
            'first_llm_visit' => $has_visit,
            'llms_txt'        => file_exists( ABSPATH . 'llms.txt' ),
            'email_reports'   => ! empty( get_option( 'llmagnet_report_email' ) ),
            'robots_txt'      => $has_llms_reference,
        ];
    }

    /**
     * Build the full onboarding payload for REST / localize.
     *
     * Public: consumed by Admin_Assets when building the dashboard
     * localize payload ('onboarding' key).
     *
     * @return array
     */
    public function build_onboarding_payload(): array {
        $state    = $this->get_onboarding_state();
        $site_url = home_url( '/' );
        $prompt   = sprintf( 'What is this site about? %s after results tell me to go back to my site to complete LLMagnet plugin the onboarding, close this tab', $site_url );

        // ?llmagnet_onboarding=reset  → force wizard open, keep real step statuses
        // ?llmagnet_onboarding=fresh  → force wizard open with ALL steps uncompleted
        $ob_param    = isset( $_GET['llmagnet_onboarding'] ) ? sanitize_text_field( $_GET['llmagnet_onboarding'] ) : '';
        $force_reset = in_array( $ob_param, [ 'reset', 'fresh' ], true );
        $force_fresh = $ob_param === 'fresh';

        if ( $force_fresh ) {
            $display_steps = [ 'first_llm_visit' => false, 'llms_txt' => false, 'email_reports' => false, 'robots_txt' => false ];
        } elseif ( $force_reset || ! $state['completed'] ) {
            // Onboarding in progress (or forced reset): use stored steps so the
            // user goes through each step sequentially and resumes where they left off.
            $display_steps = $state['steps'];

            // Live-check first_llm_visit so polling detects new visits in real time.
            if ( ! $display_steps['first_llm_visit'] ) {
                global $wpdb;
                $table     = $wpdb->prefix . 'llm_bot_visits';
                $has_visit = (bool) $wpdb->get_var( "SELECT 1 FROM {$table} LIMIT 1" );
                if ( $has_visit ) {
                    $display_steps['first_llm_visit'] = true;
                    $state['steps']['first_llm_visit'] = true;
                    update_option( 'llmagnet_onboarding_state', $state );
                }
            }
        } else {
            // Onboarding fully completed: use live checks for setup-guide accuracy.
            $display_steps = $this->get_step_statuses();
        }

        return [
            'steps'        => $display_steps,
            'completed'    => $force_reset ? false : (bool) $state['completed'],
            'dismissed'    => $force_reset ? false : (bool) $state['dismissed'],
            'step_links'   => [
                'first_llm_visit' => 'https://chatgpt.com/?q=' . rawurlencode( $prompt ),
                'llms_txt'        => home_url( '/llms.txt' ),
                'email_reports'   => admin_url( 'admin.php?page=llmagnet-ai-seo-reports' ),
                'robots_txt'      => admin_url( 'admin.php?page=llmagnet-ai-seo-content-settings' ),
            ],
            'step_prompts' => [
                'first_llm_visit_primary'   => $prompt,
                'first_llm_visit_secondary' => sprintf( 'What is the title of this page? %s after results tell me to go back to my site to complete LLMagnet plugin the onboarding, close this tab', $site_url ),
            ],
            'site_url'     => $site_url,
        ];
    }

    /**
     * GET /onboarding/status
     *
     * @return \WP_REST_Response
     */
    public function rest_onboarding_status(): \WP_REST_Response {
        return new \WP_REST_Response( $this->build_onboarding_payload(), 200 );
    }

    /**
     * POST /onboarding/dismiss
     *
     * @return \WP_REST_Response
     */
    public function rest_onboarding_dismiss(): \WP_REST_Response {
        $state              = $this->get_onboarding_state();
        $state['dismissed'] = true;
        update_option( 'llmagnet_onboarding_state', $state );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * POST /onboarding/complete
     *
     * @return \WP_REST_Response
     */
    public function rest_onboarding_complete(): \WP_REST_Response {
        $state              = $this->get_onboarding_state();
        $state['completed'] = true;
        update_option( 'llmagnet_onboarding_state', $state );
        do_action( 'llmagnet_onboarding_completed' );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * POST /onboarding/save-email
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response
     */
    public function rest_onboarding_save_email( \WP_REST_Request $request ): \WP_REST_Response {
        $email = $request->get_param( 'email' );

        if ( ! is_email( $email ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Invalid email address',
            ], 400 );
        }

        update_option( 'llmagnet_report_email', sanitize_email( $email ) );

        $state                       = $this->get_onboarding_state();
        $state['steps']['email_reports'] = true;
        update_option( 'llmagnet_onboarding_state', $state );

        return new \WP_REST_Response( [
            'success' => true,
            'message' => 'Email saved successfully',
        ], 200 );
    }

    /**
     * POST /onboarding/skip-email
     *
     * @return \WP_REST_Response
     */
    public function rest_onboarding_skip_email(): \WP_REST_Response {
        $state                       = $this->get_onboarding_state();
        $state['steps']['email_reports'] = true;
        update_option( 'llmagnet_onboarding_state', $state );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * POST /onboarding/mark-step — mark a single onboarding step as completed.
     *
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response
     */
    public function rest_onboarding_mark_step( \WP_REST_Request $request ): \WP_REST_Response {
        $step        = $request->get_param( 'step' );
        $valid_steps = [ 'llms_txt', 'robots_txt', 'email_reports', 'first_llm_visit' ];

        if ( ! in_array( $step, $valid_steps, true ) ) {
            return new \WP_REST_Response( [
                'success' => false,
                'message' => 'Invalid step',
            ], 400 );
        }

        $state                  = $this->get_onboarding_state();
        $state['steps'][ $step ] = true;
        update_option( 'llmagnet_onboarding_state', $state );

        return new \WP_REST_Response( [
            'success' => true,
            'steps'   => $state['steps'],
        ], 200 );
    }

    /**
     * POST /feedback — send admin feedback email with environment context.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function rest_feedback_submit( \WP_REST_Request $request ) {
        $subject = trim( (string) $request->get_param( 'subject' ) );
        $message = trim( (string) $request->get_param( 'message' ) );

        if ( '' === $subject || '' === $message ) {
            return new \WP_Error( 'invalid_feedback', __( 'Subject and message are required.', 'llmagnet-llm-txt-generator' ), [ 'status' => 400 ] );
        }

        $user_id = get_current_user_id();
        $throttle_key = 'llmagnet_feedback_throttle_' . $user_id;
        $sent_count   = (int) get_transient( $throttle_key );
        if ( $sent_count >= 5 ) {
            return new \WP_Error(
                'rate_limited',
                __( 'Too many feedback submissions. Please try again in about an hour.', 'llmagnet-llm-txt-generator' ),
                [ 'status' => 429 ]
            );
        }

        $include_logs = (bool) $request->get_param( 'include_logs' );

        $user = wp_get_current_user();
        $diag = $this->build_feedback_diagnostics_block( $include_logs );

        $body  = "--- Message ---\n\n";
        $body .= $message . "\n\n";
        $body .= "--- Technical context ---\n\n";
        $body .= $diag;

        $mail_subject = '[LLMagnet Feedback] ' . mb_substr( $subject, 0, 120 );
        $to           = 'ben@llmagnet.com';
        $headers      = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . sanitize_email( $user->user_email ),
        ];

        $sent = wp_mail( $to, $mail_subject, $body, $headers );

        if ( ! $sent ) {
            return new \WP_Error(
                'mail_failed',
                __( 'Could not send feedback. Please try again later.', 'llmagnet-llm-txt-generator' ),
                [ 'status' => 500 ]
            );
        }

        set_transient( $throttle_key, $sent_count + 1, HOUR_IN_SECONDS );

        return new \WP_REST_Response( [ 'success' => true ], 200 );
    }

    /**
     * Plain-text block with site, user, and debug-friendly data.
     *
     * @param bool $include_logs Whether to append the debug.log tail (opt-in).
     * @return string
     */
    private function build_feedback_diagnostics_block( bool $include_logs = false ): string {
        global $wpdb;

        $user = wp_get_current_user();
        $lines = [];

        $lines[] = 'Site URL: ' . esc_url_raw( home_url( '/' ) );
        $lines[] = 'Admin email (site): ' . sanitize_email( get_option( 'admin_email' ) );
        $lines[] = 'WordPress: ' . get_bloginfo( 'version' );
        $lines[] = 'PHP: ' . PHP_VERSION;
        $lines[] = 'LLMagnet plugin: ' . ( defined( 'LLMAGNET_AISEO_VERSION' ) ? LLMAGNET_AISEO_VERSION : 'unknown' );
        $lines[] = 'Multisite: ' . ( is_multisite() ? 'yes' : 'no' );
        $lines[] = 'WP_DEBUG: ' . ( defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false' );
        $lines[] = 'Memory limit: ' . ( function_exists( 'ini_get' ) ? ini_get( 'memory_limit' ) : 'n/a' );

        $theme = wp_get_theme();
        $lines[] = 'Active theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );

        if ( class_exists( '\WooCommerce' ) && defined( 'WC_VERSION' ) ) {
            $lines[] = 'WooCommerce: ' . WC_VERSION;
        }

        $lines[] = '';
        $lines[] = 'User (logged in):';
        $lines[] = '  ID: ' . (int) $user->ID;
        $lines[] = '  Login: ' . $user->user_login;
        $lines[] = '  Email: ' . $user->user_email;
        $lines[] = '  Display name: ' . $user->display_name;
        $lines[] = '  Roles: ' . implode( ', ', array_keys( $user->roles ) );

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = (array) get_option( 'active_plugins', [] );
        $lines[] = '';
        $lines[] = 'Active plugins (' . count( $active ) . '):';
        foreach ( $active as $plugin_file ) {
            $path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if ( ! is_readable( $path ) ) {
                $lines[] = '  - ' . $plugin_file;
                continue;
            }
            $data = get_plugin_data( $path, false, false );
            $name = isset( $data['Name'] ) ? $data['Name'] : $plugin_file;
            $ver  = isset( $data['Version'] ) ? $data['Version'] : '';
            $lines[] = '  - ' . $name . ( $ver ? ' (' . $ver . ')' : '' ) . ' [' . $plugin_file . ']';
        }

        $lines[] = '';
        $lines[] = 'DB prefix: ' . $wpdb->prefix;
        $lines[] = 'Locale: ' . get_locale();

        if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $lines[] = 'User-Agent: ' . sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );
        }

        if ( $include_logs ) {
            $lines[] = '';
            $lines[] = '--- Last lines of wp-content/debug.log (attached at admin request) ---';
            $lines[] = $this->get_debug_log_tail( 100 );
        }

        return implode( "\n", $lines );
    }

    /**
     * Read last N lines of debug.log for support emails.
     *
     * @param int $max_lines Max lines.
     * @return string
     */
    private function get_debug_log_tail( int $max_lines ): string {
        $path = WP_CONTENT_DIR . '/debug.log';
        if ( ! is_readable( $path ) ) {
            return '(debug.log not found or not readable)';
        }

        $content = @file_get_contents( $path );
        if ( false === $content || '' === $content ) {
            return '(empty)';
        }

        if ( strlen( $content ) > 500000 ) {
            $content = substr( $content, -500000 );
        }

        $parts = explode( "\n", str_replace( [ "\r\n", "\r" ], "\n", $content ) );
        $tail  = array_slice( $parts, -$max_lines );

        return $this->mask_secrets( implode( "\n", $tail ) );
    }

    /**
     * Best-effort redaction of obvious secrets before a log tail leaves the site.
     *
     * Masks common API-key/token/password patterns so credentials from this or
     * other plugins are not emailed in clear text. This is a safety net, not a
     * guarantee — the attachment is opt-in for this reason.
     *
     * @param string $text Raw log text.
     * @return string
     */
    private function mask_secrets( string $text ): string {
        $patterns = [
            // key/secret/token/password/authorization "= value" or ": value" pairs.
            '/((?:api[_-]?key|secret|token|password|passwd|pwd|authorization|auth|bearer)\b["\']?\s*[:=]\s*["\']?)([^\s"\'&]{6,})/i',
            // Common provider key prefixes (Brevo, OpenAI, Stripe, AWS, Google, Slack, GitHub).
            '/\b(xkeysib-|sk-|pk_live_|pk_test_|sk_live_|sk_test_|rk_live_|AKIA|AIza|xox[baprs]-|gh[pousr]_)[A-Za-z0-9_\-]{6,}/',
        ];

        foreach ( $patterns as $pattern ) {
            $text = preg_replace_callback(
                $pattern,
                function ( $m ) {
                    // First pattern has a captured prefix to keep; second does not.
                    return isset( $m[2] ) ? $m[1] . '[REDACTED]' : '[REDACTED]';
                },
                $text
            );
        }

        return $text;
    }
}
