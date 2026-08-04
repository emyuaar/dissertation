<?php
/**
 * WooCommerce Integration class
 *
 * Handles WooCommerce detection, hooks, and product tracking
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

// Ensure required classes are loaded
if (!class_exists('LLMagnet_AI_SEO_Optimizer\\Attribution')) {
    require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-attribution.php';
}
if (!class_exists('LLMagnet_AI_SEO_Optimizer\\Product_Analytics')) {
    require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-product-analytics.php';
}
if (!class_exists('LLMagnet_AI_SEO_Optimizer\\Product_Details')) {
    require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-product-details.php';
}

/**
 * WooCommerce Integration class
 */
class WooCommerce {
    /**
     * Attribution handler instance
     *
     * @var Attribution
     */
    private $attribution;

    /**
     * Product analytics instance
     *
     * @var Product_Analytics
     */
    private $product_analytics;

    /**
     * Product details instance
     *
     * @var Product_Details
     */
    private $product_details;

    /**
     * Database version for migrations
     *
     * @var string
     */
    const DB_VERSION = '2.1.0';

    /**
     * Events table name
     *
     * @var string
     */
    private $events_table;

    /**
     * Sessions table name
     *
     * @var string
     */
    private $sessions_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->events_table = $wpdb->prefix . 'llm_product_events';
        $this->sessions_table = $wpdb->prefix . 'llm_attribution_sessions';
    }

    /**
     * Initialize WooCommerce integration
     *
     * @return void
     */
    public function init() {
        // Run migrations if needed
        $this->maybe_run_migrations();

        // Cache invalidation hooks - ALWAYS register these regardless of WooCommerce status
        add_action('activated_plugin', [__CLASS__, 'clear_detection_cache']);
        add_action('deactivated_plugin', [__CLASS__, 'clear_detection_cache']);
        
        // REST API endpoints - always register for status check
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // Bail early if WooCommerce not active
        if (!self::is_active()) {
            return;
        }

        // Initialize attribution handler
        $this->attribution = new Attribution();

        // Attribution cookie handling - call directly since we're already past init hook
        // Also hook to template_redirect for subsequent page loads
        $this->attribution->maybe_set_attribution_cookie();
        add_action('template_redirect', [$this->attribution, 'maybe_set_attribution_cookie'], 1);

        // WooCommerce hooks for tracking
        add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
        add_action('woocommerce_thankyou', [$this, 'track_purchase'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'track_order_completed'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'track_order_completed'], 10, 1);

        // Product cache invalidation — the only triggers for the long-lived paths cache
        add_action('save_post_product', [__CLASS__, 'clear_product_paths_cache']);
        add_action('deleted_post', [__CLASS__, 'maybe_clear_product_paths_cache_on_delete'], 10, 2);
    }

    /**
     * Check if WooCommerce is installed and active
     *
     * @param bool $skip_cache Force fresh detection
     * @return bool
     */
    public static function is_active($skip_cache = false) {
        // Use transient cache for performance (skip in admin for accurate menu display)
        if (!$skip_cache && !is_admin()) {
            $cached = get_transient('llmagnet_woo_active');
            if ($cached !== false) {
                return $cached === 'yes';
            }
        }

        $is_active = false;

        // Check if WooCommerce class exists (most reliable)
        if (class_exists('WooCommerce')) {
            $is_active = true;
        } elseif (class_exists('\WooCommerce')) {
            $is_active = true;
        } elseif (function_exists('WC')) {
            $is_active = true;
        } else {
            // Check active plugins option directly
            $active_plugins = get_option('active_plugins', []);
            $is_active = in_array('woocommerce/woocommerce.php', $active_plugins);
            
            // Also check network plugins for multisite
            if (!$is_active && is_multisite()) {
                $network_plugins = get_site_option('active_sitewide_plugins', []);
                $is_active = isset($network_plugins['woocommerce/woocommerce.php']);
            }
        }

        // Update cache
        set_transient('llmagnet_woo_active', $is_active ? 'yes' : 'no', HOUR_IN_SECONDS);

        return $is_active;
    }

    /**
     * Get WooCommerce version if active
     *
     * @return string|null
     */
    public static function get_version() {
        if (!self::is_active()) {
            return null;
        }

        if (defined('WC_VERSION')) {
            return WC_VERSION;
        }

        if (function_exists('WC') && is_object(WC())) {
            return WC()->version;
        }

        return null;
    }

    /**
     * Clear WooCommerce detection cache
     *
     * @return void
     */
    public static function clear_detection_cache() {
        delete_transient('llmagnet_woo_active');
    }

    /**
     * Clear product paths cache
     *
     * @return void
     */
    public static function clear_product_paths_cache() {
        delete_transient('llmagnet_product_paths');
    }

    /**
     * Clear product paths cache when a product is deleted
     *
     * @param int           $post_id Deleted post ID
     * @param \WP_Post|null $post    Deleted post object (WP 5.5+)
     * @return void
     */
    public static function maybe_clear_product_paths_cache_on_delete($post_id, $post = null) {
        // Only invalidate for products; clear defensively when type is unknown.
        if (!($post instanceof \WP_Post) || 'product' === $post->post_type) {
            self::clear_product_paths_cache();
        }
    }

    /**
     * Get all product paths (cached)
     *
     * @return array
     */
    public static function get_product_paths() {
        if (!self::is_active()) {
            return [];
        }

        $paths = get_transient('llmagnet_product_paths');
        if ($paths !== false) {
            return $paths;
        }

        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $paths = [];
        foreach ($products as $product_id) {
            $permalink = get_permalink($product_id);
            $path = str_replace(home_url(), '', $permalink);
            $paths[] = strtok($path, '?');
        }

        // Long-lived cache: invalidated by save_post_product / deleted_post,
        // the week-long expiry is only a safety net.
        set_transient('llmagnet_product_paths', $paths, WEEK_IN_SECONDS);

        return $paths;
    }

    /**
     * Check if a URL path is a product page
     *
     * @param string $page_path URL path
     * @return bool|int False or product ID
     */
    public static function is_product_page($page_path) {
        if (!self::is_active()) {
            return false;
        }

        // Normalize path
        $clean_path = strtok($page_path, '?');
        $post_id = url_to_postid(home_url($clean_path));

        if ($post_id > 0 && get_post_type($post_id) === 'product') {
            return $post_id;
        }

        return false;
    }

    /**
     * Get product count
     *
     * @return int
     */
    public static function get_product_count() {
        if (!self::is_active()) {
            return 0;
        }

        $count = wp_count_posts('product');
        return isset($count->publish) ? (int) $count->publish : 0;
    }

    /**
     * Get WooCommerce currency
     *
     * @return string
     */
    public static function get_currency() {
        if (!self::is_active() || !function_exists('get_woocommerce_currency')) {
            return 'USD';
        }
        return get_woocommerce_currency();
    }

    /**
     * Get WooCommerce currency symbol
     *
     * @return string
     */
    public static function get_currency_symbol() {
        if (!self::is_active() || !function_exists('get_woocommerce_currency_symbol')) {
            return '$';
        }
        return get_woocommerce_currency_symbol();
    }

    /**
     * Run database migrations if needed
     *
     * @return void
     */
    private function maybe_run_migrations() {
        $current_version = get_option('llmagnet_db_version', '1.0.0');

        if (version_compare($current_version, self::DB_VERSION, '<')) {
            $this->run_migration();
            update_option('llmagnet_db_version', self::DB_VERSION);
        }
    }

    /**
     * Run database migration
     *
     * @return void
     */
    private function run_migration() {
        self::create_db_tables();
        self::migrate_product_events_order_item_id();
    }

    /**
     * Add order_item_id column for idempotent purchase rows (line-item level).
     *
     * @return void
     */
    private static function migrate_product_events_order_item_id() {
        global $wpdb;
        $table = $wpdb->prefix . 'llm_product_events';

        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'order_item_id'",
                DB_NAME,
                $table
            )
        );

        if ( (int) $exists > 0 ) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated.
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN order_item_id bigint(20) DEFAULT NULL AFTER product_id" );
    }

    /**
     * Create database tables for WooCommerce features
     *
     * @return void
     */
    public static function create_db_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Attribution sessions table
        $sessions_table = $wpdb->prefix . 'llm_attribution_sessions';
        $sql_sessions = "CREATE TABLE $sessions_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            bot_source varchar(100) NOT NULL,
            landing_page varchar(500) DEFAULT NULL,
            utm_medium varchar(100) DEFAULT NULL,
            utm_campaign varchar(100) DEFAULT NULL,
            first_touch datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            last_activity datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            is_converted tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY session_id (session_id),
            KEY bot_source (bot_source),
            KEY first_touch (first_touch)
        ) $charset_collate;";

        // Product events table
        $events_table = $wpdb->prefix . 'llm_product_events';
        $sql_events = "CREATE TABLE $events_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(20) NOT NULL,
            product_id bigint(20) NOT NULL,
            order_item_id bigint(20) DEFAULT NULL,
            product_name varchar(500) DEFAULT NULL,
            bot_source varchar(100) DEFAULT NULL,
            session_id varchar(64) DEFAULT NULL,
            quantity int DEFAULT 1,
            order_id bigint(20) DEFAULT NULL,
            revenue decimal(10,2) DEFAULT NULL,
            currency varchar(3) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY product_id (product_id),
            KEY bot_source (bot_source),
            KEY session_id (session_id),
            KEY created_at (created_at),
            KEY order_id (order_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_sessions);
        dbDelta($sql_events);
    }

    /**
     * Track add-to-cart events
     *
     * @param string $cart_item_key Cart item key
     * @param int $product_id Product ID
     * @param int $quantity Quantity added
     * @param int $variation_id Variation ID
     * @param array $variation Variation data
     * @param array $cart_item_data Cart item data
     * @return void
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        // Get attribution data
        $attribution = $this->attribution->get_current_attribution();

        // Get product
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $this->events_table,
            [
                'event_type' => 'add_to_cart',
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'bot_source' => $attribution ? $attribution['bot_source'] : null,
                'session_id' => $attribution ? $attribution['session_id'] : null,
                'quantity' => $quantity,
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d']
        );

        // Update session last activity
        if ($attribution) {
            $this->attribution->update_last_activity($attribution['session_id']);
        }
    }

    /**
     * Track purchase on thank you page
     *
     * @param int $order_id Order ID
     * @return void
     */
    public function track_purchase($order_id) {
        // Always check for existing to prevent duplicates
        $this->record_order_attribution($order_id, true);
    }

    /**
     * Track completed/processing orders
     *
     * @param int $order_id Order ID
     * @return void
     */
    public function track_order_completed($order_id) {
        // Only record if not already recorded
        $this->record_order_attribution($order_id, true);
    }

    /**
     * Record order attribution
     *
     * @param int $order_id Order ID
     * @param bool $check_existing Whether to check if already recorded
     * @return void
     */
    private function record_order_attribution($order_id, $check_existing = false) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        global $wpdb;

        $lock_name = 'llmagnet_order_attr_' . absint($order_id);
        $got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 20)', $lock_name));
        if ((int) $got_lock !== 1) {
            return;
        }

        try {
            if ($check_existing) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$this->events_table} WHERE order_id = %d AND event_type = 'purchase' LIMIT 1",
                    $order_id
                ));
                if ($exists) {
                    return;
                }
            }

            $attribution = $this->attribution->get_current_attribution();

            foreach ($order->get_items() as $item) {
                $product_id = $item->get_product_id();
                $product = $item->get_product();
                $order_item_id = $item->get_id();

                $wpdb->insert(
                    $this->events_table,
                    [
                        'event_type' => 'purchase',
                        'product_id' => $product_id,
                        'order_item_id' => $order_item_id,
                        'product_name' => $product ? $product->get_name() : '',
                        'bot_source' => $attribution ? $attribution['bot_source'] : null,
                        'session_id' => $attribution ? $attribution['session_id'] : null,
                        'quantity' => $item->get_quantity(),
                        'order_id' => $order_id,
                        'revenue' => $item->get_total(),
                        'currency' => $order->get_currency(),
                    ],
                    ['%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
                );
            }

            if ($attribution) {
                $this->attribution->mark_converted($attribution['session_id']);
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
        }
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        // Initialize product analytics and register its routes
        $this->product_analytics = new Product_Analytics();
        $this->product_analytics->register_rest_routes();

        // Initialize product details and register its routes
        $this->product_details = new Product_Details();
        $this->product_details->register_rest_routes();
    }

    /**
     * Get WooCommerce data for localized script
     *
     * @return array
     */
    public static function get_localized_data() {
        return [
            'active' => self::is_active(),
            'version' => self::get_version(),
            'product_count' => self::get_product_count(),
            'currency' => self::get_currency(),
            'currency_symbol' => self::get_currency_symbol(),
        ];
    }
}
