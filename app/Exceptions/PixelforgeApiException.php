<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PixelforgeApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        public readonly ?array $errorDetails = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Build an informative exception from a failed Pixelforge HTTP response.
     */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $errorCode = self::extractString($error['code'] ?? $body['code'] ?? null);
        $rawMessage = self::extractString(
            $error['message'] ?? $body['message'] ?? null
        );
        $details = self::extractDetails($error, $body);
        $retryAfter = self::extractRetryAfter($response);

        $message = match ($status) {
            429 => self::buildRateLimitMessage($errorCode, $rawMessage, $retryAfter),
            503 => self::buildOverloadedMessage($rawMessage, $retryAfter),
            422 => self::buildValidationMessage($rawMessage, $details),
            default => self::buildGenericMessage($status, $errorCode, $rawMessage, $response->body()),
        };

        return new self(
            message: $message,
            statusCode: $status,
            errorCode: $errorCode,
            errorDetails: $details,
            retryAfter: $retryAfter,
        );
    }

    public function isRateOrConcurrencyLimited(): bool
    {
        return $this->statusCode === 429;
    }

    public function isOverloaded(): bool
    {
        return $this->statusCode === 503;
    }

    public function isValidationError(): bool
    {
        return $this->statusCode === 422;
    }

    public function isRetryable(): bool
    {
        return $this->statusCode === 0
            || $this->statusCode === 429
            || $this->statusCode >= 500;
    }

    private static function buildRateLimitMessage(?string $errorCode, ?string $rawMessage, ?int $retryAfter): string
    {
        $isConcurrency = str_contains(strtolower((string) $errorCode), 'concurrency')
            || str_contains(strtolower((string) $rawMessage), 'concurrency');

        $label = $isConcurrency ? 'concurrency limit exceeded' : 'rate limit exceeded';
        $message = "Pixelforge {$label} (HTTP 429)";

        if ($rawMessage !== null) {
            $message .= ": {$rawMessage}";
        }

        if ($retryAfter !== null) {
            $message .= " (Retry after {$retryAfter}s)";
        }

        return $message;
    }

    private static function buildOverloadedMessage(?string $rawMessage, ?int $retryAfter): string
    {
        $message = 'Pixelforge service is temporarily overloaded (HTTP 503)';

        if ($rawMessage !== null) {
            $message .= ": {$rawMessage}";
        }

        if ($retryAfter !== null) {
            $message .= " (Retry after {$retryAfter}s)";
        }

        return $message;
    }

    private static function buildValidationMessage(?string $rawMessage, ?array $details): string
    {
        $message = 'Pixelforge validation failed (HTTP 422)';

        if ($rawMessage !== null) {
            $message .= ": {$rawMessage}";
        }

        if ($details !== null && $details !== []) {
            $flattened = self::flattenValidationErrors($details);
            if ($flattened !== '') {
                $message .= " [{$flattened}]";
            }
        }

        return $message;
    }

    private static function buildGenericMessage(
        int $status,
        ?string $errorCode,
        ?string $rawMessage,
        string $rawBody,
    ): string {
        $message = "Pixelforge API error (HTTP {$status})";

        if ($errorCode !== null) {
            $message .= " [{$errorCode}]";
        }

        if ($rawMessage !== null) {
            $message .= ": {$rawMessage}";
        } elseif ($rawBody !== '') {
            $message .= ': '.mb_substr(trim($rawBody), 0, 300);
        }

        return $message;
    }

    private static function extractString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function extractDetails(array $error, array $body): ?array
    {
        if (is_array($error['details'] ?? null)) {
            return $error['details'];
        }

        if (is_array($body['errors'] ?? null)) {
            return $body['errors'];
        }

        return null;
    }

    private static function extractRetryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if ($header === '' || ! is_numeric($header)) {
            return null;
        }

        return max(0, (int) $header);
    }

    private static function flattenValidationErrors(array $details): string
    {
        $parts = [];

        foreach ($details as $field => $messages) {
            if (is_array($messages)) {
                $joined = implode(', ', array_map('strval', $messages));
                $parts[] = "{$field}: {$joined}";
            } elseif (is_scalar($messages)) {
                $parts[] = is_string($field) ? "{$field}: {$messages}" : (string) $messages;
            }
        }

        return implode('; ', $parts);
    }
}

