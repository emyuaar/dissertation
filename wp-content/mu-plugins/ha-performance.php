<?php
/**
 * Plugin Name: HA Performance
 * Description: Lightweight front-end performance fixes for the Helping Assignments theme.
 */

defined('ABSPATH') || exit;

function ha_performance_should_bypass()
{
    if (is_admin()) {
        return true;
    }

    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
        return true;
    }

    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $request_path = $request_uri ? (string) parse_url($request_uri, PHP_URL_PATH) : '';

    if (
        stripos($request_path, '/wp-json') === 0
        || isset($_GET['rest_route'])
    ) {
        return true;
    }

    if (
        isset($_GET['elementor-preview'])
        || isset($_GET['elementor_library'])
        || isset($_GET['preview'])
    ) {
        return true;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'elementor') {
        return true;
    }

    if (
        isset($_GET['customize_changeset_uuid'])
        || isset($_GET['customize_theme'])
        || isset($_GET['customize_messenger_channel'])
        || (isset($_GET['wp_customize']) && $_GET['wp_customize'] === 'on')
        || (function_exists('is_customize_preview') && is_customize_preview())
    ) {
        return true;
    }

    if (
        stripos($request_uri, 'elementor') !== false
        && (
            stripos($request_uri, 'wp-admin') !== false
            || stripos($request_uri, 'elementor-preview') !== false
            || stripos($request_uri, 'elementor_library') !== false
        )
    ) {
        return true;
    }

    if (
        function_exists('is_user_logged_in')
        && is_user_logged_in()
        && function_exists('current_user_can')
        && (
            current_user_can('manage_options')
            || current_user_can('edit_pages')
            || current_user_can('edit_posts')
        )
    ) {
        return true;
    }

    return false;
}

if (ha_performance_should_bypass()) {
    return;
}

/**
 * Avoid loading ElementsKit's global bundle when the current Elementor document
 * contains no ElementsKit widgets.
 */
function ha_page_uses_elementskit()
{
    $post_id = get_queried_object_id();
    if (!$post_id) {
        return false;
    }

    $elementor_data = (string) get_post_meta($post_id, '_elementor_data', true);

    return strpos($elementor_data, 'elementskit-') !== false
        || strpos($elementor_data, '"ekit') !== false;
}

function ha_remove_unused_builder_assets()
{
    if (is_admin() || ha_page_uses_elementskit()) {
        return;
    }

    $styles = array(
        'ekit-widget-styles',
        'ekit-responsive',
        'elementor-icons-ekiticons',
        'elementskit-rtl',
    );
    $scripts = array(
        'elementskit-framework-js-frontend',
        'ekit-widget-scripts',
        'animate-circle',
        'elementskit-elementor',
    );

    foreach ($styles as $handle) {
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
    }

    foreach ($scripts as $handle) {
        wp_dequeue_script($handle);
        wp_deregister_script($handle);
    }

    $post_id = get_queried_object_id();
    $elementor_data = $post_id ? (string) get_post_meta($post_id, '_elementor_data', true) : '';
    $only_html_widgets = $elementor_data
        && strpos($elementor_data, '"widgetType":"html"') !== false
        && !preg_match('/"widgetType":"(?!html")[^"]+"/', $elementor_data);

    if ($only_html_widgets) {
        foreach (array(
            'elementor-webpack-runtime',
            'elementor-frontend-modules',
            'jquery-ui-core',
            'elementor-frontend',
            'jquery-migrate',
            'jquery-core',
            'jquery',
        ) as $handle) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }
}

add_action('wp_enqueue_scripts', 'ha_remove_unused_builder_assets', 999);
add_action('wp_print_styles', 'ha_remove_unused_builder_assets', 999);
add_action('wp_print_footer_scripts', 'ha_remove_unused_builder_assets', 0);

/**
 * Use WordPress-generated thumbnails for the small university logo strip and
 * reserve image space to prevent layout shifts.
 */
add_filter('the_content', function ($content) {
    if (!is_front_page()) {
        return $content;
    }

    $images = array(
        'University_of_Cambridge-Logo.wine_-scaled.png' => array('University_of_Cambridge-Logo.wine_-300x200.png', 300, 200),
        'University-Of-Leeds-Logo-Vector.svg-.png' => array('University-Of-Leeds-Logo-Vector.svg--300x86.png', 300, 86),
        'Newcastle-University-Logo-scaled.png' => array('Newcastle-University-Logo-300x169.png', 300, 169),
        'Northumbria_University_Logo.png' => array('Northumbria_University_Logo-300x95.png', 300, 95),
        'university-of-sheffield-logo-png_seeklogo-146019.png' => array('university-of-sheffield-logo-png_seeklogo-146019-150x150.png', 150, 150),
        'Imperial_College_London_crest.svg_.png' => array('Imperial_College_London_crest.svg_-150x150.png', 150, 150),
        'Logo_for_Imperial_College_London.svg_.png' => array('Logo_for_Imperial_College_London.svg_-300x79.png', 300, 79),
        'Oxford-University-Circlet.svg_.png' => array('Oxford-University-Circlet.svg_-150x150.png', 150, 150),
    );

    foreach ($images as $original => $replacement) {
        $content = str_replace($original, $replacement[0], $content);
        $pattern = '/(<img\b[^>]*' . preg_quote($replacement[0], '/') . '[^>]*)(>)/i';
        $content = preg_replace_callback($pattern, function ($matches) use ($replacement) {
            $tag = $matches[1];
            if (stripos($tag, ' width=') === false) {
                $tag .= ' width="' . (int) $replacement[1] . '"';
            }
            if (stripos($tag, ' height=') === false) {
                $tag .= ' height="' . (int) $replacement[2] . '"';
            }
            if (stripos($tag, ' loading=') === false) {
                $tag .= ' loading="lazy"';
            }
            if (stripos($tag, ' decoding=') === false) {
                $tag .= ' decoding="async"';
            }
            return $tag . $matches[2];
        }, $content);
    }

    // Font Awesome was included only for footer social icons, which are now text.
    $content = preg_replace(
        '#<link[^>]+cdnjs\.cloudflare\.com/ajax/libs/font-awesome/[^>]+>#i',
        '',
        $content
    );

    // Keep repeated destinations consistently named for assistive technology.
    $content = preg_replace(
        '#<a(?![^>]*\baria-label=)([^>]*\bhref=(["\'])[^"\']*/order/?\2[^>]*)>#i',
        '<a aria-label="Order academic writing support"$1>',
        $content
    );
    $content = preg_replace(
        '#<a(?![^>]*\baria-label=)([^>]*\bhref=(["\'])tel:[^"\']+\2[^>]*)>#i',
        '<a aria-label="Call Online Dissertation Advisors"$1>',
        $content
    );
    $content = preg_replace(
        '#<a(?![^>]*\baria-label=)([^>]*\bhref=(["\'])mailto:[^"\']+\2[^>]*)>#i',
        '<a aria-label="Email Online Dissertation Advisors"$1>',
        $content
    );
    $content = preg_replace(
        '#<a(?![^>]*\baria-label=)([^>]*\bclass=(["\'])[^"\']*\bdp4-value\b[^"\']*\2[^>]*\bhref=(["\'])\#\3[^>]*)>#i',
        '<a aria-label="Open live chat"$1>',
        $content
    );

    // Correct the one known heading skip inside the long-form sidebar.
    $content = str_replace(
        array('<h4>Still Unsure?</h4>', '<h4 class="ha-side-card-title">Still Unsure?</h4>'),
        array('<h3>Still Unsure?</h3>', '<h3 class="ha-side-card-title">Still Unsure?</h3>'),
        $content
    );

    return $content;
}, 20);

/**
 * Remove the legacy emoji payload; native emoji rendering is sufficient here.
 */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
});

/**
 * Delay analytics and live chat until after the page is usable.
 */
add_action('wp_footer', function () {
    global $tawkto;

    if (isset($tawkto) && is_object($tawkto)) {
        remove_action('wp_footer', array($tawkto, 'print_embed_code'));
    }
}, 0);

add_action('wp_footer', function () {
    $tawk_page = get_option('tawkto-embed-widget-page-id');
    $tawk_widget = get_option('tawkto-embed-widget-widget-id');
    ?>
    <script>
    (function () {
        function afterLoad(callback, delay) {
            if (document.readyState === 'complete') {
                window.setTimeout(callback, delay);
            } else {
                window.addEventListener('load', function () {
                    window.setTimeout(callback, delay);
                }, { once: true });
            }
        }

        afterLoad(function () {
            !function(f,b,e,v,n,t,s) {
                if (f.fbq) return;
                n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];
                t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t,s);
            }(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '1182261844043022');
            fbq('track', 'PageView');
        }, 2500);
    }());
    </script>
    <?php if ($tawk_page && $tawk_widget) : ?>
    <script>
    (function () {
        var loaded = false;
        function loadChat() {
            if (loaded) return;
            loaded = true;
            window.Tawk_API = window.Tawk_API || {};
            window.Tawk_LoadStart = new Date();
            var script = document.createElement('script');
            script.async = true;
            script.src = <?php echo wp_json_encode('https://embed.tawk.to/' . $tawk_page . '/' . $tawk_widget); ?>;
            script.charset = 'UTF-8';
            script.setAttribute('crossorigin', '*');
            document.head.appendChild(script);
        }
        ['pointerdown', 'touchstart', 'keydown', 'scroll'].forEach(function (eventName) {
            window.addEventListener(eventName, loadChat, { once: true, passive: true });
        });
        window.addEventListener('load', function () {
            window.setTimeout(loadChat, 8000);
        }, { once: true });
    }());
    </script>
    <?php endif;
}, 90);

function ha_purge_page_cache()
{
    $directory = WP_CONTENT_DIR . '/cache/ha-page-cache';
    if (!is_dir($directory)) {
        return;
    }

    foreach ((array) glob($directory . '/*.html') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

add_action('save_post', 'ha_purge_page_cache');
add_action('deleted_post', 'ha_purge_page_cache');
add_action('switch_theme', 'ha_purge_page_cache');
add_action('customize_save_after', 'ha_purge_page_cache');
add_action('activated_plugin', 'ha_purge_page_cache');
add_action('deactivated_plugin', 'ha_purge_page_cache');
add_action('upgrader_process_complete', 'ha_purge_page_cache');
