<?php
// Run: php tests/webhook_test.php   (no dependencies; exits non-zero on failure)

declare(strict_types=1);

require __DIR__ . '/../src/Webhook.php';

use Directsaz\Sdk\Webhook;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}
function sign(string $token, int $ts, string $body): string
{
    return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $token);
}

check('challenge: correct token -> 200', Webhook::handleChallenge('secret-token', 'secret-token')['status'] === 200);
foreach ([null, '', 'secret', 'secret-token-x'] as $p) {
    check('challenge: wrong token ' . var_export($p, true) . ' -> 401', Webhook::handleChallenge($p, 'secret-token')['status'] === 401);
}
check('challenge: empty configured token, empty provided -> 401', Webhook::handleChallenge('', '')['status'] === 401);
check('challenge: empty configured token, any provided -> 401', Webhook::handleChallenge('x', '')['status'] === 401);

$body = '{"events":[]}';
$ts = time();
$h = sign('secret-token', $ts, $body);
check('signature: valid -> true', Webhook::verifySignature('secret-token', $body, (string) $ts, $h));
check('signature: tampered body -> false', !Webhook::verifySignature('secret-token', $body . ' ', (string) $ts, $h));
check('signature: wrong token -> false', !Webhook::verifySignature('other', $body, (string) $ts, $h));
check('signature: empty token refuses a matching HMAC', !Webhook::verifySignature('', $body, (string) $ts, sign('', $ts, $body)));
$old = $ts - 301;
check('signature: stale timestamp -> false', !Webhook::verifySignature('t', $body, (string) $old, sign('t', $old, $body)));

exit($failed === 0 ? 0 : 1);
