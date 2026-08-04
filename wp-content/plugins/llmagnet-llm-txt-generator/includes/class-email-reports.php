<?php
/**
 * Email Reports class for sending monthly LLM bot analytics reports
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Email Reports class for sending monthly LLM bot analytics reports
 */
class Email_Reports {
    /**
     * Analytics instance
     *
     * @var Analytics
     */
    private $analytics;

    /**
     * Constructor
     *
     * @param Analytics|null $analytics Analytics instance (optional)
     */
    public function __construct(Analytics $analytics = null) {
        $this->analytics = $analytics;
        
        // Register cron action
        add_action('llmagnet_weekly_analytics_report', [$this, 'send_weekly_report']);
    }

    /**
     * Send weekly analytics report
     *
     * @return bool True if email was sent successfully, false otherwise
     */
    public function send_weekly_report() {
        return $this->send_scheduled_report();
    }

    /**
     * Bots allowed for Pro plan (only ChatGPT, Claude, Perplexity)
     */
    private $pro_allowed_bots = ['ChatGPT', 'Claude', 'Perplexity'];

    /**
     * All supported bots in the system
     */
    private $all_supported_bots = [
        'ChatGPT', 'Claude', 'Perplexity', 'Gemini', 'Grok',
        'Bing', 'Llama', 'Mistral', 'DeepSeek', 'Other LLM'
    ];

    /**
     * Whether scheduled analytics email reports may run (cron + wp_mail).
     * Free plan, ended trial, and cancelled/expired licenses are excluded even if recipients are saved.
     *
     * @return bool
     */
    public static function can_send_scheduled_email_reports() {
        if (!function_exists('lltg_fs')) {
            return false;
        }
        $fs = lltg_fs();
        if (!$fs) {
            return false;
        }

        return $fs->can_use_premium_code();
    }

    /**
     * Get user's current plan info
     * Uses the same logic as Admin::get_plan_data() for consistency
     *
     * @return array Plan info with name, is_paying, is_trial
     */
    private function get_user_plan() {
        $plan_info = [
            'plan_name' => 'free',
            'is_paying' => false,
            'is_trial' => false,
            'is_plus_or_higher' => false,
            'is_enterprise' => false,
        ];

        if (function_exists('lltg_fs')) {
            $fs = lltg_fs();
            if (!$fs) {
                return $plan_info;
            }

            // Determine plan name using is_plan() checks (same as Admin class)
            if ($fs->is_plan('enterprise')) {
                $plan_info['plan_name'] = 'enterprise';
            } elseif ($fs->is_plan('plus')) {
                $plan_info['plan_name'] = 'plus';
            } elseif ($fs->is_plan('pro')) {
                $plan_info['plan_name'] = 'pro';
            } else {
                $plan_info['plan_name'] = 'free';
            }
            
            $plan_info['is_paying'] = $fs->is_paying();
            $plan_info['is_trial'] = $fs->is_trial();
            $plan_info['is_enterprise'] = ($plan_info['plan_name'] === 'enterprise');
            $plan_info['is_plus_or_higher'] = in_array($plan_info['plan_name'], ['plus', 'enterprise']);
        }

        return $plan_info;
    }

    /**
     * Filter bot stats based on user's plan
     *
     * @param array $stats Array of bot stats
     * @param array $plan_info User's plan info
     * @return array Filtered stats with restricted bots combined into "Other LLM Bots"
     */
    private function filter_bots_by_plan($stats, $plan_info) {
        if (empty($stats)) {
            return $stats;
        }

        // Plus and Enterprise users see all bots
        if ($plan_info['is_plus_or_higher']) {
            return $stats;
        }

        // Pro plan users: only see ChatGPT, Claude, Perplexity
        // All other bots are combined into "Other LLM Bots"
        $filtered_stats = [];
        $other_bots_visits = 0;

        foreach ($stats as $stat) {
            $bot_name = $stat['bot_name'];
            
            if (in_array($bot_name, $this->pro_allowed_bots)) {
                // Allowed bot - include as-is
                $filtered_stats[] = $stat;
            } else {
                // Restricted bot - accumulate visits for "Other LLM Bots"
                $other_bots_visits += intval($stat['visits']);
            }
        }

        // Add combined "Other LLM Bots" entry if there are any
        if ($other_bots_visits > 0) {
            $filtered_stats[] = [
                'bot_name' => 'Other LLM Bots',
                'visits' => $other_bots_visits,
            ];
        }

        return $filtered_stats;
    }

    /**
     * Send scheduled analytics report
     *
     * @return bool True if email was sent successfully, false otherwise
     */
    public function send_scheduled_report() {
        if (!self::can_send_scheduled_email_reports()) {
            return false;
        }

        // Get recipient emails (can be multiple, comma-separated)
        $recipients = $this->get_recipient_emails();
        
        if (empty($recipients)) {
            \llmagnet_aiseo_debug_log('LLMagnet: No recipients configured for email reports');
            return false;
        }
        
        // Get user's plan info
        $plan_info = $this->get_user_plan();
        
        // Get report data
        $report_data = $this->get_report_data();
        
        // Filter bot stats based on user's plan
        $report_data['weekly_stats'] = $this->filter_bots_by_plan($report_data['weekly_stats'], $plan_info);
        $report_data['all_time_stats'] = $this->filter_bots_by_plan($report_data['all_time_stats'], $plan_info);
        
        // Get selected template
        $template = get_option('llmagnet_report_template', 'classic');
        
        // Get company logo (all paying users)
        $company_logo = get_option('llmagnet_report_company_logo', null);
        if ($company_logo && is_array($company_logo) && !empty($company_logo['url'])) {
            $report_data['company_logo'] = $company_logo['url'];
        }
        
        // Get frequency for subject line
        $frequency = get_option('llmagnet_report_frequency', 'weekly');
        $frequency_label = ucfirst($frequency);
        
        // Generate email content
        $subject = sprintf(
            __('[%s] %s LLM Bot Analytics Report - %s', 'llmagnet-llm-txt-generator'),
            get_bloginfo('name'),
            $frequency_label,
            date_i18n('M j, Y')
        );
        
        $content = $this->generate_email_content($report_data, $template);
        
        // Send email to all recipients
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $all_sent = true;
        
        foreach ($recipients as $recipient) {
            $sent = wp_mail($recipient, $subject, $content, $headers);
            
            if ($sent) {
                \llmagnet_aiseo_debug_log('LLMagnet analytics report sent to ' . $recipient . ' using template: ' . $template . ' (plan: ' . $plan_info['plan_name'] . ')');
            } else {
                \llmagnet_aiseo_debug_log('Failed to send LLMagnet analytics report to ' . $recipient);
                $all_sent = false;
            }
        }
        
        return $all_sent;
    }

    /**
     * Get recipient email address (legacy method, returns first email)
     *
     * @return string
     */
    private function get_recipient_email() {
        $recipients = $this->get_recipient_emails();
        return !empty($recipients) ? $recipients[0] : get_bloginfo('admin_email');
    }

    /**
     * Get all recipient email addresses
     *
     * @return array
     */
    private function get_recipient_emails() {
        // Get from options, fall back to admin email
        $emails_string = get_option('llmagnet_report_email', get_bloginfo('admin_email'));
        
        // Parse comma-separated emails
        $emails = array_map('trim', explode(',', $emails_string));
        
        // Filter and validate emails
        $valid_emails = [];
        foreach ($emails as $email) {
            $email = sanitize_email($email);
            if (is_email($email)) {
                $valid_emails[] = $email;
            }
        }
        
        return $valid_emails;
    }

    /**
     * Get report data
     *
     * @return array
     */
    private function get_report_data() {
        // Get data for the last week
        $last_week_start = strtotime('-1 week midnight');
        $last_week_end = strtotime('yesterday 23:59:59');
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'llm_bot_visits';
        
        // Get total visits by bot for last week
        $weekly_stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    bot_name, 
                    COUNT(*) as visits 
                FROM 
                    {$table_name} 
                WHERE 
                    visit_time BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)
                GROUP BY 
                    bot_name 
                ORDER BY 
                    visits DESC",
                $last_week_start,
                $last_week_end
            ),
            ARRAY_A
        );
        
        // Get daily visits for last week
        $daily_stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    bot_name, 
                    DATE(visit_time) as date, 
                    COUNT(*) as visits 
                FROM 
                    {$table_name} 
                WHERE 
                    visit_time BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)
                GROUP BY 
                    bot_name, DATE(visit_time) 
                ORDER BY 
                    date ASC, visits DESC",
                $last_week_start,
                $last_week_end
            ),
            ARRAY_A
        );
        
        // Get all-time totals
        $all_time_stats = $wpdb->get_results(
            "SELECT 
                bot_name, 
                COUNT(*) as visits 
            FROM 
                {$table_name} 
            GROUP BY 
                bot_name 
            ORDER BY 
                visits DESC",
            ARRAY_A
        );
        
        // Format date range for the report
        $start_date = date('Y-m-d', $last_week_start);
        $end_date = date('Y-m-d', $last_week_end);
        $week_range = date_i18n('M j', $last_week_start) . ' - ' . date_i18n('M j, Y', $last_week_end);
        
        // Get visibility score data
        $visibility_score = null;
        if (class_exists('\\LLMagnet_AI_SEO_Optimizer\\Visibility_Score')) {
            $visibility_calculator = new \LLMagnet_AI_SEO_Optimizer\Visibility_Score();
            $score_data = $visibility_calculator->compute_visibility_score(30);
            
            if ($score_data) {
                $visibility_score = [
                    'score' => $score_data['score'],
                    'total_visits' => $score_data['total_visits'],
                    'unique_pages' => $score_data['unique_pages'],
                    'breakdown' => isset($score_data['breakdown']) ? $score_data['breakdown'] : [],
                ];
            }
        }
        
        return [
            'weekly_stats' => $weekly_stats,
            'daily_stats' => $daily_stats,
            'all_time_stats' => $all_time_stats,
            'visibility_score' => $visibility_score,
            'top_pages' => $this->get_top_pages($last_week_start, $last_week_end),
            'period' => [
                'start' => $start_date,
                'end' => $end_date,
                'week_range' => $week_range,
            ],
        ];
    }

    /**
     * Top pages by AI bot visits in the period, with per-page score and a
     * drawer deep link (Admin_WP_Helper::drawer_url — adoption plan §5.8:
     * email → drawer in one click).
     *
     * @param int $start Period start (Unix).
     * @param int $end   Period end (Unix).
     * @return array[] Each: title, path, visits, score (int|null), drawer_url.
     */
    private function get_top_pages($start, $end) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'llm_bot_visits';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_path, COUNT(*) as visits
                FROM {$table_name}
                WHERE visit_time BETWEEN FROM_UNIXTIME(%d) AND FROM_UNIXTIME(%d)
                GROUP BY page_path
                ORDER BY visits DESC
                LIMIT 10",
                $start,
                $end
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return [];
        }

        $top_pages = [];
        foreach ($rows as $row) {
            $path = isset($row['page_path']) ? (string) $row['page_path'] : '';
            if ('' === $path) {
                continue;
            }

            $post_id = url_to_postid(home_url($path));
            if (!$post_id) {
                continue;
            }

            $score = null;
            if (class_exists('\\LLMagnet_AI_SEO_Optimizer\\Score_Store')) {
                $stored = Score_Store::get($post_id);
                if (null !== $stored) {
                    $score = (int) $stored['score'];
                }
            }

            $drawer_url = '';
            if (class_exists('\\LLMagnet_AI_SEO_Optimizer\\Admin_WP_Helper')) {
                $drawer_url = Admin_WP_Helper::drawer_url($post_id);
            }

            $top_pages[] = [
                'title' => get_the_title($post_id),
                'path' => $path,
                'visits' => (int) $row['visits'],
                'score' => $score,
                'drawer_url' => $drawer_url,
            ];

            if (count($top_pages) >= 5) {
                break;
            }
        }

        return $top_pages;
    }

    /**
     * "Top pages" section with drawer deep links, shared by all templates
     * (neutral inline styling so it blends with each of them).
     *
     * @param array $data Report data.
     * @return string HTML ('' when there is nothing to show).
     */
    private function generate_top_pages_section($data) {
        $top_pages = isset($data['top_pages']) && is_array($data['top_pages']) ? $data['top_pages'] : [];
        if (empty($top_pages)) {
            return '';
        }

        $html = '
    <div style="margin: 25px 0;">
        <h3 style="margin: 0 0 12px 0; font-size: 16px; color: #333;">' . esc_html__('Top Pages for AI Bots', 'llmagnet-llm-txt-generator') . '</h3>
        <table style="width: 100%; border-collapse: collapse; background-color: #ffffff;">
            <tr>
                <th style="padding: 8px 10px; border-bottom: 2px solid #e5e7eb; text-align: left; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">' . esc_html__('Page', 'llmagnet-llm-txt-generator') . '</th>
                <th style="padding: 8px 10px; border-bottom: 2px solid #e5e7eb; text-align: right; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">' . esc_html__('Visits', 'llmagnet-llm-txt-generator') . '</th>
                <th style="padding: 8px 10px; border-bottom: 2px solid #e5e7eb; text-align: right; font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">' . esc_html__('Score', 'llmagnet-llm-txt-generator') . '</th>
                <th style="padding: 8px 10px; border-bottom: 2px solid #e5e7eb;"></th>
            </tr>';

        foreach ($top_pages as $page) {
            $score_html = '&mdash;';
            if (null !== $page['score']) {
                $score_html = '<span style="font-weight: 700; color: ' . $this->get_score_color((int) $page['score']) . ';">' . (int) $page['score'] . '</span>';
            }

            $link_html = '';
            if (!empty($page['drawer_url'])) {
                $link_html = '<a href="' . esc_url($page['drawer_url']) . '" style="color: #7c3aed; text-decoration: none; font-weight: 600; font-size: 13px;">' . esc_html__('View details', 'llmagnet-llm-txt-generator') . ' &rarr;</a>';
            }

            $html .= '
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #f3f4f6; font-size: 13px; color: #1f2937;">
                    <span style="font-weight: 600;">' . esc_html($page['title'] ? $page['title'] : $page['path']) . '</span><br />
                    <span style="font-size: 11px; color: #9ca3af;">' . esc_html($page['path']) . '</span>
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #f3f4f6; text-align: right; font-size: 13px; font-weight: 600; color: #1f2937;">' . number_format($page['visits']) . '</td>
                <td style="padding: 10px; border-bottom: 1px solid #f3f4f6; text-align: right; font-size: 13px;">' . $score_html . '</td>
                <td style="padding: 10px; border-bottom: 1px solid #f3f4f6; text-align: right;">' . $link_html . '</td>
            </tr>';
        }

        $html .= '
        </table>
        <p style="margin: 8px 0 0 0; font-size: 11px; color: #9ca3af;">' . esc_html__('Links open the page details drawer in your WordPress admin (login required).', 'llmagnet-llm-txt-generator') . '</p>
    </div>';

        return $html;
    }

    /**
     * Get score color based on value
     *
     * @param int $score Score value
     * @return string Hex color code
     */
    private function get_score_color($score) {
        if ($score >= 75) return '#22c55e'; // green
        if ($score >= 50) return '#eab308'; // yellow
        return '#ef4444'; // red
    }

    /**
     * Generate email content
     *
     * @param array $data Report data
     * @param string $template Template name (classic, minimal, professional)
     * @return string
     */
    private function generate_email_content($data, $template = 'classic') {
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        $week_range = $data['period']['week_range'];
        $company_logo = isset($data['company_logo']) ? $data['company_logo'] : null;
        
        // Get visibility score data
        $visibility_score = isset($data['visibility_score']) ? $data['visibility_score'] : null;
        
        // Generate content based on selected template
        switch ($template) {
            case 'minimal':
                return $this->generate_minimal_template($data, $site_name, $week_range, $company_logo, $visibility_score);
            case 'gradient':
                return $this->generate_gradient_template($data, $site_name, $week_range, $company_logo, $visibility_score);
            case 'professional':
                return $this->generate_professional_template($data, $site_name, $week_range, $company_logo, $visibility_score);
            case 'classic':
            default:
                return $this->generate_classic_template($data, $site_name, $week_range, $company_logo, $visibility_score);
        }
    }

    /**
     * Generate Classic template
     */
    private function generate_classic_template($data, $site_name, $week_range, $company_logo, $visibility_score) {
        $logo_html = $company_logo ? '
            <div style="margin-bottom: 15px;">
                <img src="' . esc_url($company_logo) . '" alt="Company Logo" style="max-height: 50px; max-width: 150px; object-fit: contain;" />
            </div>' : '';
        
        $visibility_html = '';
        if ($visibility_score) {
            $score = isset($visibility_score['score']) ? $visibility_score['score'] : 0;
            $total_visits = isset($visibility_score['total_visits']) ? $visibility_score['total_visits'] : 0;
            $unique_pages = isset($visibility_score['unique_pages']) ? $visibility_score['unique_pages'] : 0;
            $breakdown = isset($visibility_score['breakdown']) ? $visibility_score['breakdown'] : [];
            
            $visibility_html = '
            <div style="background-color: #ffffff; border-radius: 10px; padding: 20px; margin: 20px 0; text-align: center;">
                <h2 style="margin: 0 0 15px 0; font-size: 18px; font-weight: 500;">AI Visibility Score</h2>
                <div style="display: inline-block; width: 80px; height: 80px; border-radius: 50%; background-color: ' . $this->get_score_color($score) . '; line-height: 80px; font-size: 32px; font-weight: bold; color: #fff; margin-bottom: 10px;">' . $score . '</div>
                <div style="font-size: 14px; color: #666;">' . number_format($total_visits) . ' visits across ' . $unique_pages . ' pages</div>';
            
            if (!empty($breakdown)) {
                $visibility_html .= '
                <div style="display: flex; justify-content: space-around; margin-top: 15px; flex-wrap: wrap;">';
                
                $labels = [
                    'frequency' => 'Frequency',
                    'visit_type' => 'Visit Types',
                    'visit_count' => 'Volume',
                    'page_status' => 'Health',
                    'url_type' => 'URL'
                ];
                
                foreach ($breakdown as $key => $item) {
                    $item_score = isset($item['score']) ? $item['score'] : 0;
                    $label = isset($labels[$key]) ? $labels[$key] : ucfirst($key);
                    $visibility_html .= '
                    <div style="text-align: center; padding: 5px 10px;">
                        <div style="font-size: 16px; font-weight: bold; color: ' . $this->get_score_color($item_score) . ';">' . $item_score . '</div>
                        <div style="font-size: 11px; color: #666;">' . $label . '</div>
                    </div>';
                }
                $visibility_html .= '</div>';
            }
            $visibility_html .= '</div>';
        }
        
        $content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #ffffff;">
    <div style="background-color: #5d4066; color: white; padding: 20px; border-radius: 5px 5px 0 0; margin-bottom: 20px;">
        ' . $logo_html . '
        <h1 style="margin: 0 0 10px 0; font-size: 24px;">LLM Bot Analytics Report</h1>
        <p style="margin: 0; font-size: 14px; opacity: 0.9;">' . esc_html($site_name) . ' - Week of ' . esc_html($week_range) . '</p>
    </div>
    ' . $visibility_html . '
    <div style="background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0;">
        <h2 style="margin: 0 0 10px 0; font-size: 18px; color: #333;">Weekly Summary</h2>
        <p style="margin: 0; font-size: 14px; color: #666;">This report shows LLM bot visits to your website during the week of ' . esc_html($week_range) . '.</p>
    </div>';
        
        // Weekly stats table
        if (!empty($data['weekly_stats'])) {
            $content .= '
    <h3 style="margin: 20px 0 10px 0; font-size: 16px; color: #333;">Bot Visits for the Week of ' . esc_html($week_range) . '</h3>
    <table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: white;">
        <tr>
            <th style="padding: 10px; border: 1px solid #ddd; text-align: left; background-color: #f5f5f5; font-weight: bold;">Bot Name</th>
            <th style="padding: 10px; border: 1px solid #ddd; text-align: left; background-color: #f5f5f5; font-weight: bold;">Visits</th>
        </tr>';
            
            foreach ($data['weekly_stats'] as $stat) {
                $content .= '
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">' . esc_html($stat['bot_name']) . '</td>
            <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold; color: #a070b0;">' . number_format($stat['visits']) . '</td>
        </tr>';
            }
            $content .= '</table>';
        } else {
            $content .= '<p>No LLM bot visits were recorded for the week of ' . esc_html($week_range) . '.</p>';
        }

        // Top pages with drawer deep links (adoption plan §5.8).
        $content .= $this->generate_top_pages_section($data);

        // All-time stats
        if (!empty($data['all_time_stats'])) {
            $content .= '
    <h3 style="margin: 20px 0 10px 0; font-size: 16px; color: #333;">All-Time Bot Visits</h3>
    <table style="width: 100%; border-collapse: collapse; margin: 20px 0; background-color: white;">
        <tr>
            <th style="padding: 10px; border: 1px solid #ddd; text-align: left; background-color: #f5f5f5; font-weight: bold;">Bot Name</th>
            <th style="padding: 10px; border: 1px solid #ddd; text-align: left; background-color: #f5f5f5; font-weight: bold;">Total Visits</th>
        </tr>';
            
            foreach ($data['all_time_stats'] as $stat) {
                $content .= '
        <tr>
            <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold;">' . esc_html($stat['bot_name']) . '</td>
            <td style="padding: 10px; border: 1px solid #ddd; font-weight: bold; color: #a070b0;">' . number_format($stat['visits']) . '</td>
        </tr>';
            }
            $content .= '</table>';
        }
        
        $content .= '
    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666;">
        <p>This is an automated report generated by LLMagnet AI SEO Optimizer.</p>
    </div>
</body>
</html>';
        
        return $content;
    }

    /**
     * Generate Minimal template
     */
    private function generate_minimal_template($data, $site_name, $week_range, $company_logo, $visibility_score) {
        $logo_html = $company_logo ? '
            <div style="margin-bottom: 20px; text-align: center;">
                <img src="' . esc_url($company_logo) . '" alt="Company Logo" style="max-height: 40px; max-width: 120px; object-fit: contain;" />
            </div>' : '';
        
        $visibility_html = '';
        if ($visibility_score) {
            $score = isset($visibility_score['score']) ? $visibility_score['score'] : 0;
            $total_visits = isset($visibility_score['total_visits']) ? $visibility_score['total_visits'] : 0;
            $unique_pages = isset($visibility_score['unique_pages']) ? $visibility_score['unique_pages'] : 0;
            $breakdown = isset($visibility_score['breakdown']) ? $visibility_score['breakdown'] : [];
            
            $visibility_html = '
            <div style="text-align: center; padding: 30px; margin: 30px 0; border: 2px solid #2c3e50;">
                <h2 style="font-size: 14px; font-weight: 400; text-transform: uppercase; letter-spacing: 2px; color: #7f8c8d; margin-bottom: 15px;">AI Visibility Score</h2>
                <div style="font-size: 64px; font-weight: 200; color: ' . $this->get_score_color($score) . '; line-height: 1;">' . $score . '</div>
                <div style="font-size: 12px; color: #95a5a6; margin-top: 10px;">' . number_format($total_visits) . ' visits • ' . $unique_pages . ' pages</div>';
            
            if (!empty($breakdown)) {
                $visibility_html .= '
                <div style="display: flex; justify-content: space-between; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ecf0f1;">';
                
                $labels = ['frequency' => 'Freq', 'visit_type' => 'Types', 'visit_count' => 'Vol', 'page_status' => 'Health', 'url_type' => 'URL'];
                
                foreach ($breakdown as $key => $item) {
                    $item_score = isset($item['score']) ? $item['score'] : 0;
                    $label = isset($labels[$key]) ? $labels[$key] : ucfirst($key);
                    $visibility_html .= '
                    <div style="text-align: center;">
                        <div style="font-size: 20px; font-weight: 300; color: ' . $this->get_score_color($item_score) . ';">' . $item_score . '</div>
                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #95a5a6;">' . $label . '</div>
                    </div>';
                }
                $visibility_html .= '</div>';
            }
            $visibility_html .= '</div>';
        }
        
        $content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; line-height: 1.8; color: #2c3e50; max-width: 600px; margin: 0 auto; padding: 40px 20px; background-color: #f8f9fa;">
    <div style="text-align: center; padding-bottom: 30px; border-bottom: 3px solid #2c3e50; margin-bottom: 30px;">
        ' . $logo_html . '
        <h1 style="font-size: 32px; font-weight: 300; color: #2c3e50; margin-bottom: 10px; letter-spacing: 2px;">LLM Bot Analytics</h1>
        <p style="font-size: 14px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 1px; margin: 0;">' . esc_html($site_name) . ' • ' . esc_html($week_range) . '</p>
    </div>
    ' . $visibility_html . '
    <div style="margin: 40px 0;">
        <div style="font-size: 18px; font-weight: 400; color: #34495e; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; border-left: 4px solid #2c3e50; padding-left: 15px;">Weekly Visits</div>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
        
        if (!empty($data['weekly_stats'])) {
            $content .= '
            <tr>
                <th style="text-align: left; padding: 15px 0; border-bottom: 2px solid #2c3e50; font-weight: 400; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #7f8c8d;">Bot</th>
                <th style="text-align: right; padding: 15px 0; border-bottom: 2px solid #2c3e50; font-weight: 400; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #7f8c8d;">Visits</th>
            </tr>';
            
            foreach ($data['weekly_stats'] as $stat) {
                $content .= '
            <tr>
                <td style="padding: 15px 0; border-bottom: 1px solid #ecf0f1; font-weight: 500; color: #2c3e50;">' . esc_html($stat['bot_name']) . '</td>
                <td style="padding: 15px 0; border-bottom: 1px solid #ecf0f1; text-align: right; color: #34495e; font-size: 18px;">' . number_format($stat['visits']) . '</td>
            </tr>';
            }
        }
        
        $content .= '</table>
    </div>';

        // Top pages with drawer deep links (adoption plan §5.8).
        $content .= $this->generate_top_pages_section($data);

        $content .= '
    <div style="margin: 40px 0;">
        <div style="font-size: 18px; font-weight: 400; color: #34495e; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; border-left: 4px solid #2c3e50; padding-left: 15px;">All-Time Total</div>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
        
        if (!empty($data['all_time_stats'])) {
            $content .= '
            <tr>
                <th style="text-align: left; padding: 15px 0; border-bottom: 2px solid #2c3e50; font-weight: 400; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #7f8c8d;">Bot</th>
                <th style="text-align: right; padding: 15px 0; border-bottom: 2px solid #2c3e50; font-weight: 400; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #7f8c8d;">Total</th>
            </tr>';
            
            foreach ($data['all_time_stats'] as $stat) {
                $content .= '
            <tr>
                <td style="padding: 15px 0; border-bottom: 1px solid #ecf0f1; font-weight: 500; color: #2c3e50;">' . esc_html($stat['bot_name']) . '</td>
                <td style="padding: 15px 0; border-bottom: 1px solid #ecf0f1; text-align: right; color: #34495e; font-size: 18px;">' . number_format($stat['visits']) . '</td>
            </tr>';
            }
        }
        
        $content .= '</table>
    </div>
    <div style="margin-top: 50px; padding-top: 30px; border-top: 1px solid #ecf0f1; text-align: center; font-size: 11px; color: #95a5a6; text-transform: uppercase; letter-spacing: 1px;">
        LLMagnet AI SEO Optimizer
    </div>
</body>
</html>';
        
        return $content;
    }

    /**
     * Generate Gradient template
     */
    private function generate_gradient_template($data, $site_name, $week_range, $company_logo, $visibility_score) {
        $logo_html = $company_logo ? '<img src="' . esc_url($company_logo) . '" alt="Logo" style="max-height: 44px; max-width: 140px; margin-bottom: 16px; object-fit: contain;" />' : '';

        $visibility_html = '';
        if ($visibility_score) {
            $score = isset($visibility_score['score']) ? $visibility_score['score'] : 0;
            $total_visits = isset($visibility_score['total_visits']) ? $visibility_score['total_visits'] : 0;
            $unique_pages = isset($visibility_score['unique_pages']) ? $visibility_score['unique_pages'] : 0;
            $breakdown = isset($visibility_score['breakdown']) ? $visibility_score['breakdown'] : [];

            $visibility_html = '
            <div style="margin: -24px 24px 0; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 24px; text-align: center; position: relative; z-index: 1;">
                <h2 style="font-size: 12px; text-transform: uppercase; letter-spacing: 1.5px; color: #9ca3af; margin-bottom: 12px;">Visibility Score</h2>
                <div style="font-size: 52px; font-weight: 800; line-height: 1; color: ' . $this->get_score_color($score) . ';">' . $score . '</div>
                <div style="font-size: 12px; color: #6b7280; margin-top: 8px;">' . number_format($total_visits) . ' visits across ' . $unique_pages . ' pages</div>';

            if (!empty($breakdown)) {
                $visibility_html .= '<div style="display: flex; justify-content: space-between; margin-top: 18px; padding-top: 18px; border-top: 1px solid #f3f4f6;">';
                $labels = ['frequency' => 'Freq', 'visit_type' => 'Types', 'visit_count' => 'Volume', 'page_status' => 'Health', 'url_type' => 'URL'];
                foreach ($breakdown as $key => $item) {
                    $item_score = isset($item['score']) ? $item['score'] : 0;
                    $label = isset($labels[$key]) ? $labels[$key] : ucfirst($key);
                    $visibility_html .= '
                    <div style="text-align: center; flex: 1;">
                        <div style="font-size: 18px; font-weight: 700; color: ' . $this->get_score_color($item_score) . ';">' . $item_score . '</div>
                        <div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-top: 2px;">' . $label . '</div>
                    </div>';
                }
                $visibility_html .= '</div>';
            }
            $visibility_html .= '</div>';
        }

        $content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1f2937; max-width: 600px; margin: 0 auto; background-color: #f3f4f6;">
    <div style="background: #ffffff; border-radius: 12px; overflow: hidden; margin: 20px;">
        <div style="background: linear-gradient(135deg, #301630 0%, #512756 20%, #000000 40%, #2B1434 60%, #E276F0 80%, #E7AFE7 100%); padding: 32px 28px; color: #ffffff; text-align: center;">
            ' . $logo_html . '
            <h1 style="font-size: 22px; font-weight: 700; margin-bottom: 4px; color: #ffffff;">AI Visibility Report</h1>
            <p style="font-size: 13px; opacity: 0.85; color: #ffffff;">' . esc_html($site_name) . ' &mdash; ' . esc_html($week_range) . '</p>
        </div>
        ' . $visibility_html . '
        <div style="padding: 28px 24px;">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #a855f7; margin-bottom: 16px;">Weekly Bot Visits</div>
            <table style="width: 100%; border-collapse: collapse;">';

        if (!empty($data['weekly_stats'])) {
            $content .= '
                <tr>
                    <th style="text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; border-bottom: 2px solid #f3f4f6;">Bot</th>
                    <th style="text-align: right; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; border-bottom: 2px solid #f3f4f6;">Visits</th>
                </tr>';
            foreach ($data['weekly_stats'] as $stat) {
                $content .= '
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #f9fafb; font-size: 14px; font-weight: 600; color: #1f2937;">' . esc_html($stat['bot_name']) . '</td>
                    <td style="padding: 12px; border-bottom: 1px solid #f9fafb; font-size: 14px; font-weight: 700; color: #7c3aed; text-align: right;">' . number_format($stat['visits']) . '</td>
                </tr>';
            }
        }

        $content .= '</table>
        </div>';

        // Top pages with drawer deep links (adoption plan §5.8).
        $top_pages_html = $this->generate_top_pages_section($data);
        if ($top_pages_html) {
            $content .= '<div style="padding: 0 24px;">' . $top_pages_html . '</div>';
        }

        $content .= '
        <div style="padding: 0 24px 28px 24px;">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #a855f7; margin-bottom: 16px;">All-Time Statistics</div>
            <table style="width: 100%; border-collapse: collapse;">';

        if (!empty($data['all_time_stats'])) {
            $content .= '
                <tr>
                    <th style="text-align: left; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; border-bottom: 2px solid #f3f4f6;">Bot</th>
                    <th style="text-align: right; padding: 10px 12px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; border-bottom: 2px solid #f3f4f6;">Total</th>
                </tr>';
            foreach ($data['all_time_stats'] as $stat) {
                $content .= '
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #f9fafb; font-size: 14px; font-weight: 600; color: #1f2937;">' . esc_html($stat['bot_name']) . '</td>
                    <td style="padding: 12px; border-bottom: 1px solid #f9fafb; font-size: 14px; font-weight: 700; color: #7c3aed; text-align: right;">' . number_format($stat['visits']) . '</td>
                </tr>';
            }
        }

        $content .= '</table>
        </div>
        <div style="background: #f9fafb; padding: 20px 24px; text-align: center; font-size: 11px; color: #9ca3af;">
            Powered by <a href="https://llmagnet.com" style="color: #a855f7; text-decoration: none;">LLMagnet</a> AI SEO Optimizer
        </div>
    </div>
</body>
</html>';

        return $content;
    }

    /**
     * Generate Professional template
     */
    private function generate_professional_template($data, $site_name, $week_range, $company_logo, $visibility_score) {
        $logo_html = $company_logo ? '
            <div style="margin-bottom: 20px;">
                <img src="' . esc_url($company_logo) . '" alt="Company Logo" style="max-height: 60px; max-width: 180px; object-fit: contain;" />
            </div>' : '';
        
        $visibility_html = '';
        if ($visibility_score) {
            $score = isset($visibility_score['score']) ? $visibility_score['score'] : 0;
            $total_visits = isset($visibility_score['total_visits']) ? $visibility_score['total_visits'] : 0;
            $unique_pages = isset($visibility_score['unique_pages']) ? $visibility_score['unique_pages'] : 0;
            $breakdown = isset($visibility_score['breakdown']) ? $visibility_score['breakdown'] : [];
            
            $visibility_html = '
            <div style="background-color: #f8f8f8; border: 2px solid #1a1a1a; padding: 25px; margin: 30px 0;">
                <h2 style="font-size: 18px; font-weight: 400; color: #1a1a1a; margin-bottom: 20px; text-align: center;">AI Visibility Score</h2>
                <div style="text-align: center; margin-bottom: 20px;">
                    <span style="font-size: 48px; font-weight: 600; color: ' . $this->get_score_color($score) . ';">' . $score . '</span>
                    <div style="font-size: 12px; color: #666; font-style: italic;">' . number_format($total_visits) . ' total visits across ' . $unique_pages . ' pages</div>
                </div>';
            
            if (!empty($breakdown)) {
                $visibility_html .= '
                <div style="display: flex; justify-content: space-between; border-top: 1px solid #e0e0e0; padding-top: 15px;">';
                
                $labels = ['frequency' => 'Frequency', 'visit_type' => 'Visit Types', 'visit_count' => 'Volume', 'page_status' => 'Health', 'url_type' => 'URL'];
                
                foreach ($breakdown as $key => $item) {
                    $item_score = isset($item['score']) ? $item['score'] : 0;
                    $label = isset($labels[$key]) ? $labels[$key] : ucfirst($key);
                    $visibility_html .= '
                    <div style="text-align: center; flex: 1;">
                        <div style="font-size: 18px; font-weight: 600; color: ' . $this->get_score_color($item_score) . ';">' . $item_score . '</div>
                        <div style="font-size: 11px; color: #666;">' . $label . '</div>
                    </div>';
                }
                $visibility_html .= '</div>';
            }
            $visibility_html .= '</div>';
        }
        
        $content = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Georgia, \'Times New Roman\', serif; line-height: 1.7; color: #1a1a1a; max-width: 650px; margin: 0 auto; padding: 30px; background-color: #ffffff; border: 1px solid #e0e0e0;">
    <div style="border-bottom: 3px solid #1a1a1a; padding-bottom: 20px; margin-bottom: 30px;">
        ' . $logo_html . '
        <h1 style="font-size: 36px; font-weight: 400; color: #1a1a1a; margin-bottom: 5px; letter-spacing: 1px;">LLM Bot Analytics Report</h1>
        <div style="font-size: 14px; color: #666; font-style: italic;">' . esc_html($site_name) . '</div>
    </div>
    <div style="text-align: right; margin-bottom: 30px; color: #666; font-size: 14px;">Week of ' . esc_html($week_range) . '</div>
    <div style="margin-bottom: 30px; font-size: 15px; line-height: 1.8; color: #333;">
        Dear Administrator,<br><br>
        Please find below the comprehensive analytics report for LLM bot visits to your website. This report covers your AI Visibility Score, weekly performance metrics, and cumulative all-time statistics.
    </div>
    ' . $visibility_html . '
    <div style="margin: 35px 0;">
        <h2 style="font-size: 22px; font-weight: 400; color: #1a1a1a; margin-bottom: 20px; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px;">Weekly Performance Metrics</h2>
        <table style="width: 100%; border-collapse: collapse; margin: 25px 0; font-size: 14px;">';
        
        if (!empty($data['weekly_stats'])) {
            $content .= '
            <tr>
                <th style="background-color: #1a1a1a; color: white; padding: 12px 15px; text-align: left; font-weight: 400; font-size: 13px; letter-spacing: 0.5px;">Bot Name</th>
                <th style="background-color: #1a1a1a; color: white; padding: 12px 15px; text-align: right; font-weight: 400; font-size: 13px; letter-spacing: 0.5px;">Visits</th>
            </tr>';
            
            $last_index = count($data['weekly_stats']) - 1;
            foreach ($data['weekly_stats'] as $index => $stat) {
                $border_style = $index === $last_index ? 'border-bottom: 2px solid #1a1a1a;' : 'border-bottom: 1px solid #e0e0e0;';
                $content .= '
            <tr>
                <td style="padding: 12px 15px; ' . $border_style . ' font-weight: 500; color: #1a1a1a;">' . esc_html($stat['bot_name']) . '</td>
                <td style="padding: 12px 15px; ' . $border_style . ' text-align: right; font-weight: 600; color: #1a1a1a;">' . number_format($stat['visits']) . '</td>
            </tr>';
            }
        }
        
        $content .= '</table>
    </div>';

        // Top pages with drawer deep links (adoption plan §5.8).
        $content .= $this->generate_top_pages_section($data);

        $content .= '
    <div style="margin: 35px 0;">
        <h2 style="font-size: 22px; font-weight: 400; color: #1a1a1a; margin-bottom: 20px; border-bottom: 2px solid #1a1a1a; padding-bottom: 8px;">Cumulative Statistics</h2>
        <table style="width: 100%; border-collapse: collapse; margin: 25px 0; font-size: 14px;">';
        
        if (!empty($data['all_time_stats'])) {
            $content .= '
            <tr>
                <th style="background-color: #1a1a1a; color: white; padding: 12px 15px; text-align: left; font-weight: 400; font-size: 13px; letter-spacing: 0.5px;">Bot Name</th>
                <th style="background-color: #1a1a1a; color: white; padding: 12px 15px; text-align: right; font-weight: 400; font-size: 13px; letter-spacing: 0.5px;">Total Visits</th>
            </tr>';
            
            $last_index = count($data['all_time_stats']) - 1;
            foreach ($data['all_time_stats'] as $index => $stat) {
                $border_style = $index === $last_index ? 'border-bottom: 2px solid #1a1a1a;' : 'border-bottom: 1px solid #e0e0e0;';
                $content .= '
            <tr>
                <td style="padding: 12px 15px; ' . $border_style . ' font-weight: 500; color: #1a1a1a;">' . esc_html($stat['bot_name']) . '</td>
                <td style="padding: 12px 15px; ' . $border_style . ' text-align: right; font-weight: 600; color: #1a1a1a;">' . number_format($stat['visits']) . '</td>
            </tr>';
            }
        }
        
        $content .= '</table>
    </div>
    <div style="margin-top: 40px; font-size: 14px; line-height: 1.8; color: #333;">
        Should you require any additional information or have questions regarding this report, please do not hesitate to contact us.
    </div>
    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #666; font-style: italic;">
        Respectfully,<br>
        LLMagnet AI SEO Optimizer<br>
        Automated Reporting System
    </div>
</body>
</html>';
        
        return $content;
    }
}
