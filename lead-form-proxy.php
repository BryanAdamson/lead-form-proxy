<?php
/**
 * Plugin Name: Lead Form Proxy
 * Description: Renders a lead form, stores submissions in a custom table, POSTs to a configured API, and retries failures via WP-Cron.
 * Version: 1.0.0
 * Author: Lead Form Proxy
 * License: GPL-2.0-or-later
 * Text Domain: lead-form-proxy
 *
 * -----------------------------------------------------------------------------
 * Default outbound API contract (align your .NET endpoint to this, or use filters)
 * -----------------------------------------------------------------------------
 * Method: POST
 * URL:    Configured in Settings (must be HTTPS in production).
 * Headers:
 *   Content-Type: application/json
 *   Accept:       application/json
 *   Authorization: Bearer {token}  — optional; set in Settings if your API requires it.
 *
 * JSON body (UTF-8), object shape:
 * {
 *   "submissionUuid": string (UUID v4) — generated per submission; used for server-side idempotency.
 *   "fullName":       string,
 *   "email":          string (valid email),
 *   "phone":          string,
 *   "message":        string,
 *   "source":         string — optional site identifier; default is home_url()
 * }
 *
 * Success: HTTP 2xx — submission marked sent.
 * Idempotency: HTTP 409 — the API reports this submissionUuid already exists; we treat it as sent (already persisted server-side) and stop retrying.
 * Retries: Network errors, timeouts, and HTTP 5xx — remain pending; WP-Cron retries with backoff until max attempts.
 * No retry: Other HTTP 4xx (e.g. 400 validation errors) — marked failed immediately (client errors are not retried with the same payload).
 *
 * Filters (for non-default .NET contracts):
 *   `lead_form_proxy_request_args` — (array $args, array $payload) for wp_remote_post args.
 *   `lead_form_proxy_request_body` — (array $body) alter JSON array before encode.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LEAD_FORM_PROXY_VERSION', '1.0.0');
define('LEAD_FORM_PROXY_FILE', __FILE__);
define('LEAD_FORM_PROXY_PATH', plugin_dir_path(__FILE__));

require_once LEAD_FORM_PROXY_PATH . 'includes/class-database.php';
require_once LEAD_FORM_PROXY_PATH . 'includes/class-api-client.php';
require_once LEAD_FORM_PROXY_PATH . 'includes/class-submission-service.php';
require_once LEAD_FORM_PROXY_PATH . 'includes/class-form.php';
require_once LEAD_FORM_PROXY_PATH . 'includes/class-cron.php';
require_once LEAD_FORM_PROXY_PATH . 'includes/class-admin.php';

/**
 * Bootstrap hooks.
 */
function lead_form_proxy_init(): void {
    Lead_Form_Proxy_Form::register();
    Lead_Form_Proxy_Cron::register();
    Lead_Form_Proxy_Admin::register();
}
add_action('plugins_loaded', 'lead_form_proxy_init');

/**
 * Activation: table + cron schedule.
 */
function lead_form_proxy_activate(): void {
    add_option('lead_form_proxy_api_url', '');
    add_option('lead_form_proxy_bearer_token', '');
    add_option('lead_form_proxy_max_attempts', 10);
    add_option('lead_form_proxy_batch_size', 20);
    add_option('lead_form_proxy_cron_interval', 'lead_form_every_15_minutes');

    Lead_Form_Proxy_Database::create_table();
    Lead_Form_Proxy_Cron::schedule();
}

/**
 * Deactivation: clear cron only (data retained).
 */
function lead_form_proxy_deactivate(): void {
    Lead_Form_Proxy_Cron::clear_schedule();
}

register_activation_hook(LEAD_FORM_PROXY_FILE, 'lead_form_proxy_activate');
register_deactivation_hook(LEAD_FORM_PROXY_FILE, 'lead_form_proxy_deactivate');
