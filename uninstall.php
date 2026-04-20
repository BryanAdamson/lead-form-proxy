<?php
/**
 * Uninstall: optional data removal when LEAD_FORM_PROXY_UNINSTALL_DELETE_DATA is true in wp-config.php.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('LEAD_FORM_PROXY_UNINSTALL_DELETE_DATA') || !LEAD_FORM_PROXY_UNINSTALL_DELETE_DATA) {
    return;
}

delete_option('lead_form_proxy_api_url');
delete_option('lead_form_proxy_bearer_token');
delete_option('lead_form_proxy_max_attempts');
delete_option('lead_form_proxy_batch_size');
delete_option('lead_form_proxy_cron_interval');

global $wpdb;
$table = $wpdb->prefix . 'lead_form_submissions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS {$table}");
