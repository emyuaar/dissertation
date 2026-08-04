<?php
/**
 * Analytics class for tracking LLM bot visits
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

// Canonical bot/crawler definitions shared with Visibility_Score
if (!class_exists('LLMagnet_AI_SEO_Optimizer\\Bot_Registry')) {
    require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-bot-registry.php';
}

/**
 * Analytics class for tracking LLM bot visits
 */
class Analytics {
    /**
     * Table name for storing LLM bot visits
     *
     * @var string
     */
    private $table_name;

    /**
     * Table name for storing LLM bot clicks/referrals
     *
     * @var string
     */
    private $clicks_table_name;
    
    /**
     * Table name for storing LLM bot page clicks
     *
     * @var string
     */
    private $page_clicks_table_name;

    /**
     * Whether bot visit detection already ran in this request.
     *
     * @var bool
     */
    private $bot_visit_detection_ran = false;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'llm_bot_visits';
        $this->clicks_table_name = $wpdb->prefix . 'llm_bot_clicks';
        $this->page_clicks_table_name = $wpdb->prefix . 'llm_bot_page_clicks';
    }

    /**
     * Initialize analytics functionality
     *
     * @return void
     */
    public function init() {
        // Register on init for early plugin boot, and on wp for the current boot path
        // where this class is initialized during init after priority 1 has passed.
        add_action('init', [$this, 'detect_and_log_llm_bot'], 1);
        add_action('wp', [$this, 'detect_and_log_llm_bot'], 1);

        // Bot clicks from UTM: frontend main query only (not init + wp)
        add_action('wp', [$this, 'detect_and_log_bot_clicks'], 1);

        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Create the database table on plugin activation
     *
     * @return void
     */
    public static function create_db_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'llm_bot_visits';
        $clicks_table_name = $wpdb->prefix . 'llm_bot_clicks';
        $charset_collate = $wpdb->get_charset_collate();

        // Create bot visits table
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            bot_name varchar(100) NOT NULL,
            user_agent longtext NOT NULL,
            page_path varchar(500) DEFAULT NULL,
            page_title varchar(500) DEFAULT NULL,
            visit_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY page_path (page_path(191)),
            KEY bot_name (bot_name),
            KEY visit_time (visit_time),
            KEY bot_time (bot_name, visit_time)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Migrate existing table to add new columns if they don't exist
        self::migrate_db_table();
        
        // Create bot clicks summary table - tracks total clicks per bot.
        // NOTE: bot_name must NOT carry an inline UNIQUE here — combined with
        // the explicit `UNIQUE KEY bot_name` below it produced
        // "Duplicate key name 'bot_name'" and the CREATE silently failed,
        // leaving wp_llm_bot_clicks missing (found at the Phase D gate).
        $sql_clicks = "CREATE TABLE $clicks_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            bot_name varchar(100) NOT NULL,
            clicks_30_days int DEFAULT 0,
            total_clicks int DEFAULT 0,
            last_click_time datetime,
            PRIMARY KEY  (id),
            UNIQUE KEY bot_name (bot_name)
        ) $charset_collate;";
        
        dbDelta($sql_clicks);
        
        // Create page clicks table - tracks clicks per page
        $page_clicks_table_name = $wpdb->prefix . 'llm_bot_page_clicks';
        $sql_page_clicks = "CREATE TABLE $page_clicks_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            page_path varchar(500) NOT NULL,
            bot_name varchar(100) NOT NULL,
            click_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY page_path (page_path(191)),
            KEY bot_name (bot_name)
        ) $charset_collate;";
        
        dbDelta($sql_page_clicks);
        
        // Pre-populate with known bot names
        self::populate_bot_names_table();
    }

    /**
     * Migrate database table to add new columns if they don't exist
     *
     * @return void
     */
    /**
     * Validate that a table name is exactly the expected prefixed plugin table.
     *
     * @param string $table_name Full table name.
     * @param string $suffix     Suffix after $wpdb->prefix (e.g. llm_bot_visits).
     * @return string|false
     */
    private static function validate_prefixed_table_name( $table_name, $suffix ) {
        global $wpdb;
        if ( $wpdb->prefix . $suffix !== $table_name ) {
            return false;
        }
        return $table_name;
    }

    public static function migrate_db_table() {
        global $wpdb;
        $table_name = self::validate_prefixed_table_name( $wpdb->prefix . 'llm_bot_visits', 'llm_bot_visits' );
        $page_clicks_table_name = self::validate_prefixed_table_name( $wpdb->prefix . 'llm_bot_page_clicks', 'llm_bot_page_clicks' );
        if ( ! $table_name || ! $page_clicks_table_name ) {
            return;
        }

        // Check if page_path column exists
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'page_path'",
                DB_NAME,
                $table_name
            )
        );
        
        if (empty($column_exists)) {
            // Add page_path column
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN page_path varchar(500) DEFAULT NULL AFTER user_agent");
            $wpdb->query("ALTER TABLE $table_name ADD INDEX page_path (page_path(191))");
        }
        
        // Check if page_title column exists
        $title_column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'page_title'",
                DB_NAME,
                $table_name
            )
        );
        
        if (empty($title_column_exists)) {
            // Add page_title column
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN page_title varchar(500) DEFAULT NULL AFTER page_path");
        }
        
        // Create page clicks table if it doesn't exist
        $page_clicks_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $page_clicks_table_name
            )
        );
        
        if (!$page_clicks_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql_page_clicks = "CREATE TABLE $page_clicks_table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                page_path varchar(500) NOT NULL,
                bot_name varchar(100) NOT NULL,
                click_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY page_path (page_path(191)),
                KEY bot_name (bot_name)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_page_clicks);
        }

        self::maybe_upgrade_visit_indexes($table_name);
    }

    /**
     * Versioned index upgrade for the bot visits table.
     *
     * Ensures the indexes used by the dashboard queries
     * (bot_name, visit_time, and the combined bot_name+visit_time lookups)
     * exist on installs created before the schema included them.
     *
     * @param string $table_name Validated bot visits table name.
     * @return void
     */
    private static function maybe_upgrade_visit_indexes($table_name) {
        global $wpdb;

        $target_version = '1.1.0';
        $installed_version = get_option('llmagnet_analytics_db_version', '1.0.0');

        if (version_compare($installed_version, $target_version, '>=')) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table_name}`", ARRAY_A);
        $existing_keys = [];
        foreach ((array) $indexes as $index) {
            if (isset($index['Key_name'])) {
                $existing_keys[$index['Key_name']] = true;
            }
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above.
        if (!isset($existing_keys['bot_name'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD INDEX bot_name (bot_name)");
        }
        if (!isset($existing_keys['visit_time'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD INDEX visit_time (visit_time)");
        }
        if (!isset($existing_keys['bot_time'])) {
            $wpdb->query("ALTER TABLE `{$table_name}` ADD INDEX bot_time (bot_name, visit_time)");
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        update_option('llmagnet_analytics_db_version', $target_version, false);
    }

    /**
     * Populate the bot clicks table with known bot names
     *
     * @return void
     */
    public static function populate_bot_names_table() {
        global $wpdb;
        $clicks_table_name = $wpdb->prefix . 'llm_bot_clicks';
        
        $bot_names = Bot_Registry::get_click_table_bot_names();
        
        $in = implode( ',', array_fill( 0, count( $bot_names ), '%s' ) );
        $sql  = "SELECT bot_name FROM `{$clicks_table_name}` WHERE bot_name IN ($in)";
        $existing_rows = $wpdb->get_col( call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $sql ], $bot_names ) ) );
        $existing_set    = is_array( $existing_rows ) ? array_flip( $existing_rows ) : [];

        foreach ( $bot_names as $bot_name ) {
            if ( isset( $existing_set[ $bot_name ] ) ) {
                continue;
            }
            $wpdb->insert(
                $clicks_table_name,
                [
                    'bot_name'       => $bot_name,
                    'clicks_30_days' => 0,
                    'total_clicks'   => 0,
                ],
                [ '%s', '%d', '%d' ]
            );
        }
    }

    /**
     * Detect and log LLM bot visits
     *
     * @return void
     */
    public function detect_and_log_llm_bot() {
        if ($this->bot_visit_detection_ran) {
            return;
        }

        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        // Check if user agent exists
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return;
        }

        $this->bot_visit_detection_ran = true;

        // Fast-fail pre-check: single stripos pass over the compiled needle list.
        // Bails immediately for regular (non-bot) traffic before any further work.
        if (!Bot_Registry::matches_ua($_SERVER['HTTP_USER_AGENT'])) {
            return;
        }

        $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
        $bot_name = $this->identify_llm_bot($user_agent);
        
        // // Add direct output to page body
        // echo '<div id="llm-bot-debug" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: #fff; padding: 20px; z-index: 99999; font-family: monospace; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.5); max-width: 80%;">';
        // echo '<h3 style="margin-top: 0; color: #ff5722;">LLM Bot Detection Debug</h3>';
        // echo '<p><strong>Function called:</strong> detect_and_log_llm_bot()</p>';
        // echo '<p><strong>User Agent:</strong> ' . esc_html($user_agent) . '</p>';
        // echo '<p><strong>Bot Detected:</strong> ' . ($bot_name ? '<span style="color: #4caf50; font-weight: bold;">' . esc_html($bot_name) . '</span>' : '<span style="color: #f44336;">None</span>') . '</p>';
        // echo '<p><strong>Time:</strong> ' . current_time('mysql') . '</p>';
        // echo '<button onclick="document.getElementById(\'llm-bot-debug\').style.display=\'none\'" style="background: #ff5722; color: white; border: none; padding: 5px 10px; cursor: pointer; border-radius: 3px;">Close</button>';
        // echo '</div>';
        
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'LLM Bot Detection Function Called: ' . ( $bot_name ? 'Bot detected: ' . $bot_name : 'No bot detected' ) . ' | User Agent: ' . $user_agent );
        }

        if ($bot_name) {
            // Get current page path - preserve Hebrew characters by URL decoding first
            $page_path = '';
            if (isset($_SERVER['REQUEST_URI'])) {
                $page_path = urldecode($_SERVER['REQUEST_URI']);
                $page_path = wp_strip_all_tags($page_path);
            }
            
            // Get page title if available
            $page_title = '';
            if (function_exists('get_the_title') && !is_admin()) {
                global $post;
                if (isset($post) && $post instanceof \WP_Post) {
                    $page_title = get_the_title($post->ID);
                }
            }
            
            $this->log_bot_visit($bot_name, $user_agent, $page_path, $page_title);
        }
    }

    /**
     * Identify LLM bot from user agent
     *
     * @param string $user_agent User agent string
     * @return string|false Bot name or false if not an LLM bot
     */
    private function identify_llm_bot($user_agent) {
        return Bot_Registry::identify_from_ua($user_agent);
    }

    /**
     * Log bot visit to database
     *
     * @param string $bot_name Bot name
     * @param string $user_agent User agent string
     * @param string $page_path Page path/URL
     * @param string $page_title Page title
     * @return bool True if successful, false otherwise
     */
    private function log_bot_visit($bot_name, $user_agent, $page_path = '', $page_title = '') {
        global $wpdb;

        // Rate-limit inserts: at most one row per bot+path per window so that a
        // bot crawl storm cannot hammer the database with hundreds of writes.
        $rate_limit_window = (int) apply_filters('llmagnet_bot_visit_rate_limit_window', 5 * MINUTE_IN_SECONDS);
        if ($rate_limit_window > 0) {
            $rate_limit_key = 'llmagnet_bv_' . md5($bot_name . '|' . $page_path);
            if (get_transient($rate_limit_key)) {
                return false;
            }
            set_transient($rate_limit_key, 1, $rate_limit_window);
        }

        $is_first_bot_visit = ! (bool) $wpdb->get_var( "SELECT id FROM {$this->table_name} LIMIT 1" );

        $is_new_bot = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE bot_name = %s",
                $bot_name
            )
        ) === 0;
        
        // Sanitize page path - use wp_strip_all_tags to preserve Hebrew/Unicode characters
        // Don't use sanitize_text_field as it can corrupt non-ASCII characters
        $page_path = wp_strip_all_tags($page_path);
        
        // Sanitize page title - preserve Hebrew/Unicode characters
        $page_title = wp_strip_all_tags($page_title);
        
        // Limit length to fit database column using multibyte-safe functions
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($page_path, 'UTF-8') > 500) {
                $page_path = mb_substr($page_path, 0, 500, 'UTF-8');
            }
            if (mb_strlen($page_title, 'UTF-8') > 500) {
                $page_title = mb_substr($page_title, 0, 500, 'UTF-8');
            }
        } else {
            // Fallback for systems without mbstring
            if (strlen($page_path) > 500) {
                $page_path = substr($page_path, 0, 500);
            }
            if (strlen($page_title) > 500) {
                $page_title = substr($page_title, 0, 500);
            }
        }
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'bot_name' => $bot_name,
                'user_agent' => $user_agent,
                'page_path' => $page_path,
                'page_title' => $page_title,
            ],
            ['%s', '%s', '%s', '%s']
        );
        
        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('LLM Bot Database Insert Failed: ' . $wpdb->last_error);
            }
        } elseif ($is_first_bot_visit) {
            do_action(
                'llmagnet_first_bot_visit_detected',
                [
                    'bot_name'   => $bot_name,
                    'page_path'  => $page_path,
                    'page_title' => $page_title,
                    'visit_time' => time(),
                    'user_agent' => $user_agent,
                ]
            );
        }

        if ( $result !== false ) {
            do_action(
                'llmagnet_bot_visit_logged',
                [
                    'bot_name'   => $bot_name,
                    'page_path'  => $page_path,
                    'page_title' => $page_title,
                    'visit_time' => current_time( 'mysql' ),
                    'user_agent' => $user_agent,
                    'is_new_bot' => $is_new_bot,
                ]
            );
        }
        
        return $result !== false;
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route('llm-analytics/v1', '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_bot_stats'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
        
        // Add endpoint for sending immediate email report
        register_rest_route('llm-analytics/v1', '/send-report', [
            'methods' => 'POST',
            'callback' => [$this, 'send_immediate_report'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);

        register_rest_route('llm-analytics/v1', '/pdf-report-email', [
            'methods' => 'POST',
            'callback' => [$this, 'send_pdf_report_email'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
        
        // Add endpoint for getting report email
        register_rest_route('llm-analytics/v1', '/report-email', [
            'methods' => 'GET',
            'callback' => [$this, 'get_report_email'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
        
        // Add endpoint for updating report email
        register_rest_route('llm-analytics/v1', '/report-email', [
            'methods' => 'POST',
            'callback' => [$this, 'update_report_email'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
        
        // Add endpoint for testing bot detection
        register_rest_route('llm-analytics/v1', '/test-bot-detection', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_test_bot_detection'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
        
        // Add endpoint for getting bot stats table data
        register_rest_route('llm-analytics/v1', '/bot-stats-table', [
            'methods' => 'GET',
            'callback' => [$this, 'get_bot_stats_table_response'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
        
        // Add endpoint for getting page stats
        register_rest_route('llm-analytics/v1', '/page-stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_page_stats_response'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'days' => [
                    'type' => 'integer',
                    'default' => 30,
                    'sanitize_callback' => 'absint',
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 500,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }
    
    /**
     * REST endpoint response for bot stats table
     *
     * @return \WP_REST_Response
     */
    public function get_bot_stats_table_response() {
        $bot_stats = $this->get_bot_stats_for_table();
        return rest_ensure_response($bot_stats);
    }
    
    /**
     * Send an immediate analytics report via email
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function send_immediate_report($request) {
        // Get the Email_Reports class instance
        global $llmagnet_email_reports;
        
        if (!isset($llmagnet_email_reports) || !is_object($llmagnet_email_reports)) {
            // Try to get the instance from the main plugin
            global $llmagnet_plugin;
            if (isset($llmagnet_plugin) && is_object($llmagnet_plugin) && isset($llmagnet_plugin->email_reports)) {
                $llmagnet_email_reports = $llmagnet_plugin->email_reports;
            } else {
                // Create a new instance if not available
                $llmagnet_email_reports = new \LLMagnet_AI_SEO_Optimizer\Email_Reports($this);
            }
        }
        
        // Check if the method exists
        if (!method_exists($llmagnet_email_reports, 'send_weekly_report')) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Email report functionality is not available.'
            ]);
        }
        
        // Send the report
        $result = $llmagnet_email_reports->send_weekly_report();
        
        if ($result === false) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Failed to send email report. Please check your email settings.'
            ]);
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Analytics report has been sent successfully.'
        ]);
    }

    /**
     * Email a PDF report (base64) to the current user's email address.
     * Saves the PDF to the uploads directory and sends a secure download link.
     *
     * @param \WP_REST_Request $request REST API request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function send_pdf_report_email($request) {
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = [];
        }

        $filename = isset($params['filename']) ? sanitize_file_name((string) $params['filename']) : 'llmagnet-report.pdf';
        if ($filename === '') {
            $filename = 'llmagnet-report.pdf';
        }
        if (!preg_match('/\.pdf$/i', $filename)) {
            $filename .= '.pdf';
        }

        $pdf_base64 = isset($params['pdf_base64']) ? $params['pdf_base64'] : '';
        if (!is_string($pdf_base64) || $pdf_base64 === '') {
            return new \WP_Error(
                'invalid_pdf',
                __('Invalid PDF payload.', 'llmagnet-llm-txt-generator'),
                ['status' => 400]
            );
        }

        $binary = base64_decode($pdf_base64, true);
        if ($binary === false || strlen($binary) > 20 * 1024 * 1024) {
            return new \WP_Error(
                'invalid_pdf',
                __('PDF is too large or could not be decoded.', 'llmagnet-llm-txt-generator'),
                ['status' => 400]
            );
        }

        $user = wp_get_current_user();
        $to = $user->user_email;
        if ($to === '') {
            return new \WP_Error(
                'no_email',
                __('Your user account has no email address.', 'llmagnet-llm-txt-generator'),
                ['status' => 400]
            );
        }

        // Save PDF to uploads/llmagnet-reports/ with a UUID token for security.
        // Files are auto-cleaned after 30 days via cleanup_old_pdf_reports().
        $upload_dir   = wp_upload_dir();
        $reports_dir  = trailingslashit($upload_dir['basedir']) . 'llmagnet-reports/';
        $reports_url  = trailingslashit($upload_dir['baseurl']) . 'llmagnet-reports/';

        if (!file_exists($reports_dir)) {
            wp_mkdir_p($reports_dir);
            // Block direct directory listing
            Filesystem_Helper::put_contents( $reports_dir . 'index.php', '<?php // Silence is golden.' );
        }

        $token         = wp_generate_uuid4();
        $stored_name   = $token . '-' . $filename;
        $filepath      = $reports_dir . $stored_name;
        $download_url  = $reports_url . $stored_name;

        if ( ! Filesystem_Helper::put_contents( $filepath, $binary ) ) {
            return new \WP_Error(
                'file_write',
                __('Could not save report file.', 'llmagnet-llm-txt-generator'),
                ['status' => 500]
            );
        }

        // Clean up report files older than 30 days
        $this->cleanup_old_pdf_reports($reports_dir);

        $report_type  = isset($params['report_type']) ? sanitize_text_field((string) $params['report_type']) : __('Analytics', 'llmagnet-llm-txt-generator');
        $site_name    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $site_url     = home_url();
        $display_name = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $generated_at = wp_date('F j, Y \a\t g:i a');

        $subject = sprintf(
            /* translators: 1: site name, 2: report label */
            __('[%1$s] LLMagnet — %2$s Report', 'llmagnet-llm-txt-generator'),
            $site_name,
            $report_type
        );

        $body = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . esc_html($subject) . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f3ff;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f5f3ff;padding:32px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(109,40,217,0.08);">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#7c3aed 0%,#a855f7 50%,#db2777 100%);padding:28px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td>
                  <span style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.3px;">LLMagnet</span>
                  <span style="font-size:13px;color:rgba(255,255,255,0.75);margin-left:8px;">AI SEO Optimizer</span>
                </td>
                <td align="right">
                  <span style="background:rgba(255,255,255,0.18);color:#ffffff;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;letter-spacing:0.5px;text-transform:uppercase;">' . esc_html($report_type) . ' Report</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:32px 32px 24px;">

            <p style="margin:0 0 8px;font-size:15px;color:#111827;">Hi ' . esc_html($display_name) . ',</p>
            <p style="margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.6;">
              Your <strong style="color:#7c3aed;">' . esc_html($report_type) . '</strong> analytics report is ready. Click the button below to download your PDF report.
            </p>

            <!-- Download CTA -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;">
              <tr>
                <td align="center" style="padding:28px 24px;background:linear-gradient(135deg,#faf5ff 0%,#f0fdf4 100%);border:2px dashed #c4b5fd;border-radius:10px;">
                  <p style="margin:0 0 6px;font-size:13px;color:#7c3aed;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;">Your Report is Ready</p>
                  <p style="margin:0 0 20px;font-size:13px;color:#6b7280;">📄 ' . esc_html($filename) . '</p>
                  <a href="' . esc_url($download_url) . '" style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#db2777);color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;letter-spacing:0.2px;">
                    ⬇ Download PDF Report
                  </a>
                  <p style="margin:16px 0 0;font-size:11px;color:#9ca3af;">Link expires in 30 days</p>
                </td>
              </tr>
            </table>

            <!-- Info card -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:8px;margin-bottom:24px;">
              <tr>
                <td style="padding:20px 24px;">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td style="padding-bottom:10px;border-bottom:1px solid #e9d5ff;">
                        <span style="font-size:11px;color:#9333ea;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;">Report Details</span>
                      </td>
                    </tr>
                    <tr>
                      <td style="padding-top:14px;">
                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                          <tr>
                            <td style="font-size:13px;color:#6b7280;padding-bottom:8px;width:130px;">Site</td>
                            <td style="font-size:13px;color:#111827;font-weight:500;padding-bottom:8px;">
                              <a href="' . esc_url($site_url) . '" style="color:#7c3aed;text-decoration:none;">' . esc_html($site_name) . '</a>
                            </td>
                          </tr>
                          <tr>
                            <td style="font-size:13px;color:#6b7280;padding-bottom:8px;">Report type</td>
                            <td style="font-size:13px;color:#111827;font-weight:500;padding-bottom:8px;">' . esc_html($report_type) . '</td>
                          </tr>
                          <tr>
                            <td style="font-size:13px;color:#6b7280;padding-bottom:8px;">Generated</td>
                            <td style="font-size:13px;color:#111827;font-weight:500;padding-bottom:8px;">' . esc_html($generated_at) . '</td>
                          </tr>
                          <tr>
                            <td style="font-size:13px;color:#6b7280;">File</td>
                            <td style="font-size:13px;color:#111827;font-weight:500;">
                              <span style="background:#ede9fe;color:#7c3aed;padding:2px 8px;border-radius:4px;font-size:12px;">📄 ' . esc_html($filename) . '</span>
                            </td>
                          </tr>
                        </table>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>

          </td>
        </tr>

        <!-- CTA -->
        <tr>
          <td style="padding:0 32px 32px;">
            <a href="' . esc_url($site_url) . '/wp-admin/admin.php?page=llmagnet-ai-seo-optimizer" style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#db2777);color:#ffffff;font-size:13px;font-weight:600;text-decoration:none;padding:11px 22px;border-radius:8px;">
              View Dashboard →
            </a>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#faf5ff;border-top:1px solid #e9d5ff;padding:20px 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="font-size:12px;color:#9ca3af;line-height:1.5;">
                  This email was sent to <strong>' . esc_html($to) . '</strong> because you are an administrator of <a href="' . esc_url($site_url) . '" style="color:#9333ea;text-decoration:none;">' . esc_html($site_name) . '</a>.<br>
                  Powered by <strong style="color:#7c3aed;">LLMagnet AI SEO Optimizer</strong>
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $body, $headers);

        if (!$sent) {
            @unlink($filepath);
            return new \WP_Error(
                'mail_failed',
                __('Could not send email. Check your WordPress mail configuration.', 'llmagnet-llm-txt-generator'),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success'      => true,
            'message'      => __('Report emailed to your WordPress user address.', 'llmagnet-llm-txt-generator'),
            'download_url' => $download_url,
        ]);
    }

    /**
     * Remove PDF report files older than 30 days from the llmagnet-reports directory.
     */
    private function cleanup_old_pdf_reports(string $dir): void {
        $files = glob($dir . '*.pdf');
        if (!is_array($files)) {
            return;
        }
        $cutoff = time() - (30 * DAY_IN_SECONDS);
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Get bot visit statistics
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function get_bot_stats($request) {
        global $wpdb;
        
        // Get date range parameters
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        
        $where_clause = "";
        $prepare_args = [];
        
        // Add date filtering if parameters are provided
        if ($start_date && $end_date) {
            // Add 1 day buffer to end_date to account for timezone differences
            $end_date_adjusted = date('Y-m-d', strtotime($end_date . ' +1 day'));
            $where_clause = " WHERE DATE(visit_time) >= %s AND DATE(visit_time) <= %s ";
            $prepare_args = [$start_date, $end_date_adjusted];
        }
        
        // If no args, query without WHERE clause
        if (empty($prepare_args)) {
            $query = "SELECT 
                bot_name, 
                DATE(visit_time) as date, 
                COUNT(*) as visits 
            FROM 
                {$this->table_name} 
            GROUP BY 
                bot_name, DATE(visit_time) 
            ORDER BY 
                date DESC, visits DESC";
        } else {
            $query = $wpdb->prepare(
                "SELECT 
                    bot_name, 
                    DATE(visit_time) as date, 
                    COUNT(*) as visits 
                FROM 
                    {$this->table_name} 
                {$where_clause}
                GROUP BY 
                    bot_name, DATE(visit_time) 
                ORDER BY 
                    date DESC, visits DESC",
                $prepare_args
            );
        }
        
        $stats = $wpdb->get_results($query, ARRAY_A);

        return rest_ensure_response($stats);
    }

    /**
     * Get total bot visits by bot name
     *
     * @return array
     */
    public function get_total_bot_visits() {
        global $wpdb;
        
        $stats = $wpdb->get_results(
            "SELECT 
                bot_name, 
                COUNT(*) as total_visits 
            FROM 
                {$this->table_name} 
            GROUP BY 
                bot_name 
            ORDER BY 
                total_visits DESC",
            ARRAY_A
        );

        return $stats;
    }

    /**
     * Get the report email address
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function get_report_email($request) {
        $email = get_option('llmagnet_report_email', get_bloginfo('admin_email'));
        $template = get_option('llmagnet_report_template', 'classic');
        $frequency = get_option('llmagnet_report_frequency', 'weekly');
        $send_time = get_option('llmagnet_report_send_time', '09:00');
        $company_logo = get_option('llmagnet_report_company_logo', null);
        
        return rest_ensure_response([
            'email' => $email,
            'template' => $template,
            'frequency' => $frequency,
            'send_time' => $send_time,
            'company_logo' => $company_logo,
            'event_alerts' => Event_Alerts::get_settings(),
        ]);
    }
    
    /**
     * Update the report email address
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function update_report_email($request) {
        $params = $request->get_json_params();
        
        if (!isset($params['email'])) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'Email address is required.'
            ]);
        }
        
        // Handle multiple emails separated by commas
        $email_input = sanitize_text_field($params['email']);
        $emails = array_map('trim', explode(',', $email_input));
        $valid_emails = [];
        
        foreach ($emails as $email) {
            $sanitized = sanitize_email($email);
            if (is_email($sanitized)) {
                $valid_emails[] = $sanitized;
            }
        }
        
        if (empty($valid_emails)) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'At least one valid email address is required.'
            ]);
        }
        
        // Store emails as comma-separated string (read by cron/REST only — no autoload)
        $result = update_option('llmagnet_report_email', implode(', ', $valid_emails), false);
        
        // Save template if provided
        if (isset($params['template'])) {
            $template = sanitize_text_field($params['template']);
            $allowed_templates = ['classic', 'minimal', 'gradient', 'professional'];
            if (in_array($template, $allowed_templates)) {
                update_option('llmagnet_report_template', $template, false);
            }
        }
        
        // Save frequency if provided
        if (isset($params['frequency'])) {
            $frequency = sanitize_text_field($params['frequency']);
            $allowed_frequencies = ['daily', 'weekly', 'monthly', 'quarterly'];
            if (in_array($frequency, $allowed_frequencies)) {
                update_option('llmagnet_report_frequency', $frequency, false);
            }
        }
        
        // Save send time if provided
        if (isset($params['send_time'])) {
            $send_time = sanitize_text_field($params['send_time']);
            // Validate time format (HH:MM)
            if (preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $send_time)) {
                update_option('llmagnet_report_send_time', $send_time, false);
            }
        }
        
        // Save company logo if provided (Enterprise only)
        if (isset($params['company_logo'])) {
            if ($params['company_logo'] === null) {
                delete_option('llmagnet_report_company_logo');
            } else {
                $logo_data = [
                    'id' => absint($params['company_logo']['id'] ?? 0),
                    'url' => esc_url_raw($params['company_logo']['url'] ?? '')
                ];
                if ($logo_data['id'] > 0 && !empty($logo_data['url'])) {
                    update_option('llmagnet_report_company_logo', $logo_data, false);
                }
            }
        }
        
        // Event-driven alert toggles (P3-3).
        if ( isset( $params['event_alerts'] ) && is_array( $params['event_alerts'] ) ) {
            update_option(
                Event_Alerts::OPTION,
                Event_Alerts::sanitize_settings( $params['event_alerts'] ),
                false
            );
        }

        // Reschedule email reports if frequency or time changed
        if (isset($params['frequency']) || isset($params['send_time'])) {
            if (class_exists('\\LLMagnet_AI_SEO_Optimizer\\Cron')) {
                \LLMagnet_AI_SEO_Optimizer\Cron::reschedule_email_reports();
            }
        }
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Settings updated successfully.'
        ]);
    }
    
    /**
     * Test if a user agent is detected as an LLM bot
     * For debugging and verification purposes
     *
     * @param string $user_agent User agent string to test
     * @return array Result with detection status and bot name if detected
     */
    public function test_bot_detection($user_agent) {
        $bot_name = $this->identify_llm_bot($user_agent);
        
        return [
            'user_agent' => $user_agent,
            'is_bot' => $bot_name !== false,
            'bot_name' => $bot_name ?: 'Not detected',
        ];
    }
    
    /**
     * REST API handler for testing bot detection
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function rest_test_bot_detection($request) {
        $params = $request->get_json_params();
        
        if (!isset($params['user_agent']) || empty($params['user_agent'])) {
            return rest_ensure_response([
                'success' => false,
                'message' => 'User agent is required.',
                'result' => null
            ]);
        }
        
        $user_agent = sanitize_text_field($params['user_agent']);
        $result = $this->test_bot_detection($user_agent);
        
        return rest_ensure_response([
            'success' => true,
            'message' => $result['is_bot'] ? 'Bot detected: ' . $result['bot_name'] : 'No bot detected.',
            'result' => $result
        ]);
    }
    
    /**
     * Get bot visits for the last X days
     *
     * @param int $days Number of days
     * @return array
     */
    public function get_recent_bot_visits($days = 30) {
        global $wpdb;
        
        $stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    bot_name, 
                    DATE(visit_time) as date, 
                    COUNT(*) as visits 
                FROM 
                    {$this->table_name} 
                WHERE 
                    visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY 
                    bot_name, DATE(visit_time) 
                ORDER BY 
                    date DESC, visits DESC",
                $days
            ),
            ARRAY_A
        );

        return $stats;
    }

    /**
     * Get total bot visits for the last X days (all bots combined)
     *
     * @param int $days Number of days
     * @return int Total number of bot visits
     */
    public function get_total_bot_visits_last_days($days = 30) {
        global $wpdb;
        
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$this->table_name} 
                WHERE visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        return intval($result);
    }

    /**
     * Map of known LLM bot sources and their regex patterns
     *
     * @return array
     */
    private function get_llm_bot_patterns() {
        return Bot_Registry::get_utm_patterns();
    }

    /**
     * Detect and log bot clicks from UTM parameters
     *
     * @return void
     */
    public function detect_and_log_bot_clicks() {
        // Allow both frontend and admin - we want to track all clicks
        // Only skip AJAX requests to avoid logging bot checks
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Get UTM parameters
        $utm_source = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : '';
        
        // If no utm_source, don't log
        if (empty($utm_source)) {
            return;
        }

        // Get other UTM parameters
        $utm_medium = isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : '';
        $utm_campaign = isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : '';
        $utm_content = isset($_GET['utm_content']) ? sanitize_text_field($_GET['utm_content']) : '';

        // Check if this utm_source matches any of our known LLM bot sources
        $llm_bot_patterns = $this->get_llm_bot_patterns();
        $matched_bot = null;

        foreach ($llm_bot_patterns as $bot_name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $utm_source)) {
                $matched_bot = $bot_name;
                break;
            }
        }

        // If matched, log the click
        if ($matched_bot) {
            $page_path = '';
            if (isset($_SERVER['REQUEST_URI'])) {
                $page_path = urldecode($_SERVER['REQUEST_URI']);
                $page_path = wp_strip_all_tags($page_path);
            }
            $this->log_bot_click($matched_bot, $utm_source, $utm_medium, $utm_campaign, $utm_content, $page_path);
        }
    }

    /**
     * Log bot click to database
     *
     * @param string $bot_source Matched bot source
     * @param string $utm_source UTM source parameter
     * @param string $utm_medium UTM medium parameter
     * @param string $utm_campaign UTM campaign parameter
     * @param string $utm_content UTM content parameter
     * @param string $page_path Page path where click occurred
     * @return bool True if successful, false otherwise
     */
    private function log_bot_click($bot_source, $utm_source, $utm_medium = '', $utm_campaign = '', $utm_content = '', $page_path = '') {
        global $wpdb;

        // Summary table: lifetime total_clicks; clicks_30_days kept for legacy (synced via rolling counts in reads)
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->clicks_table_name} 
                SET clicks_30_days = clicks_30_days + 1, 
                    total_clicks = total_clicks + 1,
                    last_click_time = NOW()
                WHERE bot_name = %s",
                $bot_source
            )
        );

        if ($result === false && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('LLM Bot Click Database Update Failed: ' . $wpdb->last_error);
        } elseif ($result === 0) {
            self::populate_bot_names_table();
            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->clicks_table_name} 
                    SET clicks_30_days = clicks_30_days + 1, 
                        total_clicks = total_clicks + 1,
                        last_click_time = NOW()
                    WHERE bot_name = %s",
                    $bot_source
                )
            );
        }
        
        // Also log page-level click if page_path is provided
        if (!empty($page_path)) {
            // Use wp_strip_all_tags instead of sanitize_text_field to preserve Hebrew/Unicode
            $page_path = wp_strip_all_tags($page_path);
            
            // Normalize page_path by removing query parameters (utm_source, utm_medium, etc.)
            // This ensures clicks are matched correctly to pages regardless of UTM parameters
            $normalized_click_path = strtok($page_path, '?');
            
            // Remove common UTM and tracking parameters from the path
            // But keep the base path intact - use multibyte-safe functions
            if (function_exists('mb_strlen') && mb_strlen($normalized_click_path, 'UTF-8') > 500) {
                $normalized_click_path = mb_substr($normalized_click_path, 0, 500, 'UTF-8');
            } elseif (strlen($normalized_click_path) > 500) {
                $normalized_click_path = substr($normalized_click_path, 0, 500);
            }
            
            $page_click_result = $wpdb->insert(
                $this->page_clicks_table_name,
                [
                    'page_path' => $normalized_click_path,
                    'bot_name' => $bot_source,
                ],
                ['%s', '%s']
            );
            
            if ($page_click_result === false && defined('WP_DEBUG') && WP_DEBUG) {
                error_log('LLM Bot Page Click Database Insert Failed: ' . $wpdb->last_error);
            }
        }

        return $result !== false;
    }

    /**
     * Get total bot clicks for the last X days (all bots combined)
     *
     * @param int $days Number of days
     * @return int Total number of bot clicks
     */
    public function get_total_bot_clicks_last_days($days = 30) {
        global $wpdb;

        $days = max(1, min(365, absint($days)));

        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->page_clicks_table_name
            )
        );

        if (!$table_exists) {
            return 0;
        }

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->page_clicks_table_name}
                WHERE click_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        return intval($result);
    }

    /**
     * Get bot statistics for table view - visits, clicks, CTR, and trend data
     *
     * @return array Array of bot stats with impressions, clicks, CTR, and trend
     */
    public function get_bot_stats_for_table() {
        global $wpdb;

        $bot_stats = [];

        $click_rows = $wpdb->get_results(
            "SELECT bot_name, COUNT(*) AS c FROM {$this->page_clicks_table_name}
            WHERE click_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY bot_name",
            ARRAY_A
        );
        $clicks_last_30 = [];
        foreach (is_array($click_rows) ? $click_rows : [] as $row) {
            $clicks_last_30[ $row['bot_name'] ] = (int) $row['c'];
        }

        $from_visits = $wpdb->get_col(
            "SELECT DISTINCT bot_name FROM {$this->table_name}
            WHERE visit_time >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        // Bot-name union source: the page-clicks data table (wp_llm_bot_page_clicks).
        // This previously read the legacy wp_llm_bot_clicks summary table, which is
        // absent on installs where activation-time dbDelta never created it, logging
        // a DB error on every call (flagged by Lane D2 at the Phase D gate).
        $from_summary = $wpdb->get_col(
            "SELECT DISTINCT bot_name FROM {$this->page_clicks_table_name}"
        );
        $bot_names = array_unique(array_merge(
            is_array($from_visits) ? $from_visits : [],
            is_array($from_summary) ? $from_summary : [],
            array_keys($clicks_last_30)
        ));

        foreach ($bot_names as $bot_name) {
            $visits_30 = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name}
                    WHERE bot_name = %s AND visit_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    $bot_name
                )
            );

            $visits_previous = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table_name}
                    WHERE bot_name = %s AND visit_time >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                    AND visit_time < DATE_SUB(NOW(), INTERVAL 30 DAY)",
                    $bot_name
                )
            );

            $trend = 0;
            if ($visits_previous > 0) {
                $trend = (($visits_30 - $visits_previous) / $visits_previous) * 100;
            } elseif ($visits_30 > 0) {
                $trend = 100;
            }

            $clicks = isset($clicks_last_30[ $bot_name ]) ? $clicks_last_30[ $bot_name ] : 0;
            $ctr = $visits_30 > 0 ? ( $clicks / $visits_30 ) * 100 : 0;

            $bot_stats[] = [
                'bot_name' => $bot_name,
                'impressions' => $visits_30,
                'clicks' => $clicks,
                'ctr' => round($ctr, 2),
                'trend' => round($trend, 1),
            ];
        }

        usort(
            $bot_stats,
            function ($a, $b) {
                return $b['impressions'] <=> $a['impressions'];
            }
        );

        return $bot_stats;
    }
    
    /**
     * REST endpoint response for page stats
     *
     * @return \WP_REST_Response
     */
    public function get_page_stats_response( $request = null ) {
        try {
            if ( $request instanceof \WP_REST_Request ) {
                $days = $request->get_param('days');
                $offset = $request->get_param('offset');
                $limit = $request->get_param('limit');
            } else {
                $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
                $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 500;
            }
            if ($days < 1) {
                $days = 30;
            }
            $offset = max(0, absint($offset));
            $limit = max(1, min(2000, absint($limit) ?: 500));

            $page_stats = $this->get_page_stats($days, $offset, $limit);
            return rest_ensure_response($page_stats);
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Error in get_page_stats_response: ' . $e->getMessage());
            }
            return rest_ensure_response([
                'error' => true,
                'message' => $e->getMessage(),
                'trace' => (defined('WP_DEBUG') && WP_DEBUG) ? $e->getTraceAsString() : '',
            ], 500);
        }
    }
    
    /**
     * Get page statistics - pages that received bot traffic
     *
     * @param int $range_days Date range for visibility score (days).
     * @param int $offset     Pagination offset for grouped page rows.
     * @param int $limit      Max grouped pages to return (default 500, max 2000).
     * @return array Array of page stats with path, title, URL, visits, clicks, CTR, and bots
     */
    public function get_page_stats($range_days = 30, $offset = 0, $limit = 500) {
        global $wpdb;
        
        // Ensure table exists (run migration if needed)
        self::migrate_db_table();
        
        // Check if page_clicks_table exists, if not create it
        $page_clicks_table_name = $wpdb->prefix . 'llm_bot_page_clicks';
        $page_clicks_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $page_clicks_table_name
            )
        );
        
        if (!$page_clicks_exists) {
            // Table doesn't exist, try to create it
            \llmagnet_aiseo_debug_log('Page clicks table does not exist, attempting to create: ' . $page_clicks_table_name);
            $charset_collate = $wpdb->get_charset_collate();
            $sql_page_clicks = "CREATE TABLE $page_clicks_table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                page_path varchar(500) NOT NULL,
                bot_name varchar(100) NOT NULL,
                click_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id),
                KEY page_path (page_path(191)),
                KEY bot_name (bot_name)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql_page_clicks);
        }
        
        $offset = max(0, absint($offset));
        $limit = max(1, min(2000, absint($limit) ?: 500));

        // Grouped pages (paginated) — avoids loading unbounded rows into PHP
        $pages = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    TRIM(SUBSTRING_INDEX(page_path, '?', 1)) as normalized_path,
                    MAX(page_path) as original_path,
                    MAX(page_title) as page_title,
                    COUNT(*) as bot_visits,
                    GROUP_CONCAT(DISTINCT bot_name) as bots
                FROM {$this->table_name}
                WHERE page_path IS NOT NULL AND page_path != ''
                GROUP BY normalized_path
                ORDER BY bot_visits DESC
                LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );
        
        if ($wpdb->last_error) {
            \llmagnet_aiseo_debug_log('Database error in get_page_stats: ' . $wpdb->last_error);
            throw new \Exception('Database error: ' . $wpdb->last_error);
        }
        
        $page_stats = [];
        $site_url = home_url();
        
        // Get product paths to exclude when WooCommerce is active
        $product_paths = [];
        if (class_exists('LLMagnet_AI_SEO_Optimizer\\WooCommerce') && WooCommerce::is_active()) {
            $product_paths = WooCommerce::get_product_paths();
        }
        
        foreach ($pages as $page) {
            $normalized_path = $page['normalized_path'];
            $original_path = $page['original_path'];
            $page_title = $page['page_title'] ?: basename($normalized_path);
            $bot_visits = intval($page['bot_visits']);
            
            // Skip product pages when WooCommerce is active
            if (!empty($product_paths) && in_array($normalized_path, $product_paths)) {
                continue;
            }
            
            // Get full URL using normalized path
            $page_url = $site_url . $normalized_path;
            
            // Parse bots list
            $bots_list = !empty($page['bots']) ? explode(',', $page['bots']) : [];
            $bots_list = array_unique($bots_list);
            
            // Get clicks for this specific normalized page path only
            // Match both normalized paths (new format) and paths with query params (old format)
            // This ensures backward compatibility with existing data
            $clicks = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->page_clicks_table_name} 
                    WHERE page_path = %s 
                    OR TRIM(SUBSTRING_INDEX(page_path, '?', 1)) = %s",
                    $normalized_path,
                    $normalized_path
                )
            );
            $clicks = intval($clicks);
            
            // Calculate CTR
            $ctr = $bot_visits > 0 ? ($clicks / $bot_visits) * 100 : 0;
            
            // Resolve page_path to WordPress post ID
            $resolved_post_id = 0;
            $display_title = $page_title; // default to bot-recorded title

            if ($normalized_path === '/' || $normalized_path === '') {
                // Homepage: always use current WP settings to resolve
                if (get_option('show_on_front') === 'page') {
                    // Static front page mode
                    $front_page_id = intval(get_option('page_on_front'));
                    if ($front_page_id) {
                        $resolved_post_id = $front_page_id;
                    }
                } else {
                    // "Latest posts" mode — try the blog page if set,
                    // otherwise try to find a post matching the stored title
                    $blog_page_id = intval(get_option('page_for_posts'));
                    if ($blog_page_id) {
                        $resolved_post_id = $blog_page_id;
                    }
                }

                // For homepage, always show the current WP page title (not stale bot data)
                if ($resolved_post_id) {
                    $current_post = get_post($resolved_post_id);
                    if ($current_post) {
                        $display_title = $current_post->post_title;
                    }
                }
            } else {
                $resolved_post_id = url_to_postid($page_url);
                if (!$resolved_post_id) {
                    $resolved_post_id = url_to_postid(trailingslashit($page_url));
                }
                // For non-homepage pages, also refresh title from WP if we resolved a post
                if ($resolved_post_id) {
                    $current_post = get_post($resolved_post_id);
                    if ($current_post) {
                        $display_title = $current_post->post_title;
                    }
                }
            }

            // Get inline visibility score for posts with valid IDs
            $inline_score = null;
            if ($resolved_post_id) {
                try {
                    $page_details = new Page_Details();
                    $score_data = $page_details->calculate_visibility_score($resolved_post_id, $range_days);
                    if (!is_wp_error($score_data) && isset($score_data['score'])) {
                        $inline_score = ['score' => $score_data['score']];
                    }
                } catch (\Exception $e) {
                    // Score calculation failed silently
                }
            }

            $page_stats[] = [
                'page_path' => $normalized_path,
                'page_title' => $display_title,
                'page_url' => $page_url,
                'bot_visits' => $bot_visits,
                'clicks' => $clicks,
                'ctr' => round($ctr, 2),
                'bots' => $bots_list,
                'post_id' => $resolved_post_id ?: null,
                'visibility_score' => $inline_score,
            ];
        }
        
        return $page_stats;
    }
}
