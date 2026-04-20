<?php
/**
 * Plain-PHP smoke test for Lead_Form_Proxy_Submission_Service::build_payload().
 *
 * Exercises the payload shape without booting WordPress. Stubs `home_url()`
 * and `wp_generate_uuid4()` so the class loads standalone.
 *
 * Run: `php tests/smoke.php` from the plugin root. Exits non-zero on failure.
 */

define('ABSPATH', __DIR__ . '/');

if (!function_exists('home_url')) {
    function home_url($path = '/')
    {
        return 'https://wp.test' . $path;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}

require __DIR__ . '/../includes/class-submission-service.php';

$failures = array();

function assert_true($condition, $message)
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL: {$message}\n");
        return;
    }
    echo "ok: {$message}\n";
}

$fields = array(
    'name'    => 'Ada Lovelace',
    'email'   => 'ada@example.com',
    'phone'   => '+1-555-010',
    'message' => 'Hello world',
);

$payload = Lead_Form_Proxy_Submission_Service::build_payload($fields);

assert_true(array_key_exists('submissionUuid', $payload), 'payload has submissionUuid key');
assert_true(array_key_exists('fullName', $payload), 'payload has fullName key');
assert_true(array_key_exists('email', $payload), 'payload has email key');
assert_true(array_key_exists('phone', $payload), 'payload has phone key');
assert_true(array_key_exists('message', $payload), 'payload has message key');
assert_true(array_key_exists('source', $payload), 'payload has source key');
assert_true(!array_key_exists('name', $payload), 'payload does not expose legacy name key');

assert_true($payload['fullName'] === 'Ada Lovelace', 'name mapped to fullName');
assert_true($payload['email'] === 'ada@example.com', 'email passed through');
assert_true($payload['phone'] === '+1-555-010', 'phone passed through');
assert_true($payload['message'] === 'Hello world', 'message passed through');
assert_true($payload['source'] === 'https://wp.test/', 'source is home_url');

$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
assert_true((bool) preg_match($uuid_pattern, $payload['submissionUuid']), 'submissionUuid is v4');

$other = Lead_Form_Proxy_Submission_Service::build_payload($fields);
assert_true($payload['submissionUuid'] !== $other['submissionUuid'], 'new uuid generated per call');

if (!empty($failures)) {
    fwrite(STDERR, "\n" . count($failures) . " failure(s)\n");
    exit(1);
}

echo "\nall assertions passed\n";
