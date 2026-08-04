<?php
/**
 * Product Details class
 *
 * Handles fetching and updating WooCommerce product details for the drawer,
 * including per-product visibility score calculation.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Product Details class
 */
class Product_Details {
    /**
     * Database table names
     *
     * @var string
     */
    private $visits_table;
    private $events_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->visits_table = $wpdb->prefix . 'llm_bot_visits';
        $this->events_table = $wpdb->prefix . 'llm_product_events';
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        // Get product details endpoint
        register_rest_route('llm-analytics/v1', '/product-details', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_product_details_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'product_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
                'range_days' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 30,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        // Update product details endpoint
        register_rest_route('llm-analytics/v1', '/product-details/update', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_product_details_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'product_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'short_description' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                ],
                'long_description' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post',
                ],
                'image_alts' => [
                    'required' => false,
                    'type' => 'array',
                ],
                'tags' => [
                    'required' => false,
                    'type' => 'array',
                ],
            ],
        ]);

        // Get all available product tags (for autocomplete)
        register_rest_route('llm-analytics/v1', '/product-tags', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_product_tags_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'search' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Get product visibility score endpoint
        register_rest_route('llm-analytics/v1', '/product-visibility-score', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_product_score_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'product_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
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
     * REST endpoint: Get product details
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_product_details_endpoint($request) {
        try {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'WooCommerce is not active',
                    'code' => 'woocommerce_inactive',
                ], 400);
            }

            $product_id = $request->get_param('product_id');
            $range_days = $request->get_param('range_days') ?: 30;

            if (!$product_id) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Product ID is required',
                    'code' => 'missing_product_id',
                ], 400);
            }

            $result = $this->get_product_details($product_id, $range_days);

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ], 404);
            }

            return new \WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            \llmagnet_aiseo_debug_log('Product Details Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Failed to load product details',
                'code' => 'internal_error',
            ], 500);
        }
    }

    /**
     * REST endpoint: Update product details
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function update_product_details_endpoint($request) {
        try {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'WooCommerce is not active',
                    'code' => 'woocommerce_inactive',
                ], 400);
            }

            $product_id = $request->get_param('product_id');
            
            // Validate product_id
            if (!$product_id || !is_numeric($product_id)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Invalid product ID',
                    'code' => 'invalid_product_id',
                ], 400);
            }

            $data = [
                'short_description' => $request->get_param('short_description'),
                'long_description' => $request->get_param('long_description'),
                'image_alts' => $request->get_param('image_alts'),
                'tags' => $request->get_param('tags'),
            ];

            $result = $this->update_product_details($product_id, $data);

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ], 400);
            }

            return new \WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            \llmagnet_aiseo_debug_log('Product Update Error: ' . $e->getMessage());
            \llmagnet_aiseo_debug_log('Stack trace: ' . $e->getTraceAsString());
            
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Failed to update product',
                'code' => 'update_error',
            ], 500);
        }
    }

    /**
     * REST endpoint: Get product visibility score
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_product_score_endpoint($request) {
        if (!class_exists('WooCommerce')) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'WooCommerce is not active',
            ], 400);
        }

        $product_id = $request->get_param('product_id');
        $range_days = $request->get_param('range_days') ?: 30;

        $score = $this->calculate_visibility_score($product_id, $range_days);

        if (is_wp_error($score)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $score->get_error_message(),
            ], 404);
        }

        return new \WP_REST_Response($score, 200);
    }

    /**
     * REST endpoint: Get all available product tags for autocomplete
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_all_product_tags_endpoint($request) {
        if (!class_exists('WooCommerce')) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'WooCommerce is not active',
            ], 400);
        }

        $search = $request->get_param('search') ?: '';

        // Get all product tags
        $args = [
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ];

        // Add search filter if provided
        if (!empty($search)) {
            $args['search'] = $search;
        }

        $tags = get_terms($args);

        if (is_wp_error($tags)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $tags->get_error_message(),
            ], 500);
        }

        $tag_list = [];
        foreach ($tags as $tag) {
            $tag_list[] = [
                'term_id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'count' => $tag->count,
            ];
        }

        return new \WP_REST_Response([
            'success' => true,
            'tags' => $tag_list,
        ], 200);
    }

    /**
     * Get product details for drawer
     *
     * @param int $product_id Product ID
     * @param int $range_days Date range for score calculation
     * @return array|\WP_Error Product details or error
     */
    public function get_product_details($product_id, $range_days = 30) {
        // Debug logging
        \llmagnet_aiseo_debug_log("Product Details: Fetching product ID: {$product_id}");
        
        // Ensure product_id is an integer
        $product_id = absint($product_id);
        
        if (!$product_id || $product_id <= 0) {
            \llmagnet_aiseo_debug_log("Product Details: Invalid product ID");
            return new \WP_Error('invalid_product_id', 'Invalid product ID', ['status' => 400]);
        }
        
        // Check if post exists first
        $post = get_post($product_id);
        if (!$post) {
            \llmagnet_aiseo_debug_log("Product Details: Post not found for ID {$product_id}");
            return new \WP_Error('product_not_found', 'Product not found', ['status' => 404]);
        }
        
        \llmagnet_aiseo_debug_log("Product Details: Post found, type: {$post->post_type}, status: {$post->post_status}");
        
        // Validate product type
        if ($post->post_type !== 'product') {
            \llmagnet_aiseo_debug_log("Product Details: Post is not a product, type is: {$post->post_type}");
            return new \WP_Error('not_a_product', 'Post is not a product', ['status' => 400]);
        }

        // Get WooCommerce product object
        $product = wc_get_product($product_id);
        if (!$product) {
            \llmagnet_aiseo_debug_log("Product Details: wc_get_product returned false for ID {$product_id}");
            return new \WP_Error('product_not_found', 'Product not found in WooCommerce', ['status' => 404]);
        }
        
        \llmagnet_aiseo_debug_log("Product Details: Successfully loaded product: {$product->get_name()}");

        // Get product basic info
        $product_data = [
            'id' => $product_id,
            'name' => $product->get_name(),
            'permalink' => get_permalink($product_id),
            'path' => str_replace(home_url(), '', get_permalink($product_id)),
            'thumbnail' => $this->get_product_thumbnail($product),
            'price' => $product->get_price_html(),
            'status' => $post->post_status,
            'short_description' => $post->post_excerpt,
            'long_description' => $post->post_content,
        ];

        // Get images with ALT
        $product_data['images'] = $this->get_product_images($product);

        // Get categories with hierarchy
        $product_data['categories'] = $this->get_product_categories($product_id);

        // Get tags
        $product_data['tags'] = $this->get_product_tags($product_id);

        // Calculate visibility score
        $visibility_score = $this->calculate_visibility_score($product_id, $range_days);

        return [
            'success' => true,
            'product' => $product_data,
            'visibility_score' => $visibility_score,
        ];
    }

    /**
     * Update product details
     *
     * @param int $product_id Product ID
     * @param array $data Update data
     * @return array|\WP_Error Success response or error
     */
    public function update_product_details($product_id, $data) {
        // Validate product exists
        if (get_post_type($product_id) !== 'product') {
            return new \WP_Error('product_not_found', 'Product not found');
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('product_not_found', 'Product not found');
        }

        $updated = [
            'short_description' => false,
            'long_description' => false,
            'image_alts' => 0,
            'tags' => 0,
        ];

        // Update short description (excerpt)
        if (isset($data['short_description']) && $data['short_description'] !== null) {
            $sanitized_excerpt = wp_kses_post($data['short_description']);
            $result = wp_update_post([
                'ID' => $product_id,
                'post_excerpt' => $sanitized_excerpt,
            ], true);

            if (!is_wp_error($result) && $result !== 0) {
                $updated['short_description'] = true;
            }
        }

        // Update long description (content)
        if (isset($data['long_description']) && $data['long_description'] !== null) {
            $sanitized_content = wp_kses_post($data['long_description']);
            $result = wp_update_post([
                'ID' => $product_id,
                'post_content' => $sanitized_content,
            ], true);

            if (!is_wp_error($result) && $result !== 0) {
                $updated['long_description'] = true;
            }
        }

        // Update image ALTs
        if (isset($data['image_alts']) && is_array($data['image_alts'])) {
            foreach ($data['image_alts'] as $image_data) {
                if (!is_array($image_data)) {
                    continue;
                }
                
                if (isset($image_data['attachment_id']) && isset($image_data['alt'])) {
                    $attachment_id = intval($image_data['attachment_id']);
                    
                    // Skip invalid attachment IDs
                    if ($attachment_id <= 0) {
                        continue;
                    }
                    
                    $alt = sanitize_text_field($image_data['alt']);

                    // Validate attachment exists and is an image
                    if (wp_attachment_is_image($attachment_id)) {
                        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
                        $updated['image_alts']++;
                    }
                }
            }
        }

        // Update tags
        if (isset($data['tags']) && is_array($data['tags'])) {
            // Filter out empty tags and sanitize
            $tag_names = array_filter(
                array_map('sanitize_text_field', $data['tags']),
                function($tag) {
                    return !empty($tag);
                }
            );
            
            $result = wp_set_object_terms($product_id, $tag_names, 'product_tag', false);

            if (!is_wp_error($result) && is_array($result)) {
                $updated['tags'] = count($tag_names);
            }
        }

        // Clear product score cache
        $this->clear_product_score_cache($product_id);

        return [
            'success' => true,
            'message' => 'Product updated successfully',
            'updated' => $updated,
            'cache_cleared' => true,
        ];
    }

    /**
     * Calculate product visibility score
     *
     * @param int $product_id Product ID
     * @param int $range_days Date range
     * @return array Score data with breakdown
     */
    public function calculate_visibility_score($product_id, $range_days = 30) {
        // Check cache first
        $plan_data = $this->get_current_plan_data();
        $plan_name = $plan_data['plan_name'];
        $cache_key = "llmagnet_product_score_{$product_id}_{$range_days}_{$plan_name}";
        $cached_score = get_transient($cache_key);

        if ($cached_score !== false) {
            return $cached_score;
        }

        // Validate product exists
        if (get_post_type($product_id) !== 'product') {
            return new \WP_Error('product_not_found', 'Product not found');
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return new \WP_Error('product_not_found', 'Product not found');
        }

        // === BOT VISIBILITY (70%) ===
        $bot_visibility = $this->calculate_bot_visibility($product_id, $range_days, $plan_data);

        // === CONTENT QUALITY (30%) ===
        $content_quality = $this->calculate_content_quality($product_id, $product);

        // === FINAL SCORE ===
        $final_score = round(
            ($bot_visibility['score'] * 0.70) +
            ($content_quality['score'] * 0.30)
        );

        // Generate recommendations
        $recommendations = $this->generate_recommendations($product_id, $product, $bot_visibility, $content_quality);

        $score_data = [
            'score' => $final_score,
            'bot_visibility' => $bot_visibility,
            'content_quality' => $content_quality,
            'recommendations' => $recommendations,
        ];

        // Cache for 1 hour
        set_transient($cache_key, $score_data, HOUR_IN_SECONDS);

        // Persist the canonical 30-day score to post meta (Score_Store
        // listens — adoption plan Phase 0.1). Only the default range is
        // stored; other ranges remain transient-only variants.
        if (30 === (int) $range_days) {
            do_action('llmagnet_post_score_calculated', (int) $product_id, (int) $final_score, $score_data);
        }

        return $score_data;
    }

    /**
     * Calculate bot visibility score components
     *
     * @param int $product_id Product ID
     * @param int $range_days Date range
     * @param array $plan_data Plan data
     * @return array Bot visibility score and components
     */
    private function calculate_bot_visibility($product_id, $range_days, $plan_data) {
        global $wpdb;

        // Get product URL path
        $product_path = str_replace(home_url(), '', get_permalink($product_id));
        $product_path = strtok($product_path, '?');

        // Get bot visits for this product in range
        $start_date = date('Y-m-d H:i:s', strtotime("-{$range_days} days"));

        $query = $wpdb->prepare(
            "SELECT bot_name, visit_time FROM {$this->visits_table}
            WHERE page_path = %s AND visit_time >= %s
            ORDER BY visit_time DESC",
            $product_path,
            $start_date
        );

        $bot_visits = $wpdb->get_results($query, ARRAY_A);

        // Filter by allowed bots for Pro plan
        if ($plan_data['plan_name'] === 'pro' && !$plan_data['is_trial']) {
            $allowed_bots = ['ChatGPT', 'Claude', 'Perplexity'];
            $bot_visits = array_filter($bot_visits, function($visit) use ($allowed_bots) {
                return in_array($visit['bot_name'], $allowed_bots);
            });
        }

        $total_visits = count($bot_visits);

        // A) Frequency Score
        $frequency_score = $this->calculate_frequency_score($bot_visits, $range_days);

        // B) Visit Type Score
        $visit_type_score = $this->calculate_visit_type_score($bot_visits, $total_visits);

        // C) Visit Count Score
        $visit_count_score = $this->calculate_visit_count_score($total_visits, $range_days);

        // D) Page Status Score
        $page_status_score = (get_post_status($product_id) === 'publish') ? 100 : 0;

        // E) URL Type Multiplier (products are commercial)
        $url_type_multiplier = 0.8;

        // Calculate bot visibility score
        $bot_visibility_raw = (
            $frequency_score * 0.25 +
            $visit_type_score * 0.25 +
            $visit_count_score * 0.30 +
            $page_status_score * 0.20
        ) * $url_type_multiplier;

        $bot_visibility_score = min(100, $bot_visibility_raw);

        return [
            'score' => round($bot_visibility_score),
            'components' => [
                'frequency' => round($frequency_score),
                'visit_type' => round($visit_type_score),
                'visit_count' => round($visit_count_score),
                'page_status' => $page_status_score,
                'url_type_multiplier' => $url_type_multiplier,
            ],
        ];
    }

    /**
     * Calculate frequency score
     *
     * @param array $bot_visits Bot visits
     * @param int $range_days Date range
     * @return float Frequency score
     */
    private function calculate_frequency_score($bot_visits, $range_days) {
        if (empty($bot_visits)) {
            return 0;
        }

        // Calculate days since last visit
        $last_visit = strtotime($bot_visits[0]['visit_time']);
        $days_since_last = max(0, floor((time() - $last_visit) / DAY_IN_SECONDS));

        $recency_score = max(0, 100 - ($days_since_last * 5));

        // Calculate activity ratio (recent 7 days vs total)
        $recent_cutoff = strtotime('-7 days');
        $recent_visits = 0;

        foreach ($bot_visits as $visit) {
            if (strtotime($visit['visit_time']) >= $recent_cutoff) {
                $recent_visits++;
            }
        }

        $total_visits = count($bot_visits);
        $activity_ratio = $total_visits > 0 ? ($recent_visits / $total_visits) : 0;

        $frequency_score = ($recency_score * 0.6) + ($activity_ratio * 100 * 0.4);

        return $frequency_score;
    }

    /**
     * Calculate visit type score
     *
     * @param array $bot_visits Bot visits
     * @param int $total_visits Total visit count
     * @return float Visit type score
     */
    private function calculate_visit_type_score($bot_visits, $total_visits) {
        if ($total_visits === 0) {
            return 0;
        }

        $bot_multipliers = [
            'ChatGPT' => 1.0,
            'Claude' => 1.0,
            'Perplexity' => 0.9,
            'Gemini' => 0.8,
            'Bing' => 0.7,
            'Grok' => 0.7,
            'Mistral' => 0.6,
            'DeepSeek' => 0.6,
            'Llama' => 0.5,
            'Other LLM' => 0.5,
        ];

        $weighted_sum = 0;
        foreach ($bot_visits as $visit) {
            $bot_name = $visit['bot_name'];
            $multiplier = isset($bot_multipliers[$bot_name]) ? $bot_multipliers[$bot_name] : 0.5;
            $weighted_sum += $multiplier;
        }

        $max_possible = $total_visits * 1.0;
        $visit_type_score = ($weighted_sum / $max_possible) * 100;

        return $visit_type_score;
    }

    /**
     * Calculate visit count score
     *
     * @param int $total_visits Total visits
     * @param int $range_days Date range
     * @return float Visit count score
     */
    private function calculate_visit_count_score($total_visits, $range_days) {
        // Saturation curve: K = range_days * 2
        $K = $range_days * 2;
        $visit_count_score = ($total_visits / ($total_visits + $K)) * 100;

        return $visit_count_score;
    }

    /**
     * Calculate content quality score components
     *
     * @param int $product_id Product ID
     * @param \WC_Product $product Product object
     * @return array Content quality score and components
     */
    private function calculate_content_quality($product_id, $product) {
        $post = get_post($product_id);

        // F1) Description Length Score
        $description_score = $this->calculate_description_score($post);

        // F2) Tags Count Score
        $tags_score = $this->calculate_tags_score($product_id);

        // F3) Category Assignment Score
        $category_score = $this->calculate_category_score($product_id);

        // F4) Image ALT Coverage Score
        $alt_coverage_score = $this->calculate_alt_coverage_score($product);

        // Calculate content quality score
        $content_quality_score = (
            $description_score * 0.35 +
            $tags_score * 0.20 +
            $category_score * 0.15 +
            $alt_coverage_score * 0.30
        );

        return [
            'score' => round($content_quality_score),
            'components' => [
                'description_length' => round($description_score),
                'tags_count' => $tags_score,
                'category_assignment' => $category_score,
                'alt_coverage' => round($alt_coverage_score),
            ],
        ];
    }

    /**
     * Calculate description length score
     *
     * @param \WP_Post $post Post object
     * @return float Description score
     */
    private function calculate_description_score($post) {
        // Short description
        $short_desc = strip_tags($post->post_excerpt);
        $short_length = strlen($short_desc);

        if ($short_length === 0) {
            $short_score = 0;
        } elseif ($short_length < 150) {
            $short_score = ($short_length / 150) * 100;
        } else {
            $short_score = 100;
        }

        // Long description
        $long_desc = strip_tags($post->post_content);
        $long_length = strlen($long_desc);

        if ($long_length === 0) {
            $long_score = 0;
        } elseif ($long_length < 500) {
            $long_score = ($long_length / 500) * 100;
        } else {
            $long_score = 100;
        }

        $description_score = ($short_score * 0.3) + ($long_score * 0.7);

        return $description_score;
    }

    /**
     * Calculate tags count score
     *
     * @param int $product_id Product ID
     * @return int Tags score
     */
    private function calculate_tags_score($product_id) {
        $tags = get_the_terms($product_id, 'product_tag');
        $tag_count = ($tags && !is_wp_error($tags)) ? count($tags) : 0;

        if ($tag_count === 0) {
            return 0;
        } elseif ($tag_count <= 2) {
            return 50;
        } elseif ($tag_count <= 8) {
            return 100;
        } else {
            return 80; // Slight penalty for over-tagging
        }
    }

    /**
     * Calculate category assignment score
     *
     * @param int $product_id Product ID
     * @return int Category score
     */
    private function calculate_category_score($product_id) {
        $categories = get_the_terms($product_id, 'product_cat');
        $category_count = ($categories && !is_wp_error($categories)) ? count($categories) : 0;

        return ($category_count > 0) ? 100 : 0;
    }

    /**
     * Calculate image ALT coverage score
     *
     * @param \WC_Product $product Product object
     * @return float ALT coverage score
     */
    private function calculate_alt_coverage_score($product) {
        $images = $this->get_product_images($product);
        $total_images = count($images);

        if ($total_images === 0) {
            return 100; // No images = no penalty
        }

        $images_with_alt = 0;
        foreach ($images as $image) {
            if (!empty($image['alt'])) {
                $images_with_alt++;
            }
        }

        $alt_coverage_score = ($images_with_alt / $total_images) * 100;

        return $alt_coverage_score;
    }

    /**
     * Generate recommendations based on score components
     *
     * @param int $product_id Product ID
     * @param \WC_Product $product Product object
     * @param array $bot_visibility Bot visibility data
     * @param array $content_quality Content quality data
     * @return array Recommendations
     */
    private function generate_recommendations($product_id, $product, $bot_visibility, $content_quality) {
        $recommendations = [];
        $edit_url        = get_edit_post_link( $product_id, 'raw' );
        $schema_url      = Readiness_Recommendations::admin_page_url( 'llmagnet-ai-seo-schema-jsonld' );
        $content_url     = Readiness_Recommendations::admin_page_url( 'llmagnet-ai-seo-content-settings' );
        $agent_ready_url = Readiness_Recommendations::admin_page_url( 'llmagnet-ai-seo-agent-ready' );
        $permalink       = get_permalink( $product_id );
        $visit_stats     = $this->get_product_bot_visit_stats( $product_id, 30 );
        $post            = get_post( $product_id );
        $long_length     = strlen( strip_tags( $post->post_content ) );

        if ( ! Readiness_Recommendations::is_in_llms_txt( $product_id ) ) {
            $recommendations[] = Readiness_Recommendations::item(
                'llms_txt_excluded',
                __( 'This product is not included in llms.txt exports — AI shopping agents may miss it.', 'llmagnet-llm-txt-generator' ),
                __( 'Review content settings', 'llmagnet-llm-txt-generator' ),
                $content_url
            );
        }

        $schema_types = $permalink ? Readiness_Recommendations::schema_types_for_url( $permalink ) : [];
        if ( empty( $schema_types ) || ! in_array( 'Product', $schema_types, true ) ) {
            $recommendations[] = Readiness_Recommendations::item(
                'schema_product_missing',
                __( 'No Product schema detected — add structured data so AI assistants can quote price, SKU, and availability.', 'llmagnet-llm-txt-generator' ),
                __( 'Add Product schema', 'llmagnet-llm-txt-generator' ),
                $schema_url
            );
        }

        if ( $visit_stats['total'] > 0 && $long_length < 500 ) {
            $recommendations[] = Readiness_Recommendations::item(
                'thin_product_crawled',
                sprintf(
                    /* translators: 1: bot count */
                    __( 'Crawled by %d AI bot(s) but the description is thin. Expand product details for richer AI answers.', 'llmagnet-llm-txt-generator' ),
                    $visit_stats['unique_bots']
                ),
                __( 'Edit product', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // ALT text recommendations
        $images = $this->get_product_images($product);
        $total_images = count($images);
        $images_with_alt = 0;
        foreach ($images as $image) {
            if (!empty($image['alt'])) {
                $images_with_alt++;
            }
        }

        if ($total_images > 0 && $images_with_alt < $total_images) {
            $missing_alts = $total_images - $images_with_alt;
            $recommendations[] = Readiness_Recommendations::item(
                'missing_alt_text',
                sprintf(
                    /* translators: %d: number of images */
                    _n(
                        'Add ALT text to %d product image.',
                        'Add ALT text to %d product images.',
                        $missing_alts,
                        'llmagnet-llm-txt-generator'
                    ),
                    $missing_alts
                ),
                __( 'Edit product', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        $short_length = strlen(strip_tags($post->post_excerpt));

        if ($short_length < 150) {
            $recommendations[] = Readiness_Recommendations::item(
                'short_description',
                __( 'Add a short description (150–300 characters) to improve product visibility in AI answers.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit product', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        if ($long_length < 500) {
            $recommendations[] = Readiness_Recommendations::item(
                'long_description',
                __( 'Expand the long description to at least 500 characters with detailed product information.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit product', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // Tags recommendations
        $tags = get_the_terms($product_id, 'product_tag');
        $tag_count = ($tags && !is_wp_error($tags)) ? count($tags) : 0;

        if ($tag_count < 3) {
            $recommendations[] = Readiness_Recommendations::item(
                'tags_low',
                __( 'Add more product tags (recommended: 3–8) to help AI categorization.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit product', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // Category recommendations
        $categories = get_the_terms($product_id, 'product_cat');
        $category_count = ($categories && !is_wp_error($categories)) ? count($categories) : 0;

        if ($category_count === 0) {
            $recommendations[] = Readiness_Recommendations::item(
                'category_missing',
                __( 'Assign at least one category to organize your product catalog for AI discovery.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit product', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        if ($bot_visibility['components']['visit_count'] < 30 && empty( $visit_stats['total'] ) ) {
            $recommendations[] = Readiness_Recommendations::item(
                'low_bot_traffic',
                __( 'No recent AI bot traffic on this product. Check llms.txt inclusion and robots.txt discovery.', 'llmagnet-llm-txt-generator' ),
                __( 'Open Agent Ready', 'llmagnet-llm-txt-generator' ),
                $agent_ready_url
            );
        }

        return $recommendations;
    }

    /**
     * Bot visit counts for a product path (last N days).
     *
     * @param int $product_id Product ID.
     * @param int $range_days Range in days.
     * @return array{total: int, unique_bots: int}
     */
    private function get_product_bot_visit_stats( $product_id, $range_days = 30 ) {
        global $wpdb;

        $post_path = str_replace( home_url(), '', get_permalink( $product_id ) );
        $post_path = strtok( $post_path, '?' );
        $start_date = date( 'Y-m-d H:i:s', strtotime( "-{$range_days} days" ) );

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT bot_name FROM {$this->visits_table}
                WHERE page_path = %s AND visit_time >= %s",
                $post_path,
                $start_date
            )
        );

        if ( empty( $rows ) ) {
            return [ 'total' => 0, 'unique_bots' => 0 ];
        }

        return [
            'total'       => count( $rows ),
            'unique_bots' => count( array_unique( $rows ) ),
        ];
    }

    /**
     * Get product thumbnail URL
     *
     * @param \WC_Product $product Product object
     * @return string Thumbnail URL
     */
    private function get_product_thumbnail($product) {
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image = wp_get_attachment_image_src($image_id, 'thumbnail');
            return $image ? $image[0] : '';
        }
        return wc_placeholder_img_src('thumbnail');
    }

    /**
     * Get product images with ALT text
     *
     * @param \WC_Product $product Product object
     * @return array Images list
     */
    private function get_product_images($product) {
        $images = [];

        // Featured image
        $featured_image_id = $product->get_image_id();
        if ($featured_image_id) {
            $images[] = [
                'attachment_id' => $featured_image_id,
                'url' => wp_get_attachment_url($featured_image_id),
                'alt' => get_post_meta($featured_image_id, '_wp_attachment_image_alt', true),
                'is_featured' => true,
                'position' => 0,
            ];
        }

        // Gallery images
        $gallery_image_ids = $product->get_gallery_image_ids();
        $position = 1;
        foreach ($gallery_image_ids as $image_id) {
            $images[] = [
                'attachment_id' => $image_id,
                'url' => wp_get_attachment_url($image_id),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                'is_featured' => false,
                'position' => $position++,
            ];
        }

        return $images;
    }

    /**
     * Get product categories with hierarchy
     *
     * @param int $product_id Product ID
     * @return array Categories list
     */
    private function get_product_categories($product_id) {
        $categories = get_the_terms($product_id, 'product_cat');
        $category_data = [];

        if ($categories && !is_wp_error($categories)) {
            foreach ($categories as $cat) {
                $category_data[] = [
                    'term_id' => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                    'path' => $this->get_category_path($cat->term_id),
                ];
            }
        }

        return $category_data;
    }

    /**
     * Get full category hierarchy path
     *
     * @param int $term_id Category term ID
     * @return string Path (e.g., "Clothing > Footwear > Running Shoes")
     */
    private function get_category_path($term_id) {
        $path_parts = [];
        $current_term_id = $term_id;
        $max_depth = 10; // Prevent infinite loops
        $depth = 0;

        while ($current_term_id > 0 && $depth < $max_depth) {
            $term = get_term($current_term_id, 'product_cat');

            if (is_wp_error($term) || !$term) {
                break;
            }

            // Add term name to beginning of path
            array_unshift($path_parts, $term->name);

            // Move to parent
            $current_term_id = $term->parent;
            $depth++;
        }

        return implode(' > ', $path_parts);
    }

    /**
     * Get product tags
     *
     * @param int $product_id Product ID
     * @return array Tags list
     */
    private function get_product_tags($product_id) {
        $tags = get_the_terms($product_id, 'product_tag');
        $tag_data = [];

        if ($tags && !is_wp_error($tags)) {
            foreach ($tags as $tag) {
                $tag_data[] = [
                    'term_id' => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ];
            }
        }

        return $tag_data;
    }

    /**
     * Get current plan data
     *
     * @return array Plan data
     */
    private function get_current_plan_data() {
        // Get plan from options (set by licensing system)
        $plan_name = get_option('llmagnet_plan', 'free');
        $is_trial = get_option('llmagnet_is_trial', false);

        return [
            'plan_name' => $plan_name,
            'is_trial' => $is_trial,
            'is_premium' => in_array($plan_name, ['pro', 'plus', 'enterprise']),
        ];
    }

    /**
     * Clear product score cache
     *
     * @param int $product_id Product ID
     * @return void
     */
    private function clear_product_score_cache($product_id) {
        $plans = ['free', 'pro', 'plus', 'enterprise'];
        $ranges = [7, 30, 90];

        foreach ($plans as $plan) {
            foreach ($ranges as $range) {
                $cache_key = "llmagnet_product_score_{$product_id}_{$range}_{$plan}";
                delete_transient($cache_key);
            }
        }
    }
}
