<?php
/**
 * Attribution class for tracking LLM session attribution
 *
 * Handles cookie-based attribution for tracking conversions from LLM platforms
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Attribution class
 */
class Attribution {
    /**
     * Cookie name for attribution data
     *
     * @var string
     */
    const COOKIE_NAME = 'llmagnet_llm_attribution';

    /**
     * Default cookie TTL in days
     *
     * @var int
     */
    const COOKIE_TTL_DAYS = 7;

    /**
     * Option toggling attribution tracking (cookie + session logging).
     * Stored with autoload off; defaults to enabled to preserve existing behavior.
     *
     * @var string
     */
    const TRACKING_OPTION = 'llmagnet_attribution_tracking';

    /**
     * Sessions table name
     *
     * @var string
     */
    private $sessions_table;

    /**
     * LLM platform detection patterns
     * Maps UTM source patterns to bot names
     * Patterns match both simple names (chatgpt) and full URLs (chatgpt.com)
     *
     * @var array
     */
    private $llm_patterns = [
        'ChatGPT' => '.*chatgpt.*|.*openai.*',
        'Gemini' => '.*gemini.*|.*bard.*',
        'Copilot' => '.*copilot.*|.*edgepilot.*|.*edgeservices.*',
        'Perplexity' => '.*perplexity.*',
        'Claude' => '.*claude.*|.*anthropic.*',
        'DeepSeek' => '.*deepseek.*',
        'Grok' => '.*grok.*|.*x\.ai.*',
        'Bing' => '.*bing.*',
        'Llama' => '.*llama.*|.*meta\.ai.*',
        'Mistral' => '.*mistral.*',
    ];

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->sessions_table = $wpdb->prefix . 'llm_attribution_sessions';
    }

    /**
     * Whether attribution tracking is enabled for this site.
     *
     * Site owners can disable the visitor-facing attribution cookie either via
     * the `llmagnet_attribution_tracking` option (REST: POST
     * llm-analytics/v1/privacy/settings { attribution_enabled: false }) or the
     * `llmagnet_attribution_tracking_enabled` filter.
     *
     * @return bool
     */
    public static function is_tracking_enabled() {
        $enabled = '0' !== (string) get_option(self::TRACKING_OPTION, '1');
        return (bool) apply_filters('llmagnet_attribution_tracking_enabled', $enabled);
    }

    /**
     * Check if request has LLM UTM params and set attribution cookie
     *
     * @return void
     */
    public function maybe_set_attribution_cookie() {
        // Don't run in admin or during AJAX/cron
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        // Respect the site-level attribution tracking setting: when disabled,
        // never set new cookies or log new sessions (existing cookies simply expire).
        if (!self::is_tracking_enabled()) {
            return;
        }

        // Check if already has valid attribution cookie
        if ($this->has_valid_attribution()) {
            $this->update_last_activity_from_cookie();
            return;
        }

        // Check for LLM UTM source
        $utm_source = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : '';
        if (empty($utm_source)) {
            return;
        }

        // Match against LLM patterns
        $bot_source = $this->detect_llm_source($utm_source);
        if (!$bot_source) {
            return;
        }

        // Create attribution
        $this->create_attribution($bot_source);
    }

    /**
     * Detect LLM source from UTM parameter
     *
     * @param string $utm_source UTM source value
     * @return string|null Bot name or null if not an LLM source
     */
    public function detect_llm_source($utm_source) {
        foreach ($this->llm_patterns as $bot_name => $pattern) {
            if (preg_match('/' . $pattern . '/i', $utm_source)) {
                return $bot_name;
            }
        }
        return null;
    }

    /**
     * Check if valid attribution cookie exists
     *
     * @return bool
     */
    public function has_valid_attribution() {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        $data = $this->parse_attribution_cookie_value($_COOKIE[self::COOKIE_NAME]);
        if (!$data || !isset($data['session_id'])) {
            return false;
        }

        // Check expiry
        if (isset($data['first_touch'])) {
            $ttl = $this->get_cookie_ttl_days();
            $expires = $data['first_touch'] + ($ttl * DAY_IN_SECONDS);
            if (time() > $expires) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parse and sanitize JSON attribution cookie payload.
     *
     * @param string $raw Raw cookie string.
     * @return array|null
     */
    private function parse_attribution_cookie_value($raw) {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode(wp_unslash($raw), true);
        if (!is_array($decoded) || !isset($decoded['session_id'])) {
            return null;
        }
        $session_id = sanitize_text_field((string) $decoded['session_id']);
        if ($session_id === '') {
            return null;
        }
        $out = [
            'session_id' => $session_id,
            'bot_source' => isset($decoded['bot_source']) ? sanitize_text_field((string) $decoded['bot_source']) : '',
            'landing_page' => isset($decoded['landing_page']) ? sanitize_text_field((string) $decoded['landing_page']) : '',
            'utm_medium' => isset($decoded['utm_medium']) ? sanitize_text_field((string) $decoded['utm_medium']) : '',
            'utm_campaign' => isset($decoded['utm_campaign']) ? sanitize_text_field((string) $decoded['utm_campaign']) : '',
        ];
        if (isset($decoded['first_touch'])) {
            $out['first_touch'] = absint($decoded['first_touch']);
        }
        return $out;
    }

    /**
     * Get cookie TTL in days (filterable)
     *
     * @return int
     */
    private function get_cookie_ttl_days() {
        return apply_filters('llmagnet_attribution_ttl_days', self::COOKIE_TTL_DAYS);
    }

    /**
     * Create new attribution session
     *
     * @param string $bot_source Detected bot source
     * @return void
     */
    private function create_attribution($bot_source) {
        // Generate unique session ID
        $session_id = $this->generate_session_id();

        // Get landing page
        $landing_page = '';
        if (isset($_SERVER['REQUEST_URI'])) {
            $landing_page = urldecode($_SERVER['REQUEST_URI']);
            $landing_page = wp_strip_all_tags($landing_page);
            $landing_page = strtok($landing_page, '?');
        }

        // Build attribution data
        $attribution = [
            'bot_source' => $bot_source,
            'session_id' => $session_id,
            'landing_page' => $landing_page,
            'first_touch' => time(),
            'utm_medium' => isset($_GET['utm_medium']) ? sanitize_text_field($_GET['utm_medium']) : '',
            'utm_campaign' => isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : '',
        ];

        // Set cookie
        $this->set_attribution_cookie($attribution);

        // Store in database
        $this->store_session($attribution);
    }

    /**
     * Generate unique session ID
     *
     * @return string
     */
    private function generate_session_id() {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        // Fallback for older WordPress versions
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Set attribution cookie
     *
     * @param array $attribution Attribution data
     * @return void
     */
    private function set_attribution_cookie($attribution) {
        $ttl = $this->get_cookie_ttl_days();
        $expires = time() + ($ttl * DAY_IN_SECONDS);

        $cookie_value = json_encode($attribution);

        // Check if headers already sent - can't set cookie after output starts
        if (headers_sent()) {
            // Still store in superglobal for immediate access this request
            $_COOKIE[self::COOKIE_NAME] = $cookie_value;
            return;
        }

        // Set cookie with appropriate flags
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            setcookie(self::COOKIE_NAME, $cookie_value, [
                'expires' => $expires,
                'path' => '/',
                'domain' => '',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(
                self::COOKIE_NAME,
                $cookie_value,
                $expires,
                '/',
                '',
                is_ssl(),
                true
            );
        }

        // Also store in superglobal for immediate access
        $_COOKIE[self::COOKIE_NAME] = $cookie_value;
    }

    /**
     * Store session in database
     *
     * @param array $attribution Attribution data
     * @return bool
     */
    private function store_session($attribution) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->sessions_table
            )
        );

        if (!$table_exists) {
            return false;
        }

        $result = $wpdb->insert(
            $this->sessions_table,
            [
                'session_id' => $attribution['session_id'],
                'bot_source' => $attribution['bot_source'],
                'landing_page' => $attribution['landing_page'],
                'utm_medium' => $attribution['utm_medium'],
                'utm_campaign' => $attribution['utm_campaign'],
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Update last activity from cookie data
     *
     * @return void
     */
    private function update_last_activity_from_cookie() {
        $attribution = $this->get_current_attribution();
        if ($attribution && isset($attribution['session_id'])) {
            $this->update_last_activity($attribution['session_id']);
        }
    }

    /**
     * Update last activity timestamp for session
     *
     * @param string $session_id Session ID
     * @return void
     */
    public function update_last_activity($session_id) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->sessions_table
            )
        );

        if (!$table_exists) {
            return;
        }

        $wpdb->update(
            $this->sessions_table,
            ['last_activity' => current_time('mysql')],
            ['session_id' => $session_id],
            ['%s'],
            ['%s']
        );
    }

    /**
     * Get current attribution from cookie
     *
     * @return array|null Attribution data or null if not set
     */
    public function get_current_attribution() {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $data = $this->parse_attribution_cookie_value($_COOKIE[self::COOKIE_NAME]);

        if (!is_array($data) || !isset($data['session_id'])) {
            return null;
        }

        // Validate expiry
        if (isset($data['first_touch'])) {
            $ttl = $this->get_cookie_ttl_days();
            $expires = $data['first_touch'] + ($ttl * DAY_IN_SECONDS);
            if (time() > $expires) {
                return null;
            }
        }

        return $data;
    }

    /**
     * Mark session as converted
     *
     * @param string $session_id Session ID
     * @return void
     */
    public function mark_converted($session_id) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->sessions_table
            )
        );

        if (!$table_exists) {
            return;
        }

        $wpdb->update(
            $this->sessions_table,
            ['is_converted' => 1],
            ['session_id' => $session_id],
            ['%d'],
            ['%s']
        );
    }

    /**
     * Get session by ID
     *
     * @param string $session_id Session ID
     * @return array|null Session data or null
     */
    public function get_session($session_id) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->sessions_table
            )
        );

        if (!$table_exists) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->sessions_table} WHERE session_id = %s",
                $session_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get attribution statistics
     *
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public function get_attribution_stats($days = 30) {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->sessions_table
            )
        );

        if (!$table_exists) {
            return [
                'total_sessions' => 0,
                'converted_sessions' => 0,
                'conversion_rate' => 0,
                'by_source' => [],
            ];
        }

        // Total sessions
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sessions_table} 
            WHERE first_touch >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        // Converted sessions
        $converted = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sessions_table} 
            WHERE first_touch >= DATE_SUB(NOW(), INTERVAL %d DAY)
            AND is_converted = 1",
            $days
        ));

        // By source
        $by_source = $wpdb->get_results($wpdb->prepare(
            "SELECT bot_source, COUNT(*) as sessions, SUM(is_converted) as conversions
            FROM {$this->sessions_table} 
            WHERE first_touch >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY bot_source
            ORDER BY sessions DESC",
            $days
        ), ARRAY_A);

        return [
            'total_sessions' => $total,
            'converted_sessions' => $converted,
            'conversion_rate' => $total > 0 ? round(($converted / $total) * 100, 2) : 0,
            'by_source' => $by_source ?: [],
        ];
    }

    /**
     * Clear expired sessions from database
     *
     * @return int Number of sessions deleted
     */
    public function cleanup_expired_sessions() {
        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->sessions_table
            )
        );

        if (!$table_exists) {
            return 0;
        }

        $ttl = $this->get_cookie_ttl_days();

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->sessions_table} 
            WHERE first_touch < DATE_SUB(NOW(), INTERVAL %d DAY)
            AND is_converted = 0",
            $ttl * 2 // Keep for double the TTL
        ));

        return $deleted;
    }
}
