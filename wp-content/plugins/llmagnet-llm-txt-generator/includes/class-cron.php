<?php
/**
 * Cron class
 *
 * @package LLMagnet_AI_SEO_Optimizer
 */

namespace LLMagnet_AI_SEO_Optimizer;

/**
 * Cron class for scheduled tasks
 */
class Cron {
    /**
     * Generator instance
     *
     * @var Generator
     */
    private $generator;

    /**
     * Visibility Score instance
     *
     * @var Visibility_Score|null
     */
    private $visibility_score;

    /**
     * Email Reports instance
     *
     * @var Email_Reports|null
     */
    private $email_reports;

    /**
     * Constructor
     *
     * @param Generator $generator Generator instance
     * @param Visibility_Score|null $visibility_score Visibility Score instance (optional)
     * @param Email_Reports|null $email_reports Email Reports instance (optional)
     */
    public function __construct(Generator $generator, Visibility_Score $visibility_score = null, Email_Reports $email_reports = null) {
        $this->generator = $generator;
        $this->visibility_score = $visibility_score;
        $this->email_reports = $email_reports;
        $this->init();
    }

    /**
     * Initialize cron functionality
     *
     * @return void
     */
    public function init() {
        // Add cron hook for daily generation (batched: llms.txt + llms-full.txt
        // immediately, markdown exports in cursor-tracked batches per tick)
        add_action('llmagnet_ai_seo_daily_event', [$this->generator, 'start_batched_generation']);

        // Add cron hook for processing one markdown generation batch
        add_action(Generator::BATCH_EVENT, [$this->generator, 'process_generation_batch']);
        
        // Add cron hook for daily visibility score calculation
        add_action('llmagnet_visibility_score_daily', [$this, 'calculate_visibility_score']);
        
        // Add cron hook for scheduled email reports
        add_action('llmagnet_scheduled_email_report', [$this, 'send_scheduled_email_report']);

        // Per-post score store: backfill listener, stale-on-save invalidation,
        // batch REST endpoint (adoption plan Phase 0.1/0.2). Guarded require so
        // the wiring works regardless of class-main.php integration order;
        // Score_Store::boot() is idempotent.
        if (!class_exists(__NAMESPACE__ . '\\Score_Store')) {
            require_once __DIR__ . '/class-score-store.php';
        }
        Score_Store::boot();

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_custom_schedules']);

        // Keep email report crons aligned when Freemius plan / license changes
        add_action('fs_after_account_plan_sync_llmagnet-llm-txt-generator', [__CLASS__, 'reschedule_email_reports_on_plan_change']);
        add_action('fs_after_license_change_llmagnet-llm-txt-generator', [__CLASS__, 'reschedule_email_reports_on_plan_change'], 10, 2);
    }

    /**
     * Reschedule report crons after subscription / trial / license updates
     *
     * @return void
     */
    public static function reschedule_email_reports_on_plan_change() {
        self::reschedule_email_reports();
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_custom_schedules($schedules) {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Once Monthly', 'llmagnet-ai-seo-optimizer')
        ];
        $schedules['quarterly'] = [
            'interval' => 90 * DAY_IN_SECONDS,
            'display' => __('Once Quarterly', 'llmagnet-ai-seo-optimizer')
        ];
        return $schedules;
    }

    /**
     * Calculate visibility score (called by cron)
     *
     * @return void
     */
    public function calculate_visibility_score() {
        if ($this->visibility_score) {
            $this->visibility_score->calculate_and_save_if_changed(30);
        } else {
            // Fallback: create new instance if not provided
            $visibility_score = new Visibility_Score();
            $visibility_score->calculate_and_save_if_changed(30);
        }
    }

    /**
     * Send scheduled email report (called by cron)
     *
     * @return void
     */
    public function send_scheduled_email_report() {
        if (!Email_Reports::can_send_scheduled_email_reports()) {
            return;
        }

        // Check if email reports is configured
        $emails = get_option('llmagnet_report_email', '');
        if (empty($emails)) {
            return;
        }

        // Use the email reports class if available
        if ($this->email_reports) {
            $this->email_reports->send_scheduled_report();
        } else {
            // Fallback: create new instance
            $email_reports = new Email_Reports();
            $email_reports->send_scheduled_report();
        }
    }

    /**
     * Schedule cron events
     *
     * @return void
     */
    public static function schedule_event() {
        // Schedule daily llms.txt generation
        if (!wp_next_scheduled('llmagnet_ai_seo_daily_event')) {
            wp_schedule_event(time(), 'daily', 'llmagnet_ai_seo_daily_event');
        }
        
        // Schedule daily visibility score calculation
        if (!wp_next_scheduled('llmagnet_visibility_score_daily')) {
            // Schedule for 3:00 AM to avoid conflicts
            $next_run = strtotime('tomorrow 03:00:00');
            wp_schedule_event($next_run, 'daily', 'llmagnet_visibility_score_daily');
        }
        
        // Schedule email reports based on frequency setting
        self::reschedule_email_reports();

        // Schedule lifecycle email scan once daily
        if (!wp_next_scheduled('llmagnet_lifecycle_email_scan')) {
            $next_run = strtotime('tomorrow 04:00:00');
            wp_schedule_event($next_run, 'daily', 'llmagnet_lifecycle_email_scan');
        }

        // Schedule daily privacy/data-retention pruning (bot visit + click logs)
        if (!wp_next_scheduled('llmagnet_privacy_data_prune')) {
            $next_run = strtotime('tomorrow 02:30:00');
            wp_schedule_event($next_run, 'daily', 'llmagnet_privacy_data_prune');
        }

        // Schedule hourly per-post score backfill (25 posts/run — see
        // Score_Store::run_backfill(), adoption plan Phase 0.1)
        if (!wp_next_scheduled('llmagnet_score_backfill')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', 'llmagnet_score_backfill');
        }
    }

    /**
     * Reschedule email reports based on current settings
     *
     * @return void
     */
    public static function reschedule_email_reports() {
        wp_clear_scheduled_hook('llmagnet_scheduled_email_report');
        wp_clear_scheduled_hook('llmagnet_weekly_analytics_report');

        if (!Email_Reports::can_send_scheduled_email_reports()) {
            return;
        }

        // Get frequency setting
        $frequency = get_option('llmagnet_report_frequency', 'weekly');
        $send_time = get_option('llmagnet_report_send_time', '09:00');
        
        // Parse send time
        list($hour, $minute) = explode(':', $send_time);
        $hour = intval($hour);
        $minute = intval($minute);
        
        // Calculate next run time based on frequency
        $now = time();
        $today_send_time = strtotime(wp_date('Y-m-d') . " {$hour}:{$minute}:00");
        
        switch ($frequency) {
            case 'daily':
                // If today's send time has passed, schedule for tomorrow
                if ($now > $today_send_time) {
                    $next_run = strtotime('+1 day', $today_send_time);
                } else {
                    $next_run = $today_send_time;
                }
                $recurrence = 'daily';
                break;
                
            case 'weekly':
                // Schedule for next Monday at the specified time
                $next_monday = strtotime('next monday', $now);
                $next_run = strtotime(date('Y-m-d', $next_monday) . " {$hour}:{$minute}:00");
                $recurrence = 'weekly';
                break;
                
            case 'monthly':
                // Schedule for the 1st of next month
                $next_month = strtotime('first day of next month', $now);
                $next_run = strtotime(date('Y-m-d', $next_month) . " {$hour}:{$minute}:00");
                $recurrence = 'monthly';
                break;
                
            case 'quarterly':
                // Schedule for 3 months from now
                $next_quarter = strtotime('+3 months', $now);
                $next_run = strtotime(date('Y-m-01', $next_quarter) . " {$hour}:{$minute}:00");
                $recurrence = 'quarterly';
                break;
                
            default:
                $next_run = strtotime('next monday', $now);
                $next_run = strtotime(date('Y-m-d', $next_run) . " {$hour}:{$minute}:00");
                $recurrence = 'weekly';
        }
        
        // Schedule the event
        wp_schedule_event($next_run, $recurrence, 'llmagnet_scheduled_email_report');
    }

    /**
     * Clear scheduled events
     *
     * @return void
     */
    public static function clear_scheduled_event() {
        wp_clear_scheduled_hook('llmagnet_ai_seo_daily_event');
        wp_clear_scheduled_hook(Generator::BATCH_EVENT);
        wp_clear_scheduled_hook('llmagnet_visibility_score_daily');
        wp_clear_scheduled_hook('llmagnet_scheduled_email_report');
        wp_clear_scheduled_hook('llmagnet_weekly_analytics_report');
        wp_clear_scheduled_hook('llmagnet_lifecycle_email_scan');
        wp_clear_scheduled_hook('llmagnet_brevo_site_identity_sync');
        wp_clear_scheduled_hook('llmagnet_privacy_data_prune');
        wp_clear_scheduled_hook('llmagnet_score_backfill');
    }
} 