<?php
/**
 * Theme Functions
 */

/* -------------------------------------------------------
 * Elementor basic support
 * ------------------------------------------------------*/
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
        return false; // in pages, elementor should not override single/archive templates
    }
    return $bool;
}, 10, 2);

/* -------------------------------------------------------
 * Email & Brand setup
 * ------------------------------------------------------*/

/**
 * Change these to match your live domain / brand.
 */
if ( ! defined( 'HA_INFO_EMAIL' ) ) {
    define( 'HA_INFO_EMAIL', 'info@onlinedissertationadvisors.co.uk' ); // TODO: confirm
}

if ( ! defined( 'HA_BRAND_NAME' ) ) {
    define( 'HA_BRAND_NAME', 'Online Dissertation Advisors' );
}

if ( ! defined( 'HA_BRAND_LOGO_URL' ) ) {
    define(
        'HA_BRAND_LOGO_URL',
        'https://onlinedissertationadvisors.co.uk/wp-content/uploads/2025/12/Online-Dissertation-Final-01-scaled.png'
    );
}

/**
 * Helper: get admin/info email
 */
function ha_get_admin_email() {
    if ( filter_var( HA_INFO_EMAIL, FILTER_VALIDATE_EMAIL ) ) {
        return HA_INFO_EMAIL;
    }
    return get_option( 'admin_email' );
}

/* -------------------------------------------------------
 * Custom Post Types: Orders & Enquiries
 * ------------------------------------------------------*/

add_action('init', 'ha_register_order_and_enquiry_cpts');
function ha_register_order_and_enquiry_cpts()
{
    // ----- ORDERS CPT -----
    $order_labels = array(
        'name'               => __('Orders', 'ha'),
        'singular_name'      => __('Order', 'ha'),
        'add_new'            => __('Add New', 'ha'),
        'add_new_item'       => __('Add New Order', 'ha'),
        'edit_item'          => __('Edit Order', 'ha'),
        'new_item'           => __('New Order', 'ha'),
        'all_items'          => __('Orders', 'ha'),
        'view_item'          => __('View Order', 'ha'),
        'search_items'       => __('Search Orders', 'ha'),
        'not_found'          => __('No orders found', 'ha'),
        'not_found_in_trash' => __('No orders found in Trash', 'ha'),
        'menu_name'          => __('Orders', 'ha'),
    );

    $order_args = array(
        'labels'        => $order_labels,
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'capability_type' => 'post',
        'hierarchical'  => false,
        'supports'      => array('title'),
        'menu_position' => 20,
        'menu_icon'     => 'dashicons-clipboard',
    );

    register_post_type('ha_order', $order_args);

    // ----- ENQUIRIES CPT -----
    $enquiry_labels = array(
        'name'               => __('Enquiries', 'ha'),
        'singular_name'      => __('Enquiry', 'ha'),
        'add_new'            => __('Add New', 'ha'),
        'add_new_item'       => __('Add New Enquiry', 'ha'),
        'edit_item'          => __('Edit Enquiry', 'ha'),
        'new_item'           => __('New Enquiry', 'ha'),
        'all_items'          => __('Enquiries', 'ha'),
        'view_item'          => __('View Enquiry', 'ha'),
        'search_items'       => __('Search Enquiries', 'ha'),
        'not_found'          => __('No enquiries found', 'ha'),
        'not_found_in_trash' => __('No enquiries found in Trash', 'ha'),
        'menu_name'          => __('Enquiries', 'ha'),
    );

    $enquiry_args = array(
        'labels'        => $enquiry_labels,
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'capability_type' => 'post',
        'hierarchical'  => false,
        'supports'      => array('title'),
        'menu_position' => 21, // just under Orders
        'menu_icon'     => 'dashicons-email',
    );

    register_post_type('ha_enquiry', $enquiry_args);
}

/* -------------------------------------------------------
 * Form handler for Orders + Enquiries
 * ------------------------------------------------------*/

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

    // Decide CPT based on type
    $post_type = ($type === 'order') ? 'ha_order' : 'ha_enquiry';

    // Title prefix
    $prefix = ($type === 'order') ? 'Order from ' : 'Enquiry from ';

    // Build post title
    $post_title = $prefix . $name . ' – ' . current_time('Y-m-d H:i');

    // ORDER PAGE FIELDS
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

    // ENQUIRY PAGE FIELDS
    $service_type = isset($_POST['service_type']) ? sanitize_text_field($_POST['service_type']) : '';
    $requirements = isset($_POST['requirements']) ? wp_kses_post($_POST['requirements'])       : '';

    // Insert custom post
    $post_id = wp_insert_post(array(
        'post_title'  => $post_title,
        'post_type'   => $post_type,
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

            // Enquiry fields
            'ha_service_type' => $service_type,
            'ha_requirements' => $requirements,
        ),
    ));

    /* ---------------- Email Notifications (HTML templates) ---------------- */

    if ( $post_id ) {

        $admin_email = ha_get_admin_email();

        // Prepare data array for templates
        $data = array(
            'post_id'      => $post_id,
            'type'         => $type,
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone,
            'education'    => $education,
            'subject'      => $subject,
            'paper_type'   => $paperType,
            'pages'        => $pages,
            'word_count'   => $wordCount,
            'quality'      => $quality,
            'delivery'     => $delivery,
            'citation'     => $citation,
            'topic'        => $topic,
            'instructions' => wp_strip_all_tags($instructions),
            'service_type' => $service_type,
            'requirements' => wp_strip_all_tags($requirements),
            'created_at'   => current_time( 'mysql' ),
            'site_url'     => site_url(),
        );

        // --- Admin notification
        $subject_admin = ($type === 'order'
            ? 'New Order Received – ' . $name
            : 'New Enquiry Received – ' . $name
        );

        $html_admin = ha_build_email_html( $type, $data, 'admin' );

        $headers_admin = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . HA_BRAND_NAME . ' <' . $admin_email . '>'
        );

        wp_mail( $admin_email, $subject_admin, $html_admin, $headers_admin );

        // --- Auto reply to customer
        if ( ! empty( $email ) ) {

            $subject_user = ($type === 'order')
                ? 'Your Order Has Been Received – ' . HA_BRAND_NAME
                : 'Your Enquiry Has Been Received – ' . HA_BRAND_NAME;

            $html_user = ha_build_email_html( $type, $data, 'customer' );

            $headers_user = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . HA_BRAND_NAME . ' <' . $admin_email . '>',
                'Reply-To: ' . $admin_email
            );

            wp_mail( $email, $subject_user, $html_user, $headers_user );
        }
    }

    /* ---------------- Redirects ---------------- */

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

        if (!empty($_POST['redirect_url'])) {
            $redirect = esc_url_raw($_POST['redirect_url']);

        } elseif ($ref = wp_get_referer()) {
            $redirect = $ref;

        } else {
            $redirect = home_url('/');
        }

        $redirect = add_query_arg('enquiry_success', '1', $redirect);
    }

    wp_safe_redirect($redirect);
    exit;
}

/* -------------------------------------------------------
 * Email HTML templates
 * ------------------------------------------------------*/

/**
 * Build HTML email for orders / enquiries.
 *
 * @param string $context       'order' or 'enquiry'
 * @param array  $data          details from form
 * @param string $recipientType 'admin' or 'customer'
 *
 * @return string HTML
 */
function ha_build_email_html( $context, $data, $recipientType = 'customer' ) {

    $is_order  = ( $context === 'order' );
    $is_admin  = ( $recipientType === 'admin' );

    $title_line = $is_order ? 'Order Details' : 'Enquiry Details';

    $intro_line = $is_order
        ? ( $is_admin
            ? 'A new order has been submitted on your website.'
            : 'Thank you for placing your order with ' . HA_BRAND_NAME . '. Below is a summary of your request.' )
        : ( $is_admin
            ? 'A new enquiry has been submitted on your website.'
            : 'Thank you for contacting ' . HA_BRAND_NAME . '. Below is a summary of your enquiry.' );

    // Short label for header
    $badge_label = $is_order ? 'Order' : 'Enquiry';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title><?php echo esc_html( $title_line ); ?></title>
    </head>
    <body style="margin:0; padding:0; background-color:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f3f4f6; padding:25px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px; background-color:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 10px 30px rgba(15,23,42,0.12);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#ffffff; border-bottom: 3px solid #facc15; padding:18px 24px;" align="left">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="left">
                                        <img src="<?php echo esc_url( HA_BRAND_LOGO_URL ); ?>" alt="<?php echo esc_attr( HA_BRAND_NAME ); ?>" style="max-width:260px; height:auto; display:block;">
                                    </td>
                                    <td align="right" style="vertical-align:middle;">
                                        <span style="display:inline-block; background-color:#facc15; color:#0b1f3b; padding:4px 12px; border-radius:999px; font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">
                                            <?php echo $is_admin ? 'Admin Copy' : $badge_label; ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Intro -->
                    <tr>
                        <td style="padding:24px 24px 10px 24px;">
                            <h1 style="margin:0 0 8px 0; font-size:20px; color:#0b1f3b; font-weight:700;">
                                <?php echo $is_order ? 'Thank you for your order' : 'Thank you for your enquiry'; ?>
                            </h1>
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#4b5563;">
                                <?php echo esc_html( $intro_line ); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Contact details -->
                    <tr>
                        <td style="padding:10px 24px 0 24px;">
                            <h2 style="margin:0 0 6px 0; font-size:15px; color:#111827;">Contact Information</h2>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 18px 24px;">
                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px; color:#374151;">
                                <tbody>
                                <tr>
                                    <td style="padding:4px 0; width:140px; font-weight:600;">Name</td>
                                    <td style="padding:4px 0;"><?php echo esc_html( $data['name'] ); ?></td>
                                </tr>
                                <?php if ( ! empty( $data['email'] ) ) : ?>
                                    <tr>
                                        <td style="padding:4px 0; font-weight:600;">Email</td>
                                        <td style="padding:4px 0;"><a href="mailto:<?php echo esc_attr( $data['email'] ); ?>" style="color:#2563eb; text-decoration:none;"><?php echo esc_html( $data['email'] ); ?></a></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ( ! empty( $data['phone'] ) ) : ?>
                                    <tr>
                                        <td style="padding:4px 0; font-weight:600;">Phone</td>
                                        <td style="padding:4px 0;"><?php echo esc_html( $data['phone'] ); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ( ! $is_admin && ! empty( $data['created_at'] ) ) : ?>
                                    <tr>
                                        <td style="padding:4px 0; font-weight:600;">Submitted At</td>
                                        <td style="padding:4px 0;"><?php echo esc_html( $data['created_at'] ); ?></td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>

                    <!-- Details -->
                    <tr>
                        <td style="padding:0 24px 6px 24px;">
                            <h2 style="margin:0 0 6px 0; font-size:15px; color:#111827;">
                                <?php echo esc_html( $title_line ); ?>
                            </h2>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 24px 18px 24px;">
                            <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; font-size:13px; color:#374151;">
                                <tbody>

                                <?php if ( $is_order ) : ?>
                                    <?php
                                    $rows = array(
                                        'Education Level' => $data['education'],
                                        'Subject Area'    => $data['subject'],
                                        'Paper Type'      => $data['paper_type'],
                                        'Pages'           => $data['pages'],
                                        'Word Count'      => $data['word_count'],
                                        'Quality Level'   => $data['quality'],
                                        'Delivery Time'   => $data['delivery'],
                                        'Citation Style'  => $data['citation'],
                                        'Topic / Title'   => $data['topic'],
                                    );
                                    foreach ( $rows as $label => $value ) :
                                        if ( $value === '' && $value !== 0 ) {
                                            continue;
                                        }
                                        ?>
                                        <tr>
                                            <td style="padding:4px 0; width:160px; font-weight:600;"><?php echo esc_html( $label ); ?></td>
                                            <td style="padding:4px 0;"><?php echo esc_html( $value ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if ( ! empty( $data['instructions'] ) ) : ?>
                                        <tr>
                                            <td style="padding:6px 0; width:160px; font-weight:600; vertical-align:top;">Instructions</td>
                                            <td style="padding:6px 0; white-space:pre-wrap;"><?php echo nl2br( esc_html( $data['instructions'] ) ); ?></td>
                                        </tr>
                                    <?php endif; ?>

                                <?php else : ?>
                                    <?php
                                    $rows = array(
                                        'Service Type' => $data['service_type'],
                                    );
                                    foreach ( $rows as $label => $value ) :
                                        if ( empty( $value ) ) {
                                            continue;
                                        }
                                        ?>
                                        <tr>
                                            <td style="padding:4px 0; width:160px; font-weight:600;"><?php echo esc_html( $label ); ?></td>
                                            <td style="padding:4px 0;"><?php echo esc_html( $value ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if ( ! empty( $data['requirements'] ) ) : ?>
                                        <tr>
                                            <td style="padding:6px 0; width:160px; font-weight:600; vertical-align:top;">Requirements</td>
                                            <td style="padding:6px 0; white-space:pre-wrap;"><?php echo nl2br( esc_html( $data['requirements'] ) ); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endif; ?>

                                </tbody>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:16px 24px 20px 24px; background-color:#f9fafb; border-top:1px solid #e5e7eb;">
                            <?php if ( ! $is_admin ) : ?>
                                <p style="margin:0 0 6px 0; font-size:13px; color:#4b5563;">
                                    Our academic team will review your request and contact you shortly via email or WhatsApp.
                                </p>
                            <?php else : ?>
                                <p style="margin:0 0 6px 0; font-size:13px; color:#4b5563;">
                                    You received this email because a visitor submitted a <?php echo esc_html( $badge_label ); ?> form on your website.
                                </p>
                            <?php endif; ?>

                            <p style="margin:0; font-size:12px; color:#9ca3af;">
                                &copy; <?php echo date('Y'); ?> <?php echo esc_html( HA_BRAND_NAME ); ?>.
                                All rights reserved.
                                <br>
                                <a href="<?php echo esc_url( $data['site_url'] ); ?>" style="color:#2563eb; text-decoration:none;">
                                    <?php echo esc_html( parse_url( $data['site_url'], PHP_URL_HOST ) ); ?>
                                </a>
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/* -------------------------------------------------------
 * Meta box: Order / Enquiry details
 * ------------------------------------------------------*/

add_action('add_meta_boxes', 'ha_add_order_details_metabox');
function ha_add_order_details_metabox()
{
    foreach ( array('ha_order', 'ha_enquiry') as $post_type ) {
        add_meta_box(
            'ha_order_details',
            'Order / Enquiry Details',
            'ha_render_order_details_metabox',
            $post_type,
            'normal',
            'high'
        );
    }
}

/**
 * Render meta box content (read-only view)
 */
function ha_render_order_details_metabox($post)
{
    // Meta fields
    $fields = [
        'Type'          => get_post_meta($post->ID, 'ha_type', true),
        'Name'          => get_post_meta($post->ID, 'ha_name', true),
        'Email'         => get_post_meta($post->ID, 'ha_email', true),
        'Phone'         => get_post_meta($post->ID, 'ha_phone', true),

        // Order fields
        'Education'     => get_post_meta($post->ID, 'ha_education', true),
        'Subject'       => get_post_meta($post->ID, 'ha_subject', true),
        'Paper Type'    => get_post_meta($post->ID, 'ha_paper_type', true),
        'Pages'         => get_post_meta($post->ID, 'ha_pages', true),
        'Word Count'    => get_post_meta($post->ID, 'ha_word_count', true),
        'Quality'       => get_post_meta($post->ID, 'ha_quality', true),
        'Delivery Time' => get_post_meta($post->ID, 'ha_delivery', true),
        'Citation'      => get_post_meta($post->ID, 'ha_citation', true),
        'Topic'         => get_post_meta($post->ID, 'ha_topic', true),
        'Instructions'  => get_post_meta($post->ID, 'ha_instructions', true),

        // Enquiry fields
        'Service Type'  => get_post_meta($post->ID, 'ha_service_type', true),
        'Requirements'  => get_post_meta($post->ID, 'ha_requirements', true),
    ];

    // WhatsApp link
    $phone_raw = isset($fields['Phone']) ? $fields['Phone'] : '';
    $whatsapp_number = preg_replace('/\D+/', '', $phone_raw);
    $whatsapp_link   = $whatsapp_number ? "https://wa.me/$whatsapp_number" : '';

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
            white-space: pre-wrap;
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

                if ($label === 'Email' && !empty($value)) {
                    $value = "<a href='mailto:" . esc_attr($value) . "'>" . esc_html($value) . "</a>";
                }

                if ($label === 'Phone' && !empty($value) && !empty($whatsapp_link)) {
                    $value = "<a href='" . esc_url($whatsapp_link) . "' target='_blank'>" . esc_html($value) . " ↗</a>";
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

/* -------------------------------------------------------
 * CSV Export: all Orders + Enquiries
 * ------------------------------------------------------*/

add_action('admin_menu', 'ha_add_export_submenu');
function ha_add_export_submenu() {
    // Submenu under Orders CPT
    add_submenu_page(
        'edit.php?post_type=ha_order',
        'Export Orders & Enquiries',
        'Export CSV',
        'manage_options',
        'ha-export-orders-enquiries',
        'ha_export_orders_enquiries_page'
    );
}

/**
 * Admin page callback – shows button & handles CSV download
 */
function ha_export_orders_enquiries_page() {
    if ( ! current_user_can('manage_options') ) {
        wp_die('You do not have permission to access this page.');
    }

    // If ?download=1, stream CSV and exit
    if ( isset($_GET['download']) && $_GET['download'] == '1' ) {
        ha_download_orders_enquiries_csv();
        exit;
    }

    ?>
    <div class="wrap">
        <h1>Export Orders &amp; Enquiries</h1>
        <p>Click the button below to download all orders and enquiries as a CSV file.</p>
        <a href="<?php echo esc_url( add_query_arg( 'download', '1' ) ); ?>" class="button button-primary">
            Download CSV
        </a>
    </div>
    <?php
}

/**
 * Actually output the CSV
 */
function ha_download_orders_enquiries_csv() {

    $filename = 'orders-enquiries-' . date('Y-m-d-His') . '.csv';

    // Headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // CSV Header row
    $header = array(
        'ID',
        'Post Type',
        'WP Date',
        'Type',
        'Name',
        'Email',
        'Phone',
        'Education',
        'Subject',
        'Paper Type',
        'Pages',
        'Word Count',
        'Quality',
        'Delivery',
        'Citation',
        'Topic',
        'Service Type',
        'Requirements',
        'Instructions'
    );
    fputcsv($output, $header);

    // Fetch all orders + enquiries
    $posts = get_posts(array(
        'post_type'      => array('ha_order', 'ha_enquiry'),
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    foreach ( $posts as $post ) {

        $type          = get_post_meta($post->ID, 'ha_type', true);
        $name          = get_post_meta($post->ID, 'ha_name', true);
        $email         = get_post_meta($post->ID, 'ha_email', true);
        $phone         = get_post_meta($post->ID, 'ha_phone', true);
        $education     = get_post_meta($post->ID, 'ha_education', true);
        $subject       = get_post_meta($post->ID, 'ha_subject', true);
        $paper_type    = get_post_meta($post->ID, 'ha_paper_type', true);
        $pages         = get_post_meta($post->ID, 'ha_pages', true);
        $word_count    = get_post_meta($post->ID, 'ha_word_count', true);
        $quality       = get_post_meta($post->ID, 'ha_quality', true);
        $delivery      = get_post_meta($post->ID, 'ha_delivery', true);
        $citation      = get_post_meta($post->ID, 'ha_citation', true);
        $topic         = get_post_meta($post->ID, 'ha_topic', true);
        $service_type  = get_post_meta($post->ID, 'ha_service_type', true);

        $requirements  = get_post_meta($post->ID, 'ha_requirements', true);
        $instructions  = get_post_meta($post->ID, 'ha_instructions', true);

        // Make long text single-line for CSV
        $requirements  = preg_replace("/\r|\n/", ' ', wp_strip_all_tags($requirements));
        $instructions  = preg_replace("/\r|\n/", ' ', wp_strip_all_tags($instructions));

        $row = array(
            $post->ID,
            $post->post_type,
            $post->post_date,
            $type,
            $name,
            $email,
            $phone,
            $education,
            $subject,
            $paper_type,
            $pages,
            $word_count,
            $quality,
            $delivery,
            $citation,
            $topic,
            $service_type,
            $requirements,
            $instructions,
        );

        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}
