<?php
/**
 * Settings Sanitizer class
 *
 * Single canonical sanitizer for the main plugin settings option
 * (Generator::OPTION_NAME / llmagnet_ai_seo_optimizer_settings), used by
 * every write path: the Settings API register_setting() callback and the
 * llmagnet_ai_seo_save_settings AJAX endpoint (improvement plan P2-1.5).
 * Logic is moved verbatim from Admin::sanitize_settings() — no option
 * renames, no semantic changes to accepted values.
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical sanitization for the llmagnet_ai_seo_optimizer_settings option
 */
class Settings_Sanitizer {

    /**
     * Sanitize settings
     *
     * @param array $input Settings input
     * @return array
     */
    public static function sanitize($input) {
        $sanitized = [];

        // Sanitize post types
        $sanitized['post_types'] = isset($input['post_types']) && is_array($input['post_types'])
            ? array_map('sanitize_text_field', $input['post_types'])
            : ['post', 'page'];

        // Sanitize full content checkbox
        $sanitized['full_content'] = isset($input['full_content']) ? (bool) $input['full_content'] : true;

        // Sanitize days to include
        $sanitized['days_to_include'] = isset($input['days_to_include'])
            ? absint($input['days_to_include'])
            : 365;

        // Sanitize delete on uninstall
        $sanitized['delete_on_uninstall'] = isset($input['delete_on_uninstall'])
            ? (bool) $input['delete_on_uninstall']
            : false;

        // Sanitize report email
        if (isset($input['report_email'])) {
            $email = sanitize_email($input['report_email']);
            if (!empty($email)) {
                $sanitized['report_email'] = $email;
            }
        }

        // Sanitize image settings (premium feature)
        if (Admin_WP_Helper::is_premium_user()) {
            // Handle include_images toggle
            $sanitized['include_images'] = isset($input['include_images'])
                ? (bool) $input['include_images']
                : false;

            // Handle new multiple images format
            if (isset($input['llm_response_images']) && is_array($input['llm_response_images'])) {
                $sanitized_images = [];
                foreach ($input['llm_response_images'] as $image) {
                    if (is_array($image) && isset($image['id']) && isset($image['position'])) {
                        $sanitized_images[] = [
                            'id' => absint($image['id']),
                            'position' => in_array($image['position'], ['before', 'after']) ? $image['position'] : 'after'
                        ];
                    }
                }
                $sanitized['llm_response_images'] = $sanitized_images;
            }

            // Keep backward compatibility with single image format
            $sanitized['llm_response_image_id'] = isset($input['llm_response_image_id'])
                ? absint($input['llm_response_image_id'])
                : 0;
            $sanitized['llm_response_image_position'] = isset($input['llm_response_image_position'])
                ? sanitize_text_field($input['llm_response_image_position'])
                : 'after';
        }

        // Sanitize LLMs.txt enhancement settings
        $sanitized['llms_txt_custom_intro'] = isset($input['llms_txt_custom_intro'])
            ? sanitize_textarea_field($input['llms_txt_custom_intro'])
            : '';

        $sanitized['llms_txt_show_excerpts'] = isset($input['llms_txt_show_excerpts'])
            ? (bool) $input['llms_txt_show_excerpts']
            : true;

        $sanitized['llms_txt_excerpt_length'] = isset($input['llms_txt_excerpt_length'])
            ? absint($input['llms_txt_excerpt_length'])
            : 150;

        $sanitized['llms_txt_max_per_section'] = isset($input['llms_txt_max_per_section'])
            ? absint($input['llms_txt_max_per_section'])
            : 50;

        $sanitized['llms_txt_show_prices'] = isset($input['llms_txt_show_prices'])
            ? (bool) $input['llms_txt_show_prices']
            : true;

        $sanitized['llms_txt_group_by_type'] = isset($input['llms_txt_group_by_type'])
            ? (bool) $input['llms_txt_group_by_type']
            : true;

        $sanitized['llms_txt_show_author'] = isset($input['llms_txt_show_author'])
            ? (bool) $input['llms_txt_show_author']
            : true;

        // LLMs-full.txt settings (Pro+ only)
        $sanitized['generate_full'] = isset($input['generate_full'])
            ? (bool) $input['generate_full']
            : true;

        $sanitized['llms_full_max_posts'] = isset($input['llms_full_max_posts'])
            ? absint($input['llms_full_max_posts'])
            : 200;

        return $sanitized;
    }
}
