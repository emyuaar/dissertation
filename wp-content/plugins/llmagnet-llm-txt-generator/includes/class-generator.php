<?php
/**
 * Generator class
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! class_exists( __NAMESPACE__ . '\\Markdown_Converter' ) ) {
    require_once __DIR__ . '/class-markdown-converter.php';
}

/**
 * Generator class for creating and updating llms.txt and Markdown files
 */
class Generator {
    /**
     * Option name for storing settings
     *
     * @var string
     */
    const OPTION_NAME = 'llmagnet_ai_seo_optimizer_settings';

    /**
     * Transient name for tracking last generation time
     *
     * @var string
     */
    const TRANSIENT_NAME = 'llmagnet_ai_seo_optimizer_last_run';

    /**
     * Option name for the batched-generation cursor state
     *
     * @var string
     */
    const CURSOR_OPTION = 'llmagnet_generation_cursor';

    /**
     * Cron hook used to process one generation batch
     *
     * @var string
     */
    const BATCH_EVENT = 'llmagnet_generation_batch_event';

    /**
     * Default settings
     *
     * @var array
     */
    private $default_settings = [
        'post_types' => ['post', 'page'],
        'full_content' => true,
        'days_to_include' => 365,
        'delete_on_uninstall' => false,
        'llm_response_images' => [], // Array of images: [{'id': 123, 'position': 'after'}, ...]
        // Keep old fields for backward compatibility
        'llm_response_image_id' => 0,
        'llm_response_image_position' => 'after',
        // LLMs.txt enhancement settings
        'llms_txt_custom_intro' => '',
        'llms_txt_show_excerpts' => true,
        'llms_txt_excerpt_length' => 150,
        'llms_txt_max_per_section' => 50,
        'llms_txt_show_prices' => true,
        'llms_txt_group_by_type' => true,
        'llms_txt_show_author' => true,
        // LLMs-full.txt settings (Pro+ only)
        'generate_full' => true,
        'llms_full_max_posts' => 200,
    ];

    /**
     * Plugin activation
     *
     * @return void
     */
    public static function activate() {
        // Schedule cron job
        if (!wp_next_scheduled('llmagnet_ai_seo_daily_event')) {
            wp_schedule_event(time(), 'daily', 'llmagnet_ai_seo_daily_event');
        }

        // Create initial files
        $generator = new self();
        $generator->generate_all();

        // Store default settings if not already set (no autoload — can hold image arrays)
        if (!get_option(self::OPTION_NAME)) {
            update_option(self::OPTION_NAME, $generator->default_settings, false);
        }
    }

    /**
     * Plugin deactivation
     *
     * @return void
     */
    public static function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('llmagnet_ai_seo_daily_event');
        wp_clear_scheduled_hook(self::BATCH_EVENT);
    }

    /**
     * Get plugin settings
     *
     * @return array
     */
    public function get_settings() {
        $settings = get_option(self::OPTION_NAME, $this->default_settings);
        
        // Migrate old ChatGPT-specific field names to universal LLM field names
        $settings = $this->migrate_settings($settings);
        
        return $settings;
    }

    /**
     * Migrate old ChatGPT-specific settings to universal LLM settings
     *
     * @param array $settings Current settings
     * @return array Migrated settings
     */
    private function migrate_settings($settings) {
        $migrated = false;
        
        // Migrate chatgpt_image_id to llm_response_image_id
        if (isset($settings['chatgpt_image_id']) && !isset($settings['llm_response_image_id'])) {
            $settings['llm_response_image_id'] = $settings['chatgpt_image_id'];
            unset($settings['chatgpt_image_id']);
            $migrated = true;
        }
        
        // Migrate chatgpt_image_position to llm_response_image_position
        if (isset($settings['chatgpt_image_position']) && !isset($settings['llm_response_image_position'])) {
            $settings['llm_response_image_position'] = $settings['chatgpt_image_position'];
            unset($settings['chatgpt_image_position']);
            $migrated = true;
        }
        
        // Migrate single image to images array format
        if (!isset($settings['llm_response_images']) || empty($settings['llm_response_images'])) {
            $settings['llm_response_images'] = [];
            
            // If there's an existing single image, migrate it to the array format
            if (!empty($settings['llm_response_image_id'])) {
                $settings['llm_response_images'][] = [
                    'id' => $settings['llm_response_image_id'],
                    'position' => $settings['llm_response_image_position'] ?? 'after'
                ];
                $migrated = true;
            }
        }
        
        // Save migrated settings
        if ($migrated) {
            update_option(self::OPTION_NAME, $settings, false);
        }
        
        return $settings;
    }

    /**
     * Update plugin settings
     *
     * @param array $settings New settings
     * @return bool
     */
    public function update_settings($settings) {
        $existing = get_option(self::OPTION_NAME, []);
        if (maybe_serialize($existing) === maybe_serialize($settings)) {
            return true;
        }
        return update_option(self::OPTION_NAME, $settings, false) !== false;
    }

    /**
     * Check if we should regenerate files
     *
     * @param int     $post_id Post ID
     * @param WP_Post $post    Post object
     * @return void
     */
    public function maybe_regenerate($post_id, $post) {
        // Skip if this is an autosave or revision
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Skip if post is not published
        if ('publish' !== $post->post_status) {
            return;
        }

        // Check if post type is included in settings
        $settings = $this->get_settings();
        if (!in_array($post->post_type, $settings['post_types'], true)) {
            return;
        }

        // Debounced: regenerate only the changed post's markdown file and the
        // llms.txt index instead of the entire corpus. llms-full.txt is
        // refreshed by the daily cron run.
        $this->regenerate_post($post);
    }

    /**
     * Regenerate the markdown file for a single post plus the llms.txt index
     *
     * @param \WP_Post $post Post object
     * @return bool
     */
    public function regenerate_post($post) {
        if (!$this->init_filesystem()) {
            return false;
        }

        $this->create_llms_docs_directory();

        $settings = $this->get_settings();
        $docs_dir = $this->get_root_path() . 'llms-docs/';

        if ('1' === get_post_meta($post->ID, '_llmagnet_exclude_from_llms', true)) {
            // Post opted out of the exports (adoption §2.1): remove its
            // markdown file now instead of waiting for the daily prune.
            global $wp_filesystem;
            $file = $docs_dir . sanitize_title($post->post_name) . '.md';
            if ($wp_filesystem->exists($file)) {
                $wp_filesystem->delete($file);
            }
        } else {
            $this->write_post_markdown($post, $settings, $docs_dir);
        }

        $result = $this->write_llms_txt();

        set_transient(self::TRANSIENT_NAME, time(), DAY_IN_SECONDS);

        return $result;
    }

    /**
     * Generate all files
     *
     * @return bool
     */
    public function generate_all() {
        // Starting file generation
        
        // Set transient to track last generation time (for informational purposes only)
        set_transient(self::TRANSIENT_NAME, time(), DAY_IN_SECONDS);

        // Initialize WordPress filesystem
        if (!$this->init_filesystem()) {
            return false;
        }

        // Create llms-docs directory if it doesn't exist
        $this->create_llms_docs_directory();

        // Generate llms.txt file
        if (!$this->write_llms_txt()) {
            return false;
        }

        // Generate Markdown files
        $this->generate_markdown_files();

        // Generate llms-full.txt (Pro+ only)
        $this->generate_llms_full();
        
        return true;
    }

    /**
     * Build and write the llms.txt index file
     *
     * @return bool
     */
    private function write_llms_txt() {
        global $wp_filesystem;

        $llms_txt_content = $this->generate_llms_txt_content();

        // Ensure content is properly encoded as UTF-8
        if (!mb_check_encoding($llms_txt_content, 'UTF-8')) {
            $llms_txt_content = mb_convert_encoding($llms_txt_content, 'UTF-8', mb_detect_encoding($llms_txt_content));
        }

        // Add UTF-8 BOM to ensure file is recognized as UTF-8
        $llms_txt_content = "\xEF\xBB\xBF" . $llms_txt_content;

        $llms_txt_path = $this->get_root_path() . 'llms.txt';

        return false !== $wp_filesystem->put_contents($llms_txt_path, $llms_txt_content, FS_CHMOD_FILE);
    }

    /**
     * Start a batched generation run (daily cron entry point)
     *
     * Writes the bounded llms.txt / llms-full.txt files immediately, then
     * processes the per-post markdown exports in batches of N posts per cron
     * tick using a stored cursor so large sites never regenerate everything
     * in a single request.
     *
     * @return bool
     */
    public function start_batched_generation() {
        set_transient(self::TRANSIENT_NAME, time(), DAY_IN_SECONDS);

        if (!$this->init_filesystem()) {
            return false;
        }

        $this->create_llms_docs_directory();
        $this->write_llms_txt();
        $this->generate_llms_full();

        // Reset the cursor and process the first markdown batch in this tick.
        update_option(self::CURSOR_OPTION, ['offset' => 0, 'started' => time()], false);
        $this->process_generation_batch();

        return true;
    }

    /**
     * Process one batch of markdown exports (cron tick worker)
     *
     * Batch size is filterable via `llmagnet_generation_batch_size` and the
     * total run is capped by `llmagnet_max_posts_per_generation`.
     *
     * @return void
     */
    public function process_generation_batch() {
        $state = get_option(self::CURSOR_OPTION, false);
        if (!is_array($state) || !isset($state['offset'])) {
            return; // No batch run in progress.
        }

        if (!$this->init_filesystem()) {
            return;
        }

        $this->create_llms_docs_directory();

        $batch_size = max(1, (int) apply_filters('llmagnet_generation_batch_size', 200));
        $offset = max(0, (int) $state['offset']);
        $max_posts = $this->get_max_posts_per_generation();

        if ($offset >= $max_posts) {
            $this->finish_batched_generation($state);
            return;
        }

        $batch_size = min($batch_size, $max_posts - $offset);
        $settings = $this->get_settings();
        $docs_dir = $this->get_root_path() . 'llms-docs/';
        $posts = $this->get_posts_to_export($batch_size, $offset);

        foreach ($posts as $post) {
            $this->write_post_markdown($post, $settings, $docs_dir);
        }

        if (count($posts) < $batch_size) {
            $this->finish_batched_generation($state);
            return;
        }

        $state['offset'] = $offset + $batch_size;
        update_option(self::CURSOR_OPTION, $state, false);

        if (!wp_next_scheduled(self::BATCH_EVENT)) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::BATCH_EVENT);
        }
    }

    /**
     * Finish a batched generation run: prune orphaned markdown files and clear the cursor
     *
     * Files written or touched during the run carry an mtime >= the run start,
     * so anything older belongs to a post that is no longer exported.
     *
     * @param array $state Batch run state (offset, started)
     * @return void
     */
    private function finish_batched_generation($state) {
        global $wp_filesystem;

        if (!empty($state['started'])) {
            $docs_dir = $this->get_root_path() . 'llms-docs/';
            $files = glob($docs_dir . '*.md');
            foreach ((array) $files as $file) {
                if ((int) $wp_filesystem->mtime($file) < (int) $state['started']) {
                    $wp_filesystem->delete($file);
                }
            }
        }

        delete_option(self::CURSOR_OPTION);
    }

    /**
     * Hard ceiling on the number of posts a single generation run may export
     *
     * @return int
     */
    public function get_max_posts_per_generation() {
        /**
         * Filters the maximum number of posts included in a generation run.
         *
         * Safety valve for very large sites.
         *
         * @param int $max_posts Default 1000.
         */
        $max_posts = (int) apply_filters('llmagnet_max_posts_per_generation', 1000);

        return max(1, $max_posts);
    }

    /**
     * Initialize WordPress filesystem
     *
     * @return bool
     */
    private function init_filesystem() {
        global $wp_filesystem;

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        // Initialize the WP filesystem
        if (false === ($credentials = request_filesystem_credentials('', '', false, false, null))) {
            return false;
        }

        if (!WP_Filesystem($credentials)) {
            request_filesystem_credentials('', '', true, false, null);
            return false;
        }

        return true;
    }

    /**
     * Get WordPress root path
     *
     * @return string
     */
    public function get_root_path() {
        return trailingslashit(ABSPATH);
    }

    /**
     * Create llms-docs directory and .htaccess file
     *
     * @return bool
     */
    private function create_llms_docs_directory() {
        global $wp_filesystem;

        $docs_dir = $this->get_root_path() . 'llms-docs';
        
        // Create directory if it doesn't exist
        if (!$wp_filesystem->exists($docs_dir)) {
            $wp_filesystem->mkdir($docs_dir, FS_CHMOD_DIR);
        }

        // Create .htaccess file to allow crawling
        $htaccess_content = "# Allow LLM crawlers to access this directory\n";
        $htaccess_content .= "<IfModule mod_rewrite.c>\n";
        $htaccess_content .= "RewriteEngine On\n";
        $htaccess_content .= "RewriteRule .* - [L]\n";
        $htaccess_content .= "</IfModule>\n";

        $wp_filesystem->put_contents($docs_dir . '/.htaccess', $htaccess_content, FS_CHMOD_FILE);

        return true;
    }

    /**
     * Meta query fragment excluding posts opted out of the llms.txt exports
     * via the `_llmagnet_exclude_from_llms` meta (registered in
     * class-post-meta.php — adoption plan §2.1). Absence of the meta means
     * included, so the common case adds no meta rows.
     *
     * @return array
     */
    private function get_exclude_meta_query(): array {
        return [
            'relation' => 'OR',
            [
                'key'     => '_llmagnet_exclude_from_llms',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_llmagnet_exclude_from_llms',
                'value'   => '1',
                'compare' => '!=',
            ],
        ];
    }

    /**
     * Build the structured header block for llms.txt
     *
     * @return string
     */
    private function build_header(): string {
        $settings = $this->get_settings();
        $site_name = get_bloginfo('name');
        $intro = !empty($settings['llms_txt_custom_intro']) 
            ? $settings['llms_txt_custom_intro'] 
            : get_bloginfo('description');
        
        $header = "# " . $site_name . "\n\n";
        $header .= "> " . $intro . "\n\n";
        $header .= "**URL:** " . home_url() . "\n";
        $header .= "**Language:** " . get_locale() . "\n";
        $header .= "**Last Updated:** " . current_time('Y-m-d') . "\n\n";
        
        return $header;
    }

    /**
     * Build a content section for a specific post type
     *
     * @param string $post_type The post type to query
     * @param int    $max       Maximum posts to include
     * @return string
     */
    private function build_section(string $post_type, int $max = 50): string {
        $settings = $this->get_settings();
        $show_excerpts = $settings['llms_txt_show_excerpts'] ?? true;
        $excerpt_words = 30;
        
        $post_type_obj = get_post_type_object($post_type);
        $label = $post_type === 'post' 
            ? 'Blog Posts' 
            : ($post_type_obj ? $post_type_obj->labels->name : ucwords(str_replace('_', ' ', $post_type)));
        
        $query = new \WP_Query([
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $max,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => $this->get_exclude_meta_query(),
        ]);
        
        if (!$query->have_posts()) {
            return '';
        }
        
        $output = "## $label\n\n";
        
        foreach ($query->posts as $post) {
            $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $permalink = get_permalink($post);
            $output .= "- [$title]($permalink)";
            
            if ($show_excerpts) {
                $excerpt = get_the_excerpt($post);
                if (empty($excerpt)) {
                    $excerpt = $post->post_content;
                }
                $excerpt = wp_strip_all_tags($excerpt);
                $excerpt = wp_trim_words($excerpt, $excerpt_words, '...');
                $excerpt = html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $output .= ": " . $excerpt;
            }
            
            $output .= "\n";
        }
        
        return $output . "\n";
    }

    /**
     * Build the WooCommerce products section with optional pricing
     *
     * @param int $max Maximum products to include
     * @return string
     */
    private function build_woocommerce_section(int $max = 50): string {
        $settings = $this->get_settings();
        $show_excerpts = $settings['llms_txt_show_excerpts'] ?? true;
        $show_prices = $settings['llms_txt_show_prices'] ?? true;
        $excerpt_words = 30;
        
        $query = new \WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $max,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => $this->get_exclude_meta_query(),
        ]);
        
        if (!$query->have_posts()) {
            return '';
        }
        
        $output = "## Products\n\n";
        
        foreach ($query->posts as $post) {
            $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $permalink = get_permalink($post);
            $output .= "- [$title]($permalink)";
            
            $parts = [];
            
            if ($show_excerpts) {
                $excerpt = get_the_excerpt($post);
                if (empty($excerpt)) {
                    $excerpt = $post->post_content;
                }
                $excerpt = wp_strip_all_tags($excerpt);
                $excerpt = wp_trim_words($excerpt, $excerpt_words, '...');
                $excerpt = html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!empty($excerpt)) {
                    $parts[] = $excerpt;
                }
            }
            
            if ($show_prices) {
                $price = get_post_meta($post->ID, '_price', true);
                if (!empty($price) && is_numeric($price)) {
                    $currency_symbol = function_exists('get_woocommerce_currency_symbol') 
                        ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        : '$';
                    $parts[] = "Price: " . $currency_symbol . number_format((float)$price, 2);
                }
            }
            
            if (!empty($parts)) {
                $output .= ": " . implode(' | ', $parts);
            }
            
            $output .= "\n";
        }
        
        return $output . "\n";
    }

    /**
     * Build a legacy flat list (for backward compatibility when group_by_type is disabled)
     *
     * @param array $post_types Post types to include
     * @param int   $max        Maximum items
     * @return string
     */
    private function build_legacy_flat_list(array $post_types, int $max = 50): string {
        $settings = $this->get_settings();
        $show_excerpts = $settings['llms_txt_show_excerpts'] ?? true;
        $excerpt_words = 30;
        
        $output = "## Content\n\n";
        
        $query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $max,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => $this->get_exclude_meta_query(),
        ]);
        
        foreach ($query->posts as $post) {
            $title = html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $permalink = get_permalink($post);
            $output .= "- [$title]($permalink)";
            
            if ($show_excerpts) {
                $excerpt = get_the_excerpt($post);
                if (empty($excerpt)) {
                    $excerpt = $post->post_content;
                }
                $excerpt = wp_strip_all_tags($excerpt);
                $excerpt = wp_trim_words($excerpt, $excerpt_words, '...');
                $excerpt = html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $output .= ": " . $excerpt;
            }
            
            $output .= "\n";
        }
        
        return $output . "\n";
    }

    /**
     * Build the about/contact section
     *
     * @return string
     */
    private function build_about(): string {
        $output = "## About\n\n";
        
        $admin_user = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admin_user)) {
            $display_name = $admin_user[0]->display_name;
            if (!empty($display_name)) {
                $output .= "**Author:** " . esc_html($display_name) . "\n";
            }
        }
        
        $contact_page = get_page_by_path('contact');
        if (!$contact_page) {
            $contact_page = get_page_by_path('contact-us');
        }
        
        if ($contact_page) {
            $contact_url = get_permalink($contact_page);
            $output .= "**Contact:** " . esc_url($contact_url) . "\n";
        }
        
        return $output . "\n";
    }

    /**
     * Generate llms.txt content
     *
     * @return string
     */
    private function generate_llms_txt_content() {
        $settings = $this->get_settings();
        $post_types = $settings['post_types'] ?? ['post', 'page'];
        $max_per_section = $settings['llms_txt_max_per_section'] ?? 50;
        $group_by_type = $settings['llms_txt_group_by_type'] ?? true;
        $show_author = $settings['llms_txt_show_author'] ?? true;
        
        $content = $this->build_header();
        
        if ($group_by_type) {
            foreach ($post_types as $post_type) {
                if ($post_type === 'product' && WooCommerce::is_active()) {
                    $content .= $this->build_woocommerce_section($max_per_section);
                } else {
                    $content .= $this->build_section($post_type, $max_per_section);
                }
            }
        } else {
            $content .= $this->build_legacy_flat_list($post_types, $max_per_section);
        }
        
        $content .= "## Markdown Exports\n\n";
        $markdown_files = $this->get_markdown_files();
        foreach ($markdown_files as $file) {
            $content .= "- " . $file . "\n";
        }
        $content .= "\n";
        
        if ($show_author) {
            $content .= $this->build_about();
        }
        
        $include_images = !empty($settings['include_images']);
        
        // Only include images if this is a premium user and the setting is enabled
        if ($include_images && $this->is_premium_user()) {
            $content .= "\n# Post Images\n";
            $content .= "# These images are used in the posts on this site\n";
            
            // Track the number of images we've added and images without alt text
            $image_count = 0;
            $images_without_alt = [];
            
            // Get posts from selected post types (bounded by the generation ceiling)
            $max_posts = $this->get_max_posts_per_generation();
            foreach ($post_types as $post_type) {
                // First, get posts with featured images
                $posts_with_featured_images = get_posts([
                    'post_type' => $post_type,
                    'posts_per_page' => $max_posts,
                    'meta_query' => [
                        'relation' => 'AND',
                        [
                            'key' => '_thumbnail_id', // Featured image
                            'compare' => 'EXISTS',
                        ],
                        $this->get_exclude_meta_query(),
                    ],
                ]);
                
                // Process featured images
                foreach ($posts_with_featured_images as $post) {
                    $thumbnail_id = get_post_thumbnail_id($post->ID);
                    if ($thumbnail_id) {
                        $image_url = $this->get_absolute_image_url($thumbnail_id);
                        $image_alt = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
                        $image_caption = wp_get_attachment_caption($thumbnail_id);
                        $image_description = !empty($image_caption) ? $image_caption : (!empty($image_alt) ? $image_alt : $post->post_title);
                        
                        if ($image_url) {
                            $content .= "\n## Featured Image: " . $post->post_title . "\n";
                            $content .= "image: " . $image_url . "\n";
                            $content .= "post: " . str_replace(home_url(), '', get_permalink($post->ID)) . "\n";
                            $content .= "description: " . $image_description . "\n";
                            $image_count++;
                            
                            // Track if this image has no alt text
                            if (empty($image_alt)) {
                                $images_without_alt[] = [
                                    'id' => $thumbnail_id,
                                    'url' => $image_url,
                                    'type' => 'featured',
                                    'post_id' => $post->ID,
                                    'post_title' => $post->post_title,
                                    'preview_url' => wp_get_attachment_image_url($thumbnail_id, 'thumbnail')
                                ];
                            }
                        }
                    }
                }
                
                // Now get posts to extract content images
                $all_posts = get_posts([
                    'post_type' => $post_type,
                    'posts_per_page' => $max_posts,
                    'post_status' => 'publish',
                ]);
                
                // Process content images
                foreach ($all_posts as $post) {
                    $post_content = $post->post_content;
                    if (!empty($post_content)) {
                        // Extract image URLs using regex (more compatible than DOMDocument)
                        if (preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\\1[^>]*>/i', $post_content, $matches)) {
                            foreach ($matches['src'] as $index => $src) {
                                // Get alt text if available
                                $alt = '';
                                if (preg_match('/alt=([\'"])([^\'"]*?)\\1/i', $matches[0][$index], $alt_matches)) {
                                    $alt = $alt_matches[2];
                                }
                                
                                // Skip data URLs or empty sources
                                if (empty($src) || strpos($src, 'data:') === 0) {
                                    continue;
                                }
                                
                                // Make sure URL is absolute
                                if (strpos($src, 'http') !== 0) {
                                    if (strpos($src, '//') === 0) {
                                        $src = 'https:' . $src;
                                    } else {
                                        $src = site_url($src);
                                    }
                                }
                                
                                // Prefer existing alt; if empty, try media-library alt/caption via attachment ID from URL
                                $attachment_id = $this->get_attachment_id_from_url($src) ?: 0;
                                $library_alt = '';
                                if (empty($alt) && $attachment_id) {
                                    $caption = wp_get_attachment_caption($attachment_id);
                                    $stored_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                                    $library_alt = !empty($caption) ? $caption : $stored_alt;
                                }

                                $final_description = !empty($alt) ? $alt : (!empty($library_alt) ? $library_alt : $post->post_title);

                                $content .= "\n## Content Image: " . $post->post_title . "\n";
                                $content .= "image: " . $src . "\n";
                                $content .= "post: " . str_replace(home_url(), '', get_permalink($post->ID)) . "\n";
                                $content .= "description: " . $final_description . "\n";
                                $image_count++;

                                // Only track as missing alt when both tag alt and media-library alt are empty
                                if (empty($alt) && empty($library_alt)) {
                                    $images_without_alt[] = [
                                        'id' => $attachment_id ?: 0,
                                        'url' => $src,
                                        'type' => 'content',
                                        'post_id' => $post->ID,
                                        'post_title' => $post->post_title,
                                        'preview_url' => $attachment_id ? wp_get_attachment_image_url($attachment_id, 'thumbnail') : $src
                                    ];
                                }
                            }
                        }
                    }
                    
                    // Check for gallery shortcodes
                    if (preg_match_all('/\[gallery[^\]]*ids=([\'"])(?<ids>[0-9,]+)\\1[^\]]*\]/i', $post_content, $matches)) {
                        foreach ($matches['ids'] as $ids_str) {
                            $ids = explode(',', $ids_str);
                            foreach ($ids as $attachment_id) {
                                $image_url = $this->get_absolute_image_url($attachment_id);
                                $image_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                                $image_caption = wp_get_attachment_caption($attachment_id);
                                $image_description = !empty($image_caption) ? $image_caption : (!empty($image_alt) ? $image_alt : $post->post_title);
                                
                                if ($image_url) {
                                    $content .= "\n## Gallery Image: " . $post->post_title . "\n";
                                    $content .= "image: " . $image_url . "\n";
                                    $content .= "post: " . str_replace(home_url(), '', get_permalink($post->ID)) . "\n";
                                    $content .= "description: " . $image_description . "\n";
                                    $image_count++;
                                    
                                    // Track if this image has no alt text
                                    if (empty($image_alt)) {
                                        $images_without_alt[] = [
                                            'id' => $attachment_id,
                                            'url' => $image_url,
                                            'type' => 'gallery',
                                            'post_id' => $post->ID,
                                            'post_title' => $post->post_title,
                                            'preview_url' => wp_get_attachment_image_url($attachment_id, 'thumbnail')
                                        ];
                                    }
                                }
                            }
                        }
                    }
                    
                    // Check for Gutenberg blocks if the function exists (WP 5.0+)
                    if (function_exists('has_blocks') && function_exists('parse_blocks') && has_blocks($post->post_content)) {
                        $blocks = parse_blocks($post->post_content);
                        foreach ($blocks as $block) {
                            if (isset($block['blockName']) && $block['blockName'] === 'core/gallery' && !empty($block['attrs']['ids'])) {
                                foreach ($block['attrs']['ids'] as $attachment_id) {
                                    $image_url = $this->get_absolute_image_url($attachment_id);
                                    $image_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
                                    $image_caption = wp_get_attachment_caption($attachment_id);
                                    $image_description = !empty($image_caption) ? $image_caption : (!empty($image_alt) ? $image_alt : $post->post_title);
                                    
                                    if ($image_url) {
                                        $content .= "\n## Gallery Image: " . $post->post_title . "\n";
                                        $content .= "image: " . $image_url . "\n";
                                        $content .= "post: " . str_replace(home_url(), '', get_permalink($post->ID)) . "\n";
                                        $content .= "description: " . $image_description . "\n";
                                        $image_count++;
                                        
                                        // Track if this image has no alt text
                                        if (empty($image_alt)) {
                                            $images_without_alt[] = [
                                                'id' => $attachment_id,
                                                'url' => $image_url,
                                                'type' => 'gutenberg-gallery',
                                                'post_id' => $post->ID,
                                                'post_title' => $post->post_title,
                                                'preview_url' => wp_get_attachment_image_url($attachment_id, 'thumbnail')
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            // Store the image count for display in the dashboard (rarely read — no autoload)
            update_option('llmagnet_ai_seo_optimizer_image_count', $image_count, false);
            
            // Store images without alt text (potentially large — no autoload)
            update_option('llmagnet_ai_seo_optimizer_images_without_alt', $images_without_alt, false);
        }
        
        return $content;
    }

    /**
     * Check if current user has premium access
     * 
     * @return bool True if premium user, false otherwise
     */
    private function is_premium_user() {
        // Check if Freemius is available and user has premium access
        if (function_exists('lltg_fs')) {
            $fs = lltg_fs();
            return $fs->can_use_premium_code();
        }
        
        // Fallback to admin check if Freemius is not available
        return current_user_can('manage_options');
    }

    /**
     * Get attachment ID from URL
     *
     * @param string $url The URL to the image
     * @return int|false Attachment ID or false if not found
     */
    private function get_attachment_id_from_url($url) {
        global $wpdb;
        
        if (empty($url)) {
            return false;
        }
        
        // Normalize URL: remove query string
        $original_url = $url;
        $url = preg_replace('/\?.*/', '', $url);
        
        // 1) Try core helper first
        if (function_exists('attachment_url_to_postid')) {
            $id = attachment_url_to_postid($url);
            if (!empty($id)) {
                return (int) $id;
            }
        }
        
        // 2) Handle resized filenames like -1024x850.png → .png
        $url_without_size = preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url);
        if ($url_without_size !== $url && function_exists('attachment_url_to_postid')) {
            $id = attachment_url_to_postid($url_without_size);
            if (!empty($id)) {
                return (int) $id;
            }
        }
        
        // Extract filename(s)
        $parsed = parse_url($url);
        if (!isset($parsed['path'])) {
            return false;
        }
        $filename = basename($parsed['path']);
        $filename_no_size = basename(parse_url($url_without_size, PHP_URL_PATH));
        
        // Helper to query by filename against common locations
        $query_by_filename = function (string $name) use ($wpdb) {
            // _wp_attached_file meta
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
                '%' . $name
            ));
            if (!empty($id)) return $id;
            
            // posts.guid
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
                '%' . $name
            ));
            if (!empty($id)) return $id;
            
            // _wp_attachment_metadata scan (expensive but robust)
            $rows = $wpdb->get_results(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'",
                ARRAY_A
            );
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $meta = maybe_unserialize($row['meta_value']);
                    if (is_array($meta)) {
                        if (!empty($meta['file']) && strpos($meta['file'], $name) !== false) {
                            return (int) $row['post_id'];
                        }
                        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                            foreach ($meta['sizes'] as $size) {
                                if (!empty($size['file']) && $size['file'] === $name) {
                                    return (int) $row['post_id'];
                                }
                            }
                        }
                    }
                }
            }
            return 0;
        };
        
        // 3) Try by exact filename
        $id = $query_by_filename($filename);
        if (!empty($id)) return $id;
        
        // 4) Try by filename without size suffix
        if ($filename_no_size !== $filename) {
            $id = $query_by_filename($filename_no_size);
            if (!empty($id)) return $id;
        }
        
        // Not found
        return false;
    }
    
    /**
     * Get absolute URL for attachment with proper domain
     *
     * @param mixed $input WordPress attachment ID or URL string
     * @return string|false Absolute URL or false if not found
     */
    private function get_absolute_image_url($input) {
        $image_url = '';
        $site_url = get_site_url();
        $upload_dir = wp_get_upload_dir();
        
        // Handle different input types
        if (is_numeric($input)) {
            // Input is an attachment ID
            $image_url = wp_get_attachment_url($input);
        
        if (!$image_url) {
            return false;
        }
        
            // Check if the image URL contains a local development domain
            if (preg_match('/plugin-driver\.local|localhost|127\.0\.0\.1|\.local|test|dev\./', $image_url)) {
                // Get the file path relative to uploads directory
                $file_path = get_attached_file($input);
                if ($file_path) {
                    // Get relative path from uploads directory
                    $relative_path = str_replace($upload_dir['basedir'], '', $file_path);
                    // Construct proper URL with real domain
                    $image_url = $upload_dir['baseurl'] . $relative_path;
                    
                    // Ensure we're using the correct site URL for baseurl
                    if (preg_match('/plugin-driver\.local|localhost|127\.0\.0\.1|\.local|test|dev\./', $upload_dir['baseurl'])) {
                        $parsed_baseurl = parse_url($upload_dir['baseurl']);
                        if ($parsed_baseurl && isset($parsed_baseurl['path'])) {
                            $image_url = $site_url . $parsed_baseurl['path'] . $relative_path;
                        }
                    }
                }
            }
        } else if (is_string($input)) {
            // Input is a URL string
            $image_url = $input;
        } else {
            return false;
        }
        
        // Handle protocol-relative URLs (//example.com/image.jpg)
        if (strpos($image_url, '//') === 0) {
            $image_url = 'https:' . $image_url;
        }
        
        // Handle absolute URLs
        if (preg_match('/^https?:\/\//', $image_url)) {
            // Check for common CDN domains and replace with site URL if needed
            $cdn_patterns = [
                '/wp-content\/uploads/',
                '/wp-includes/',
                '/wp-content\/themes/',
                '/wp-content\/plugins/'
            ];
            
            foreach ($cdn_patterns as $pattern) {
                if (preg_match($pattern, $image_url)) {
                    $parsed_url = parse_url($image_url);
                    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
                    
                    // Extract the path after wp-content
                    if (preg_match('/(\/wp-content\/.*)/', $path, $matches)) {
                        $wp_content_path = $matches[1];
                        // Replace the domain with the site URL
                        $image_url = rtrim($site_url, '/') . $wp_content_path;
                    }
                }
            }
            
            return $image_url;
        }
        
        // Handle relative URLs
        if (strpos($image_url, '/') === 0) {
            // Absolute path relative to domain root
            return rtrim($site_url, '/') . $image_url;
        } else {
            // Relative path
        return rtrim($site_url, '/') . '/' . ltrim($image_url, '/');
        }
    }

    /**
     * Get list of Markdown files
     *
     * @return array
     */
    private function get_markdown_files() {
        $files = [];
        $site_url = trailingslashit(get_site_url());
        $docs_url = $site_url . 'llms-docs/';
        
        // Get posts to export
        $posts = $this->get_posts_to_export();
        
        foreach ($posts as $post) {
            $slug = sanitize_title($post->post_name);
            $files[] = $docs_url . $slug . '.md';
        }
        
        return $files;
    }

    /**
     * Get posts to export as Markdown
     *
     * Bounded by the `llmagnet_max_posts_per_generation` ceiling. Ordered by
     * ID so batched runs paginate over a stable set.
     *
     * @param int|null $limit  Maximum posts to return (null = up to the ceiling)
     * @param int      $offset Pagination offset for batched runs
     * @return array
     */
    public function get_posts_to_export($limit = null, $offset = 0) {
        $settings = $this->get_settings();
        
        // Ensure attachments are not included
        $post_types = $settings['post_types'];
        if (is_array($post_types)) {
            $post_types = array_diff($post_types, ['attachment']);
        }
        
        $max_posts = $this->get_max_posts_per_generation();
        if (null === $limit || (int) $limit > $max_posts) {
            $limit = $max_posts;
        }
        
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => (int) $limit,
            'offset' => max(0, (int) $offset),
            'orderby' => 'ID',
            'order' => 'ASC',
            // Respect the per-post llms.txt exclude toggle (adoption §2.1)
            'meta_query' => $this->get_exclude_meta_query(),
        ];
        
        // Add date filter if set
        if (!empty($settings['days_to_include']) && $settings['days_to_include'] > 0) {
            $args['date_query'] = [
                'after' => gmdate('Y-m-d', strtotime('-' . $settings['days_to_include'] . ' days')),
            ];
        }
        
        return get_posts($args);
    }

    /**
     * Generate Markdown files for posts
     *
     * @return bool
     */
    private function generate_markdown_files() {
        global $wp_filesystem;
        
        // Get posts to export
        $posts = $this->get_posts_to_export();
        $settings = $this->get_settings();
        $docs_dir = $this->get_root_path() . 'llms-docs/';
        
        // Remove markdown files that no longer correspond to an exported post
        $expected = [];
        foreach ($posts as $post) {
            $expected[$docs_dir . sanitize_title($post->post_name) . '.md'] = true;
        }
        $existing_files = glob($docs_dir . '*.md');
        foreach ((array) $existing_files as $file) {
            if (!isset($expected[$file])) {
                $wp_filesystem->delete($file);
            }
        }
        
        // Generate new files (unchanged posts are skipped)
        foreach ($posts as $post) {
            $this->write_post_markdown($post, $settings, $docs_dir);
        }
        
        return true;
    }

    /**
     * Write the markdown export file for a single post
     *
     * Skips the (expensive) regeneration when the existing file is at least
     * as new as the post's last modification.
     *
     * @param \WP_Post $post     Post object
     * @param array    $settings Plugin settings
     * @param string   $docs_dir Trailing-slashed llms-docs directory path
     * @return bool
     */
    private function write_post_markdown($post, $settings, $docs_dir) {
        global $wp_filesystem;
        
        $slug = sanitize_title($post->post_name);
        $filename = $docs_dir . $slug . '.md';
        
        // Skip unchanged posts: file mtime (UTC) >= post_modified (GMT)
        $modified_gmt = get_post_modified_time('U', true, $post);
        if ($modified_gmt && $wp_filesystem->exists($filename) && (int) $wp_filesystem->mtime($filename) >= (int) $modified_gmt) {
            // Refresh mtime so batch-run orphan pruning keeps this file.
            $wp_filesystem->touch($filename);
            return true;
        }
        
        // Generate Markdown content
        $content = "# {$post->post_title}\n\n";
        
        // Add post meta
        $content .= "*Published:* " . get_the_date('F j, Y', $post->ID) . "\n";
        $content .= "*URL:* " . get_permalink($post->ID) . "\n\n";
        
        // Add content
        if ($settings['full_content']) {
            $post_content = $post->post_content;
        } else {
            $post_content = $post->post_excerpt ?: wp_trim_words($post->post_content, 55, '...');
        }
        
        // Convert to Markdown
        $content .= $this->html_to_markdown($post_content);
        
        // Ensure content is properly encoded as UTF-8
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
        }
        
        // Add UTF-8 BOM to ensure file is recognized as UTF-8
        $content = "\xEF\xBB\xBF" . $content;
        
        // Write to file
        return false !== $wp_filesystem->put_contents($filename, $content, FS_CHMOD_FILE);
    }

    /**
     * Convert HTML to Markdown
     *
     * Delegates to the shared Markdown_Converter (agent-readiness F5 /
     * dependency D4) so /llms-docs/ exports, the {permalink}.md endpoints,
     * and the MCP/WebMCP read tools all produce identical markdown.
     * Shortcode processing is enabled because this receives raw
     * post_content (not `the_content`-filtered HTML).
     *
     * @param string $content HTML content
     * @return string
     */
    private function html_to_markdown($content) {
        return Markdown_Converter::convert((string) $content, true);
    }

    /**
     * Check if WordPress root directory is writable
     *
     * @return bool
     */
    public function is_root_writable() {
        global $wp_filesystem;
        
        if (!$this->init_filesystem()) {
            return false;
        }
        
        return $wp_filesystem->is_writable($this->get_root_path());
    }

    /**
     * Get last generation timestamp
     *
     * @return int|false
     */
    public function get_last_generated_time() {
        return get_transient(self::TRANSIENT_NAME);
    }
    
    /**
     * Generate llms-full.txt with complete post content (Pro+ only)
     *
     * @return bool
     */
    public function generate_llms_full(): bool {
        if (function_exists('lltg_fs') && lltg_fs()->is_free_plan()) {
            return false;
        }

        $settings = $this->get_settings();
        if (empty($settings['generate_full'])) {
            return false;
        }

        global $wp_filesystem;
        if (!$this->init_filesystem()) {
            return false;
        }

        $post_types = $settings['post_types'] ?? ['post', 'page'];
        $max_posts  = $settings['llms_full_max_posts'] ?? 200;

        $query = new \WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $max_posts,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'meta_query'     => $this->get_exclude_meta_query(),
        ]);

        $post_count = count($query->posts);
        $content = $this->build_full_header($post_count);

        foreach ($query->posts as $post) {
            $content .= $this->build_full_post_block($post);
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
        }

        $content = "\xEF\xBB\xBF" . $content;

        $path = $this->get_root_path() . 'llms-full.txt';
        $result = $wp_filesystem->put_contents($path, $content, FS_CHMOD_FILE);

        if ($result) {
            $file_size = strlen($content);
            update_option('llmagnet_llms_full_post_count', $post_count, false);
            update_option('llmagnet_llms_full_file_size', $file_size, false);

            if ($file_size > 5 * 1024 * 1024) {
                $size_mb = round($file_size / (1024 * 1024), 1);
                update_option('llmagnet_llms_full_size_warning', $size_mb, false);
            } else {
                delete_option('llmagnet_llms_full_size_warning');
            }
        }

        return (bool) $result;
    }

    /**
     * Build the header block for llms-full.txt
     *
     * @param int $post_count Number of posts included
     * @return string
     */
    private function build_full_header(int $post_count): string {
        $site_name = get_bloginfo('name');
        $header  = "# " . $site_name . " — Full Content\n\n";
        $header .= "> Complete content index for LLM consumption\n";
        $header .= "> Generated: " . current_time('Y-m-d') . " | Posts: " . $post_count . "\n\n";
        $header .= "---\n\n";
        return $header;
    }

    /**
     * Build a full content block for a single post in llms-full.txt
     *
     * @param \WP_Post $post The post object
     * @return string
     */
    private function build_full_post_block(\WP_Post $post): string {
        $raw = apply_filters('the_content', $post->post_content);
        $text = wp_strip_all_tags($raw);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return "## " . get_the_title($post) . "\n"
             . "URL: " . get_permalink($post) . "\n"
             . "Type: " . $post->post_type . "\n"
             . "Modified: " . get_the_modified_date('Y-m-d', $post) . "\n\n"
             . trim($text) . "\n\n---\n\n";
    }

    /**
     * Get llms-full.txt file info for dashboard display
     *
     * @return array{exists: bool, size: int, size_formatted: string, post_count: int, warning: float|null}
     */
    public function get_llms_full_info(): array {
        $path = $this->get_root_path() . 'llms-full.txt';
        $exists = file_exists($path);

        return [
            'exists' => $exists,
            'size' => $exists ? filesize($path) : 0,
            'size_formatted' => $exists ? $this->format_file_size(filesize($path)) : '0 B',
            'post_count' => (int) get_option('llmagnet_llms_full_post_count', 0),
            'warning' => get_option('llmagnet_llms_full_size_warning', null),
        ];
    }

    /**
     * Format bytes to human-readable file size
     *
     * @param int $bytes File size in bytes
     * @return string Formatted size string
     */
    private function format_file_size(int $bytes): string {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /**
     * Count posts that will be exported based on current settings
     *
     * @param array $settings Plugin settings
     * @return int Number of posts to export
     */
    public function count_posts_for_export($settings = null) {
        if (null === $settings) {
            $settings = $this->get_settings();
        }
        
        // Ensure attachments are not included
        $post_types = $settings['post_types'];
        if (is_array($post_types)) {
            $post_types = array_diff($post_types, ['attachment']);
        }
        
        $args = [
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids', // Only get post IDs for better performance
        ];
        
        // Add date filter if set
        if (!empty($settings['days_to_include']) && $settings['days_to_include'] > 0) {
            $args['date_query'] = [
                'after' => gmdate('Y-m-d', strtotime('-' . $settings['days_to_include'] . ' days')),
            ];
        }
        
        $query = new \WP_Query($args);
        return $query->found_posts;
    }
} 