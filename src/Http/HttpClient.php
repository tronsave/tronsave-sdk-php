<?php

declare(strict_types=1);

namespace Tronsave\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tronsave\ErrorCodes;
use Tronsave\TronsaveNetworkException;

/**
 * Transport: PSR-18 client + per-endpoint rate limiting, retry with exponential backoff + jitter
 * (network errors, 5xx, 429 honouring Retry-After), and Tronsave envelope parsing.
 */
final class HttpClient
{
    private const RETRYABLE = [408, 425, 429, 500, 502, 503, 504];

    private readonly ClientInterface $http;
    private readonly RequestFactoryInterface $requests;
    private readonly StreamFactoryInterface $streams;
    private readonly RateLimiter $limiter;

    /** @param array<string, array{capacity: int, intervalMs: int}> $rateLimit */
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        private readonly int $timeoutMs = 30000,
        private readonly int $maxRetries = 3,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $rateLimit = [],
    ) {
        $factory = new HttpFactory();
        $this->http = $http ?? new GuzzleClient([
            'timeout' => $this->timeoutMs / 1000,
            'http_errors' => false,
        ]);
        $this->requests = $requestFactory ?? $factory;
        $this->streams = $streamFactory ?? $factory;
        $this->limiter = new RateLimiter($rateLimit);
    }

    private static function bucketKey(string $path): string
    {
        $q = strpos($path, '?');
        return $q === false ? $path : substr($path, 0, $q);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return mixed Decoded `data` (or the raw decoded body when $raw is true).
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, bool $auth = true, bool $raw = false): mixed
    {
        $url = rtrim($this->baseUrl, '/') . $path . $this->encodeQuery($query);
        $bucket = self::bucketKey($path);

        $request = $this->requests->createRequest($method, $url);
        if ($body !== null) {
            $request = $request
                ->withHeader('content-type', 'application/json')
                ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }
        if ($auth) {
            if ($this->apiKey === null || $this->apiKey === '') {
                throw ErrorCodes::make('API_KEY_REQUIRED', 401);
            }
            $request = $request->withHeader('apikey', $this->apiKey);
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $this->limiter->acquire($bucket);

            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                $lastError = new TronsaveNetworkException($e->getMessage(), 'NETWORK_ERROR', null, null, $e);
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->backoffMs($attempt));
                    continue;
                }
                throw $lastError;
            }

            $status = $response->getStatusCode();
            if (in_array($status, self::RETRYABLE, true) && $attempt < $this->maxRetries) {
                $retryAfter = $this->retryAfterMs($response->getHeaderLine('retry-after'));
                $this->sleep($retryAfter ?? $this->backoffMs($attempt));
                continue;
            }

            return $this->handle($response, $raw);
        }

        throw $lastError ?? new TronsaveNetworkException('Request failed', 'NETWORK_ERROR');
    }

    private function handle(\Psr\Http\Message\ResponseInterface $response, bool $raw): mixed
    {
        $status = $response->getStatusCode();
        $text = (string) $response->getBody();
        $decoded = $text === '' ? null : json_decode($text, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? ($decoded['message'] ?? array_key_first($decoded))
                : (is_string($decoded) ? $decoded : null);
            throw ErrorCodes::make(is_string($message) ? $message : null, $status, $decoded, $this->retryAfterMs($response->getHeaderLine('retry-after')));
        }

        if ($raw) {
            return $decoded;
        }
        if (!is_array($decoded) || !array_key_exists('error', $decoded)) {
            throw new TronsaveNetworkException('Unexpected response shape (missing envelope)', 'NETWORK_ERROR', $status, $decoded);
        }
        if ($decoded['error'] === true) {
            throw ErrorCodes::make($decoded['message'] ?? null, $status, $decoded);
        }
        return $decoded['data'] ?? null;
    }

    /** @param array<string, scalar|null> $query */
    private function encodeQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            $pairs[$k] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        return $pairs === [] ? '' : '?' . http_build_query($pairs);
    }

    private function backoffMs(int $attempt): int
    {
        $exp = min(10000, 300 * (2 ** $attempt));
        return random_int(0, $exp);
    }

    private function retryAfterMs(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) max(0, (float) $value * 1000);
        }
        $ts = strtotime($value);
        return $ts === false ? null : (int) max(0, ($ts - time()) * 1000);
    }

    private function sleep(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
