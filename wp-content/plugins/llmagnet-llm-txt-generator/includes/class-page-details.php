<?php
/**
 * Page Details class
 *
 * Handles fetching and updating post/page details for the drawer,
 * including per-page visibility score calculation with SEO-focused
 * content quality scoring.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Page Details class
 */
class Page_Details {
    /**
     * Database table name
     *
     * @var string
     */
    private $visits_table;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->visits_table = $wpdb->prefix . 'llm_bot_visits';
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register_rest_routes() {
        // Get page details endpoint
        register_rest_route('llm-analytics/v1', '/page-details', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_page_details_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'post_id' => [
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
                ],
            ],
        ]);

        // Update page details endpoint
        register_rest_route('llm-analytics/v1', '/page-details/update', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_page_details_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'post_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'excerpt' => [
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

        // Get all available post tags (for autocomplete)
        register_rest_route('llm-analytics/v1', '/post-tags', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_post_tags_endpoint'],
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

        // Get page visibility score endpoint
        register_rest_route('llm-analytics/v1', '/page-visibility-score', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_page_score_endpoint'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'args' => [
                'post_id' => [
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
     * REST endpoint: Get page details
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_page_details_endpoint($request) {
        try {
            $post_id = $request->get_param('post_id');
            $range_days = $request->get_param('range_days') ?: 30;

            if (!$post_id) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Post ID is required',
                    'code' => 'missing_post_id',
                ], 400);
            }

            $result = $this->get_page_details($post_id, $range_days);

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ], 404);
            }

            return new \WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            \llmagnet_aiseo_debug_log('Page Details Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Failed to load page details',
                'code' => 'internal_error',
            ], 500);
        }
    }

    /**
     * REST endpoint: Update page details
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function update_page_details_endpoint($request) {
        try {
            $post_id = $request->get_param('post_id');

            if (!$post_id || !is_numeric($post_id)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Invalid post ID',
                    'code' => 'invalid_post_id',
                ], 400);
            }

            $data = [
                'excerpt' => $request->get_param('excerpt'),
                'image_alts' => $request->get_param('image_alts'),
                'tags' => $request->get_param('tags'),
            ];

            $result = $this->update_page_details($post_id, $data);

            if (is_wp_error($result)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->get_error_message(),
                    'code' => $result->get_error_code(),
                ], 400);
            }

            return new \WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            \llmagnet_aiseo_debug_log('Page Update Error: ' . $e->getMessage());
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Failed to update page',
                'code' => 'update_error',
            ], 500);
        }
    }

    /**
     * REST endpoint: Get page visibility score
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_page_score_endpoint($request) {
        $post_id = $request->get_param('post_id');
        $range_days = $request->get_param('range_days') ?: 30;

        $score = $this->calculate_visibility_score($post_id, $range_days);

        if (is_wp_error($score)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $score->get_error_message(),
            ], 404);
        }

        return new \WP_REST_Response($score, 200);
    }

    /**
     * REST endpoint: Get all available post tags for autocomplete
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response
     */
    public function get_all_post_tags_endpoint($request) {
        $search = $request->get_param('search') ?: '';

        $args = [
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ];

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
     * Get page details for drawer
     *
     * @param int $post_id Post ID
     * @param int $range_days Date range for score calculation
     * @return array|\WP_Error Page details or error
     */
    public function get_page_details($post_id, $range_days = 30) {
        $post_id = absint($post_id);

        if (!$post_id || $post_id <= 0) {
            return new \WP_Error('invalid_post_id', 'Invalid post ID', ['status' => 400]);
        }

        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('post_not_found', 'Post not found', ['status' => 404]);
        }

        // Get content images
        $images = $this->get_content_images($post_id);

        // Get post data
        $post_data = [
            'id' => $post_id,
            'title' => get_the_title($post_id),
            'permalink' => get_permalink($post_id),
            'path' => str_replace(home_url(), '', get_permalink($post_id)),
            'post_type' => $post->post_type,
            'status' => $post->post_status,
            'excerpt' => $post->post_excerpt,
            'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'images' => $images,
        ];

        // Get categories
        $post_data['categories'] = $this->get_post_categories($post_id);

        // Get tags
        $post_data['tags'] = $this->get_post_tags($post_id);

        // Get heading structure
        $post_data['heading_structure'] = $this->parse_heading_structure($post->post_content);

        // Get readability metrics
        $post_data['readability'] = $this->calculate_readability($post->post_content);

        // Calculate visibility score
        $visibility_score = $this->calculate_visibility_score($post_id, $range_days);

        return [
            'success' => true,
            'post' => $post_data,
            'visibility_score' => $visibility_score,
        ];
    }

    /**
     * Update page details
     *
     * @param int $post_id Post ID
     * @param array $data Update data
     * @return array|\WP_Error Success response or error
     */
    public function update_page_details($post_id, $data) {
        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('post_not_found', 'Post not found');
        }

        $updated = [
            'excerpt' => false,
            'image_alts' => 0,
            'tags' => 0,
        ];

        // Update excerpt
        if (isset($data['excerpt']) && $data['excerpt'] !== null) {
            $sanitized_excerpt = wp_kses_post($data['excerpt']);
            $result = wp_update_post([
                'ID' => $post_id,
                'post_excerpt' => $sanitized_excerpt,
            ], true);

            if (!is_wp_error($result) && $result !== 0) {
                $updated['excerpt'] = true;
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

                    if ($attachment_id <= 0) {
                        continue;
                    }

                    $alt = sanitize_text_field($image_data['alt']);

                    if (wp_attachment_is_image($attachment_id)) {
                        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
                        $updated['image_alts']++;
                    }
                }
            }
        }

        // Update tags
        if (isset($data['tags']) && is_array($data['tags'])) {
            $tag_names = array_filter(
                array_map('sanitize_text_field', $data['tags']),
                function($tag) {
                    return !empty($tag);
                }
            );

            $result = wp_set_object_terms($post_id, $tag_names, 'post_tag', false);

            if (!is_wp_error($result) && is_array($result)) {
                $updated['tags'] = count($tag_names);
            }
        }

        // Clear page score cache
        $this->clear_page_score_cache($post_id);

        return [
            'success' => true,
            'message' => 'Page updated successfully',
            'updated' => $updated,
            'cache_cleared' => true,
        ];
    }

    /**
     * Calculate page visibility score
     *
     * @param int $post_id Post ID
     * @param int $range_days Date range
     * @return array Score data with breakdown
     */
    public function calculate_visibility_score($post_id, $range_days = 30) {
        // Check cache first
        $plan_data = $this->get_current_plan_data();
        $plan_name = $plan_data['plan_name'];
        $cache_key = "llmagnet_page_score_{$post_id}_{$range_days}_{$plan_name}";
        $cached_score = get_transient($cache_key);

        if ($cached_score !== false) {
            return $cached_score;
        }

        $post = get_post($post_id);
        if (!$post) {
            return new \WP_Error('post_not_found', 'Post not found');
        }

        // === BOT VISIBILITY (70%) ===
        $bot_visibility = $this->calculate_bot_visibility($post_id, $range_days, $plan_data);

        // === CONTENT QUALITY (30%) ===
        $content_quality = $this->calculate_content_quality($post_id, $post);

        // === FINAL SCORE ===
        $final_score = round(
            ($bot_visibility['score'] * 0.70) +
            ($content_quality['score'] * 0.30)
        );

        // Generate recommendations
        $recommendations = $this->generate_recommendations($post_id, $post, $bot_visibility, $content_quality);

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
            do_action('llmagnet_post_score_calculated', (int) $post_id, (int) $final_score, $score_data);
        }

        return $score_data;
    }

    /**
     * Calculate bot visibility score components
     *
     * @param int $post_id Post ID
     * @param int $range_days Date range
     * @param array $plan_data Plan data
     * @return array Bot visibility score and components
     */
    private function calculate_bot_visibility($post_id, $range_days, $plan_data) {
        global $wpdb;

        $post_path = str_replace(home_url(), '', get_permalink($post_id));
        $post_path = strtok($post_path, '?');

        $start_date = date('Y-m-d H:i:s', strtotime("-{$range_days} days"));

        $query = $wpdb->prepare(
            "SELECT bot_name, visit_time FROM {$this->visits_table}
            WHERE page_path = %s AND visit_time >= %s
            ORDER BY visit_time DESC",
            $post_path,
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
        $page_status_score = (get_post_status($post_id) === 'publish') ? 100 : 0;

        // URL type multiplier = 1.0 for content pages (vs 0.8 for products)
        $url_type_multiplier = 1.0;

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

        $last_visit = strtotime($bot_visits[0]['visit_time']);
        $days_since_last = max(0, floor((time() - $last_visit) / DAY_IN_SECONDS));
        $recency_score = max(0, 100 - ($days_since_last * 5));

        $recent_cutoff = strtotime('-7 days');
        $recent_visits = 0;
        foreach ($bot_visits as $visit) {
            if (strtotime($visit['visit_time']) >= $recent_cutoff) {
                $recent_visits++;
            }
        }

        $total_visits = count($bot_visits);
        $activity_ratio = $total_visits > 0 ? ($recent_visits / $total_visits) : 0;

        return ($recency_score * 0.6) + ($activity_ratio * 100 * 0.4);
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
        return ($weighted_sum / $max_possible) * 100;
    }

    /**
     * Calculate visit count score
     *
     * @param int $total_visits Total visits
     * @param int $range_days Date range
     * @return float Visit count score
     */
    private function calculate_visit_count_score($total_visits, $range_days) {
        $K = $range_days * 2;
        return ($total_visits / ($total_visits + $K)) * 100;
    }

    /**
     * Calculate content quality score components (SEO-focused)
     *
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @return array Content quality score and components
     */
    private function calculate_content_quality($post_id, $post) {
        // 1) Content Length Score (20%)
        $content_length_score = $this->calculate_content_length_score($post);

        // 2) Heading Structure Score (20%)
        $heading_score = $this->calculate_heading_score($post->post_content);

        // 3) Image ALT Coverage Score (20%)
        $alt_coverage_score = $this->calculate_alt_coverage_score($post_id);

        // 4) Excerpt Quality Score (15%)
        $excerpt_score = $this->calculate_excerpt_score($post);

        // 5) Tags & Categories Score (15%)
        $tags_categories_score = $this->calculate_tags_categories_score($post_id);

        // 6) Readability Score (10%)
        $readability_score = $this->calculate_readability_score($post->post_content);

        $content_quality_score = (
            $content_length_score * 0.20 +
            $heading_score * 0.20 +
            $alt_coverage_score * 0.20 +
            $excerpt_score * 0.15 +
            $tags_categories_score * 0.15 +
            $readability_score * 0.10
        );

        return [
            'score' => round($content_quality_score),
            'components' => [
                'content_length' => round($content_length_score),
                'heading_structure' => round($heading_score),
                'alt_coverage' => round($alt_coverage_score),
                'excerpt_quality' => round($excerpt_score),
                'tags_categories' => round($tags_categories_score),
                'readability' => round($readability_score),
            ],
        ];
    }

    /**
     * Calculate content length score
     *
     * @param \WP_Post $post Post object
     * @return float Score 0-100
     */
    private function calculate_content_length_score($post) {
        $word_count = str_word_count(wp_strip_all_tags($post->post_content));

        if ($word_count < 300) {
            return ($word_count / 300) * 50; // 0-299 words = proportional up to 50
        } elseif ($word_count < 800) {
            return 50 + (($word_count - 300) / 500) * 25; // 300-799 = 50-75
        } elseif ($word_count < 1500) {
            return 75 + (($word_count - 800) / 700) * 15; // 800-1499 = 75-90
        }
        return 100; // 1500+ = 100
    }

    /**
     * Calculate heading structure score
     *
     * @param string $content Post content
     * @return float Score 0-100
     */
    private function calculate_heading_score($content) {
        $headings = $this->parse_heading_structure($content);

        if (empty($headings)) {
            return 0;
        }

        $has_h2 = false;
        $has_h3 = false;
        $has_hierarchy = true;
        $prev_level = 1; // Assume H1 is the post title

        foreach ($headings as $heading) {
            $level = intval(str_replace('h', '', $heading['level']));
            if ($level === 2) $has_h2 = true;
            if ($level === 3) $has_h3 = true;

            // Check for skipped levels (e.g., H2 -> H4)
            if ($level > $prev_level + 1) {
                $has_hierarchy = false;
            }
            $prev_level = $level;
        }

        if ($has_h2 && $has_h3 && $has_hierarchy) {
            return 100;
        } elseif ($has_h2 && $has_h3) {
            return 80;
        } elseif ($has_h2) {
            return 50;
        }
        return 30; // Has headings but no H2
    }

    /**
     * Calculate image ALT coverage score
     *
     * @param int $post_id Post ID
     * @return float Score 0-100
     */
    private function calculate_alt_coverage_score($post_id) {
        $images = $this->get_content_images($post_id);
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

        return ($images_with_alt / $total_images) * 100;
    }

    /**
     * Calculate excerpt quality score
     *
     * @param \WP_Post $post Post object
     * @return float Score 0-100
     */
    private function calculate_excerpt_score($post) {
        $excerpt = trim($post->post_excerpt);

        if (empty($excerpt)) {
            return 0;
        }

        $length = strlen($excerpt);

        if ($length < 120) {
            return 50;
        } elseif ($length <= 160) {
            return 100;
        }
        return 80; // Over 160 chars
    }

    /**
     * Calculate tags and categories combined score
     *
     * @param int $post_id Post ID
     * @return float Score 0-100
     */
    private function calculate_tags_categories_score($post_id) {
        // Tags score
        $tags = get_the_terms($post_id, 'post_tag');
        $tag_count = ($tags && !is_wp_error($tags)) ? count($tags) : 0;

        if ($tag_count === 0) {
            $tags_score = 0;
        } elseif ($tag_count <= 2) {
            $tags_score = 50;
        } elseif ($tag_count <= 8) {
            $tags_score = 100;
        } else {
            $tags_score = 80;
        }

        // Category score
        $categories = get_the_terms($post_id, 'category');
        $category_count = ($categories && !is_wp_error($categories)) ? count($categories) : 0;
        $category_score = ($category_count > 0) ? 100 : 0;

        return ($tags_score * 0.5) + ($category_score * 0.5);
    }

    /**
     * Calculate readability score
     *
     * @param string $content Post content
     * @return float Score 0-100
     */
    private function calculate_readability_score($content) {
        $text = wp_strip_all_tags($content);

        if (empty(trim($text))) {
            return 0;
        }

        // Split into paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $paragraphs = array_filter($paragraphs, function($p) {
            return !empty(trim($p));
        });
        $paragraph_count = count($paragraphs);

        if ($paragraph_count === 0) {
            return 0;
        }

        // Calculate average paragraph word count
        $total_words = 0;
        foreach ($paragraphs as $p) {
            $total_words += str_word_count($p);
        }
        $avg_paragraph_words = $total_words / $paragraph_count;

        // Score based on paragraph length variety
        $score = 100;

        // Penalize very long paragraphs (avg > 150 words)
        if ($avg_paragraph_words > 150) {
            $score -= min(40, ($avg_paragraph_words - 150) * 0.5);
        }

        // Penalize too few paragraphs (wall of text)
        if ($paragraph_count <= 2 && $total_words > 300) {
            $score -= 30;
        }

        // Bonus for good paragraph count (at least 4 paragraphs for 500+ words)
        if ($paragraph_count >= 4 && $total_words >= 500) {
            $score = min(100, $score + 10);
        }

        return max(0, $score);
    }

    /**
     * Generate recommendations based on score components
     *
     * @param int $post_id Post ID
     * @param \WP_Post $post Post object
     * @param array $bot_visibility Bot visibility data
     * @param array $content_quality Content quality data
     * @return array Recommendations
     */
    private function generate_recommendations($post_id, $post, $bot_visibility, $content_quality) {
        $recommendations = [];
        $edit_url        = get_edit_post_link( $post_id, 'raw' );
        $schema_url      = Readiness_Recommendations::admin_page_url( 'llmagnet-ai-seo-schema-jsonld' );
        $content_url     = Readiness_Recommendations::admin_page_url( 'llmagnet-ai-seo-content-settings' );
        $agent_ready_url = Readiness_Recommendations::admin_page_url( 'llmagnet-ai-seo-agent-ready' );
        $permalink       = get_permalink( $post_id );
        $word_count      = str_word_count( wp_strip_all_tags( $post->post_content ) );
        $visit_stats     = $this->get_page_bot_visit_stats( $post_id, 30 );

        if ( ! Readiness_Recommendations::is_in_llms_txt( $post_id ) ) {
            $recommendations[] = Readiness_Recommendations::item(
                'llms_txt_excluded',
                __( 'This page is not included in llms.txt exports — AI crawlers may miss it.', 'llmagnet-llm-txt-generator' ),
                __( 'Review content settings', 'llmagnet-llm-txt-generator' ),
                $content_url
            );
        }

        $schema_types = $permalink ? Readiness_Recommendations::schema_types_for_url( $permalink ) : [];
        if ( empty( $schema_types ) ) {
            $recommendations[] = Readiness_Recommendations::item(
                'schema_missing',
                __( 'No structured data (schema.org) detected for this page.', 'llmagnet-llm-txt-generator' ),
                __( 'Add schema in JSON-LD', 'llmagnet-llm-txt-generator' ),
                $schema_url
            );
        }

        if ( $visit_stats['total'] > 0 && $word_count < 300 ) {
            $recommendations[] = Readiness_Recommendations::item(
                'thin_content_crawled',
                sprintf(
                    /* translators: 1: bot count, 2: word count */
                    __( 'Crawled by %1$d AI bot(s) but content is thin (%2$d words). Expand the page body for better AI answers.', 'llmagnet-llm-txt-generator' ),
                    $visit_stats['unique_bots'],
                    $word_count
                ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // ALT text recommendations
        $images = $this->get_content_images($post_id);
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
                        'Add ALT text to %d image to improve accessibility and AI context.',
                        'Add ALT text to %d images to improve accessibility and AI context.',
                        $missing_alts,
                        'llmagnet-llm-txt-generator'
                    ),
                    $missing_alts
                ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // Heading structure
        $headings = $this->parse_heading_structure($post->post_content);
        if (empty($headings)) {
            $recommendations[] = Readiness_Recommendations::item(
                'headings_missing',
                __( 'Add H2 and H3 headings to structure your content for better readability.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        } elseif ($content_quality['components']['heading_structure'] < 80) {
            $recommendations[] = Readiness_Recommendations::item(
                'headings_hierarchy',
                __( 'Improve heading hierarchy — use H2 for main sections and H3 for subsections.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // Excerpt
        $excerpt = trim($post->post_excerpt);
        if (empty($excerpt)) {
            $recommendations[] = Readiness_Recommendations::item(
                'excerpt_missing',
                __( 'Add an excerpt (120–160 characters) to control how this page appears in AI summaries.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        } elseif (strlen($excerpt) < 120) {
            $recommendations[] = Readiness_Recommendations::item(
                'excerpt_short',
                __( 'Extend your excerpt to 120–160 characters for optimal AI snippet appearance.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // Content length
        if ($word_count < 300) {
            $recommendations[] = Readiness_Recommendations::item(
                'content_short',
                sprintf(
                    /* translators: %d: word count */
                    __( 'Content is short (%d words). Aim for 800+ words for better LLM visibility.', 'llmagnet-llm-txt-generator' ),
                    $word_count
                ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        } elseif ($word_count < 800) {
            $recommendations[] = Readiness_Recommendations::item(
                'content_expand',
                sprintf(
                    /* translators: %d: word count */
                    __( 'Expand content from %d to 800+ words for improved LLM visibility.', 'llmagnet-llm-txt-generator' ),
                    $word_count
                ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        // Tags
        $tags = get_the_terms($post_id, 'post_tag');
        $tag_count = ($tags && !is_wp_error($tags)) ? count($tags) : 0;
        if ($tag_count < 3) {
            $recommendations[] = Readiness_Recommendations::item(
                'tags_low',
                __( 'Add more tags (recommended: 3–8) to improve categorization for AI systems.', 'llmagnet-llm-txt-generator' ),
                __( 'Edit page', 'llmagnet-llm-txt-generator' ),
                $edit_url ?: $content_url
            );
        }

        if ( empty( $visit_stats['total'] ) && $word_count >= 300 ) {
            $recommendations[] = Readiness_Recommendations::item(
                'no_bot_traffic',
                __( 'No AI bot visits detected in the last 30 days. Ensure llms.txt and robots.txt are configured.', 'llmagnet-llm-txt-generator' ),
                __( 'Open Agent Ready', 'llmagnet-llm-txt-generator' ),
                $agent_ready_url
            );
        }

        return $recommendations;
    }

    /**
     * Bot visit counts for a page path (last N days).
     *
     * @param int $post_id    Post ID.
     * @param int $range_days Range in days.
     * @return array{total: int, unique_bots: int}
     */
    private function get_page_bot_visit_stats( $post_id, $range_days = 30 ) {
        global $wpdb;

        $post_path = str_replace( home_url(), '', get_permalink( $post_id ) );
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
     * Get content images (featured image + images from post content)
     *
     * @param int $post_id Post ID
     * @return array Images list
     */
    private function get_content_images($post_id) {
        $images = [];
        $post = get_post($post_id);

        // Featured image
        $featured_id = get_post_thumbnail_id($post_id);
        if ($featured_id) {
            $images[] = [
                'attachment_id' => (int) $featured_id,
                'url' => wp_get_attachment_url($featured_id),
                'alt' => get_post_meta($featured_id, '_wp_attachment_image_alt', true),
                'is_featured' => true,
            ];
        }

        // Content images via regex
        if ($post && !empty($post->post_content)) {
            preg_match_all('/<img[^>]+>/i', $post->post_content, $matches);

            foreach ($matches[0] as $img_tag) {
                // Extract src
                if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
                    continue;
                }
                $src = $src_match[1];

                // Extract existing alt
                $alt = '';
                if (preg_match('/alt=["\']([^"\']*?)["\']/i', $img_tag, $alt_match)) {
                    $alt = $alt_match[1];
                }

                // Try to resolve to attachment ID
                $attachment_id = attachment_url_to_postid($src);

                // If not found, try without size suffix
                if (!$attachment_id) {
                    $cleaned_src = preg_replace('/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $src);
                    $attachment_id = attachment_url_to_postid($cleaned_src);
                }

                // Skip if this is the featured image (already added)
                if ($attachment_id && $attachment_id == $featured_id) {
                    continue;
                }

                // Get alt from attachment meta if we have an ID and alt is empty
                if ($attachment_id && empty($alt)) {
                    $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                }

                $images[] = [
                    'attachment_id' => $attachment_id ? (int) $attachment_id : 0,
                    'url' => $src,
                    'alt' => $alt ?: '',
                    'is_featured' => false,
                ];
            }
        }

        return $images;
    }

    /**
     * Get post categories with hierarchy
     *
     * @param int $post_id Post ID
     * @return array Categories list
     */
    private function get_post_categories($post_id) {
        $categories = get_the_terms($post_id, 'category');
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
     * @return string Path (e.g., "News > Technology > AI")
     */
    private function get_category_path($term_id) {
        $path_parts = [];
        $current_term_id = $term_id;
        $max_depth = 10;
        $depth = 0;

        while ($current_term_id > 0 && $depth < $max_depth) {
            $term = get_term($current_term_id, 'category');

            if (is_wp_error($term) || !$term) {
                break;
            }

            array_unshift($path_parts, $term->name);
            $current_term_id = $term->parent;
            $depth++;
        }

        return implode(' > ', $path_parts);
    }

    /**
     * Get post tags
     *
     * @param int $post_id Post ID
     * @return array Tags list
     */
    private function get_post_tags($post_id) {
        $tags = get_the_terms($post_id, 'post_tag');
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
     * Parse heading structure from post content
     *
     * @param string $content Post content (HTML)
     * @return array Array of {level, text}
     */
    private function parse_heading_structure($content) {
        $headings = [];

        if (empty($content)) {
            return $headings;
        }

        // Match H2-H6 tags
        preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $headings[] = [
                'level' => 'h' . $match[1],
                'text' => wp_strip_all_tags($match[2]),
            ];
        }

        return $headings;
    }

    /**
     * Calculate readability metrics from post content
     *
     * @param string $content Post content (HTML)
     * @return array Readability metrics
     */
    private function calculate_readability($content) {
        $text = wp_strip_all_tags($content);

        if (empty(trim($text))) {
            return [
                'avg_sentence_length' => 0,
                'avg_paragraph_length' => 0,
                'paragraph_count' => 0,
            ];
        }

        // Paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $paragraphs = array_filter($paragraphs, function($p) {
            return !empty(trim($p));
        });
        $paragraph_count = count($paragraphs);

        // Average paragraph word count
        $total_words = 0;
        foreach ($paragraphs as $p) {
            $total_words += str_word_count($p);
        }
        $avg_paragraph_length = $paragraph_count > 0 ? round($total_words / $paragraph_count) : 0;

        // Average sentence length
        $sentences = preg_split('/[.!?]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $sentences = array_filter($sentences, function($s) {
            return str_word_count(trim($s)) > 0;
        });
        $sentence_count = count($sentences);
        $avg_sentence_length = $sentence_count > 0 ? round($total_words / $sentence_count) : 0;

        return [
            'avg_sentence_length' => $avg_sentence_length,
            'avg_paragraph_length' => $avg_paragraph_length,
            'paragraph_count' => $paragraph_count,
        ];
    }

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
            'is_trial' => $is_trial,
            'is_premium' => in_array($plan_name, ['pro', 'plus', 'enterprise']),
        ];
    }

    /**
     * Clear page score cache
     *
     * @param int $post_id Post ID
     * @return void
     */
    private function clear_page_score_cache($post_id) {
        $plans = ['free', 'pro', 'plus', 'enterprise'];
        $ranges = [7, 30, 90];

        foreach ($plans as $plan) {
            foreach ($ranges as $range) {
                $cache_key = "llmagnet_page_score_{$post_id}_{$range}_{$plan}";
                delete_transient($cache_key);
            }
        }
    }
}
