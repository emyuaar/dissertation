<?php
/**
 * Alt Text Manager class
 *
 * Image upload + alt-text AJAX backend and URL→attachment-ID resolution
 * helpers, extracted verbatim from class-admin.php (improvement plan
 * P2-1.1). AJAX action names are unchanged:
 * - wp_ajax_llmagnet_ai_seo_upload_image
 * - wp_ajax_llmagnet_ai_seo_update_alt_text
 * - wp_ajax_llmagnet_ai_seo_get_images_without_alt
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Image upload and alt-text management (AJAX backend for the dashboard)
 */
class Alt_Text_Manager {
    /**
     * Generator instance
     *
     * @var Generator
     */
    private $generator;

    /**
     * Constructor
     *
     * @param Generator $generator Generator instance
     */
    public function __construct(Generator $generator) {
        $this->generator = $generator;
    }

    /**
     * Register AJAX hooks (same action names as the pre-split Admin class)
     *
     * @return void
     */
    public function init() {
        // Add AJAX handler for media upload
        add_action('wp_ajax_llmagnet_ai_seo_upload_image', [$this, 'ajax_upload_image']);

        // Add AJAX handler for updating alt text
        add_action('wp_ajax_llmagnet_ai_seo_update_alt_text', [$this, 'ajax_update_alt_text']);

        // Add AJAX handler for getting images without alt text
        add_action('wp_ajax_llmagnet_ai_seo_get_images_without_alt', [$this, 'ajax_get_images_without_alt']);
    }

    /**
     * Log alt-text debug lines only when WP_DEBUG is true.
     *
     * @param string $message Message.
     * @return void
     */
    private function alt_text_debug_log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( $message );
        }
    }

    /**
     * AJAX handler for image upload
     *
     * @return void
     */
    public function ajax_upload_image() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'llmagnet_ai_seo_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check permissions
        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to upload files.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check premium status
        if (!Admin_WP_Helper::is_premium_user()) {
            wp_send_json_error(['message' => esc_html__('This feature is available for premium users only.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check if file was uploaded
        if (empty($_FILES['image'])) {
            wp_send_json_error(['message' => esc_html__('No image file uploaded.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Handle the upload
        $uploaded_file = $_FILES['image'];
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($uploaded_file['type'], $allowed_types)) {
            wp_send_json_error(['message' => esc_html__('Invalid file type. Please upload an image file.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Use WordPress media upload functionality
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        
        $upload_overrides = [
            'test_form' => false,
        ];
        
        $movefile = wp_handle_upload($uploaded_file, $upload_overrides);
        
        if ($movefile && !isset($movefile['error'])) {
            // Create attachment
            $attachment = [
                'post_mime_type' => $movefile['type'],
                'post_title' => sanitize_file_name(pathinfo($movefile['file'], PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit'
            ];
            
            $attachment_id = wp_insert_attachment($attachment, $movefile['file']);
            
            if (!is_wp_error($attachment_id)) {
                // Generate metadata
                if (!function_exists('wp_generate_attachment_metadata')) {
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                }
                
                $attachment_data = wp_generate_attachment_metadata($attachment_id, $movefile['file']);
                wp_update_attachment_metadata($attachment_id, $attachment_data);
                
                // Get image data with absolute URLs
                $image_url = $this->get_absolute_image_url($attachment_id);
                $image_data = wp_get_attachment_image_src($attachment_id, 'medium');
                $preview_url = $image_data ? $this->fix_image_url($image_data[0]) : $image_url;
                
                wp_send_json_success([
                    'message' => esc_html__('Image uploaded successfully.', 'llmagnet-llm-txt-generator'),
                    'attachment_id' => $attachment_id,
                    'url' => $image_url,
                    'preview_url' => $preview_url,
                    'width' => $image_data ? $image_data[1] : null,
                    'height' => $image_data ? $image_data[2] : null,
                ]);
            } else {
                wp_send_json_error(['message' => esc_html__('Failed to create attachment.', 'llmagnet-llm-txt-generator')]);
            }
        } else {
            wp_send_json_error(['message' => $movefile['error'] ?? esc_html__('Upload failed.', 'llmagnet-llm-txt-generator')]);
        }
    }

    /**
     * Get absolute URL for attachment with proper domain
     *
     * @param int $attachment_id WordPress attachment ID
     * @return string|false Absolute URL or false if not found
     */
    private function get_absolute_image_url($attachment_id) {
        // Get the attachment URL using WordPress function
        $image_url = wp_get_attachment_url($attachment_id);
        
        if (!$image_url) {
            return false;
        }
        
        return $this->fix_image_url($image_url);
    }

    /**
     * Get image data for attachment
     *
     * Public: consumed by Admin_Assets when building the dashboard
     * localize payload (imageData).
     *
     * @param int $attachment_id Attachment ID
     * @return array|null Image data or null if not found
     */
    public function get_image_data($attachment_id) {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return null;
        }
        
        $image_data = wp_get_attachment_image_src($attachment_id, 'medium');
        $preview_url = $image_data ? $this->fix_image_url($image_data[0]) : $url;
        
        return [
            'id' => $attachment_id,
            'url' => $this->fix_image_url($url),
            'preview_url' => $preview_url,
            'width' => $image_data ? $image_data[1] : null,
            'height' => $image_data ? $image_data[2] : null,
        ];
    }

    /**
     * Fix image URL to use proper domain instead of local development URLs
     *
     * @param string $image_url Original image URL
     * @return string Fixed image URL
     */
    private function fix_image_url($image_url) {
        // Get upload directory information for more reliable URL construction
        $upload_dir = wp_get_upload_dir();
        $site_url = get_site_url();
        
        // If URL is already absolute (starts with http:// or https://), check for local domains
        if (preg_match('/^https?:\/\//', $image_url)) {
            // Check if the image URL contains a local development domain
            if (preg_match('/plugin-driver\.local|localhost|127\.0\.0\.1|\.local/', $image_url)) {
                // Replace the domain part with the actual site domain
                $parsed_image = parse_url($image_url);
                if ($parsed_image && isset($parsed_image['path'])) {
                    // Check if this is an uploads path
                    $uploads_path = parse_url($upload_dir['baseurl'], PHP_URL_PATH);
                    if ($uploads_path && strpos($parsed_image['path'], $uploads_path) === 0) {
                        // Reconstruct URL using proper baseurl
                        $relative_path = substr($parsed_image['path'], strlen($uploads_path));
                        
                        // Ensure baseurl uses correct domain
                        if (preg_match('/plugin-driver\.local|localhost|127\.0\.0\.1|\.local/', $upload_dir['baseurl'])) {
                            $image_url = $site_url . $uploads_path . $relative_path;
                        } else {
                            $image_url = $upload_dir['baseurl'] . $relative_path;
                        }
                    } else {
                        // General path replacement
                        $image_url = $site_url . $parsed_image['path'];
                    }
                    
                    // Add query string if exists
                    if (isset($parsed_image['query'])) {
                        $image_url .= '?' . $parsed_image['query'];
                    }
                }
            }
            
            return $image_url;
        }
        
        // If URL is relative, make it absolute using site URL
        return rtrim($site_url, '/') . '/' . ltrim($image_url, '/');
    }

    /**
     * Get attachment ID from URL using various methods
     *
     * @param string $url The URL of the image
     * @return int The attachment ID or 0 if not found
     */
    private function get_attachment_id_from_url($url) {
        global $wpdb;
        
        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Trying to get attachment ID from URL: ' . $url);
        
        // Try the WordPress built-in function first
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id > 0) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by attachment_url_to_postid: ' . $attachment_id);
            return $attachment_id;
        }
        
        // Clean the URL
        $url = preg_replace('/\?.*/', '', $url); // Remove query string
        
        // Handle various image sizes
        $url_without_size = preg_replace('/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $url);
        $url = str_replace('-scaled.', '.', $url); // Handle scaled images
        $url = str_replace('-1024x850.', '.', $url); // Handle specific size
        $url = str_replace('-1024x1024.', '.', $url); // Handle resized images
        $url = str_replace('-150x150.', '.', $url); // Handle thumbnails
        
        // Extract the filename with and without size
        $filename = basename($url);
        $filename_without_size = basename($url_without_size);
        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Extracted filename: ' . $filename);
        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Filename without size: ' . $filename_without_size);
        
        // Try direct SQL query by filename (more reliable)
        $attachment_id = $this->get_attachment_id_by_filename($filename);
        if ($attachment_id > 0) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by filename SQL query: ' . $attachment_id);
            return $attachment_id;
        }
        
        // Try with filename without size
        if ($filename !== $filename_without_size) {
            $attachment_id = $this->get_attachment_id_by_filename($filename_without_size);
            if ($attachment_id > 0) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by filename without size SQL query: ' . $attachment_id);
                return $attachment_id;
            }
        }
        
        // Try to find by guid (direct URL match)
        $attachment = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE guid LIKE %s AND post_type = 'attachment'",
            '%' . $filename
        ));
        
        if (!empty($attachment[0])) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by guid: ' . $attachment[0]);
            return (int)$attachment[0];
        }
        
        // Try with filename without size
        if ($filename !== $filename_without_size) {
            $attachment = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE guid LIKE %s AND post_type = 'attachment'",
                '%' . $filename_without_size
            ));
            
            if (!empty($attachment[0])) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by guid with filename without size: ' . $attachment[0]);
                return (int)$attachment[0];
            }
        }
        
        // Try to find by _wp_attached_file meta
        $attachment = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
            '%' . $filename
        ));
        
        if (!empty($attachment[0])) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by _wp_attached_file: ' . $attachment[0]);
            return (int)$attachment[0];
        }
        
        // Try with filename without size
        if ($filename !== $filename_without_size) {
            $attachment = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                '%' . $filename_without_size
            ));
            
            if (!empty($attachment[0])) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by _wp_attached_file with filename without size: ' . $attachment[0]);
                return (int)$attachment[0];
            }
        }
        
        // Try to find by _wp_attachment_metadata (more complex, but sometimes necessary)
        $attachments = $wpdb->get_results(
            "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attachment_metadata'"
        );
        
        foreach ($attachments as $attachment) {
            $meta = maybe_unserialize($attachment->meta_value);
            
            // Check if the filename is in the main file
            if (isset($meta['file']) && (strpos($meta['file'], $filename) !== false || strpos($meta['file'], $filename_without_size) !== false)) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by _wp_attachment_metadata main file: ' . $attachment->post_id);
                return (int)$attachment->post_id;
            }
            
            // Check if the filename is in the sizes array
            if (isset($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (isset($size['file']) && ($size['file'] === $filename || $size['file'] === $filename_without_size)) {
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by _wp_attachment_metadata sizes: ' . $attachment->post_id);
                        return (int)$attachment->post_id;
                    }
                }
            }
        }
        
        // As a last resort, try to query all attachments and look for a similar filename
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);
        
        foreach ($attachments as $id) {
            $attachment_url = wp_get_attachment_url($id);
            if ($attachment_url && (strpos($attachment_url, $filename) !== false || strpos($attachment_url, $filename_without_size) !== false)) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found by attachment URL comparison: ' . $id);
                return (int)$id;
            }
        }
        
        // If we get here, we couldn't find the attachment
        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Could not find attachment ID for URL: ' . $url);
        
        return 0;
    }

    /**
     * Get attachment ID by filename using direct SQL query
     *
     * @param string $filename The filename to look for
     * @return int The attachment ID or 0 if not found
     */
    private function get_attachment_id_by_filename($filename) {
        global $wpdb;
        
        // Try to find in guid
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'attachment' 
            AND guid LIKE %s",
            '%' . $filename
        ));
        
        if ($attachment_id) {
            return (int)$attachment_id;
        }
        
        // Try to find in _wp_attached_file
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value LIKE %s",
            '%' . $filename
        ));
        
        if ($attachment_id) {
            return (int)$attachment_id;
        }
        
        // Try to find in _wp_attachment_metadata
        $attachments = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attachment_metadata'
            AND meta_value LIKE %s",
            '%' . $filename . '%'
        ));
        
        foreach ($attachments as $attachment) {
            return (int)$attachment->post_id;
        }
        
        return 0;
    }

    /**
     * AJAX handler for getting images without alt text
     *
     * @return void
     */
    public function ajax_get_images_without_alt() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'llmagnet_ai_seo_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Force regenerate to find images without alt text
        $this->generator->generate_all();
        
        // Get the latest images without alt text
        $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
        
        // If still no images found, try to find them directly
        if (empty($images_without_alt)) {
            global $wpdb;
            
            // Get all image attachments
            $attachments = $wpdb->get_results("
                SELECT p.ID, p.post_title, p.post_parent
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
                WHERE p.post_type = 'attachment'
                AND p.post_mime_type LIKE 'image/%'
                AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ");
            
            foreach ($attachments as $attachment) {
                // Get parent post title if available
                $parent_title = $attachment->post_title;
                if ($attachment->post_parent) {
                    $parent = get_post($attachment->post_parent);
                    if ($parent) {
                        $parent_title = $parent->post_title;
                    }
                }
                
                // Get image URLs
                $image_url = wp_get_attachment_url($attachment->ID);
                $preview_url = wp_get_attachment_image_url($attachment->ID, 'thumbnail');
                
                if ($image_url) {
                    $images_without_alt[] = [
                        'id' => $attachment->ID,
                        'url' => $image_url,
                        'type' => 'attachment',
                        'post_id' => $attachment->post_parent,
                        'post_title' => $parent_title,
                        'preview_url' => $preview_url ?: $image_url
                    ];
                }
            }
            
            // Save the updated list
            update_option('llmagnet_ai_seo_optimizer_images_without_alt', $images_without_alt);
        }
        
        wp_send_json_success([
            'imagesWithoutAlt' => $images_without_alt,
            'count' => count($images_without_alt)
        ]);
    }

    /**
     * AJAX handler for updating alt text for images
     *
     * @return void
     */
    public function ajax_update_alt_text() {
        // CSRF hardening (P2-5.3): require POST and verify the nonce before any
        // output buffering or processing. GET requests (including the legacy
        // ?direct=1 popup flow) are rejected so state changes never ride on GET
        // with the nonce exposed in the URL.
        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ('POST' !== $request_method) {
            wp_send_json_error(['message' => esc_html__('Invalid request method. This action requires a POST request.', 'llmagnet-llm-txt-generator')], 405);
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'llmagnet_ai_seo_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'llmagnet-llm-txt-generator')], 403);
        }

        // Handle direct GET request as a fallback
        if (isset($_GET['direct']) && $_GET['direct'] == '1') {
            // Start output buffering to catch any errors
            ob_start();
            
            try {
                // Check nonce
                if (!isset($_GET['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'llmagnet_ai_seo_nonce')) {
                    echo 'Security check failed.';
                    exit;
                }
                
                // Check permissions
                if (!current_user_can('manage_options')) {
                    echo 'You do not have permission to perform this action.';
                    exit;
                }
            
            // Get all images without alt text
            $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
            
            // Debug - log the images without alt text
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Images without alt text: ' . print_r($images_without_alt, true));
            
            // If no images without alt text or force parameter is set, force a regeneration to find them
            if (empty($images_without_alt) || (isset($_GET['force']) && $_GET['force'] == '1')) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - No images without alt text found or force parameter set, forcing regeneration');
                
                // Debug mode
                $debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
                if ($debug_mode) {
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Debug mode activated');
                    echo '<html><body><h1>Debug Mode</h1>';
                    echo '<p>Starting image alt text update process...</p>';
                }
                
                // Check for the super simple mode
                if (isset($_GET['simple']) && $_GET['simple'] == '1') {
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Simple mode activated, adding placeholder alt text to all images');
                    if ($debug_mode) {
                        echo '<p>Simple mode activated, adding placeholder alt text to all images</p>';
                    }
                    
                    // Direct SQL query to get all attachment IDs (much more efficient)
                    global $wpdb;
                    
                    // Initialize updated count
                    $updated_count = 0;
                    
                    // First try to get attachments without alt text
                    $attachment_ids = $wpdb->get_col("
                        SELECT p.ID 
                        FROM {$wpdb->posts} p
                        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
                        WHERE p.post_type = 'attachment'
                        AND p.post_mime_type LIKE 'image/%'
                        AND (pm.meta_value IS NULL OR pm.meta_value = '')
                    ");
                    
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found ' . count($attachment_ids) . ' image attachments without alt text via SQL');
                    
                    // If no attachments found without alt text, get all image attachments
                    if (empty($attachment_ids)) {
                        $attachment_ids = $wpdb->get_col("
                            SELECT ID FROM {$wpdb->posts} 
                            WHERE post_type = 'attachment' 
                            AND post_mime_type LIKE 'image/%'
                        ");
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found ' . count($attachment_ids) . ' total image attachments via SQL');
                    }
                    
                    // If still no attachments found, try to find them by URL in the images_without_alt option
                    if (empty($attachment_ids)) {
                        $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Trying to find attachments from images_without_alt URLs, found ' . count($images_without_alt) . ' images');
                        
                        if ($debug_mode) {
                            echo '<p>Trying to find attachments from URLs, found ' . count($images_without_alt) . ' images</p>';
                            echo '<ul>';
                        }
                        
                        foreach ($images_without_alt as $image) {
                            if (isset($image['url']) && !empty($image['url'])) {
                                $id = $this->get_attachment_id_from_url($image['url']);
                                if ($id > 0) {
                                    $attachment_ids[] = $id;
                                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found attachment ID ' . $id . ' from URL ' . $image['url']);
                                    if ($debug_mode) {
                                        echo '<li>Found attachment ID ' . $id . ' from URL ' . esc_html($image['url']) . '</li>';
                                    }
                                } else {
                                    if ($debug_mode) {
                                        echo '<li>Could not find attachment ID for URL ' . esc_html($image['url']) . '</li>';
                                    }
                                }
                            }
                        }
                        
                        if ($debug_mode) {
                            echo '</ul>';
                        }
                        
                        // Remove duplicates
                        $attachment_ids = array_unique($attachment_ids);
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found ' . count($attachment_ids) . ' unique attachment IDs from URLs');
                    }
                    
                    // If force_lookup is set, try to get all image attachments from the database
                    if (empty($attachment_ids) || (isset($_GET['force_lookup']) && $_GET['force_lookup'] == '1')) {
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Force lookup mode activated, getting all image attachments');
                        
                        if ($debug_mode) {
                            echo '<p>Force lookup mode activated, getting all image attachments</p>';
                        }
                        
                        // Get all image attachments
                        $all_attachments = get_posts([
                            'post_type' => 'attachment',
                            'post_mime_type' => 'image',
                            'post_status' => 'inherit',
                            'posts_per_page' => -1,
                            'fields' => 'ids',
                        ]);
                        
                        $attachment_ids = array_merge($attachment_ids, $all_attachments);
                        $attachment_ids = array_unique($attachment_ids);
                        
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found ' . count($attachment_ids) . ' total image attachments with force lookup');
                        
                        if ($debug_mode) {
                            echo '<p>Found ' . count($attachment_ids) . ' total image attachments with force lookup</p>';
                            echo '<p>First 5 IDs: ' . implode(', ', array_slice($attachment_ids, 0, 5)) . '</p>';
                        }
                    }
                    
                    // Add all attachments to the list
                    foreach ($attachment_ids as $attachment_id) {
                        // Get parent post title if available
                        $parent_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d",
                            $attachment_id
                        ));
                        
                        $parent_title = 'website';
                        if ($parent_id) {
                            $parent_title = $wpdb->get_var($wpdb->prepare(
                                "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
                                $parent_id
                            ));
                        }
                        
                        if (empty($parent_title)) {
                            $parent_title = $wpdb->get_var($wpdb->prepare(
                                "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d",
                                $attachment_id
                            ));
                        }
                        
                        if (empty($parent_title)) {
                            $parent_title = 'website';
                        }
                        
                        // Add a simple placeholder alt text directly
                        $alt_text = 'Image from ' . $parent_title;
                        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                        $updated_count++;
                    }
                    
                    // Regenerate llms.txt
                    $this->generator->generate_all();
                    
                    // Output success message and exit early
                    echo '<html><body>';
                    echo '<h1>Alt Text Updated (Simple Mode)</h1>';
                    echo '<p>Added placeholder alt text to ' . $updated_count . ' images.</p>';
                    
                    if ($debug_mode) {
                        echo '<h2>Debug Information</h2>';
                        echo '<p><strong>Attachment IDs processed:</strong> ' . count($attachment_ids) . '</p>';
                        
                        // Show the first 10 IDs
                        if (!empty($attachment_ids)) {
                            echo '<p><strong>First 10 IDs:</strong> ' . implode(', ', array_slice($attachment_ids, 0, 10)) . '</p>';
                        }
                        
                        // Show the images_without_alt data
                        $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
                        echo '<p><strong>Images without alt in database:</strong> ' . count($images_without_alt) . '</p>';
                        
                        if (!empty($images_without_alt)) {
                            echo '<h3>First image data:</h3>';
                            echo '<pre>' . esc_html(print_r($images_without_alt[0], true)) . '</pre>';
                        }
                        
                        echo '<h3>WordPress Image Information</h3>';
                        echo '<p>Total attachments in database: ' . wp_count_posts('attachment')->inherit . '</p>';
                        
                        // Show some system information
                        echo '<h3>System Information</h3>';
                        echo '<p>WordPress Version: ' . get_bloginfo('version') . '</p>';
                        echo '<p>PHP Version: ' . phpversion() . '</p>';
                        
                        // Don't auto-close in debug mode
                        echo '<p><a href="#" onclick="window.opener && window.opener.location.reload(); window.close(); return false;">Close Window</a></p>';
                    } else {
                        echo '<p>You can close this window now.</p>';
                        echo '<script>window.opener && window.opener.location.reload(); setTimeout(() => window.close(), 2000);</script>';
                    }
                    
                    echo '</body></html>';
                    exit;
                }
                // If force parameter is set, also add all images from media library
                else if (isset($_GET['force']) && $_GET['force'] == '1') {
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Force parameter set, adding all images from media library');
                    
                    // Get all image attachments
                    // Make sure we include the WordPress core files
                    require_once(ABSPATH . 'wp-admin/includes/image.php');
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/media.php');
                    
                    // Use get_posts instead of WP_Query for better compatibility
                    $attachments = get_posts(array(
                        'post_type' => 'attachment',
                        'post_mime_type' => 'image',
                        'post_status' => 'inherit',
                        'posts_per_page' => -1,
                    ));
                    
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found ' . count($attachments) . ' image attachments');
                    
                    // Check each attachment for alt text
                    foreach ($attachments as $attachment) {
                        $alt_text = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
                        
                        if (empty($alt_text)) {
                            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found image without alt text: ' . $attachment->ID);
                            
                            // Get the parent post
                            $parent_id = $attachment->post_parent;
                            $parent_title = '';
                            
                            if ($parent_id) {
                                $parent = get_post($parent_id);
                                if ($parent) {
                                    $parent_title = $parent->post_title;
                                }
                            }
                            
                            if (empty($parent_title)) {
                                $parent_title = $attachment->post_title;
                            }
                            
                            // Add to images without alt text
                            $image_url = wp_get_attachment_url($attachment->ID);
                            $preview_url = wp_get_attachment_image_url($attachment->ID, 'thumbnail');
                            
                            $images_without_alt[] = [
                                'id' => $attachment->ID,
                                'url' => $image_url,
                                'type' => 'attachment',
                                'post_id' => $parent_id,
                                'post_title' => $parent_title,
                                'preview_url' => $preview_url
                            ];
                        }
                    }
                    
                    // Update the option
                    update_option('llmagnet_ai_seo_optimizer_images_without_alt', $images_without_alt);
                } else {
                    // Force regenerate to find images without alt text
                    $this->generator->generate_all();
                    // Get the images without alt text again
                    $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
                }
                
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - After regeneration: ' . print_r($images_without_alt, true));
            }
            
            // For direct requests, we'll just set a placeholder alt text
            $updated_count = 0;
            foreach ($images_without_alt as $image) {
                if (isset($image['id']) && $image['id'] > 0) {
                    // Set a placeholder alt text
                    $alt_text = 'Image from ' . $image['post_title'];
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Setting alt text for image ' . $image['id'] . ' to: ' . $alt_text);
                    update_post_meta($image['id'], '_wp_attachment_image_alt', $alt_text);
                    $updated_count++;
                }
            }
            
            // Regenerate llms.txt
            $this->generator->generate_all();
            
            // Output success message
            echo '<html><body>';
            echo '<h1>Alt Text Updated</h1>';
            echo '<p>Updated alt text for ' . $updated_count . ' images.</p>';
            
            // Debug mode
            $debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';
            if ($debug_mode) {
                echo '<h2>Debug Information</h2>';
                
                // Show the images_without_alt data
                $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
                echo '<p><strong>Images without alt in database:</strong> ' . count($images_without_alt) . '</p>';
                
                if (!empty($images_without_alt)) {
                    echo '<h3>First image data:</h3>';
                    echo '<pre>' . esc_html(print_r($images_without_alt[0], true)) . '</pre>';
                }
                
                echo '<h3>WordPress Image Information</h3>';
                echo '<p>Total attachments in database: ' . wp_count_posts('attachment')->inherit . '</p>';
                
                // Show some system information
                echo '<h3>System Information</h3>';
                echo '<p>WordPress Version: ' . get_bloginfo('version') . '</p>';
                echo '<p>PHP Version: ' . phpversion() . '</p>';
                
                // Don't auto-close in debug mode
                echo '<p><a href="#" onclick="window.opener && window.opener.location.reload(); window.close(); return false;">Close Window</a></p>';
            } else {
                echo '<p>You can close this window now.</p>';
                echo '<script>window.opener && window.opener.location.reload(); setTimeout(() => window.close(), 2000);</script>';
            }
            
            echo '</body></html>';
            
            // End the try block
            } catch (Exception $e) {
                // Get the output buffer
                $output = ob_get_clean();
                
                // Log the error
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Error: ' . $e->getMessage());
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Trace: ' . $e->getTraceAsString());
                
                // Display a user-friendly error message
                echo '<html><body>';
                echo '<h1>Error</h1>';
                echo '<p>There was an error updating the alt text: ' . esc_html($e->getMessage()) . '</p>';
                echo '<p>Please try again or contact support.</p>';
                echo '</body></html>';
            } catch (Error $e) {
                // Get the output buffer
                $output = ob_get_clean();
                
                // Log the error
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Fatal Error: ' . $e->getMessage());
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Trace: ' . $e->getTraceAsString());
                
                // Display a user-friendly error message
                echo '<html><body>';
                echo '<h1>Error</h1>';
                echo '<p>There was a fatal error updating the alt text: ' . esc_html($e->getMessage()) . '</p>';
                echo '<p>Please try again or contact support.</p>';
                echo '</body></html>';
            }
            
            // End output buffering if still active
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            
            exit;
        }
        
        // Regular AJAX request handling
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'llmagnet_ai_seo_nonce')) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'llmagnet-llm-txt-generator')]);
        }
        
        // Get data from POST - try multiple ways to get the data
        $images_data = [];
        
        // First try the JSON data
        if (isset($_POST['images_data'])) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found images_data in POST: ' . $_POST['images_data']);
            $images_data = json_decode(wp_unslash($_POST['images_data']), true);
        }
        
        // If that didn't work, try to build from individual fields
        if (empty($images_data)) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - No valid JSON data found, trying individual fields');
            
            // Look for all alt_text_ fields
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'alt_text_') === 0) {
                    $id = (int) str_replace('alt_text_', '', $key);
                    if ($id > 0) {
                        $images_data[] = [
                            'id' => $id,
                            'alt_text' => sanitize_text_field(wp_unslash($value))
                        ];
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found individual field: ' . $key . ' with value: ' . $value);
                    }
                }
            }
            
            // Also check for the old format with image_ids array
            if (empty($images_data) && isset($_POST['image_ids']) && is_array($_POST['image_ids'])) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found image_ids array with ' . count($_POST['image_ids']) . ' entries');
                foreach ($_POST['image_ids'] as $id) {
                    // Skip empty or zero IDs
                    if (empty($id) || $id === '0') {
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Skipping empty or zero ID');
                        continue;
                    }
                    
                    $alt_key = 'alt_text_' . $id;
                    if (isset($_POST[$alt_key])) {
                        $images_data[] = [
                            'id' => (int)$id,
                            'alt_text' => sanitize_text_field(wp_unslash($_POST[$alt_key]))
                        ];
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found image_ids entry: ' . $id . ' with value: ' . $_POST[$alt_key]);
                    } else {
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Found image ID ' . $id . ' but no corresponding alt_text_' . $id . ' field');
                    }
                }
            }
        }
        
        // If still empty, log an error with detailed POST data
        if (empty($images_data) || !is_array($images_data)) {
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - No valid image data found');
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - POST data: ' . print_r($_POST, true));
            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - POST keys: ' . implode(', ', array_keys($_POST)));
            
            // Try to auto-generate data from the images_without_alt option
            $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
            if (!empty($images_without_alt)) {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Attempting to auto-generate data from images_without_alt option');
                foreach ($images_without_alt as $image) {
                    // First try to get attachment ID from URL
                    $attachment_id = 0;
                    
                    if (isset($image['url']) && !empty($image['url'])) {
                        // First try WordPress's built-in function
                        $attachment_id = attachment_url_to_postid($image['url']);
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Trying attachment_url_to_postid for URL: ' . $image['url'] . ', got ID: ' . $attachment_id);
                        
                        // If that fails, try our custom function
                        if (!$attachment_id) {
                            $attachment_id = $this->get_attachment_id_from_url($image['url']);
                            $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Custom get_attachment_id_from_url for URL: ' . $image['url'] . ', got ID: ' . $attachment_id);
                        }
                    }
                    
                    // If that fails, try the provided ID
                    if (!$attachment_id && isset($image['id']) && $image['id'] > 0) {
                        $attachment_id = (int)$image['id'];
                    }
                    
                    // Only proceed if we have a valid attachment ID
                    if ($attachment_id > 0) {
                        $post_title = isset($image['post_title']) ? $image['post_title'] : 'website';
                        $images_data[] = [
                            'id' => $attachment_id,
                            'alt_text' => 'Image from ' . $post_title
                        ];
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Auto-generated data for image ID: ' . $attachment_id);
                    } else {
                        $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Could not determine valid attachment ID for image: ' . print_r($image, true));
                    }
                }
            }
            
            // If still empty after auto-generation, return error
            if (empty($images_data) || !is_array($images_data)) {
                wp_send_json_error(['message' => esc_html__('Invalid image data. Please try the direct link instead.', 'llmagnet-llm-txt-generator')]);
            } else {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Successfully auto-generated data for ' . count($images_data) . ' images');
            }
        }
        
        $updated_count = 0;
        
        // Update alt text for each image
        foreach ($images_data as $image) {
            $attachment_id = 0;
            
            // First try to use the provided ID if it's valid
            if (isset($image['id']) && $image['id'] > 0) {
                $attachment_id = (int)$image['id'];
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Using provided ID: ' . $attachment_id);
            }
            
            // If we have a URL but no valid ID, try to get the ID from the URL
            if (!$attachment_id && isset($image['url']) && !empty($image['url'])) {
                // First try WordPress's built-in function
                $attachment_id = attachment_url_to_postid($image['url']);
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - attachment_url_to_postid returned ID: ' . $attachment_id . ' for URL: ' . $image['url']);
                
                // If that fails, try our custom function
                if (!$attachment_id) {
                    $attachment_id = $this->get_attachment_id_from_url($image['url']);
                    $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Custom get_attachment_id_from_url returned ID: ' . $attachment_id . ' for URL: ' . $image['url']);
                }
            }
            
            // Only proceed if we have a valid attachment ID and alt text
            if ($attachment_id > 0 && isset($image['alt_text'])) {
                // Sanitize alt text
                $alt_text = sanitize_text_field($image['alt_text']);
                
                // Update alt text meta
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Updating alt text for attachment ID: ' . $attachment_id . ' to: ' . $alt_text);
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                $updated_count++;
            } else {
                $this->alt_text_debug_log('LLMAGNET ALT TEXT UPDATE - Skipping image update due to invalid ID or missing alt text: ' . print_r($image, true));
            }
        }
        
        // Regenerate llms.txt to include the updated alt text
        $result = $this->generator->generate_all();
        
        if ($result) {
            // Get updated images without alt text
            $images_without_alt = get_option('llmagnet_ai_seo_optimizer_images_without_alt', []);
            
            wp_send_json_success([
                'message' => sprintf(
                    esc_html__('Updated alt text for %d images and regenerated llms.txt.', 'llmagnet-llm-txt-generator'),
                    $updated_count
                ),
                'imagesWithoutAlt' => $images_without_alt,
                'imageCount' => get_option('llmagnet_ai_seo_optimizer_image_count', 0)
            ]);
        } else {
            wp_send_json_error([
                'message' => esc_html__('Error regenerating llms.txt after updating alt text.', 'llmagnet-llm-txt-generator')
            ]);
        }
    }

}
