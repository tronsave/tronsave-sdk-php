<?php

declare(strict_types=1);

namespace Tronsave;

/** Base class for every error thrown by the SDK. Carries the API `code`, HTTP `status`, and `raw` body. */
class TronsaveException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $code,
        public readonly ?int $status = null,
        public readonly mixed $raw = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/** Missing or invalid API key (HTTP 401). */
class TronsaveAuthException extends TronsaveException {}

/** Invalid request parameters (HTTP 400). */
class TronsaveValidationException extends TronsaveException {}

/** A business rule prevented the operation, e.g. insufficient balance (HTTP 400). */
class TronsaveBusinessException extends TronsaveException {}

/** Rate limit exceeded (HTTP 429). */
class TronsaveRateLimitException extends TronsaveException
{
    public function __construct(
        string $message,
        string $code,
        ?int $status = null,
        mixed $raw = null,
        public readonly ?int $retryAfterMs = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $status, $raw, $previous);
    }
}

/** Transport-level failure: timeout, connection, or unparseable response. */
class TronsaveNetworkException extends TronsaveException {}

/** Documented error codes + classification, mirrored from spec/openapi.yaml. */
final class ErrorCodes
{
    public const AUTH = ['API_KEY_REQUIRED', 'INVALID_API_KEY'];
    public const VALIDATION = ['MISSING_PARAMS', 'INVALID_PARAMS', 'MIN_PRICE_INVALID'];
    public const BUSINESS = [
        'INTERNAL_ACCOUNT_NOT_FOUND',
        'INTERNAL_BALANCE_ACCOUNT_TOO_LOW',
        'CANNOT_FULFILLED',
        'MUST_BE_WAIT_PREVIOUS_ORDER_FILLED',
        'PRICE_EXCEED_MAX_PRICE_REQUIRED',
        'SOME_DELEGATE_CANNOT_EXTEND',
    ];
    public const RATE_LIMIT = 'RATE_LIMIT';

    public const ALL = [
        ...self::AUTH,
        ...self::VALIDATION,
        ...self::BUSINESS,
        self::RATE_LIMIT,
    ];

    /** Build the most specific exception for an HTTP failure or error envelope. */
    public static function make(?string $message, ?int $status, mixed $raw = null, ?int $retryAfterMs = null): TronsaveException
    {
        $code = self::extract($message, $raw) ?? 'UNKNOWN';
        $msg = $message !== null && $message !== '' ? $message : $code;

        if ($code === self::RATE_LIMIT || $status === 429) {
            return new TronsaveRateLimitException($msg, self::RATE_LIMIT, $status, $raw, $retryAfterMs);
        }
        if (in_array($code, self::AUTH, true) || $status === 401) {
            return new TronsaveAuthException($msg, $code, $status, $raw);
        }
        if (in_array($code, self::VALIDATION, true)) {
            return new TronsaveValidationException($msg, $code, $status, $raw);
        }
        if (in_array($code, self::BUSINESS, true)) {
            return new TronsaveBusinessException($msg, $code, $status, $raw);
        }
        if ($status === 400) {
            return new TronsaveValidationException($msg, $code, $status, $raw);
        }
        return new TronsaveException($msg, $code, $status, $raw);
    }

    /** Find a known code as the whole message or embedded in a longer string/body. */
    private static function extract(?string $message, mixed $raw): ?string
    {
        if ($message !== null && in_array($message, self::ALL, true)) {
            return $message;
        }
        $haystack = ($message ?? '') . ' ' . (is_string($raw) ? $raw : json_encode($raw ?? []));
        foreach (self::ALL as $code) {
            if (str_contains($haystack, $code)) {
                return $code;
            }
        }
        return null;
    }
}
