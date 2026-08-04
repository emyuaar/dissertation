<?php
/**
 * CLI class
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * CLI class for WP-CLI commands
 */
class CLI {
    /**
     * Generator instance
     *
     * @var Generator
     */
    private $generator;

    /**
     * Lifecycle emails instance
     *
     * @var \LLMagnet\Lifecycle\Lifecycle_Emails|null
     */
    private $lifecycle_emails;

    /**
     * Constructor
     *
     * @param Generator                                 $generator        Generator instance
     * @param \LLMagnet\Lifecycle\Lifecycle_Emails|null $lifecycle_emails Lifecycle emails instance
     */
    public function __construct( Generator $generator, $lifecycle_emails = null ) {
        $this->generator        = $generator;
        $this->lifecycle_emails = $lifecycle_emails;
    }

    /**
     * Regenerate llms.txt and Markdown files
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force regeneration even if recently generated
     *
     * ## EXAMPLES
     *
     *     wp llms-txt regenerate
     *     wp llms-txt regenerate --force
     *
     * @param array $args Command arguments
     * @param array $assoc_args Command associative arguments
     * @return void
     */
    public function regenerate($args, $assoc_args) {
        // Check if root is writable
        if (!$this->generator->is_root_writable()) {
            \WP_CLI::error('WordPress root directory is not writable. Cannot generate LLMS.txt.');
            return;
        }
        
        \WP_CLI::log('Generating LLMS.txt and Markdown files...');
        
        $result = $this->generator->generate_all();
        
        if ($result) {
            \WP_CLI::success('LLMS.txt and Markdown files generated successfully.');
        } else {
            \WP_CLI::error('Failed to generate LLMS.txt and Markdown files.');
        }
    }

    /**
     * Show current settings
     *
     * ## EXAMPLES
     *
     *     wp llms-txt settings
     *
     * @param array $args Command arguments
     * @param array $assoc_args Command associative arguments
     * @return void
     */
    public function settings($args, $assoc_args) {
        $settings = $this->generator->get_settings();
        
        \WP_CLI::log('LLMagnet AI SEO Optimizer Settings:');
        \WP_CLI::log('');
        
        // Post types
        \WP_CLI::log('Content Types:');
        foreach ($settings['post_types'] as $post_type) {
            \WP_CLI::log(' - ' . $post_type);
        }
        \WP_CLI::log('');
        
        // Full content
        \WP_CLI::log('Full Content: ' . ($settings['full_content'] ? 'Yes' : 'No (excerpt only)'));
        
        // Days to include
        \WP_CLI::log('Days to Include: ' . ($settings['days_to_include'] > 0 ? $settings['days_to_include'] : 'All'));
        
        // Delete on uninstall
        \WP_CLI::log('Delete on Uninstall: ' . ($settings['delete_on_uninstall'] ? 'Yes' : 'No'));
        
        // Last generated
        $last_generated = $this->generator->get_last_generated_time();
        \WP_CLI::log('Last Generated: ' . ($last_generated ? gmdate('Y-m-d H:i:s', $last_generated) : 'Never'));
    }

    /**
     * Show lifecycle email diagnostics
     *
     * ## EXAMPLES
     *
     *     wp llmagnet-ai-seo lifecycle-status
     *
     * @param array $args Command arguments
     * @param array $assoc_args Command associative arguments
     * @return void
     */
    public function lifecycle_status($args, $assoc_args) {
        $email_state = new \LLMagnet\Lifecycle\Email_State();
        $email_log = new \LLMagnet\Lifecycle\Email_Log();
        $brevo_client = new \LLMagnet\Lifecycle\Brevo_Client();

        $raw_report_email = get_option('llmagnet_report_email', '');
        $admin_email = get_option('admin_email', '');
        $resolved_recipient = \LLMagnet\Lifecycle\resolve_transactional_recipient();
        $trial_status = \LLMagnet\Lifecycle\get_trial_status();
        $onboarding_state = get_option('llmagnet_onboarding_state', []);

        \WP_CLI::log('Lifecycle Email Diagnostics');
        \WP_CLI::log('');
        \WP_CLI::log('Configuration:');
        \WP_CLI::log(' - Brevo configured: ' . $this->format_bool($brevo_client->is_configured()));
        \WP_CLI::log(' - Report email option: ' . ($raw_report_email !== '' ? $raw_report_email : '(empty)'));
        \WP_CLI::log(' - Admin email fallback: ' . ($admin_email !== '' ? $admin_email : '(empty)'));
        \WP_CLI::log(' - Resolved recipient: ' . ($resolved_recipient !== '' ? $resolved_recipient : '(none)'));
        \WP_CLI::log(' - Install timestamp: ' . $this->format_timestamp(get_option('llmagnet_install_timestamp', 0)));
        \WP_CLI::log(' - Onboarding completed: ' . $this->format_bool(!empty($onboarding_state['completed'])));
        \WP_CLI::log('');

        $domain_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $domain_param  = (is_string($domain_host) && $domain_host !== '')
            ? strtolower($domain_host)
            : '(none)';

        \WP_CLI::log('Templates:');
        \WP_CLI::log(' - params.DOMAIN (all lifecycle sends): ' . $domain_param);
        \WP_CLI::log(' - E-01 template ID: ' . intval(get_option('llmagnet_brevo_template_e01', 0)));
        \WP_CLI::log(' - E-02 template ID: ' . intval(get_option('llmagnet_brevo_template_e02', 0)));
        \WP_CLI::log(' - E-05 template ID: ' . intval(get_option('llmagnet_brevo_template_e05', 0)));
        \WP_CLI::log('');

        \WP_CLI::log('Trial Status:');
        \WP_CLI::log(' - In trial: ' . $this->format_bool(!empty($trial_status['is_trial'])));
        \WP_CLI::log(' - Trial started: ' . $this->format_timestamp(isset($trial_status['trial_started_ts']) ? $trial_status['trial_started_ts'] : 0));
        \WP_CLI::log(' - Trial ends: ' . $this->format_timestamp(isset($trial_status['trial_ends_ts']) ? $trial_status['trial_ends_ts'] : 0));
        \WP_CLI::log(' - Trial days remaining: ' . intval(isset($trial_status['trial_days_remaining']) ? $trial_status['trial_days_remaining'] : 0));
        \WP_CLI::log('');

        \WP_CLI::log('State:');
        $this->log_email_state_line($email_state, $email_log, 'e01_onboarding_incomplete', 'E-01');
        $this->log_email_state_line($email_state, $email_log, 'e02_first_bot_visit', 'E-02');
        $this->log_email_state_line($email_state, $email_log, 'e05_trial_day_5', 'E-05');
        \WP_CLI::log(' - Install contact synced: ' . $this->format_bool((bool) $email_state->get('brevo_contact_synced', false)));
        \WP_CLI::log(' - Install contact synced at: ' . $this->format_timestamp($email_state->get('brevo_contact_synced_at', 0)));
        \WP_CLI::log(' - Install contact email: ' . ($email_state->get('brevo_contact_email', '') ?: '(none)'));
    }

    /**
     * Log a single lifecycle email state line.
     *
     * @param \LLMagnet\Lifecycle\Email_State $email_state Email state manager.
     * @param \LLMagnet\Lifecycle\Email_Log   $email_log   Email log manager.
     * @param string                          $email_key   Email key.
     * @param string                          $label       Human-readable label.
     *
     * @return void
     */
    private function log_email_state_line(
        \LLMagnet\Lifecycle\Email_State $email_state,
        \LLMagnet\Lifecycle\Email_Log $email_log,
        $email_key,
        $label
    ) {
        $stats = $email_log->get_email_stats($email_key);
        $state = $email_state->get_email_state($email_key);

        $parts = [
            $label,
            'sent=' . $this->format_bool($email_state->was_sent($email_key)),
            'sent_at=' . $this->format_timestamp(isset($state['sent_at']) ? $state['sent_at'] : 0),
            'attempts=' . intval($stats['total_attempts']),
            'success=' . intval($stats['successful']),
            'failed=' . intval($stats['failed']),
            'skipped=' . intval($stats['skipped']),
            'last_attempt=' . $this->format_timestamp($stats['last_attempt_ts']),
        ];

        \WP_CLI::log(' - ' . implode(' | ', $parts));
    }

    /**
     * Format a boolean for CLI output.
     *
     * @param bool $value Boolean value.
     *
     * @return string
     */
    private function format_bool($value) {
        return $value ? 'yes' : 'no';
    }

    /**
     * Force-send a lifecycle email for testing (bypasses dedup and trigger conditions)
     *
     * ## OPTIONS
     *
     * <email-key>
     * : Email key to send. One of: e01_onboarding_incomplete, e02_first_bot_visit, e05_trial_day_5
     *
     * [--recipient=<email>]
     * : Override recipient email address. Defaults to the configured report/admin email.
     *
     * ## EXAMPLES
     *
     *     wp llmagnet-ai-seo send-test e01_onboarding_incomplete
     *     wp llmagnet-ai-seo send-test e05_trial_day_5
     *     wp llmagnet-ai-seo send-test e02_first_bot_visit --recipient=test@example.com
     *
     * Every test send includes params.DOMAIN (host from home_url). E-02 also sends PAGE_PATH/PAGE_TITLE for page links.
     *
     * @param array $args       Command arguments.
     * @param array $assoc_args Command associative arguments.
     * @return void
     */
    public function send_test( $args, $assoc_args ) {
        if ( ! $this->lifecycle_emails ) {
            \WP_CLI::error( 'Lifecycle emails instance not available.' );
            return;
        }

        $valid_keys = [
            'e01_onboarding_incomplete',
            'e02_first_bot_visit',
            'e05_trial_day_5',
        ];

        $email_key = isset( $args[0] ) ? trim( $args[0] ) : '';
        if ( ! in_array( $email_key, $valid_keys, true ) ) {
            \WP_CLI::error(
                'Invalid email key. Valid keys: ' . implode( ', ', $valid_keys )
            );
            return;
        }

        $recipient = isset( $assoc_args['recipient'] ) ? trim( $assoc_args['recipient'] ) : '';

        \WP_CLI::log( 'Sending test email: ' . $email_key . ' ...' );

        $result = $this->lifecycle_emails->force_send_test( $email_key, $recipient );

        \WP_CLI::log( 'Recipient : ' . ( $result['recipient'] ?? '(none)' ) );
        \WP_CLI::log( 'Payload   : ' . wp_json_encode( $result['payload'] ?? [] ) );

        if ( ! empty( $result['success'] ) ) {
            \WP_CLI::success( 'Email sent successfully.' );
        } else {
            \WP_CLI::error(
                sprintf(
                    '[%s] %s',
                    $result['error_code'] ?? 'ERROR',
                    $result['error_message'] ?? 'Unknown error'
                )
            );
        }
    }

    /**
     * Format a timestamp for CLI output.
     *
     * @param int $timestamp Unix timestamp.
     *
     * @return string
     */
    private function format_timestamp($timestamp) {
        $timestamp = intval($timestamp);
        if ($timestamp <= 0) {
            return '(none)';
        }

        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }

    /**
     * Store the Brevo API key encrypted in wp_options (no plaintext in DB).
     *
     * ## OPTIONS
     *
     * <api-key>
     * : Brevo API key (xkeysib-...).
     *
     * ## EXAMPLES
     *
     *     wp llmagnet-ai-seo brevo-set-key xkeysib-...
     *
     * @param array $args Positional args.
     * @return void
     */
    public function brevo_set_key( $args ) {
        $key = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
        if ( $key === '' ) {
            \WP_CLI::error( 'API key is required.' );
        }
        if ( ! class_exists( '\LLMagnet_AI_SEO_Optimizer\Brevo_Key_Store' ) ) {
            require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-key-store.php';
        }
        if ( \LLMagnet_AI_SEO_Optimizer\Brevo_Key_Store::save_plaintext_key( $key ) ) {
            \WP_CLI::success( 'Brevo API key saved (encrypted in wp_options).' );
        } else {
            \WP_CLI::error( 'Could not save API key. Check OpenSSL is available.' );
        }
    }

    /**
     * Remove the stored Brevo API key from wp_options.
     *
     * ## EXAMPLES
     *
     *     wp llmagnet-ai-seo brevo-clear-key
     *
     * @return void
     */
    public function brevo_clear_key() {
        if ( ! class_exists( '\LLMagnet_AI_SEO_Optimizer\Brevo_Key_Store' ) ) {
            require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-brevo-key-store.php';
        }
        if ( \LLMagnet_AI_SEO_Optimizer\Brevo_Key_Store::clear_key() ) {
            \WP_CLI::success( 'Stored Brevo API key removed.' );
        } else {
            \WP_CLI::log( 'No stored key to remove.' );
        }
    }

    /**
     * Generate and store an MCP REST Bearer token (Authorization: Bearer ...).
     *
     * ## EXAMPLES
     *
     *     wp llmagnet-ai-seo mcp-set-token
     *
     * @return void
     */
    public function mcp_set_token() {
        $token = wp_generate_password( 64, false, true );
        update_option( 'llmagnet_mcp_api_token', $token, false );
        \WP_CLI::log( 'Use header: Authorization: Bearer <token>' );
        \WP_CLI::log( $token );
        \WP_CLI::success( 'MCP API token saved to wp_options.' );
    }

    /**
     * Remove stored MCP Bearer token (browser/Application Password auth still work).
     *
     * ## EXAMPLES
     *
     *     wp llmagnet-ai-seo mcp-clear-token
     *
     * @return void
     */
    public function mcp_clear_token() {
        delete_option( 'llmagnet_mcp_api_token' );
        \WP_CLI::success( 'MCP API token removed.' );
    }
} 