<?php

declare(strict_types=1);

namespace Directsaz\Sdk;

use Directsaz\Sdk\Exception\ApiException;
use Directsaz\Sdk\Exception\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Client for the Directsaz developer-worker REST API.
 *
 * Auth: Authorization: Bearer ds_live_{app_key}_{secret}
 */
final class Client
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://developers.directsaz.com/api/v1',
        private readonly ?ClientInterface $http = null,
        private readonly ?RequestFactoryInterface $requestFactory = null,
        private readonly ?StreamFactoryInterface $streamFactory = null,
        private readonly float $timeoutSeconds = 15.0,
    ) {
    }

    /** HTTP status of the most recent response, or null before the first call. */
    private ?int $lastStatusCode = null;

    /**
     * HTTP status of the most recent response.
     *
     * POST /send answers 202 to mean "queued" — the Meta delivery happens
     * afterwards and its failures never reach the caller — so 202 and 200 are
     * worth telling apart:
     *
     *     $ds->sendDm($recipientId, 'Hello');
     *     if ($ds->lastStatusCode() === 202) { ... }
     *
     * Reflects the last call made on this client instance.
     */
    public function lastStatusCode(): ?int
    {
        return $this->lastStatusCode;
    }

    /**
     * Build a client using Guzzle (suggested dependency).
     */
    public static function createDefault(string $apiKey, string $baseUrl = 'https://developers.directsaz.com/api/v1', float $timeout = 15.0): self
    {
        if (!class_exists(\GuzzleHttp\Client::class)) {
            throw new \RuntimeException('Install guzzlehttp/guzzle or pass a PSR-18 client.');
        }

        $guzzle = new \GuzzleHttp\Client(['timeout' => $timeout, 'http_errors' => false]);

        return new self(
            apiKey: $apiKey,
            baseUrl: rtrim($baseUrl, '/'),
            http: $guzzle,
            requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            streamFactory: new \GuzzleHttp\Psr7\HttpFactory(),
            timeoutSeconds: $timeout,
        );
    }

    public function send(array $payload): array
    {
        return $this->request('POST', '/send', $payload);
    }

    public function sendDm(string $recipientId, string $text, array $buttons = []): array
    {
        $body = [
            'action' => 'dm',
            'recipient_id' => $recipientId,
            'type' => 'text',
            'text' => $text,
        ];
        if ($buttons !== []) {
            $body['buttons'] = $buttons;
        }

        return $this->send($body);
    }

    public function sendImage(string $recipientId, string $mediaUrl, array $buttons = []): array
    {
        $body = [
            'action' => 'dm',
            'recipient_id' => $recipientId,
            'type' => 'image',
            'media_url' => $mediaUrl,
        ];
        if ($buttons !== []) {
            $body['buttons'] = $buttons;
        }

        return $this->send($body);
    }

    public function sendVideo(string $recipientId, string $mediaUrl): array
    {
        return $this->send([
            'action' => 'dm',
            'recipient_id' => $recipientId,
            'type' => 'video',
            'media_url' => $mediaUrl,
        ]);
    }

    public function sendCarousel(string $recipientId, array $cards): array
    {
        return $this->send([
            'action' => 'dm',
            'recipient_id' => $recipientId,
            'type' => 'carousel',
            'cards' => $cards,
        ]);
    }

    public function privateReply(string $commentId, string $text, array $extra = []): array
    {
        return $this->send(array_merge([
            'action' => 'private_reply',
            'comment_id' => $commentId,
            'text' => $text,
        ], $extra));
    }

    public function commentReply(string $commentId, string $text): array
    {
        return $this->send([
            'action' => 'comment_reply',
            'comment_id' => $commentId,
            'text' => $text,
        ]);
    }

    public function like(string $recipientId, string $messageId): array
    {
        return $this->send([
            'action' => 'like',
            'recipient_id' => $recipientId,
            'message_id' => $messageId,
        ]);
    }

    public function listPosts(?int $limit = 30, ?string $after = null, ?string $before = null): array
    {
        $q = array_filter([
            'limit' => $limit,
            'after' => $after,
            'before' => $before,
        ], fn ($v) => $v !== null && $v !== '');

        $path = '/posts';
        if ($q !== []) {
            $path .= '?' . http_build_query($q);
        }

        return $this->request('GET', $path);
    }

    public function followStatus(string $userId): array
    {
        return $this->request('GET', '/users/' . rawurlencode($userId) . '/follow-status');
    }

    private function request(string $method, string $path, ?array $json = null, bool $auth = true): array
    {
        if ($this->http === null || $this->requestFactory === null || $this->streamFactory === null) {
            throw new TransportException('HTTP client not configured. Use Client::createDefault().');
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json');

        if ($auth) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $this->apiKey);
        }

        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                throw new TransportException('Failed to encode JSON body');
            }
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->http->sendRequest($request);
        } catch (\Throwable $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }

        $raw = (string) $response->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $status = $response->getStatusCode();
        $this->lastStatusCode = $status;

        if (!is_array($decoded)) {
            throw new ApiException('Invalid JSON response', $status, null, $raw);
        }

        $ok = ($decoded['success'] ?? null) === true || ($status >= 200 && $status < 300 && !isset($decoded['success']));
        if (!$ok || $status >= 400) {
            throw ApiException::fromResponse($status, $decoded, $raw);
        }

        return $decoded;
    }
}
