<?php
/**
 * Shortcode + admin-post handler for the lead form.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lead_Form_Proxy_Form {

    public const SHORTCODE = 'lead_form';

    public static function register(): void {
        add_shortcode(self::SHORTCODE, array(__CLASS__, 'render_shortcode'));
        add_action('admin_post_nopriv_lead_form_proxy_submit', array(__CLASS__, 'handle_submit'));
        add_action('admin_post_lead_form_proxy_submit', array(__CLASS__, 'handle_submit'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_styles'));
    }

    public static function maybe_enqueue_styles(): void {
        if (!is_singular()) {
            return;
        }
        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }
        if (!has_shortcode((string) $post->post_content, self::SHORTCODE)) {
            return;
        }
        wp_register_style('lead-form-proxy', false, array(), LEAD_FORM_PROXY_VERSION);
        wp_enqueue_style('lead-form-proxy');
        $css = '.lead-form-proxy{max-width:32rem}.lead-form-proxy label{display:block;margin:.5rem 0 .25rem}.lead-form-proxy input,.lead-form-proxy textarea{width:100%;max-width:100%;box-sizing:border-box}.lead-form-proxy .submit-row{margin-top:1rem}.lead-form-proxy .notice{padding:.75rem;margin:0 0 1rem;border-left:4px solid #46b450;background:#f0f6f0}.lead-form-proxy .notice.error{border-color:#dc3232;background:#fef7f7}';
        wp_add_inline_style('lead-form-proxy', $css);
    }

    /**
     * @param array<string, string> $atts Shortcode attributes (unused; fixed fields).
     */
    public static function render_shortcode(array $atts = array(), string $content = ''): string {
        if (isset($_GET['lead_form_status'])) {
            $status = sanitize_key((string) wp_unslash($_GET['lead_form_status']));
        } else {
            $status = '';
        }

        $action = esc_url(admin_url('admin-post.php'));
        $nonce  = wp_nonce_field('lead_form_proxy_submit', 'lead_form_proxy_nonce', true, false);

        ob_start();
        ?>
        <div class="lead-form-proxy">
            <?php if ('thanks' === $status) : ?>
                <p class="notice" role="status"><?php esc_html_e('Thank you for your submission.', 'lead-form-proxy'); ?></p>
            <?php elseif ('invalid' === $status) : ?>
                <p class="notice error" role="alert"><?php esc_html_e('Please check the form and try again.', 'lead-form-proxy'); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url($action); ?>" class="lead-form-proxy__form">
                <input type="hidden" name="action" value="lead_form_proxy_submit" />
                <?php echo $nonce; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field ?>
                <p>
                    <label for="lead_form_proxy_name"><?php esc_html_e('Name', 'lead-form-proxy'); ?> <span class="required">*</span></label>
                    <input type="text" name="lead_form_name" id="lead_form_proxy_name" required autocomplete="name" />
                </p>
                <p>
                    <label for="lead_form_proxy_email"><?php esc_html_e('Email', 'lead-form-proxy'); ?> <span class="required">*</span></label>
                    <input type="email" name="lead_form_email" id="lead_form_proxy_email" required autocomplete="email" />
                </p>
                <p>
                    <label for="lead_form_proxy_phone"><?php esc_html_e('Phone', 'lead-form-proxy'); ?></label>
                    <input type="text" name="lead_form_phone" id="lead_form_proxy_phone" autocomplete="tel" />
                </p>
                <p>
                    <label for="lead_form_proxy_message"><?php esc_html_e('Message', 'lead-form-proxy'); ?> <span class="required">*</span></label>
                    <textarea name="lead_form_message" id="lead_form_proxy_message" rows="5" required></textarea>
                </p>
                <p class="submit-row">
                    <button type="submit"><?php esc_html_e('Send', 'lead-form-proxy'); ?></button>
                </p>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function handle_submit(): void {
        if (!isset($_POST['lead_form_proxy_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lead_form_proxy_nonce'])), 'lead_form_proxy_submit')) {
            self::redirect_with_status('invalid');
        }

        $name    = isset($_POST['lead_form_name']) ? sanitize_text_field(wp_unslash($_POST['lead_form_name'])) : '';
        $email   = isset($_POST['lead_form_email']) ? sanitize_email(wp_unslash($_POST['lead_form_email'])) : '';
        $phone   = isset($_POST['lead_form_phone']) ? sanitize_text_field(wp_unslash($_POST['lead_form_phone'])) : '';
        $message = isset($_POST['lead_form_message']) ? sanitize_textarea_field(wp_unslash($_POST['lead_form_message'])) : '';

        if ($name === '' || $email === '' || $message === '' || !is_email($email)) {
            self::redirect_with_status('invalid');
        }

        $fields = array(
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'message' => $message,
        );

        Lead_Form_Proxy_Submission_Service::submit_new($fields);

        self::redirect_with_status('thanks');
    }

    private static function redirect_with_status(string $status): void {
        $url = wp_get_referer();
        if (!$url) {
            $url = home_url('/');
        }
        $url = remove_query_arg(array('lead_form_status'), $url);
        $url = add_query_arg('lead_form_status', $status, $url);
        wp_safe_redirect($url);
        exit;
    }
}
