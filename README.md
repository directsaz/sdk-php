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

Env: `DIRECTSAZ_WEBHOOK_TOKEN`, `DIRECTSAZ_API_KEY`, optional `DIRECTSAZ_VERIFY_SIGNATURE=true`.

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
