<?php

declare(strict_types=1);

namespace Tronsave\Http;

/** Per-endpoint token-bucket limiter. Defaults: 15 req/s; 1 req/s for get-extendable-delegates. */
final class RateLimiter
{
    private const DEFAULTS = [
        'default' => ['capacity' => 15, 'intervalMs' => 1000],
        '/v2/get-extendable-delegates' => ['capacity' => 1, 'intervalMs' => 1000],
    ];

    /** @var array<string, array{tokens: float, last: float, capacity: int, intervalMs: int}> */
    private array $buckets = [];

    /** @param array<string, array{capacity: int, intervalMs: int}> $overrides */
    public function __construct(private readonly array $overrides = []) {}

    private function nowMs(): float
    {
        return microtime(true) * 1000.0;
    }

    /** @return array{capacity: int, intervalMs: int} */
    private function specFor(string $key): array
    {
        return $this->overrides[$key] ?? self::DEFAULTS[$key] ?? self::DEFAULTS['default'];
    }

    public function acquire(string $key): void
    {
        if (!isset($this->buckets[$key])) {
            $spec = $this->specFor($key);
            $this->buckets[$key] = [
                'tokens' => (float) $spec['capacity'],
                'last' => $this->nowMs(),
                'capacity' => $spec['capacity'],
                'intervalMs' => $spec['intervalMs'],
            ];
        }

        while (true) {
            $b = &$this->buckets[$key];
            $now = $this->nowMs();
            $elapsed = $now - $b['last'];
            if ($elapsed > 0) {
                $rate = $b['capacity'] / $b['intervalMs'];
                $b['tokens'] = min((float) $b['capacity'], $b['tokens'] + $elapsed * $rate);
                $b['last'] = $now;
            }
            if ($b['tokens'] >= 1.0) {
                $b['tokens'] -= 1.0;
                return;
            }
            $deficit = 1.0 - $b['tokens'];
            $waitMs = (int) ceil($deficit * ($b['intervalMs'] / $b['capacity']));
            usleep(max(1, $waitMs) * 1000);
        }
    }
}
