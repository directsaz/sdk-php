<?php

declare(strict_types=1);

namespace Directsaz\Sdk;

/**
 * Helpers for implementing the Directsaz developer webhook endpoint.
 */
final class Webhook
{
    public const HEADER_TOKEN = 'X-Webhook-Token';
    public const HEADER_TIMESTAMP = 'X-Directsaz-Timestamp';
    public const HEADER_SIGNATURE = 'X-Directsaz-Signature';
    public const HEADER_EVENT = 'X-Directsaz-Event';

    /**
     * Handle the GET challenge used when subscribing a webhook.
     * Without token → 401; with matching token → 200.
     *
     * @return array{status: int, body: string}
     */
    public static function handleChallenge(?string $providedToken, string $expectedToken): array
    {
        if ($providedToken === null || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            return ['status' => 401, 'body' => 'unauthorized'];
        }

        return ['status' => 200, 'body' => 'ok'];
    }

    /**
     * Verify POST delivery signature.
     * Signature header format: t=<unix>,v1=<hex hmac>
     */
    public static function verifySignature(
        string $webhookToken,
        string $rawBody,
        ?string $timestampHeader,
        ?string $signatureHeader,
        int $maxSkewSeconds = 300,
    ): bool {
        if ($timestampHeader === null || $signatureHeader === null || $timestampHeader === '' || $signatureHeader === '') {
            return false;
        }

        $ts = (int) $timestampHeader;
        if ($ts <= 0) {
            return false;
        }
        if (abs(time() - $ts) > $maxSkewSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $webhookToken);
        $got = self::extractV1($signatureHeader);
        if ($got === null) {
            return false;
        }

        return hash_equals($expected, $got);
    }

    public static function extractV1(string $signatureHeader): ?string
    {
        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'v1=')) {
                return substr($part, 3);
            }
        }

        return null;
    }

    /**
     * Parse events array from webhook JSON body.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseEvents(string|array $body): array
    {
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                return [];
            }
            $body = $decoded;
        }

        $events = $body['events'] ?? [];

        return is_array($events) ? array_values($events) : [];
    }
}
