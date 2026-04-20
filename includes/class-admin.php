<?php
/**
 * Settings: API URL, bearer token, cron interval, batch size, max attempts.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lead_Form_Proxy_Admin {

    public const OPTION_GROUP = 'lead_form_proxy_settings';
    public const PAGE_SLUG    = 'lead-form-proxy';

    public static function register(): void {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('update_option_' . 'lead_form_proxy_cron_interval', array(__CLASS__, 'on_cron_interval_change'), 10, 3);
    }

    public static function on_cron_interval_change($old_value, $value, $option): void {
        Lead_Form_Proxy_Cron::reschedule();
    }

    public static function add_menu(): void {
        add_options_page(
            __('Lead Form Proxy', 'lead-form-proxy'),
            __('Lead Form Proxy', 'lead-form-proxy'),
            'manage_options',
            self::PAGE_SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            'lead_form_proxy_api_url',
            array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) {
                    return esc_url_raw((string) $value);
                },
                'default'           => '',
            )
        );

        register_setting(
            self::OPTION_GROUP,
            'lead_form_proxy_bearer_token',
            array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) {
                    return sanitize_text_field((string) $value);
                },
                'default'           => '',
            )
        );

        register_setting(
            self::OPTION_GROUP,
            'lead_form_proxy_max_attempts',
            array(
                'type'              => 'integer',
                'sanitize_callback' => function ($value) {
                    $n = (int) $value;
                    return max(1, min(100, $n));
                },
                'default'           => 10,
            )
        );

        register_setting(
            self::OPTION_GROUP,
            'lead_form_proxy_batch_size',
            array(
                'type'              => 'integer',
                'sanitize_callback' => function ($value) {
                    $n = (int) $value;
                    return max(1, min(500, $n));
                },
                'default'           => 20,
            )
        );

        register_setting(
            self::OPTION_GROUP,
            'lead_form_proxy_cron_interval',
            array(
                'type'              => 'string',
                'sanitize_callback' => array(__CLASS__, 'sanitize_interval'),
                'default'           => 'lead_form_every_15_minutes',
            )
        );

        add_settings_section(
            'lead_form_proxy_main',
            __('API and delivery', 'lead-form-proxy'),
            array(__CLASS__, 'render_section_intro'),
            self::PAGE_SLUG
        );

        add_settings_field(
            'lead_form_proxy_api_url',
            __('API endpoint URL', 'lead-form-proxy'),
            array(__CLASS__, 'field_api_url'),
            self::PAGE_SLUG,
            'lead_form_proxy_main'
        );

        add_settings_field(
            'lead_form_proxy_bearer_token',
            __('Bearer token', 'lead-form-proxy'),
            array(__CLASS__, 'field_bearer'),
            self::PAGE_SLUG,
            'lead_form_proxy_main'
        );

        add_settings_field(
            'lead_form_proxy_max_attempts',
            __('Max send attempts', 'lead-form-proxy'),
            array(__CLASS__, 'field_max_attempts'),
            self::PAGE_SLUG,
            'lead_form_proxy_main'
        );

        add_settings_field(
            'lead_form_proxy_batch_size',
            __('Cron batch size', 'lead-form-proxy'),
            array(__CLASS__, 'field_batch'),
            self::PAGE_SLUG,
            'lead_form_proxy_main'
        );

        add_settings_field(
            'lead_form_proxy_cron_interval',
            __('Retry schedule', 'lead-form-proxy'),
            array(__CLASS__, 'field_interval'),
            self::PAGE_SLUG,
            'lead_form_proxy_main'
        );
    }

    /**
     * @param mixed $value Raw option value.
     */
    public static function sanitize_interval($value): string {
        $value = is_string($value) ? $value : '';
        $allowed = array_keys(Lead_Form_Proxy_Cron::interval_map());
        return in_array($value, $allowed, true) ? $value : 'lead_form_every_15_minutes';
    }

    public static function render_section_intro(): void {
        echo '<p>' . esc_html__('Configure the outbound POST target and retry behavior. Use the shortcode [lead_form] on any page.', 'lead-form-proxy') . '</p>';
        echo '<p><strong>' . esc_html__('WP-Cron note:', 'lead-form-proxy') . '</strong> ';
        echo esc_html__('WordPress schedules retries using WP-Cron, which may run on page visits unless a system cron hits wp-cron.php. For reliable delivery, configure a real cron job on your server or use a host that triggers WP-Cron on a schedule.', 'lead-form-proxy');
        echo '</p>';
    }

    public static function field_api_url(): void {
        $v = (string) get_option('lead_form_proxy_api_url', '');
        printf(
            '<input type="url" class="regular-text" name="lead_form_proxy_api_url" id="lead_form_proxy_api_url" value="%s" placeholder="https://api.example.com/leads" />',
            esc_attr($v)
        );
        echo '<p class="description">' . esc_html__('Full URL for POST (JSON body per plugin header contract).', 'lead-form-proxy') . '</p>';
    }

    public static function field_bearer(): void {
        $v = (string) get_option('lead_form_proxy_bearer_token', '');
        printf(
            '<input type="password" class="regular-text" name="lead_form_proxy_bearer_token" id="lead_form_proxy_bearer_token" value="%s" autocomplete="off" />',
            esc_attr($v)
        );
        echo '<p class="description">' . esc_html__('Optional. Sent as Authorization: Bearer {token}. Leave empty if the API has no auth.', 'lead-form-proxy') . '</p>';
    }

    public static function field_max_attempts(): void {
        $v = (int) get_option('lead_form_proxy_max_attempts', 10);
        printf(
            '<input type="number" min="1" max="100" name="lead_form_proxy_max_attempts" id="lead_form_proxy_max_attempts" value="%d" />',
            (int) $v
        );
        echo '<p class="description">' . esc_html__('After this many failed attempts (network/5xx), the submission is marked failed.', 'lead-form-proxy') . '</p>';
    }

    public static function field_batch(): void {
        $v = (int) get_option('lead_form_proxy_batch_size', 20);
        printf(
            '<input type="number" min="1" max="500" name="lead_form_proxy_batch_size" id="lead_form_proxy_batch_size" value="%d" />',
            (int) $v
        );
    }

    public static function field_interval(): void {
        $current = (string) get_option('lead_form_proxy_cron_interval', 'lead_form_every_15_minutes');
        $map     = Lead_Form_Proxy_Cron::interval_map();
        echo '<select name="lead_form_proxy_cron_interval" id="lead_form_proxy_cron_interval">';
        foreach ($map as $slug => $info) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($slug),
                selected($current, $slug, false),
                esc_html($info['display'])
            );
        }
        echo '</select>';
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
