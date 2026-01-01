<?php

declare(strict_types=1);

namespace Directsaz\Sdk\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?array $payload = null,
        public readonly ?string $rawBody = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(int $status, array $payload, string $raw): self
    {
        $msg = (string) ($payload['msg'] ?? $payload['message'] ?? 'API error');

        return new self($msg, $status, $payload, $raw);
    }

    public function errors(): array
    {
        $errors = $this->payload['errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
