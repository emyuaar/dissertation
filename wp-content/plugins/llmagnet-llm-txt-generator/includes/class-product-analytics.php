<?php
/**
 * Product Analytics class
 *
 * Handles product-level analytics queries and REST API endpoints
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Product Analytics class
 */
class Product_Analytics {
    /**
     * Bot visits table name
     *
     * @var string
     */
    private $visits_table;

    /**
     * Page clicks table name
     *
     * @var string
     */
    private $clicks_table;

    /**
     * Product events table name
     *
     * @var string
     */
    private $events_table;

    /**
     * Attribution sessions table name
     *
     * @var string
     */
    private $sessions_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->visits_table = $wpdb->prefix . 'llm_bot_visits';
        $this->clicks_table = $wpdb->prefix . 'llm_bot_page_clicks';
        $this->events_table = $wpdb->prefix . 'llm_product_events';
        $this->sessions_table = $wpdb->prefix . 'llm_attribution_sessions';
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        // Product stats (table data)
        register_rest_route('llm-analytics/v1', '/product-stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product_stats'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // Product timeseries (chart data)
        register_rest_route('llm-analytics/v1', '/product-timeseries', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product_timeseries'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // Product quick stats (aggregate data)
        register_rest_route('llm-analytics/v1', '/product-quick-stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_quick_stats'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // Top products
        register_rest_route('llm-analytics/v1', '/top-products', [
            'methods' => 'GET',
            'callback' => [$this, 'get_top_products'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // WooCommerce status
        register_rest_route('llm-analytics/v1', '/woocommerce-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_woocommerce_status'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // Product of the week
        register_rest_route('llm-analytics/v1', '/product-of-week', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product_of_week'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);

        // AI Revenue Funnel (for Overview card)
        register_rest_route('llm-analytics/v1', '/overview/ai-revenue-funnel', [
            'methods' => 'GET',
            'callback' => [$this, 'get_ai_revenue_funnel'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'range_days' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 30,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Product Readiness (for Overview card)
        register_rest_route('llm-analytics/v1', '/overview/product-readiness', [
            'methods' => 'GET',
            'callback' => [$this, 'get_product_readiness'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'range_days' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 30,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Get WooCommerce status
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_woocommerce_status($request) {
        return rest_ensure_response([
            'active' => WooCommerce::is_active(),
            'version' => WooCommerce::get_version(),
            'product_count' => WooCommerce::get_product_count(),
            'currency' => WooCommerce::get_currency(),
            'currency_symbol' => WooCommerce::get_currency_symbol(),
        ]);
    }

    /**
     * Get product stats for table view
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_product_stats($request) {
        if (!WooCommerce::is_active()) {
            return rest_ensure_response([
                'products' => [],
                'total' => 0,
                'offset' => 0,
                'limit' => 20,
                'error' => 'WooCommerce is not active',
            ]);
        }

        $days = absint($request->get_param('days')) ?: 30;
        $offset = absint($request->get_param('offset')) ?: 0;
        $limit = absint($request->get_param('limit')) ?: 20;
        $bots = $request->get_param('bots');

        global $wpdb;

        $visits_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->visits_table
            )
        );
        $clicks_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->clicks_table
            )
        );
        $events_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->events_table
            )
        );

        $product_ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $product_rows = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            $permalink = get_permalink($product_id);
            $path = str_replace(home_url(), '', $permalink);
            $normalized_path = strtok($path, '?');
            if (false === $normalized_path) {
                $normalized_path = '';
            }
            $product_rows[ $product_id ] = [
                'product' => $product,
                'permalink' => $permalink,
                'path' => $normalized_path,
            ];
        }

        $paths = array_unique(array_filter(array_column($product_rows, 'path'), 'strlen'));

        $impressions_by_path = [];
        $clicks_by_path = [];
        $bots_by_path = [];

        if ($visits_exists && !empty($paths)) {
            $ph = implode(',', array_fill(0, count($paths), '%s'));
            $sql = "SELECT TRIM(SUBSTRING_INDEX(page_path, '?', 1)) AS pth, COUNT(*) AS c
                FROM {$this->visits_table}
                WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($ph)
                AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params = array_merge($paths, [ $days ]);

            if ($bots) {
                $bots_array = is_array($bots) ? $bots : explode(',', $bots);
                $bots_array = array_map('trim', $bots_array);
                $bots_array = array_filter($bots_array);
                if (!empty($bots_array)) {
                    $bh = implode(',', array_fill(0, count($bots_array), '%s'));
                    $sql .= " AND bot_name IN ($bh)";
                    $params = array_merge($params, $bots_array);
                }
            }

            $sql .= ' GROUP BY pth';
            $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $impressions_by_path[ $row['pth'] ] = (int) $row['c'];
            }

            $sql2 = "SELECT TRIM(SUBSTRING_INDEX(page_path, '?', 1)) AS pth, bot_name, COUNT(*) AS visits
                FROM {$this->visits_table}
                WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($ph)
                AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params2 = array_merge($paths, [ $days ]);
            if ($bots) {
                $bots_array = is_array($bots) ? $bots : explode(',', $bots);
                $bots_array = array_map('trim', $bots_array);
                $bots_array = array_filter($bots_array);
                if (!empty($bots_array)) {
                    $bh = implode(',', array_fill(0, count($bots_array), '%s'));
                    $sql2 .= " AND bot_name IN ($bh)";
                    $params2 = array_merge($params2, $bots_array);
                }
            }
            $sql2 .= ' GROUP BY pth, bot_name ORDER BY visits DESC';
            $brows = $wpdb->get_results($wpdb->prepare($sql2, $params2), ARRAY_A);
            foreach (is_array($brows) ? $brows : [] as $row) {
                $p = $row['pth'];
                if (!isset($bots_by_path[ $p ])) {
                    $bots_by_path[ $p ] = [];
                }
                $bots_by_path[ $p ][] = [
                    'bot_name' => $row['bot_name'],
                    'visits' => (int) $row['visits'],
                ];
            }
        }

        if ($clicks_exists && !empty($paths)) {
            $ph = implode(',', array_fill(0, count($paths), '%s'));
            $sql = "SELECT TRIM(SUBSTRING_INDEX(page_path, '?', 1)) AS pth, COUNT(*) AS c
                FROM {$this->clicks_table}
                WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($ph)
                AND click_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY pth";
            $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge($paths, [ $days ])), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $clicks_by_path[ $row['pth'] ] = (int) $row['c'];
            }
        }

        $atc_by_product = [];
        $revenue_by_product = [];

        if ($events_exists && !empty($product_rows)) {
            $pids = array_keys($product_rows);
            $pid_ph = implode(',', array_fill(0, count($pids), '%d'));
            $atc_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, COUNT(*) AS c FROM {$this->events_table}
                    WHERE event_type = 'add_to_cart'
                    AND product_id IN ($pid_ph)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    GROUP BY product_id",
                    array_merge($pids, [ $days ])
                ),
                ARRAY_A
            );
            foreach (is_array($atc_rows) ? $atc_rows : [] as $row) {
                $atc_by_product[ (int) $row['product_id'] ] = (int) $row['c'];
            }

            $rev_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, COALESCE(SUM(revenue), 0) AS rev, COUNT(*) AS cnt
                    FROM {$this->events_table}
                    WHERE event_type = 'purchase'
                    AND product_id IN ($pid_ph)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    GROUP BY product_id",
                    array_merge($pids, [ $days ])
                ),
                ARRAY_A
            );
            foreach (is_array($rev_rows) ? $rev_rows : [] as $row) {
                $revenue_by_product[ (int) $row['product_id'] ] = [
                    'revenue' => round((float) $row['rev'], 2),
                    'purchases' => (int) $row['cnt'],
                ];
            }
        }

        $product_stats = [];

        foreach ($product_rows as $product_id => $row) {
            $product = $row['product'];
            $normalized_path = $row['path'];
            $permalink = $row['permalink'];

            $impressions = isset($impressions_by_path[ $normalized_path ]) ? $impressions_by_path[ $normalized_path ] : 0;
            $clicks = isset($clicks_by_path[ $normalized_path ]) ? $clicks_by_path[ $normalized_path ] : 0;
            $add_to_cart = isset($atc_by_product[ $product_id ]) ? $atc_by_product[ $product_id ] : 0;
            $rev = isset($revenue_by_product[ $product_id ]) ? $revenue_by_product[ $product_id ] : ['revenue' => 0, 'purchases' => 0];

            $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
            $atc_rate = $impressions > 0 ? round(($add_to_cart / $impressions) * 100, 2) : 0;

            $visibility_score = $this->calculate_product_visibility_score($product_id, $normalized_path, $days);

            $product_stats[] = [
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'product_url' => $permalink,
                'product_path' => $normalized_path,
                'thumbnail' => get_the_post_thumbnail_url($product_id, 'thumbnail') ?: '',
                'price' => $product->get_price(),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'add_to_cart' => $add_to_cart,
                'add_to_cart_rate' => $atc_rate,
                'revenue' => $rev['revenue'],
                'purchases' => $rev['purchases'],
                'bots' => isset($bots_by_path[ $normalized_path ]) ? $bots_by_path[ $normalized_path ] : [],
                'visibility_score' => $visibility_score,
            ];
        }

        usort(
            $product_stats,
            function ($a, $b) {
                return $b['impressions'] <=> $a['impressions'];
            }
        );

        $total = count($product_stats);
        $product_stats = array_slice($product_stats, $offset, $limit);

        return rest_ensure_response([
            'products' => $product_stats,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * Get product impressions from bot visits
     *
     * @param string $path Product path
     * @param int $days Number of days
     * @param string|array|null $bots Bot filter
     * @return int
     */
    private function get_product_impressions($path, $days, $bots = null) {
        global $wpdb;

        // Check if visits table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->visits_table
            )
        );

        if (!$table_exists) {
            return 0;
        }

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->visits_table}
            WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) = %s
            AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $path,
            $days
        );

        if ($bots) {
            $bots_array = is_array($bots) ? $bots : explode(',', $bots);
            $bots_array = array_map('trim', $bots_array);
            $bots_array = array_filter($bots_array);

            if (!empty($bots_array)) {
                $placeholders = implode(',', array_fill(0, count($bots_array), '%s'));
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->visits_table}
                    WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) = %s
                    AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    AND bot_name IN ($placeholders)",
                    array_merge([$path, $days], $bots_array)
                );
            }
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get product clicks
     *
     * @param string $path Product path
     * @param int $days Number of days
     * @return int
     */
    private function get_product_clicks($path, $days) {
        global $wpdb;

        // Check if clicks table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->clicks_table
            )
        );

        if (!$table_exists) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->clicks_table}
            WHERE (page_path = %s OR TRIM(SUBSTRING_INDEX(page_path, '?', 1)) = %s)
            AND click_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $path,
            $path,
            $days
        ));
    }

    /**
     * Get add-to-cart count for product
     *
     * @param int $product_id Product ID
     * @param int $days Number of days
     * @return int
     */
    private function get_product_add_to_cart($product_id, $days) {
        global $wpdb;

        // Check if events table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->events_table
            )
        );

        if (!$table_exists) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->events_table}
            WHERE product_id = %d
            AND event_type = 'add_to_cart'
            AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $product_id,
            $days
        ));
    }

    /**
     * Get product revenue and purchase count
     *
     * @param int $product_id Product ID
     * @param int $days Number of days
     * @return array
     */
    private function get_product_revenue($product_id, $days) {
        global $wpdb;

        // Check if events table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->events_table
            )
        );

        if (!$table_exists) {
            return ['revenue' => 0, 'purchases' => 0];
        }

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(revenue), 0) as revenue, COUNT(*) as purchases
            FROM {$this->events_table}
            WHERE product_id = %d
            AND event_type = 'purchase'
            AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $product_id,
            $days
        ), ARRAY_A);

        return [
            'revenue' => $result ? round((float) $result['revenue'], 2) : 0,
            'purchases' => $result ? (int) $result['purchases'] : 0,
        ];
    }

    /**
     * Get bot breakdown for product
     *
     * @param string $path Product path
     * @param int $days Number of days
     * @return array
     */
    private function get_product_bot_breakdown($path, $days) {
        global $wpdb;

        // Check if visits table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->visits_table
            )
        );

        if (!$table_exists) {
            return [];
        }

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT bot_name, COUNT(*) as visits
            FROM {$this->visits_table}
            WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) = %s
            AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY bot_name
            ORDER BY visits DESC",
            $path,
            $days
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Get product timeseries for chart
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_product_timeseries($request) {
        global $wpdb;

        if (!WooCommerce::is_active()) {
            return rest_ensure_response(['data' => []]);
        }

        $days = absint($request->get_param('days')) ?: 30;
        $bots = $request->get_param('bots');

        // Check if visits table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->visits_table
            )
        );

        if (!$table_exists) {
            return rest_ensure_response(['data' => []]);
        }

        // Get all product paths
        $product_paths = WooCommerce::get_product_paths();

        if (empty($product_paths)) {
            return rest_ensure_response(['data' => []]);
        }

        // Build query
        $placeholders = implode(',', array_fill(0, count($product_paths), '%s'));
        $params = array_merge($product_paths, [$days]);

        $sql = "SELECT DATE(visit_time) as date, bot_name, COUNT(*) as visits
                FROM {$this->visits_table}
                WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($placeholders)
                AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";

        if ($bots) {
            $bots_array = is_array($bots) ? $bots : explode(',', $bots);
            $bots_array = array_map('trim', $bots_array);
            $bots_array = array_filter($bots_array);

            if (!empty($bots_array)) {
                $bot_placeholders = implode(',', array_fill(0, count($bots_array), '%s'));
                $sql .= " AND bot_name IN ($bot_placeholders)";
                $params = array_merge($params, $bots_array);
            }
        }

        $sql .= " GROUP BY DATE(visit_time), bot_name ORDER BY date ASC";

        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        return rest_ensure_response(['data' => $results ?: []]);
    }

    /**
     * Get quick stats for products dashboard
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_quick_stats($request) {
        global $wpdb;

        if (!WooCommerce::is_active()) {
            return rest_ensure_response([
                'total_impressions' => 0,
                'total_clicks' => 0,
                'total_add_to_cart' => 0,
                'add_to_cart_rate' => 0,
                'total_revenue' => 0,
                'purchase_count' => 0,
                'purchase_rate' => 0,
                'attributed_revenue' => 0,
                'attributed_purchases' => 0,
                'currency' => 'USD',
            ]);
        }

        $days = absint($request->get_param('days')) ?: 30;

        // Get all product paths
        $product_paths = WooCommerce::get_product_paths();

        $total_impressions = 0;
        $total_clicks = 0;

        // Check tables exist
        $visits_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->visits_table
            )
        );

        $clicks_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->clicks_table
            )
        );

        $events_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->events_table
            )
        );

        if (!empty($product_paths) && $visits_exists) {
            $placeholders = implode(',', array_fill(0, count($product_paths), '%s'));

            // Total impressions
            $total_impressions = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->visits_table}
                WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($placeholders)
                AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                array_merge($product_paths, [$days])
            ));
        }

        if (!empty($product_paths) && $clicks_exists) {
            $placeholders = implode(',', array_fill(0, count($product_paths), '%s'));

            // Total clicks
            $total_clicks = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->clicks_table}
                WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($placeholders)
                AND click_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                array_merge($product_paths, [$days])
            ));
        }

        // Events stats (add-to-cart, purchases, revenue)
        $total_add_to_cart = 0;
        $total_revenue = 0;
        $purchase_count = 0;
        $attributed_revenue = 0;
        $attributed_purchases = 0;

        if ($events_exists) {
            // Total add-to-carts
            $total_add_to_cart = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->events_table}
                WHERE event_type = 'add_to_cart'
                AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));

            // Total purchases and revenue (using subquery to avoid counting duplicate entries per order)
            $purchase_data = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as purchases, COALESCE(SUM(order_revenue), 0) as revenue
                FROM (
                    SELECT order_id, SUM(revenue) as order_revenue
                    FROM {$this->events_table}
                    WHERE event_type = 'purchase'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    GROUP BY order_id
                ) as unique_orders",
                $days
            ), ARRAY_A);

            $purchase_count = $purchase_data ? (int) $purchase_data['purchases'] : 0;
            $total_revenue = $purchase_data ? (float) $purchase_data['revenue'] : 0;

            // Attributed (with bot_source) purchases and revenue
            $attributed_data = $wpdb->get_row($wpdb->prepare(
                "SELECT COUNT(*) as purchases, COALESCE(SUM(order_revenue), 0) as revenue
                FROM (
                    SELECT order_id, SUM(revenue) as order_revenue
                    FROM {$this->events_table}
                    WHERE event_type = 'purchase'
                    AND bot_source IS NOT NULL
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                    GROUP BY order_id
                ) as attributed_orders",
                $days
            ), ARRAY_A);

            $attributed_purchases = $attributed_data ? (int) $attributed_data['purchases'] : 0;
            $attributed_revenue = $attributed_data ? (float) $attributed_data['revenue'] : 0;
        }

        // Calculate rates
        $atc_rate = $total_impressions > 0 ? round(($total_add_to_cart / $total_impressions) * 100, 2) : 0;
        $purchase_rate = $total_impressions > 0 ? round(($purchase_count / $total_impressions) * 100, 2) : 0;

        return rest_ensure_response([
            'total_impressions' => $total_impressions,
            'total_clicks' => $total_clicks,
            'total_add_to_cart' => $total_add_to_cart,
            'add_to_cart_rate' => $atc_rate,
            'total_revenue' => round($total_revenue, 2),
            'purchase_count' => $purchase_count,
            'purchase_rate' => $purchase_rate,
            'attributed_revenue' => round($attributed_revenue, 2),
            'attributed_purchases' => $attributed_purchases,
            'currency' => WooCommerce::get_currency(),
        ]);
    }

    /**
     * Get top products by bot views
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_top_products($request) {
        if (!WooCommerce::is_active()) {
            return rest_ensure_response([
                'products' => [],
                'has_data' => false,
            ]);
        }

        $days = absint($request->get_param('days')) ?: 7;
        $limit = absint($request->get_param('limit')) ?: 5;

        // Get all products
        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);

        $product_data = [];

        foreach ($products as $product) {
            $permalink = get_permalink($product->ID);
            $path = strtok(str_replace(home_url(), '', $permalink), '?');

            $impressions = $this->get_product_impressions($path, $days, null);

            if ($impressions > 0) {
                $wc_product = wc_get_product($product->ID);

                $product_data[] = [
                    'product_id' => $product->ID,
                    'product_name' => $product->post_title,
                    'product_url' => $permalink,
                    'thumbnail' => get_the_post_thumbnail_url($product->ID, 'thumbnail') ?: '',
                    'price' => $wc_product ? $wc_product->get_price() : '',
                    'impressions' => $impressions,
                    'bots' => $this->get_product_bot_breakdown($path, $days),
                ];
            }
        }

        // Sort by impressions descending
        usort($product_data, function($a, $b) {
            return $b['impressions'] - $a['impressions'];
        });

        // Apply limit
        $product_data = array_slice($product_data, 0, $limit);

        return rest_ensure_response([
            'products' => $product_data,
            'has_data' => !empty($product_data),
        ]);
    }

    /**
     * Get product of the week
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_product_of_week($request) {
        if (!WooCommerce::is_active()) {
            return rest_ensure_response([
                'product' => null,
                'has_data' => false,
            ]);
        }

        // Get top 1 product for last 7 days
        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ]);

        $top_product = null;
        $max_impressions = 0;

        foreach ($products as $product) {
            $permalink = get_permalink($product->ID);
            $path = strtok(str_replace(home_url(), '', $permalink), '?');

            $impressions = $this->get_product_impressions($path, 7, null);

            if ($impressions > $max_impressions) {
                $max_impressions = $impressions;
                $wc_product = wc_get_product($product->ID);

                $top_product = [
                    'product_id' => $product->ID,
                    'product_name' => $product->post_title,
                    'product_url' => $permalink,
                    'thumbnail' => get_the_post_thumbnail_url($product->ID, 'woocommerce_thumbnail') ?: '',
                    'price' => $wc_product ? $wc_product->get_price() : '',
                    'formatted_price' => $wc_product ? wc_price($wc_product->get_price()) : '',
                    'impressions' => $impressions,
                    'bots' => $this->get_product_bot_breakdown($path, 7),
                ];
            }
        }

        return rest_ensure_response([
            'product' => $top_product,
            'has_data' => $top_product !== null,
        ]);
    }

    /**
     * Calculate product visibility score
     *
     * Uses the Product_Details class to calculate the complete visibility score
     * including bot visibility and content quality factors.
     *
     * @param int $product_id Product ID
     * @param string $normalized_path Product URL path
     * @param int $days Number of days for analysis
     * @return array Score data with total and breakdown
     */
    private function calculate_product_visibility_score($product_id, $normalized_path, $days) {
        // Use Product_Details class for score calculation
        $product_details = new Product_Details();
        
        try {
            $score_data = $product_details->calculate_visibility_score($product_id, $days);
            
            if (is_wp_error($score_data)) {
                \llmagnet_aiseo_debug_log('LLMagnet: Error calculating product visibility score for product ' . $product_id . ': ' . $score_data->get_error_message());
                return [
                    'total' => 0,
                    'breakdown' => null,
                ];
            }
            
            return $score_data;
            
        } catch (\Exception $e) {
            \llmagnet_aiseo_debug_log('LLMagnet: Exception calculating product visibility score for product ' . $product_id . ': ' . $e->getMessage());
            return [
                'total' => 0,
                'breakdown' => null,
            ];
        }
    }

    /**
     * Thresholds for product readiness scoring
     */
    const SHORT_DESC_MIN_CHARS = 80;
    const LONG_DESC_MIN_CHARS = 200;

    /**
     * Pro plan allowed bots for filtering
     */
    const PRO_ALLOWED_BOTS = ['ChatGPT', 'Claude', 'Perplexity'];

    /**
     * Get current plan data
     *
     * @return array Plan data
     */
    private function get_current_plan_data() {
        $plan_name = get_option('llmagnet_plan', 'free');
        $is_trial = get_option('llmagnet_is_trial', false);

        return [
            'plan_name' => $plan_name,
            'is_trial' => (bool) $is_trial,
            'is_premium' => in_array($plan_name, ['pro', 'plus', 'enterprise']) || $is_trial,
        ];
    }

    /**
     * Get AI Revenue Funnel data for Overview card
     *
     * Shows commerce impact of AI-attributed traffic:
     * Bot product views → Add to cart → Purchases
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_ai_revenue_funnel($request) {
        // Check WooCommerce
        if (!WooCommerce::is_active()) {
            return rest_ensure_response([
                'error' => 'WooCommerce is not active',
                'empty' => true,
            ]);
        }

        $days = absint($request->get_param('range_days')) ?: 30;
        $plan_data = $this->get_current_plan_data();

        // Check if free plan (should be masked)
        $is_masked = $plan_data['plan_name'] === 'free' && !$plan_data['is_trial'];

        // Use transient cache
        $cache_key = 'llmagnet_ai_funnel_' . $days . '_' . $plan_data['plan_name'];
        $cached = get_transient($cache_key);

        if ($cached !== false && !$is_masked) {
            return rest_ensure_response($cached);
        }

        global $wpdb;

        // Get all product paths for bot views query
        $product_paths = WooCommerce::get_product_paths();
        $views_count = 0;

        // Check if visits table exists
        $visits_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->visits_table
            )
        );

        if ($visits_exists && !empty($product_paths)) {
            $placeholders = implode(',', array_fill(0, count($product_paths), '%s'));
            $params = array_merge($product_paths, [$days]);

            // Build query with optional bot filtering for Pro plan
            $sql = "SELECT COUNT(*) FROM {$this->visits_table}
                    WHERE TRIM(SUBSTRING_INDEX(page_path, '?', 1)) IN ($placeholders)
                    AND visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)";

            // Pro plan: filter to PRO_ALLOWED_BOTS only
            if ($plan_data['plan_name'] === 'pro' && !$plan_data['is_trial']) {
                $bot_placeholders = implode(',', array_fill(0, count(self::PRO_ALLOWED_BOTS), '%s'));
                $sql .= " AND bot_name IN ($bot_placeholders)";
                $params = array_merge($params, self::PRO_ALLOWED_BOTS);
            }

            $views_count = (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        }

        // Get add-to-cart count (AI attributed)
        $add_to_cart_count = 0;
        $events_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->events_table
            )
        );

        if ($events_exists) {
            // Count AI-attributed add-to-cart events (those with bot_source)
            $atc_sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->events_table}
                WHERE event_type = 'add_to_cart'
                AND bot_source IS NOT NULL
                AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            );

            // Pro plan: filter by allowed bots
            if ($plan_data['plan_name'] === 'pro' && !$plan_data['is_trial']) {
                $bot_placeholders = implode(',', array_fill(0, count(self::PRO_ALLOWED_BOTS), '%s'));
                $atc_sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->events_table}
                    WHERE event_type = 'add_to_cart'
                    AND bot_source IS NOT NULL
                    AND bot_source IN ($bot_placeholders)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                    array_merge(self::PRO_ALLOWED_BOTS, [$days])
                );
            }

            $add_to_cart_count = (int) $wpdb->get_var($atc_sql);
        }

        // Get purchases and revenue (AI attributed)
        $purchases_count = 0;
        $revenue_amount = 0.0;

        if ($events_exists) {
            // Get AI-attributed purchases (with bot_source)
            $purchase_sql = $wpdb->prepare(
                "SELECT COUNT(DISTINCT order_id) as purchases, COALESCE(SUM(revenue), 0) as revenue
                FROM {$this->events_table}
                WHERE event_type = 'purchase'
                AND bot_source IS NOT NULL
                AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            );

            // Pro plan: filter by allowed bots
            if ($plan_data['plan_name'] === 'pro' && !$plan_data['is_trial']) {
                $bot_placeholders = implode(',', array_fill(0, count(self::PRO_ALLOWED_BOTS), '%s'));
                $purchase_sql = $wpdb->prepare(
                    "SELECT COUNT(DISTINCT order_id) as purchases, COALESCE(SUM(revenue), 0) as revenue
                    FROM {$this->events_table}
                    WHERE event_type = 'purchase'
                    AND bot_source IS NOT NULL
                    AND bot_source IN ($bot_placeholders)
                    AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                    array_merge(self::PRO_ALLOWED_BOTS, [$days])
                );
            }

            $purchase_data = $wpdb->get_row($purchase_sql, ARRAY_A);
            $purchases_count = $purchase_data ? (int) $purchase_data['purchases'] : 0;
            $revenue_amount = $purchase_data ? (float) $purchase_data['revenue'] : 0.0;
        }

        // Calculate conversion rates
        $view_to_cart_pct = $views_count > 0 ? round(($add_to_cart_count / $views_count) * 100) : 0;
        $cart_to_purchase_pct = $add_to_cart_count > 0 ? round(($purchases_count / $add_to_cart_count) * 100) : 0;

        // Format currency
        $currency = WooCommerce::get_currency();
        $formatted_revenue = function_exists('wc_price') ? wc_price($revenue_amount) : '$' . number_format($revenue_amount, 2);

        // Check if empty (no AI activity)
        $empty = ($views_count === 0 && $add_to_cart_count === 0 && $purchases_count === 0);

        $result = [
            'range_days' => $days,
            'views' => ['count' => $views_count],
            'add_to_cart' => ['count' => $add_to_cart_count],
            'purchases' => ['count' => $purchases_count],
            'conversion' => [
                'view_to_cart_pct' => $view_to_cart_pct,
                'cart_to_purchase_pct' => $cart_to_purchase_pct,
            ],
            'revenue' => [
                'amount' => round($revenue_amount, 2),
                'currency' => $currency,
                'formatted' => $formatted_revenue,
            ],
            'empty' => $empty,
            'is_masked' => $is_masked,
        ];

        // Cache for 15 minutes (unless masked)
        if (!$is_masked) {
            set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        }

        return rest_ensure_response($result);
    }

    /**
     * Get Product Readiness data for Overview card
     *
     * Shows readiness/quality indicators for AI visibility:
     * - Average product visibility score
     * - % with image ALT text
     * - % missing tags
     * - % with short descriptions
     *
     * @param \WP_REST_Request $request REST request
     * @return \WP_REST_Response
     */
    public function get_product_readiness($request) {
        // Check WooCommerce
        if (!WooCommerce::is_active()) {
            return rest_ensure_response([
                'error' => 'WooCommerce is not active',
                'empty' => true,
            ]);
        }

        $days = absint($request->get_param('range_days')) ?: 30;
        $plan_data = $this->get_current_plan_data();

        // Check if free plan (should be masked)
        $is_masked = $plan_data['plan_name'] === 'free' && !$plan_data['is_trial'];

        // Use transient cache
        $cache_key = 'llmagnet_product_readiness_' . $days;
        $cached = get_transient($cache_key);

        if ($cached !== false && !$is_masked) {
            $cached['is_masked'] = $is_masked;
            return rest_ensure_response($cached);
        }

        // Get all published products
        $products = get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $total_products = count($products);

        // Empty state check
        if ($total_products === 0) {
            return rest_ensure_response([
                'range_days' => $days,
                'avg_product_visibility' => 0,
                'alt_coverage_pct' => 0,
                'missing_tags_pct' => 0,
                'short_desc_pct' => 0,
                'thresholds' => [
                    'short_desc_min_chars' => self::SHORT_DESC_MIN_CHARS,
                    'long_desc_min_chars' => self::LONG_DESC_MIN_CHARS,
                ],
                'empty' => true,
                'is_masked' => $is_masked,
            ]);
        }

        // Calculate metrics
        $visibility_scores = [];
        $products_with_alt = 0;
        $products_missing_tags = 0;
        $products_with_short_desc = 0;

        $product_details = new Product_Details();

        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // 1) Calculate visibility score
            $score_data = $product_details->calculate_visibility_score($product_id, $days);
            if (!is_wp_error($score_data) && isset($score_data['score'])) {
                $visibility_scores[] = $score_data['score'];
            }

            // 2) Check image ALT coverage (ALL images must have ALT)
            $has_all_alts = $this->product_has_all_image_alts($product);
            if ($has_all_alts) {
                $products_with_alt++;
            }

            // 3) Check for missing tags
            $tags = get_the_terms($product_id, 'product_tag');
            if (!$tags || is_wp_error($tags) || count($tags) === 0) {
                $products_missing_tags++;
            }

            // 4) Check for short descriptions
            $post = get_post($product_id);
            $short_desc_length = strlen(strip_tags($post->post_excerpt));
            $long_desc_length = strlen(strip_tags($post->post_content));

            if ($short_desc_length < self::SHORT_DESC_MIN_CHARS || $long_desc_length < self::LONG_DESC_MIN_CHARS) {
                $products_with_short_desc++;
            }
        }

        // Calculate averages and percentages
        $avg_visibility = count($visibility_scores) > 0 
            ? round(array_sum($visibility_scores) / count($visibility_scores)) 
            : 0;

        $alt_coverage_pct = $total_products > 0 
            ? round(($products_with_alt / $total_products) * 100) 
            : 0;

        $missing_tags_pct = $total_products > 0 
            ? round(($products_missing_tags / $total_products) * 100) 
            : 0;

        $short_desc_pct = $total_products > 0 
            ? round(($products_with_short_desc / $total_products) * 100) 
            : 0;

        $result = [
            'range_days' => $days,
            'avg_product_visibility' => $avg_visibility,
            'alt_coverage_pct' => $alt_coverage_pct,
            'missing_tags_pct' => $missing_tags_pct,
            'short_desc_pct' => $short_desc_pct,
            'thresholds' => [
                'short_desc_min_chars' => self::SHORT_DESC_MIN_CHARS,
                'long_desc_min_chars' => self::LONG_DESC_MIN_CHARS,
            ],
            'total_products' => $total_products,
            'empty' => false,
            'is_masked' => $is_masked,
        ];

        // Cache for 30 minutes (content quality doesn't change rapidly)
        set_transient($cache_key, $result, 30 * MINUTE_IN_SECONDS);

        return rest_ensure_response($result);
    }

    /**
     * Check if a product has ALT text on ALL images (featured + gallery)
     *
     * @param \WC_Product $product Product object
     * @return bool True if all images have ALT text
     */
    private function product_has_all_image_alts($product) {
        $image_ids = [];

        // Featured image
        $featured_id = $product->get_image_id();
        if ($featured_id) {
            $image_ids[] = $featured_id;
        }

        // Gallery images
        $gallery_ids = $product->get_gallery_image_ids();
        if ($gallery_ids) {
            $image_ids = array_merge($image_ids, $gallery_ids);
        }

        // If no images, consider it as having all ALTs (no penalty)
        if (empty($image_ids)) {
            return true;
        }

        // Check each image for ALT text
        foreach ($image_ids as $image_id) {
            $alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
            if (empty($alt)) {
                return false;
            }
        }

        return true;
    }
}
