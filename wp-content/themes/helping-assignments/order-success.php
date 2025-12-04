<?php
/* Template Name: Order Success */

get_header();

if (!isset($_GET['order_id'])) {
    echo "<div style='padding:50px;text-align:center;font-size:20px;'>Invalid Order.</div>";
    get_footer();
    exit;
}

$order_id = intval($_GET['order_id']);
$post     = get_post($order_id);

if (!$post || $post->post_type !== 'ha_order') {
    echo "<div style='padding:50px;text-align:center;font-size:20px;'>Order Not Found.</div>";
    get_footer();
    exit;
}

// Individual meta fields
$type         = get_post_meta($order_id, 'ha_type', true);
$name         = get_post_meta($order_id, 'ha_name', true);
$email        = get_post_meta($order_id, 'ha_email', true);
$phone        = get_post_meta($order_id, 'ha_phone', true);
$education    = get_post_meta($order_id, 'ha_education', true);
$subject      = get_post_meta($order_id, 'ha_subject', true);
$paperType    = get_post_meta($order_id, 'ha_paper_type', true);
$pages        = get_post_meta($order_id, 'ha_pages', true);
$wordCount    = get_post_meta($order_id, 'ha_word_count', true);
$quality      = get_post_meta($order_id, 'ha_quality', true);
$delivery     = get_post_meta($order_id, 'ha_delivery', true);
$citation     = get_post_meta($order_id, 'ha_citation', true);
$topic        = get_post_meta($order_id, 'ha_topic', true);
$instructions = get_post_meta($order_id, 'ha_instructions', true);
?>

<style>
    .order-success-page {
        background: #f3f4f6;
        padding: 60px 15px;
    }

    .order-success-card {
        max-width: 960px;
        margin: 0 auto;
        background: #ffffff;
        border-radius: 18px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
        border: 1px solid #e5e7eb;
        padding: 32px 32px 40px;
    }

    @media (max-width: 640px) {
        .order-success-card {
            padding: 24px 18px 28px;
        }
    }

    .order-success-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 8px;
    }

    .order-success-icon {
        width: 52px;
        height: 52px;
        border-radius: 999px;
        background: #ecfdf5;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        color: #16a34a;
    }

    .order-success-title {
        font-size: 28px;
        line-height: 1.2;
        font-weight: 700;
        margin: 0;
        color: #111827;
    }

    .order-success-subtitle {
        margin: 4px 0 4px;
        color: #4b5563;
        font-size: 15px;
    }

    .order-ref {
        font-size: 13px;
        color: #6b7280;
    }

    .order-summary-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 12px;
        padding: 6px 12px;
        margin-top: 10px;
        font-weight: 500;
    }

    .order-success-sections {
        margin-top: 28px;
        display: grid;
        grid-template-columns: 1.1fr 1.4fr;
        gap: 28px;
    }

    @media (max-width: 900px) {
        .order-success-sections {
            grid-template-columns: 1fr;
        }
    }

    .order-highlight-box {
        border-radius: 14px;
        background: linear-gradient(135deg, #0ea5e9, #6366f1);
        color: #eff6ff;
        padding: 18px 20px;
    }

    .order-highlight-box h3 {
        margin: 0 0 8px;
        font-size: 18px;
        font-weight: 600;
    }

    .order-highlight-box p {
        margin: 0 0 4px;
        font-size: 14px;
        opacity: 0.9;
    }

    .order-highlight-meta {
        margin-top: 14px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        font-size: 13px;
    }

    .order-highlight-label {
        opacity: 0.85;
    }

    .order-highlight-value {
        font-weight: 600;
    }

    .order-details-section {
        background: #f9fafb;
        border-radius: 14px;
        padding: 18px 20px 6px;
        border: 1px solid #e5e7eb;
    }

    .order-details-section + .order-details-section {
        margin-top: 16px;
    }

    .order-details-title {
        font-size: 15px;
        font-weight: 600;
        margin: 0 0 10px;
        color: #111827;
    }

    .order-details-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .order-details-table tr th,
    .order-details-table tr td {
        padding: 8px 4px;
        border-bottom: 1px solid #e5e7eb;
    }

    .order-details-table tr:last-child th,
    .order-details-table tr:last-child td {
        border-bottom: none;
    }

    .order-details-table th {
        width: 34%;
        text-align: left;
        font-weight: 600;
        color: #374151;
    }

    .order-details-table td {
        color: #111827;
    }

    .order-instructions-box {
        margin-top: 16px;
        border-radius: 10px;
        background: #ffffff;
        border: 1px dashed #d1d5db;
        padding: 12px 14px;
        font-size: 14px;
        color: #374151;
        max-height: 220px;
        overflow-y: auto;
    }

    .order-actions {
        margin-top: 28px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .order-btn-primary,
    .order-btn-outline {
        border-radius: 999px;
        padding: 9px 18px;
        font-size: 14px;
        border: none;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .order-btn-primary {
        background: #2563eb;
        color: #ffffff;
    }

    .order-btn-primary:hover {
        background: #1d4ed8;
    }

    .order-btn-outline {
        background: transparent;
        border: 1px solid #d1d5db;
        color: #374151;
    }

    .order-btn-outline:hover {
        background: #f3f4f6;
    }
</style>

<section class="order-success-page">
    <div class="order-success-card">

        <div class="order-success-header">
            <div class="order-success-icon">✔</div>
            <div>
                <h1 class="order-success-title">Order Submitted Successfully</h1>
                <p class="order-success-subtitle">
                    Thank you, <?php echo esc_html($name); ?>. Your order has been received.
                </p>
                <div class="order-ref">
                    Order reference: <strong>#<?php echo esc_html($order_id); ?></strong>
                </div>
                <div class="order-summary-badge">
                    <?php echo $pages ? esc_html($pages) . ' page(s)' : 'Custom length'; ?> •
                    <?php echo esc_html($paperType ?: 'Assignment'); ?> •
                    <?php echo esc_html($delivery ?: 'Standard delivery'); ?>
                </div>
            </div>
        </div>

        <div class="order-success-sections">
            <!-- Left: Highlight / Quick info -->
            <div class="order-highlight-box">
                <h3>What happens next?</h3>
                <p>One of our team members will review your order details and contact you on:</p>
                <p><strong><?php echo esc_html($email); ?></strong></p>

                <?php if (!empty($phone)) : ?>
                    <p>Phone: <strong><?php echo esc_html($phone); ?></strong></p>
                <?php endif; ?>

                <div class="order-highlight-meta">
                    <div>
                        <div class="order-highlight-label">Order Type</div>
                        <div class="order-highlight-value">
                            <?php echo ucfirst(esc_html($type)); ?>
                        </div>
                    </div>
                    <div>
                        <div class="order-highlight-label">Education Level</div>
                        <div class="order-highlight-value">
                            <?php echo esc_html($education ?: 'Not specified'); ?>
                        </div>
                    </div>
                    <div>
                        <div class="order-highlight-label">Subject</div>
                        <div class="order-highlight-value">
                            <?php echo esc_html($subject ?: 'Not specified'); ?>
                        </div>
                    </div>
                    <div>
                        <div class="order-highlight-label">Citation Style</div>
                        <div class="order-highlight-value">
                            <?php echo esc_html($citation ?: 'Not specified'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Detailed breakdown -->
            <div>
                <div class="order-details-section">
                    <h3 class="order-details-title">Personal Details</h3>
                    <table class="order-details-table">
                        <tr>
                            <th>Name</th>
                            <td><?php echo esc_html($name); ?></td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?php echo esc_html($email); ?></td>
                        </tr>
                        <tr>
                            <th>Phone</th>
                            <td><?php echo esc_html($phone); ?></td>
                        </tr>
                    </table>
                </div>

                <div class="order-details-section">
                    <h3 class="order-details-title">Academic Details</h3>
                    <table class="order-details-table">
                        <tr>
                            <th>Education Level</th>
                            <td><?php echo esc_html($education ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Subject</th>
                            <td><?php echo esc_html($subject ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Paper Type</th>
                            <td><?php echo esc_html($paperType ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Quality</th>
                            <td><?php echo esc_html($quality ?: '—'); ?></td>
                        </tr>
                    </table>
                </div>

                <div class="order-details-section">
                    <h3 class="order-details-title">Paper Requirements</h3>
                    <table class="order-details-table">
                        <tr>
                            <th>Pages</th>
                            <td><?php echo esc_html($pages ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Approx. Word Count</th>
                            <td><?php echo esc_html($wordCount ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Delivery Time</th>
                            <td><?php echo esc_html($delivery ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Citation Style</th>
                            <td><?php echo esc_html($citation ?: '—'); ?></td>
                        </tr>
                        <tr>
                            <th>Topic</th>
                            <td><?php echo esc_html($topic ?: '—'); ?></td>
                        </tr>
                    </table>

                    <?php if (!empty($instructions)) : ?>
                        <div class="order-instructions-box">
                            <?php echo nl2br(esc_html($instructions)); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="order-actions">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="order-btn-primary">
                ← Back to Home
            </a>
            <a href="mailto:<?php echo esc_attr(get_option('admin_email')); ?>" class="order-btn-outline">
                Contact Support
            </a>
        </div>
    </div>
</section>

<?php get_footer(); ?>
