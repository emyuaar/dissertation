<?php
/**
 * Plugin Name: ODA Blog Archive Fix (MU)
 * Description: Ensures the /blog URL resolves to a real Posts page (not a 404 context) for SEO and proper routing.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Create/restore the Blog posts page and assign it as "Posts page" (Settings → Reading).
 *
 * Why:
 * - If `page_for_posts` is unset, `/blog` is treated like a normal page route; if the page doesn't exist it becomes 404.
 * - Some themes "include" a template for `/blog` while WP still thinks it's 404, causing Yoast to output noindex + 404 title.
 */
function oda_ensure_blog_posts_page(): void
{
    // Prevent concurrent requests from creating duplicate pages.
    if (get_transient('oda_blog_fix_lock')) {
        return;
    }
    set_transient('oda_blog_fix_lock', '1', 5 * MINUTE_IN_SECONDS);

    // Avoid doing this during installs, cron, or AJAX.
    if (defined('WP_INSTALLING') && WP_INSTALLING) {
        delete_transient('oda_blog_fix_lock');
        return;
    }
    if (wp_doing_cron() || wp_doing_ajax()) {
        delete_transient('oda_blog_fix_lock');
        return;
    }

    $show_on_front = (string) get_option('show_on_front');
    if ($show_on_front !== 'page') {
        // The site isn't configured for a static homepage, so a "Posts page" is not used by core.
        // Do not mutate Reading settings in that mode.
        delete_transient('oda_blog_fix_lock');
        return;
    }

    $changed = false;

    $blog_pages = get_posts(
        array(
            'post_type' => 'page',
            'name' => 'blog',
            'post_status' => array('publish', 'draft', 'private'),
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        )
    );

    $blog_page = !empty($blog_pages) && $blog_pages[0] instanceof WP_Post ? $blog_pages[0] : null;

    // Defensive: if duplicates exist, de-conflict them so /blog resolves deterministically.
    if (is_array($blog_pages) && count($blog_pages) > 1 && $blog_page instanceof WP_Post) {
        foreach (array_slice($blog_pages, 1) as $duplicate) {
            if (!$duplicate instanceof WP_Post) {
                continue;
            }
            wp_update_post(
                array(
                    'ID' => (int) $duplicate->ID,
                    'post_name' => 'blog-duplicate-' . (int) $duplicate->ID,
                    'post_status' => 'draft',
                )
            );
            $changed = true;
        }
    }

    if (!$blog_page instanceof WP_Post) {
        $new_page_id = wp_insert_post(
            array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'Blog',
                'post_name' => 'blog',
                'post_content' => '',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ),
            true
        );

        if (!is_wp_error($new_page_id) && $new_page_id) {
            $blog_page = get_post((int) $new_page_id);
            $changed = true;
        } else {
            // Failed to create a page; bail without changing options.
            delete_transient('oda_blog_fix_lock');
            return;
        }
    } else {
        // If it exists but isn't published, publish it so /blog doesn't 404 for visitors.
        if ($blog_page->post_status !== 'publish') {
            wp_update_post(
                array(
                    'ID' => (int) $blog_page->ID,
                    'post_status' => 'publish',
                )
            );
            $changed = true;
        }
    }

    if ($blog_page instanceof WP_Post && (int) get_option('page_for_posts') !== (int) $blog_page->ID) {
        update_option('page_for_posts', (int) $blog_page->ID);
        $changed = true;
    }

    // Rewrite flush is only needed once after changing posts-page routing; avoid repeated flushes.
    if ($changed && get_option('oda_blog_fix_rewrites_flushed') !== '1') {
        flush_rewrite_rules(false);
        update_option('oda_blog_fix_rewrites_flushed', '1');
    }

    delete_transient('oda_blog_fix_lock');
}

add_action('wp_loaded', 'oda_ensure_blog_posts_page', 1);
