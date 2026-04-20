<?php
/**
 * WP-Cron registration and retry worker.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lead_Form_Proxy_Cron {

    public const HOOK = 'lead_form_proxy_retry_event';

    public static function register(): void {
        add_filter('cron_schedules', array(__CLASS__, 'add_schedules'));
        add_action(self::HOOK, array(__CLASS__, 'run_retries'));
    }

    /**
     * Register all custom intervals so any saved option value is valid for wp_schedule_event.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function add_schedules(array $schedules): array {
        foreach (self::interval_map() as $slug => $def) {
            $schedules[ $slug ] = $def;
        }
        return $schedules;
    }

    /**
     * @return array<string, array{interval: int, display: string}>
     */
    public static function interval_map(): array {
        return array(
            'lead_form_every_5_minutes'  => array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every 5 minutes', 'lead-form-proxy'),
            ),
            'lead_form_every_15_minutes' => array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 minutes', 'lead-form-proxy'),
            ),
            'lead_form_hourly'           => array(
                'interval' => HOUR_IN_SECONDS,
                'display'  => __('Hourly', 'lead-form-proxy'),
            ),
        );
    }

    public static function schedule(): void {
        self::clear_schedule();

        $slug = (string) get_option('lead_form_proxy_cron_interval', 'lead_form_every_15_minutes');
        $map = self::interval_map();
        if (!isset($map[ $slug ])) {
            $slug = 'lead_form_every_15_minutes';
            update_option('lead_form_proxy_cron_interval', $slug);
        }

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, $slug, self::HOOK);
        }
    }

    public static function clear_schedule(): void {
        $timestamp = wp_next_scheduled(self::HOOK);
        while (false !== $timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
        }
    }

    /**
     * Reschedule when admin changes interval.
     */
    public static function reschedule(): void {
        self::schedule();
    }

    public static function run_retries(): void {
        $batch = (int) get_option('lead_form_proxy_batch_size', 20);
        if ($batch < 1) {
            $batch = 20;
        }
        $max = (int) get_option('lead_form_proxy_max_attempts', 10);
        if ($max < 1) {
            $max = 10;
        }

        $rows = Lead_Form_Proxy_Database::get_retry_batch($batch, $max);

        foreach ($rows as $row) {
            Lead_Form_Proxy_Submission_Service::retry_row($row);
        }
    }
}
