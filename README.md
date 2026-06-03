# tronsave/sdk (PHP)

Fully-typed PHP SDK for the [Tronsave](https://tronsave.io) v2 API (TRON Energy & Bandwidth).
PHP 8.1+, PSR-18 HTTP client (Guzzle by default).

## Install

```bash
composer require tronsave/sdk
```

## Quick start

```php
use Tronsave\TronsaveClient;
use Tronsave\Network;

$sdk = new TronsaveClient(
    apiKey: getenv('TRONSAVE_API_KEY'),
    network: Network::Mainnet, // or Network::Testnet
);

$me = $sdk->getUserInfo();

$est = $sdk->estimateBuyResource([
    'receiver' => 'TFwUFWr3QV376677Z8VWXxGUAMFSrq1MbM',
    'resourceType' => 'ENERGY',
    'resourceAmount' => 65000, // ENERGY must be > 64000
    'durationSec' => 3600,
]);

$order = $sdk->buyResource(
    ['receiver' => 'TFwUFWr3QV376677Z8VWXxGUAMFSrq1MbM', 'resourceAmount' => 65000, 'durationSec' => 3600],
    ['allowPartialFill' => true, 'maxPriceAccepted' => 100],
);

$status = $sdk->getOrder($order->orderId);
echo $status->fulfilledPercent; // 100 = fully delegated
```

## Errors

```php
use Tronsave\{TronsaveBusinessException, TronsaveRateLimitException};

try {
    $sdk->buyResource(['receiver' => 'T...', 'resourceAmount' => 65000]);
} catch (TronsaveBusinessException $e) {
    // $e->code, $e->status, $e->raw
} catch (TronsaveRateLimitException $e) {
    // $e->retryAfterMs
}
```

All exceptions extend `Tronsave\TronsaveException` (`->code`, `->status`, `->raw`). Classes:
`TronsaveAuthException` (401), `TronsaveValidationException` / `TronsaveBusinessException` (400),
`TronsaveRateLimitException` (429), `TronsaveNetworkException` (transport).

## Notes

- Defaults: `resourceType=ENERGY`, `unitPrice=MEDIUM`, `durationSec=259200`.
- ENERGY `resourceAmount` must be **> 64000** (rejected client-side).
- `extendTo` is a **Unix timestamp in seconds**.
- Rate limits enforced client-side (15/s; 1/s for `getExtendableDelegates`).

## Develop

```bash
composer install
composer test   # runs the shared conformance suite (needs Node.js for the mock server)
```

See [`../../spec/PARITY.md`](../../spec/PARITY.md) for cross-language behavior parity.
