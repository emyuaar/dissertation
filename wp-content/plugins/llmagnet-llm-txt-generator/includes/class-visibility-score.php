<?php
/**
 * Visibility Score Calculator
 *
 * Calculates a weighted visibility score based on multiple criteria:
 * - A) Last Crawl Frequency (25%)
 * - B) Visit Type (20%)
 * - C) Visit Count (25%)
 * - D) Page Status (10%)
 * - E) URL Type (20%)
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

// Canonical bot/crawler definitions shared with Analytics
if (!class_exists('LLMagnet_AI_SEO_Optimizer\\Bot_Registry')) {
    require_once LLMAGNET_AISEO_PLUGIN_DIR . 'includes/class-bot-registry.php';
}

/**
 * Visibility Score Calculator class
 */
class Visibility_Score {
    /**
     * Table name for storing visibility scores
     *
     * @var string
     */
    private $table_name;

    /**
     * Bot visits table name
     *
     * @var string
     */
    private $visits_table_name;

    /**
     * Score weights configuration
     *
     * @var array
     */
    private $weights = [
        'frequency'   => 0.25, // A) Last Crawl Frequency
        'visit_type'  => 0.20, // B) Visit Type
        'visit_count' => 0.25, // C) Visit Count
        'page_status' => 0.10, // D) Page Status
        'url_type'    => 0.20, // E) URL Type
    ];

    /**
     * URL type score multipliers
     *
     * @var array
     */
    private $url_type_multipliers = [
        'content'    => 1.0,
        'commercial' => 0.5,
        'technical'  => 0.1,
    ];

    /**
     * Expected midpoint visits for saturation curve
     * Adjust based on expected traffic patterns
     *
     * @var array
     */
    private $expected_midpoint_visits = [
        7  => 3,   // 7 days range
        30 => 10,  // 30 days range
        90 => 30,  // 90 days range
    ];

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'llm_visibility_scores';
        $this->visits_table_name = $wpdb->prefix . 'llm_bot_visits';
    }

    /**
     * Initialize visibility score functionality
     *
     * @return void
     */
    public function init() {
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Add hook for visibility score calculation on dashboard load
        add_action('admin_init', [$this, 'maybe_calculate_score_on_dashboard']);
    }

    /**
     * Create the database table on plugin activation
     *
     * @return void
     */
    public static function create_db_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'llm_visibility_scores';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            score int NOT NULL,
            frequency_score int NOT NULL DEFAULT 0,
            visit_type_score int NOT NULL DEFAULT 0,
            visit_count_score int NOT NULL DEFAULT 0,
            page_status_score int NOT NULL DEFAULT 0,
            url_type_score int NOT NULL DEFAULT 0,
            total_visits int NOT NULL DEFAULT 0,
            unique_pages int NOT NULL DEFAULT 0,
            range_days int NOT NULL DEFAULT 30,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        register_rest_route('llm-analytics/v1', '/visibility-score', [
            'methods' => 'GET',
            'callback' => [$this, 'get_visibility_score_response'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);

        register_rest_route('llm-analytics/v1', '/visibility-score/calculate', [
            'methods' => 'POST',
            'callback' => [$this, 'calculate_and_save_score_response'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);

        register_rest_route('llm-analytics/v1', '/visibility-score/history', [
            'methods' => 'GET',
            'callback' => [$this, 'get_score_history_response'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);

        register_rest_route('llm-analytics/v1', '/visibility/timeline', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_visibility_timeline'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => [
                'range' => [
                    'required'          => false,
                    'default'           => '30d',
                    'sanitize_callback' => function ( $v ) {
                        return in_array( $v, ['7d', '30d', '90d'], true ) ? $v : '30d';
                    },
                ],
                'interval' => [
                    'required'          => false,
                    'default'           => 'day',
                    'sanitize_callback' => function ( $v ) {
                        return 'day';
                    },
                ],
            ],
        ]);
    }

    /**
     * Get visibility score REST response
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function get_visibility_score_response($request) {
        $range_days = $request->get_param('range_days') ?: 30;
        $score_data = $this->get_latest_score($range_days);
        
        if (!$score_data) {
            // Calculate if no score exists
            $score_data = $this->compute_visibility_score($range_days);
            $this->save_score($score_data);
        }
        
        return rest_ensure_response($score_data);
    }

    /**
     * Calculate and save visibility score REST response
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function calculate_and_save_score_response($request) {
        $range_days = $request->get_param('range_days') ?: 30;
        $score_data = $this->compute_visibility_score($range_days);
        
        // Check if score changed from last saved score
        $last_score = $this->get_latest_score($range_days);
        
        if (!$last_score || $last_score['score'] !== $score_data['score']) {
            $this->save_score($score_data);
        }
        
        return rest_ensure_response([
            'success' => true,
            'score_data' => $score_data,
            'was_updated' => (!$last_score || $last_score['score'] !== $score_data['score']),
        ]);
    }

    /**
     * Get score history REST response
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function get_score_history_response($request) {
        $limit = $request->get_param('limit') ?: 30;
        $history = $this->get_score_history($limit);
        
        return rest_ensure_response($history);
    }

    /**
     * Get aggregated daily timeline for the Visibility Score Over Time graph.
     *
     * @param \WP_REST_Request $request REST API request
     * @return \WP_REST_Response
     */
    public function rest_visibility_timeline( \WP_REST_Request $request ) {
        global $wpdb;

        $range = $request->get_param('range') ?: '30d';
        $days  = (int) filter_var( $range, FILTER_SANITIZE_NUMBER_INT ) ?: 30;

        // Check table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->table_name
            )
        );

        if ( ! $table_exists ) {
            return rest_ensure_response([
                'range'          => $range,
                'interval'       => 'day',
                'current_score'  => null,
                'previous_score' => null,
                'delta'          => null,
                'delta_percent'  => null,
                'trend'          => 'na',
                'points'         => [],
            ]);
        }

        // Daily scores for the selected range.
        // NULLIF(score, 0) treats zero as no-data (a zero score means the calculation
        // ran before any bot visits were recorded and is not meaningful).
        // AVG ignores NULLs automatically; HAVING excludes days where every record was 0.
        // We use the latest non-zero score per day rather than a plain average so that a
        // corrected recalculation always wins over an earlier stale zero.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) AS d,
                        AVG(NULLIF(score, 0)) AS avg_score
                 FROM {$this->table_name}
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                 GROUP BY DATE(created_at)
                 HAVING avg_score IS NOT NULL
                 ORDER BY d ASC",
                $days
            ),
            ARRAY_A
        );

        $points = array_map( function ( $r ) {
            return [
                'date'  => $r['d'],
                'score' => round( (float) $r['avg_score'], 1 ),
            ];
        }, $rows ?: [] );

        // Current period average (excluding zero scores)
        $current_avg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(NULLIF(score, 0)) FROM {$this->table_name}
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        // Previous same-length period average (excluding zero scores)
        $prev_avg = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(NULLIF(score, 0)) FROM {$this->table_name}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                   AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days,
                $days * 2
            )
        );

        $current  = is_null( $current_avg ) ? null : round( (float) $current_avg, 1 );
        $previous = is_null( $prev_avg )    ? null : round( (float) $prev_avg, 1 );
        $delta    = ( ! is_null( $current ) && ! is_null( $previous ) )
            ? round( $current - $previous, 1 )
            : null;
        $delta_percent = ( ! is_null( $delta ) && $previous > 0 )
            ? round( ( $delta / $previous ) * 100, 1 )
            : null;

        $trend = 'na';
        if ( ! is_null( $delta ) ) {
            if ( $delta > 0.5 )       $trend = 'up';
            elseif ( $delta < -0.5 )  $trend = 'down';
            else                       $trend = 'flat';
        }

        return rest_ensure_response([
            'range'          => $range,
            'interval'       => 'day',
            'current_score'  => $current,
            'previous_score' => $previous,
            'delta'          => $delta,
            'delta_percent'  => $delta_percent,
            'trend'          => $trend,
            'points'         => $points,
        ]);
    }

    /**
     * Maybe calculate score when dashboard is loaded
     *
     * @return void
     */
    public function maybe_calculate_score_on_dashboard() {
        // Check if we're on the plugin dashboard
        if (!isset($_GET['page']) || strpos($_GET['page'], 'llmagnet-ai-seo') === false) {
            return;
        }
        
        // Only calculate on main dashboard page
        if ($_GET['page'] !== 'llmagnet-ai-seo-optimizer') {
            return;
        }
        
        $latest = $this->get_latest_score();

        if ($latest) {
            $score_time  = strtotime($latest['created_at']);
            $is_today    = date('Y-m-d', $score_time) === date('Y-m-d');
            $is_nonzero  = (int) $latest['score'] > 0;

            // Already have a valid (non-zero) score recorded today — nothing to do.
            if ($is_today && $is_nonzero) {
                return;
            }

            // Score is less than 1 hour old and non-zero — still fresh enough.
            if ($is_nonzero && $score_time > time() - HOUR_IN_SECONDS) {
                return;
            }

            // Score is less than 15 minutes old even if zero — avoid hammering on every page load.
            if ($score_time > time() - (15 * MINUTE_IN_SECONDS)) {
                return;
            }
        }

        // Calculate new score
        $this->calculate_and_save_if_changed();
    }

    /**
     * Calculate visibility score and save if changed
     *
     * @param int $range_days Number of days for the range
     * @return array Score data
     */
    public function calculate_and_save_if_changed($range_days = 30) {
        $score_data = $this->compute_visibility_score($range_days);
        $last_score = $this->get_latest_score($range_days);
        
        if (!$last_score || $last_score['score'] !== $score_data['score']) {
            $this->save_score($score_data);
        }
        
        return $score_data;
    }

    /**
     * Compute visibility score based on all criteria
     *
     * @param int $range_days Number of days for the analysis range
     * @return array Score data with breakdown
     */
    public function compute_visibility_score($range_days = 30) {
        global $wpdb;
        
        // Use the same date calculation as Analytics class for consistency
        // This ensures visit counts match between visibility score and dashboard analytics
        $range_start = gmdate('Y-m-d H:i:s', strtotime("-{$range_days} days"));
        $range_end = gmdate('Y-m-d H:i:s');
        
        // Get all visits in the range using MySQL DATE_SUB for consistency with Analytics class
        $visits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->visits_table_name} 
            WHERE visit_time >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $range_days
        ), ARRAY_A);
        
        // If no visits, return 0 score
        if (empty($visits)) {
            return [
                'score' => 0,
                'frequency_score' => 0,
                'visit_type_score' => 0,
                'visit_count_score' => 0,
                'page_status_score' => 0,
                'url_type_score' => 0,
                'total_visits' => 0,
                'unique_pages' => 0,
                'range_days' => $range_days,
                'breakdown' => [
                    'frequency' => ['score' => 0, 'weight' => $this->weights['frequency'], 'details' => 'No visits'],
                    'visit_type' => ['score' => 0, 'weight' => $this->weights['visit_type'], 'details' => 'No visits'],
                    'visit_count' => ['score' => 0, 'weight' => $this->weights['visit_count'], 'details' => 'No visits'],
                    'page_status' => ['score' => 0, 'weight' => $this->weights['page_status'], 'details' => 'No visits'],
                    'url_type' => ['score' => 0, 'weight' => $this->weights['url_type'], 'details' => 'No visits'],
                ],
            ];
        }
        
        // A) Calculate Last Crawl Frequency Score
        $freq_result = $this->calculate_frequency_score($visits, $range_start, $range_end, $range_days);
        
        // B) Calculate Visit Type Score
        $type_result = $this->calculate_visit_type_score($visits);
        
        // C) Calculate Visit Count Score
        $count_result = $this->calculate_visit_count_score($visits, $range_days);
        
        // D) Calculate Page Status Score
        $status_result = $this->calculate_page_status_score($visits);
        
        // E) Calculate URL Type Score
        $url_type_result = $this->calculate_url_type_score($visits);
        
        // Get unique pages
        $unique_pages = count(array_unique(array_column($visits, 'page_path')));
        
        // Calculate weighted final score
        $final_score = round(
            $this->weights['frequency'] * $freq_result['score'] +
            $this->weights['visit_type'] * $type_result['score'] +
            $this->weights['visit_count'] * $count_result['score'] +
            $this->weights['page_status'] * $status_result['score'] +
            $this->weights['url_type'] * $url_type_result['score']
        );
        
        // Clamp between 0-100
        $final_score = max(0, min(100, $final_score));
        
        return [
            'score' => $final_score,
            'frequency_score' => $freq_result['score'],
            'visit_type_score' => $type_result['score'],
            'visit_count_score' => $count_result['score'],
            'page_status_score' => $status_result['score'],
            'url_type_score' => $url_type_result['score'],
            'total_visits' => count($visits),
            'unique_pages' => $unique_pages,
            'range_days' => $range_days,
            'breakdown' => [
                'frequency' => [
                    'score' => $freq_result['score'],
                    'weight' => $this->weights['frequency'],
                    'weighted_contribution' => round($this->weights['frequency'] * $freq_result['score']),
                    'details' => $freq_result['details'],
                ],
                'visit_type' => [
                    'score' => $type_result['score'],
                    'weight' => $this->weights['visit_type'],
                    'weighted_contribution' => round($this->weights['visit_type'] * $type_result['score']),
                    'details' => $type_result['details'],
                ],
                'visit_count' => [
                    'score' => $count_result['score'],
                    'weight' => $this->weights['visit_count'],
                    'weighted_contribution' => round($this->weights['visit_count'] * $count_result['score']),
                    'details' => $count_result['details'],
                ],
                'page_status' => [
                    'score' => $status_result['score'],
                    'weight' => $this->weights['page_status'],
                    'weighted_contribution' => round($this->weights['page_status'] * $status_result['score']),
                    'details' => $status_result['details'],
                ],
                'url_type' => [
                    'score' => $url_type_result['score'],
                    'weight' => $this->weights['url_type'],
                    'weighted_contribution' => round($this->weights['url_type'] * $url_type_result['score']),
                    'details' => $url_type_result['details'],
                ],
            ],
        ];
    }

    /**
     * A) Calculate Last Crawl Frequency Score (0-100)
     *
     * @param array $visits Array of visit records
     * @param string $range_start Range start datetime
     * @param string $range_end Range end datetime
     * @param int $range_days Number of days in range
     * @return array Score and details
     */
    private function calculate_frequency_score($visits, $range_start, $range_end, $range_days) {
        // Get last visit timestamp
        $last_visit = max(array_column($visits, 'visit_time'));
        $last_visit_time = strtotime($last_visit);
        $current_time = time(); // Use current time for consistency
        
        $days_since_last = floor(($current_time - $last_visit_time) / DAY_IN_SECONDS);
        
        // Calculate recency score
        if ($days_since_last <= 1) {
            $recency_score = 100;
        } elseif ($days_since_last <= 7) {
            $recency_score = 80;
        } elseif ($days_since_last <= 30) {
            $recency_score = 40;
        } else {
            $recency_score = 0;
        }
        
        // Calculate activity ratio (active days / total days)
        $visit_dates = array_unique(array_map(function($v) {
            return date('Y-m-d', strtotime($v['visit_time']));
        }, $visits));
        $active_days = count($visit_dates);
        $activity_ratio = min(1, $active_days / max(1, $range_days));
        
        // Combine recency (70%) and activity (30%)
        $final_score = round(0.7 * $recency_score + 0.3 * ($activity_ratio * 100));
        
        return [
            'score' => $final_score,
            'details' => sprintf(
                'Last visit: %s ago, Active days: %d/%d',
                $days_since_last <= 1 ? 'today' : $days_since_last . ' days',
                $active_days,
                $range_days
            ),
        ];
    }

    /**
     * B) Calculate Visit Type Score (0-100)
     *
     * @param array $visits Array of visit records
     * @return array Score and details
     */
    private function calculate_visit_type_score($visits) {
        $total = count($visits);
        
        if ($total === 0) {
            return ['score' => 0, 'details' => 'No visits'];
        }
        
        // Count visits by bot type (classification from the shared bot registry)
        $type_counts = [
            'user_search_ai' => 0,
            'crawler_ai' => 0,
            'unknown' => 0,
        ];
        
        foreach ($visits as $visit) {
            $bot_type = Bot_Registry::get_bot_type($visit['bot_name']);
            $type_counts[$bot_type]++;
        }
        
        // Calculate weighted score
        $multipliers = Bot_Registry::get_bot_type_multipliers();
        $score = 100 * (
            $multipliers['user_search_ai'] * ($type_counts['user_search_ai'] / $total) +
            $multipliers['crawler_ai'] * ($type_counts['crawler_ai'] / $total) +
            $multipliers['unknown'] * ($type_counts['unknown'] / $total)
        );
        
        $score = round(max(0, min(100, $score)));
        
        return [
            'score' => $score,
            'details' => sprintf(
                'User AI: %d, Crawlers: %d, Unknown: %d',
                $type_counts['user_search_ai'],
                $type_counts['crawler_ai'],
                $type_counts['unknown']
            ),
        ];
    }

    /**
     * C) Calculate Visit Count Score (0-100) using saturation curve
     *
     * @param array $visits Array of visit records
     * @param int $range_days Number of days in range
     * @return array Score and details
     */
    private function calculate_visit_count_score($visits, $range_days) {
        $visit_count = count($visits);
        
        if ($visit_count === 0) {
            return ['score' => 0, 'details' => 'No visits'];
        }
        
        // Get expected midpoint based on range
        $k = $this->expected_midpoint_visits[30]; // Default to 30-day expectation
        foreach ($this->expected_midpoint_visits as $days => $midpoint) {
            if ($range_days <= $days) {
                $k = $midpoint;
                break;
            }
        }
        
        // Saturation curve: score = 100 * (1 - e^(-visits/K))
        $score = 100 * (1 - exp(-$visit_count / $k));
        $score = round(max(0, min(100, $score)));
        
        return [
            'score' => $score,
            'details' => sprintf('%d visits (expected midpoint: %d)', $visit_count, $k),
        ];
    }

    /**
     * D) Calculate Page Status Score (0-100)
     * 
     * Since we don't store HTTP status codes, we assume all visited pages are valid.
     * In the future, this can be enhanced to actually check page status.
     *
     * @param array $visits Array of visit records
     * @return array Score and details
     */
    private function calculate_page_status_score($visits) {
        // Get unique URLs
        $unique_urls = array_unique(array_filter(array_column($visits, 'page_path')));
        $total_urls = count($unique_urls);
        
        if ($total_urls === 0) {
            return ['score' => 100, 'details' => 'No pages to validate'];
        }
        
        // For now, assume all pages are valid (status 200)
        // TODO: Implement actual HTTP status checking in future versions
        $valid_urls = $total_urls;
        
        // Check if pages still exist in WordPress
        $valid_count = 0;
        foreach ($unique_urls as $path) {
            // Remove query string for checking
            $clean_path = strtok($path, '?');
            
            // Check if this path corresponds to a valid WordPress post/page
            $post_id = url_to_postid(home_url($clean_path));
            
            if ($post_id > 0) {
                $post_status = get_post_status($post_id);
                if ($post_status === 'publish') {
                    $valid_count++;
                }
            } else {
                // Could be homepage or other valid URL
                if ($clean_path === '/' || $clean_path === '') {
                    $valid_count++;
                }
            }
        }
        
        // Calculate score based on valid ratio
        // Give benefit of doubt for URLs we can't validate
        $validated_ratio = $total_urls > 0 ? $valid_count / $total_urls : 1;
        
        // If we validated less than half, assume the rest are likely valid too
        if ($valid_count < $total_urls && $validated_ratio < 0.5) {
            $score = max(70, round($validated_ratio * 100 + 50));
        } else {
            $score = round($validated_ratio * 100);
        }
        
        $score = max(0, min(100, $score));
        
        return [
            'score' => $score,
            'details' => sprintf('%d/%d pages validated as accessible', $valid_count, $total_urls),
        ];
    }

    /**
     * E) Calculate URL Type Score (0-100)
     *
     * @param array $visits Array of visit records
     * @return array Score and details
     */
    private function calculate_url_type_score($visits) {
        // Get unique URLs
        $unique_urls = array_unique(array_filter(array_column($visits, 'page_path')));
        $total_urls = count($unique_urls);
        
        if ($total_urls === 0) {
            return ['score' => 100, 'details' => 'No pages to classify'];
        }
        
        $type_counts = [
            'content' => 0,
            'commercial' => 0,
            'technical' => 0,
        ];
        
        $score_sum = 0;
        foreach ($unique_urls as $url) {
            $type = $this->classify_url_type($url);
            $type_counts[$type]++;
            $score_sum += $this->url_type_multipliers[$type];
        }
        
        $score = round(100 * ($score_sum / $total_urls));
        $score = max(0, min(100, $score));
        
        return [
            'score' => $score,
            'details' => sprintf(
                'Content: %d, Commercial: %d, Technical: %d',
                $type_counts['content'],
                $type_counts['commercial'],
                $type_counts['technical']
            ),
        ];
    }

    /**
     * Classify URL type
     *
     * @param string $url URL path to classify
     * @return string Type: content, commercial, or technical
     */
    public function classify_url_type($url) {
        $path = strtolower(strtok($url, '?'));
        
        // Technical patterns
        $technical_patterns = [
            '/wp-admin',
            '/wp-login',
            '/wp-json',
            '/feed',
            '/rss',
            '/sitemap',
            '/robots.txt',
            '/xmlrpc.php',
            '/wp-content',
            '/wp-includes',
            '/tag/',
            '/category/',
            '/author/',
            '/page/',
            '/search',
            '/?s=',
            '/archive',
        ];
        
        foreach ($technical_patterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return 'technical';
            }
        }
        
        // Commercial patterns
        $commercial_patterns = [
            '/cart',
            '/checkout',
            '/account',
            '/my-account',
            '/pricing',
            '/product/',
            '/products/',
            '/shop/',
            '/store/',
            '/order',
            '/payment',
            '/subscription',
            'add-to-cart',
            'add_to_cart',
        ];
        
        foreach ($commercial_patterns as $pattern) {
            if (strpos($path, $pattern) !== false) {
                return 'commercial';
            }
        }
        
        // Check if it's a single post/page (content)
        $post_id = url_to_postid(home_url($path));
        if ($post_id > 0) {
            $post_type = get_post_type($post_id);
            
            // WooCommerce products are commercial
            if ($post_type === 'product') {
                return 'commercial';
            }
            
            // Posts, pages, and custom post types are content
            return 'content';
        }
        
        // Homepage is content
        if ($path === '/' || $path === '' || $path === '/index.php') {
            return 'content';
        }
        
        // Default to technical for unidentified URLs
        return 'technical';
    }

    /**
     * Get the latest saved score
     *
     * @param int $range_days Range days filter (optional)
     * @return array|null Score data or null if not found
     */
    public function get_latest_score($range_days = 30) {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->table_name
            )
        );
        
        if (!$table_exists) {
            return null;
        }
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            WHERE range_days = %d 
            ORDER BY created_at DESC 
            LIMIT 1",
            $range_days
        ), ARRAY_A);
        
        return $result;
    }

    /**
     * Save score to database
     *
     * @param array $score_data Score data to save
     * @return bool Success status
     */
    public function save_score($score_data) {
        global $wpdb;
        
        // Ensure table exists
        self::create_db_table();
        
        $result = $wpdb->insert(
            $this->table_name,
            [
                'score' => $score_data['score'],
                'frequency_score' => $score_data['frequency_score'],
                'visit_type_score' => $score_data['visit_type_score'],
                'visit_count_score' => $score_data['visit_count_score'],
                'page_status_score' => $score_data['page_status_score'],
                'url_type_score' => $score_data['url_type_score'],
                'total_visits' => $score_data['total_visits'],
                'unique_pages' => $score_data['unique_pages'],
                'range_days' => $score_data['range_days'],
            ],
            ['%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d']
        );

        if ( $result !== false ) {
            $this->clear_breakdown_cache();
            do_action( 'llmagnet_visibility_score_updated', (int) round( $score_data['score'] ) );
        }

        return $result !== false;
    }

    /**
     * Invalidate cached score breakdown (e.g. after a new score is saved).
     *
     * @return void
     */
    private function clear_breakdown_cache() {
        foreach ( [ 7, 14, 30, 60, 90 ] as $days ) {
            delete_transient( 'llmagnet_visibility_breakdown_' . $days );
        }
    }

    /**
     * Get score history
     *
     * @param int $limit Number of records to retrieve
     * @return array Array of score records
     */
    public function get_score_history($limit = 30) {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $this->table_name
            )
        );
        
        if (!$table_exists) {
            return [];
        }
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
            ORDER BY created_at DESC 
            LIMIT %d",
            $limit
        ), ARRAY_A);
        
        return $results ?: [];
    }

    /**
     * Get current score for dashboard display
     * Returns cached score if available and recent, otherwise calculates new
     *
     * @param int $range_days Range days
     * @return int Score value (0-100)
     */
    public function get_current_score($range_days = 30) {
        $latest = $this->get_latest_score($range_days);
        
        if ($latest) {
            // Check if score is less than 1 hour old
            $score_time = strtotime($latest['created_at']);
            if ($score_time > time() - HOUR_IN_SECONDS) {
                return (int) $latest['score'];
            }
        }
        
        // Calculate new score
        $score_data = $this->compute_visibility_score($range_days);
        $this->save_score($score_data);
        
        return $score_data['score'];
    }

    /**
     * Get score breakdown for display
     *
     * @param int $range_days Range days
     * @return array Full score data with breakdown
     */
    public function get_score_breakdown($range_days = 30) {
        $range_days = (int) $range_days;
        if ( $range_days < 1 ) {
            $range_days = 30;
        }
        $cache_key = 'llmagnet_visibility_breakdown_' . $range_days;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false && is_array( $cached ) ) {
            return $cached;
        }
        $data = $this->compute_visibility_score( $range_days );
        set_transient( $cache_key, $data, HOUR_IN_SECONDS );
        return $data;
    }

    /**
     * Get bot type for a given bot name
     *
     * @param string $bot_name Bot name
     * @return string Bot type
     */
    public function get_bot_type($bot_name) {
        return Bot_Registry::get_bot_type($bot_name);
    }

    /**
     * Get all bot types mapping
     *
     * @return array Bot type map
     */
    public function get_bot_type_map() {
        return Bot_Registry::get_bot_type_map();
    }

    /**
     * Update score weights
     *
     * @param array $new_weights New weight values
     * @return void
     */
    public function set_weights($new_weights) {
        $this->weights = array_merge($this->weights, $new_weights);
        $this->clear_breakdown_cache();
    }

    /**
     * Get current weights
     *
     * @return array Current weights
     */
    public function get_weights() {
        return $this->weights;
    }
}

