<?php

declare(strict_types=1);

namespace Directsaz\Sdk\Laravel;

use Directsaz\Sdk\Webhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Drop-in Laravel controller methods for Directsaz webhook GET/POST.
 *
 * Usage in routes/web.php or api.php:
 *   Route::match(['get','post'], '/hooks/directsaz', [DirectsazWebhookController::class, 'handle']);
 *
 * Set env: DIRECTSAZ_WEBHOOK_TOKEN
 */
class WebhookController
{
    public function handle(Request $request): SymfonyResponse
    {
        $token = (string) config('services.directsaz.webhook_token', env('DIRECTSAZ_WEBHOOK_TOKEN', ''));

        if ($request->isMethod('GET')) {
            $result = Webhook::handleChallenge($request->header(Webhook::HEADER_TOKEN), $token);

            return response($result['body'], $result['status']);
        }

        $raw = $request->getContent();
        $verify = (bool) config('services.directsaz.verify_signature', env('DIRECTSAZ_VERIFY_SIGNATURE', true));

        if ($verify && !Webhook::verifySignature(
            $token,
            $raw,
            $request->header(Webhook::HEADER_TIMESTAMP),
            $request->header(Webhook::HEADER_SIGNATURE),
        )) {
            return response('invalid signature', 401);
        }

        $events = Webhook::parseEvents($raw);
        $this->onEvents($events, $request);

        return response()->json(['ok' => true]);
    }

    /**
     * Override in your app, or listen and dispatch jobs here.
     *
     * @param list<array<string, mixed>> $events
     */
    protected function onEvents(array $events, Request $request): void
    {
        // no-op by default
    }
}
