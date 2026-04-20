<?php
/**
 * Outbound HTTP to the configured .NET (or other) API.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lead_Form_Proxy_Api_Client {

    /**
     * POST payload; returns structured result.
     *
     * @param array<string, mixed> $payload Body object (submissionUuid, fullName, email, phone, message, source).
     * @return array{ok: bool, code: int|null, error: string|null, is_client_error: bool, is_duplicate: bool}
     */
    public static function send(array $payload): array {
        $url = (string) get_option('lead_form_proxy_api_url', '');
        if ($url === '') {
            return array(
                'ok'               => false,
                'code'             => null,
                'error'            => __('API URL is not configured.', 'lead-form-proxy'),
                'is_client_error'  => false,
                'is_duplicate'     => false,
            );
        }

        $body = apply_filters('lead_form_proxy_request_body', $payload);

        $args = array(
            'method'      => 'POST',
            'timeout'     => 15,
            'redirection' => 3,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept'       => 'application/json',
            ),
            'body'        => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $token = (string) get_option('lead_form_proxy_bearer_token', '');
        if ($token !== '') {
            $args['headers']['Authorization'] = 'Bearer ' . $token;
        }

        $args = apply_filters('lead_form_proxy_request_args', $args, $body);

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return array(
                'ok'               => false,
                'code'             => null,
                'error'            => $response->get_error_message(),
                'is_client_error'  => false,
                'is_duplicate'     => false,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $snippet = self::body_snippet((string) wp_remote_retrieve_body($response));

        if ($code >= 200 && $code < 300) {
            return array(
                'ok'               => true,
                'code'             => $code,
                'error'            => null,
                'is_client_error'  => false,
                'is_duplicate'     => false,
            );
        }

        if ($code >= 400 && $code < 500) {
            return array(
                'ok'               => false,
                'code'             => $code,
                'error'            => sprintf(
                    /* translators: 1: HTTP status, 2: response snippet */
                    __('Client error %1$d: %2$s', 'lead-form-proxy'),
                    $code,
                    $snippet
                ),
                'is_client_error'  => true,
                'is_duplicate'     => $code === 409,
            );
        }

        return array(
            'ok'               => false,
            'code'             => $code,
            'error'            => sprintf(
                /* translators: 1: HTTP status, 2: response snippet */
                __('Server or unexpected response %1$d: %2$s', 'lead-form-proxy'),
                $code,
                $snippet
            ),
            'is_client_error'  => false,
            'is_duplicate'     => false,
        );
    }

    private static function body_snippet(string $body): string {
        $body = trim(wp_strip_all_tags($body));
        if (strlen($body) > 200) {
            return substr($body, 0, 200) . '…';
        }
        return $body !== '' ? $body : '—';
    }
}
