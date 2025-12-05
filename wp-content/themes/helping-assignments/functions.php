<?php
/**
 * Theme Functions
 */
function ha_elementor_support() {
    add_theme_support('elementor');
    add_theme_support('elementor-posts-css');
    add_theme_support('widgets');
}
add_action('after_setup_theme', 'ha_elementor_support');

add_filter('page_template', function($template) {
    if (is_page() && get_page_template_slug() == '') {
        return get_theme_file_path('page-elementor.php');
    }
    return $template;
});

/**
 * Tell Elementor not to override some theme locations
 */
add_filter( 'elementor_theme_do_location', function( $bool, $location ) {
    if ( 'single' === $location || 'archive' === $location ) {
        return false; // in pages, elementor should not override single/ archive templates
    }
    return $bool;
}, 10, 2 );

/**
 * 1) Register "Orders" custom post type
 *    (If you already have a CPT for orders, remove this section
 *    and keep only the handler functions below.)
 */

add_action('init', 'ha_register_order_cpt');
function ha_register_order_cpt()
{

    $labels = array(
        'name' => __('Orders', 'ha'),
        'singular_name' => __('Order', 'ha'),
        'add_new' => __('Add New', 'ha'),
        'add_new_item' => __('Add New Order', 'ha'),
        'edit_item' => __('Edit Order', 'ha'),
        'new_item' => __('New Order', 'ha'),
        'all_items' => __('Orders', 'ha'),
        'view_item' => __('View Order', 'ha'),
        'search_items' => __('Search Orders', 'ha'),
        'not_found' => __('No orders found', 'ha'),
        'not_found_in_trash' => __('No orders found in Trash', 'ha'),
        'menu_name' => __('Orders', 'ha'),
    );

    $args = array(
        'labels' => $labels,
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'capability_type' => 'post',
        'hierarchical' => false,
        'supports' => array('title'),
        'menu_position' => 20,
        'menu_icon' => 'dashicons-clipboard',
    );

    register_post_type('ha_order', $args);
}

/**
 * 2) Handle both Enquiry + Order forms
 *
 * Forms use:
 *   action="ha_submit_order"
 *   hidden field "ha_type" = "order" or "enquiry"
 */
add_action('admin_post_nopriv_ha_submit_order', 'ha_handle_submit_order');
add_action('admin_post_ha_submit_order', 'ha_handle_submit_order');

function ha_handle_submit_order()
{
    // Basic validation
    if (!isset($_POST['name'])) {
        wp_die('Invalid submission.');
    }

    // Common fields
    $name  = sanitize_text_field($_POST['name']);
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';

    // Determine type: enquiry or order
    $type = isset($_POST['ha_type']) ? sanitize_text_field($_POST['ha_type']) : 'enquiry';

    // Title prefix
    $prefix = ($type === 'order') ? 'Order from ' : 'Enquiry from ';

    // Build post title
    $post_title = $prefix . $name . ' – ' . current_time('Y-m-d H:i');

    /**
     * Extra fields – ORDER PAGE FIELDS (already in your old code)
     */
    $education   = isset($_POST['education'])   ? sanitize_text_field($_POST['education'])   : '';
    $subject     = isset($_POST['subject'])     ? sanitize_text_field($_POST['subject'])     : '';
    $paperType   = isset($_POST['paperType'])   ? sanitize_text_field($_POST['paperType'])   : '';
    $pages       = isset($_POST['pages'])       ? intval($_POST['pages'])                    : '';
    $wordCount   = isset($_POST['wordCount'])   ? sanitize_text_field($_POST['wordCount'])   : '';
    $quality     = isset($_POST['quality'])     ? sanitize_text_field($_POST['quality'])     : '';
    $delivery    = isset($_POST['delivery'])    ? sanitize_text_field($_POST['delivery'])    : '';
    $citation    = isset($_POST['citation'])    ? sanitize_text_field($_POST['citation'])    : '';
    $topic       = isset($_POST['topic'])       ? sanitize_text_field($_POST['topic'])       : '';
    $instructions = isset($_POST['instructions']) ? wp_kses_post($_POST['instructions'])     : '';

    /**
     * Extra fields – ENQUIRY PAGE FIELDS (NEW)
     */
    $service_type = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : '';
    $requirements = isset($_POST['requirements']) ? wp_kses_post($_POST['requirements'])       : '';

    // Insert custom post
    $post_id = wp_insert_post(array(
        'post_title'  => $post_title,
        'post_type'   => 'ha_order',
        'post_status' => 'publish',
        'meta_input'  => array(
            'ha_type'         => $type,      // enquiry / order
            'ha_name'         => $name,
            'ha_email'        => $email,
            'ha_phone'        => $phone,

            // Order fields
            'ha_education'    => $education,
            'ha_subject'      => $subject,
            'ha_paper_type'   => $paperType,
            'ha_pages'        => $pages,
            'ha_word_count'   => $wordCount,
            'ha_quality'      => $quality,
            'ha_delivery'     => $delivery,
            'ha_citation'     => $citation,
            'ha_topic'        => $topic,
            'ha_instructions' => $instructions,

            // Enquiry fields (NEW)
            'ha_service_type' => $service_type,
            'ha_requirements' => $requirements,
        ),
    ));

    // Optional: send email notification to admin
    if ($post_id && !empty($email)) {
        $admin_email = get_option('admin_email');

        $subject_mail = ($type === 'order' ? 'New Order: ' : 'New Enquiry: ') . $name;

        $message  = "Type: {$type}\n";
        $message .= "Name: {$name}\n";
        $message .= "Email: {$email}\n";
        $message .= "Phone: {$phone}\n\n";

        if ($type === 'order') {
            // Order detailed email
            $message .= "Education: {$education}\n";
            $message .= "Subject: {$subject}\n";
            $message .= "Paper Type: {$paperType}\n";
            $message .= "Pages: {$pages}\n";
            $message .= "Word Count: {$wordCount}\n";
            $message .= "Quality: {$quality}\n";
            $message .= "Delivery: {$delivery}\n";
            $message .= "Citation: {$citation}\n";
            $message .= "Topic: {$topic}\n\n";
            $message .= "Instructions:\n{$instructions}\n";
        } else {
            // Enquiry simple email
            $message .= "Service Type: {$service_type}\n\n";
            $message .= "Requirements:\n{$requirements}\n";
        }

        wp_mail($admin_email, $subject_mail, $message);
    }

    // Redirect based on type
    if ($type === 'order') {

        // Try to get the page with slug "order-success"
        $success_page = get_page_by_path('order-success');

        if ($success_page) {
            $redirect = add_query_arg(
                'order_id',
                $post_id,
                get_permalink($success_page->ID)
            );
        } else {
            // Fallback: home if page not found
            $redirect = home_url('/');
        }

    } else {

        // Enquiry form – redirect back to same page

        if ( ! empty( $_POST['redirect_url'] ) ) {
            $redirect = esc_url_raw( $_POST['redirect_url'] );

        } elseif ( $ref = wp_get_referer() ) {
            $redirect = $ref;

        } else {
            $redirect = home_url( '/' );
        }

        $redirect = add_query_arg( 'enquiry_success', '1', $redirect );
    }

    wp_safe_redirect($redirect);
    exit;
}

/**
 * Add meta box for showing order / enquiry details
 */
add_action('add_meta_boxes', 'ha_add_order_details_metabox');
function ha_add_order_details_metabox()
{
    add_meta_box(
        'ha_order_details',
        'Order / Enquiry Details',
        'ha_render_order_details_metabox',
        'ha_order',
        'normal',
        'high'
    );
}

/**
 * Render meta box content (read-only view)
 */
function ha_render_order_details_metabox($post)
{
    // Meta fields
    $fields = [
        'Type' => get_post_meta($post->ID, 'ha_type', true),
        'Name' => get_post_meta($post->ID, 'ha_name', true),
        'Email' => get_post_meta($post->ID, 'ha_email', true),
        'Phone' => get_post_meta($post->ID, 'ha_phone', true),
        'Education' => get_post_meta($post->ID, 'ha_education', true),
        'Subject' => get_post_meta($post->ID, 'ha_subject', true),
        'Paper Type' => get_post_meta($post->ID, 'ha_paper_type', true),
        'Pages' => get_post_meta($post->ID, 'ha_pages', true),
        'Word Count' => get_post_meta($post->ID, 'ha_word_count', true),
        'Quality' => get_post_meta($post->ID, 'ha_quality', true),
        'Delivery Time' => get_post_meta($post->ID, 'ha_delivery', true),
        'Citation' => get_post_meta($post->ID, 'ha_citation', true),
        'Topic' => get_post_meta($post->ID, 'ha_topic', true),
        'Instructions' => get_post_meta($post->ID, 'ha_instructions', true),
    ];

    // WhatsApp link
    $whatsapp_number = preg_replace('/\D+/', '', $fields['Phone']);
    $whatsapp_link = "https://wa.me/$whatsapp_number";

    ?>

    <style>
        .ha-meta-box {
            padding: 15px;
            background: #f8fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .ha-meta-grid {
            display: grid;
            grid-template-columns: 180px auto;
            row-gap: 12px;
            column-gap: 20px;
        }

        .ha-label {
            font-weight: 600;
            color: #334155;
        }

        .ha-value {
            background: #ffffff;
            border-radius: 6px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }

        .ha-value a {
            text-decoration: none;
            color: #0ea5e9;
            font-weight: 600;
        }

        .ha-footnote {
            margin-top: 15px;
            font-style: italic;
            font-size: 13px;
            color: #64748b;
        }
    </style>

    <div class="ha-meta-box">
        <div class="ha-meta-grid">

            <?php foreach ($fields as $label => $value):

                // hyperlink email
                if ($label === "Email" && !empty($value)) {
                    $value = "<a href='mailto:$value'>$value</a>";
                }

                // hyperlink WhatsApp
                if ($label === "Phone" && !empty($value)) {
                    $value = "<a href='$whatsapp_link' target='_blank'>$value ↗</a>";
                }

                ?>

                <div class="ha-label"><?php echo esc_html($label); ?></div>
                <div class="ha-value"><?php echo wp_kses_post(nl2br($value)); ?></div>

            <?php endforeach; ?>

        </div>

        <div class="ha-footnote">
            This box is read-only. All values are saved from the front-end forms.
        </div>
    </div>

    <?php
}
