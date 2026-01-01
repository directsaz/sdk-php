# Directsaz PHP SDK

Composer package for the Directsaz **developer-worker** API (`/send`, `/posts`, `/users/.../follow-status`) plus webhook challenge + signature helpers. Works with Laravel, Symfony, or plain PHP.

## Install

```bash
composer require directsaz/sdk guzzlehttp/guzzle
```

(Path repository, to develop against a local clone of this repo:)

```json
{
  "repositories": [{ "type": "path", "url": "/path/to/sdk-php" }],
  "require": { "directsaz/sdk": "*" }
}
```

## Send API

```php
use Directsaz\Sdk\Client;

$ds = Client::createDefault(
    apiKey: getenv('DIRECTSAZ_API_KEY'),
    baseUrl: 'https://developers.directsaz.com/api/v1',
);

$ds->sendDm($recipientId, 'Hello');
$ds->sendImage($recipientId, 'https://cdn.example.com/a.jpg', [
    ['title' => 'Open', 'url' => 'https://example.com'],
]);
$ds->sendCarousel($recipientId, [/* cards */]);
$ds->privateReply($commentId, 'Thanks!');
$ds->commentReply($commentId, 'Public reply');
$ds->like($recipientId, $messageId);
$ds->listPosts(limit: 30);
$ds->followStatus($userId);
```

## Errors

```php
use Directsaz\Sdk\Exception\ApiException;

try {
    $ds->sendDm($recipientId, str_repeat('x', 1500));
} catch (ApiException $e) {
    $e->statusCode;   // 422
    $e->errors();     // [['field' => 'text', 'rule' => 'max_bytes', 'msg' => '…got 1500']]
    $e->rawBody;      // the response body, useful when it was not valid JSON
}
```

`errors()` is always an array, empty when the response carried no field-level detail.
Transport failures (DNS, TCP, TLS, timeout) raise `TransportException` instead.

## `202` means queued, not sent

Sends are delivered to Instagram asynchronously — a Meta failure *after* queueing never reaches
you — so 202 and 200 are worth telling apart:

```php
$ds->sendDm($recipientId, 'Hello');
$ds->lastStatusCode();   // 202 = accepted for delivery
```

It reflects the most recent call made on that client instance.

## Webhook (Laravel)

```php
// routes/api.php
Route::match(['get', 'post'], '/hooks/directsaz', [\App\Http\Controllers\DirectsazHook::class, 'handle']);

// app/Http/Controllers/DirectsazHook.php
use Directsaz\Sdk\Laravel\WebhookController;
use Directsaz\Sdk\Client;

class DirectsazHook extends WebhookController
{
    protected function onEvents(array $events, $request): void
    {
        $client = Client::createDefault(env('DIRECTSAZ_API_KEY'));
        foreach ($events as $event) {
            if (($event['type'] ?? '') === 'dm') {
                $rid = $event['actions']['dm']['recipient_id'] ?? null;
                if ($rid) {
                    $client->sendDm($rid, 'Got it');
                }
            }
        }
    }
}
```

Env: `DIRECTSAZ_WEBHOOK_TOKEN`, `DIRECTSAZ_API_KEY`, optional `DIRECTSAZ_VERIFY_SIGNATURE=true`. An empty or unset
`DIRECTSAZ_WEBHOOK_TOKEN` fails closed: every challenge gets 401 and every delivery is rejected.

## Webhook (plain PHP)

```php
use Directsaz\Sdk\Webhook;

$token = getenv('DIRECTSAZ_WEBHOOK_TOKEN');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $r = Webhook::handleChallenge($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null, $token);
    http_response_code($r['status']);
    echo $r['body'];
    exit;
}

$raw = file_get_contents('php://input');
if (!Webhook::verifySignature($token, $raw, $_SERVER['HTTP_X_DIRECTSAZ_TIMESTAMP'] ?? null, $_SERVER['HTTP_X_DIRECTSAZ_SIGNATURE'] ?? null)) {
    http_response_code(401);
    exit('invalid signature');
}
$events = Webhook::parseEvents($raw);
http_response_code(200);
echo json_encode(['ok' => true]);
```
