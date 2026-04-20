<?php
/**
 * Custom table for lead submissions.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Lead_Form_Proxy_Database {

    public const TABLE_SUFFIX = 'lead_form_submissions';

    /**
     * Full prefixed table name.
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create or upgrade table via dbDelta.
     */
    public static function create_table(): void {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempt_count int unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            sent_at datetime NULL,
            remote_response_code smallint NULL,
            last_attempt_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status_created (status, created_at)
        ) {$charset};";

        dbDelta($sql);
    }

    /**
     * Insert a new submission row (pending).
     *
     * @param array<string, mixed> $payload Normalized field array for JSON storage.
     * @return int Insert ID.
     */
    public static function insert(array $payload): int {
        global $wpdb;

        $now = current_time('mysql', true);
        $wpdb->insert(
            self::table_name(),
            array(
                'created_at'   => $now,
                'payload_json' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status'       => 'pending',
                'attempt_count'=> 0,
            ),
            array('%s', '%s', '%s', '%d')
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;

        $formats = array();
        $row = array();
        foreach ($data as $key => $value) {
            $row[ $key ] = $value;
            if (in_array($key, array('attempt_count'), true)) {
                $formats[] = '%d';
            } elseif ('remote_response_code' === $key) {
                $formats[] = null === $value ? '%s' : '%d';
            } else {
                $formats[] = '%s';
            }
        }

        $result = $wpdb->update(
            self::table_name(),
            $row,
            array('id' => $id),
            $formats,
            array('%d')
        );

        return false !== $result;
    }

    /**
     * Fetch one row by ID.
     *
     * @return object|null
     */
    public static function get_row(int $id) {
        global $wpdb;

        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    /**
     * Rows eligible for retry: pending, under max attempts, backoff elapsed.
     *
     * @return list<object>
     */
    public static function get_retry_batch(int $limit, int $max_attempts): array {
        global $wpdb;

        $table = self::table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE status = %s
                AND attempt_count < %d
                ORDER BY created_at ASC
                LIMIT %d",
                'pending',
                $max_attempts,
                $limit
            )
        );

        if (!is_array($rows)) {
            return array();
        }

        $out = array();
        $now = time();
        foreach ($rows as $row) {
            if (!self::is_backoff_elapsed($row, $now)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Exponential backoff: min(300, 30 * 2^attempt_count) seconds after last_attempt_at.
     */
    private static function is_backoff_elapsed(object $row, int $now): bool {
        if (empty($row->last_attempt_at)) {
            return true;
        }

        $attempts = (int) $row->attempt_count;
        $delay = min(300, 30 * (2 ** max(0, $attempts)));

        $last = strtotime($row->last_attempt_at . ' GMT');
        if (false === $last) {
            return true;
        }

        return ($now - $last) >= $delay;
    }
}
