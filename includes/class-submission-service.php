<?php
/**
 * Insert row + send + update status (used by form handler and cron).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lead_Form_Proxy_Submission_Service {

    /**
     * Build normalized payload from raw POST-style array (fixed fields).
     *
     * @param array<string, string> $fields Sanitized strings.
     * @return array<string, string>
     */
    public static function build_payload(array $fields): array {
        return array(
            'submissionUuid' => wp_generate_uuid4(),
            'fullName'       => $fields['name'] ?? '',
            'email'          => $fields['email'] ?? '',
            'phone'          => $fields['phone'] ?? '',
            'message'        => $fields['message'] ?? '',
            'source'         => home_url('/'),
        );
    }

    /**
     * Insert, then attempt send; updates row.
     *
     * @param array<string, string> $sanitized_fields
     * @return array{submission_id: int, status: string}
     */
    public static function submit_new(array $sanitized_fields): array {
        $payload = self::build_payload($sanitized_fields);
        $id = Lead_Form_Proxy_Database::insert($payload);

        self::process_submission_row($id, $payload);

        $row = Lead_Form_Proxy_Database::get_row($id);

        return array(
            'submission_id' => $id,
            'status'        => $row ? (string) $row->status : 'pending',
        );
    }

    /**
     * Send for an existing row (cron). Loads payload from DB.
     */
    public static function retry_row(object $row): void {
        $payload = json_decode((string) $row->payload_json, true);
        if (!is_array($payload)) {
            Lead_Form_Proxy_Database::update(
                (int) $row->id,
                array(
                    'status'       => 'failed',
                    'last_error'   => __('Invalid stored payload JSON.', 'lead-form-proxy'),
                    'last_attempt_at' => gmdate('Y-m-d H:i:s'),
                )
            );
            return;
        }

        if (empty($payload['submissionUuid']) || !is_string($payload['submissionUuid'])) {
            $payload['submissionUuid'] = wp_generate_uuid4();
            Lead_Form_Proxy_Database::update(
                (int) $row->id,
                array(
                    'payload_json' => (string) wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                )
            );
        }

        self::process_submission_row((int) $row->id, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function process_submission_row(int $id, array $payload): void {
        $attempted_at = gmdate('Y-m-d H:i:s');

        $row = Lead_Form_Proxy_Database::get_row($id);
        $attempt_count = $row ? (int) $row->attempt_count : 0;

        Lead_Form_Proxy_Database::update(
            $id,
            array(
                'attempt_count'   => $attempt_count + 1,
                'last_attempt_at' => $attempted_at,
            )
        );

        $result = Lead_Form_Proxy_Api_Client::send($payload);
        $max = (int) get_option('lead_form_proxy_max_attempts', 10);

        if ($result['ok']) {
            Lead_Form_Proxy_Database::update(
                $id,
                array(
                    'status'               => 'sent',
                    'sent_at'              => gmdate('Y-m-d H:i:s'),
                    'last_error'           => '',
                    'remote_response_code' => $result['code'],
                )
            );
            return;
        }

        if (!empty($result['is_duplicate'])) {
            Lead_Form_Proxy_Database::update(
                $id,
                array(
                    'status'               => 'sent',
                    'sent_at'              => gmdate('Y-m-d H:i:s'),
                    'last_error'           => __('Already persisted server-side (409).', 'lead-form-proxy'),
                    'remote_response_code' => $result['code'],
                )
            );
            return;
        }

        if (!empty($result['is_client_error'])) {
            Lead_Form_Proxy_Database::update(
                $id,
                array(
                    'status'               => 'failed',
                    'last_error'           => $result['error'],
                    'remote_response_code' => $result['code'],
                )
            );
            return;
        }

        $new_count = $attempt_count + 1;
        if ($new_count >= $max) {
            Lead_Form_Proxy_Database::update(
                $id,
                array(
                    'status'               => 'failed',
                    'last_error'           => $result['error'],
                    'remote_response_code' => $result['code'],
                )
            );
            return;
        }

        Lead_Form_Proxy_Database::update(
            $id,
            array(
                'status'               => 'pending',
                'last_error'           => $result['error'],
                'remote_response_code' => $result['code'],
            )
        );
    }
}
