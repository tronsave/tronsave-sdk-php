<?php

declare(strict_types=1);

namespace Tronsave;

use Tronsave\Http\HttpClient;

/**
 * Strongly-typed client for the Tronsave v2 API.
 *
 * ```php
 * $sdk = new TronsaveClient(apiKey: getenv('TRONSAVE_API_KEY'), network: Network::Testnet);
 * $me  = $sdk->getUserInfo();
 * ```
 */
final class TronsaveClient
{
    private const DEFAULTS = ['resourceType' => 'ENERGY', 'unitPrice' => 'MEDIUM', 'durationSec' => 259200];
    private const MIN_RESOURCE_AMOUNT = ['ENERGY' => 64000]; // exclusive: amount must be > value

    private readonly HttpClient $http;

    /** @param array<string, array{capacity: int, intervalMs: int}> $rateLimit */
    public function __construct(
        ?string $apiKey = null,
        Network $network = Network::Mainnet,
        ?string $baseUrl = null,
        int $timeoutMs = 30000,
        int $maxRetries = 3,
        array $rateLimit = [],
        ?HttpClient $http = null,
    ) {
        $this->http = $http ?? new HttpClient(
            baseUrl: $baseUrl ?? $network->baseUrl(),
            apiKey: $apiKey,
            timeoutMs: $timeoutMs,
            maxRetries: $maxRetries,
            rateLimit: $rateLimit,
        );
    }

    /** @param array<string, mixed> $params @param array<string, mixed> $options */
    public function estimateBuyResource(array $params, array $options = []): EstimateBuyResource
    {
        $body = $this->orderBody($params, $options);
        return EstimateBuyResource::fromArray($this->http->request('POST', '/v2/estimate-buy-resource', body: $body, auth: false));
    }

    /** @param array<string, mixed> $params @param array<string, mixed> $options */
    public function buyResource(array $params, array $options = []): BuyResourceResult
    {
        $this->assertMinAmount($params);
        $body = $this->orderBody($params, $options);
        return BuyResourceResult::fromArray($this->http->request('POST', '/v2/buy-resource', body: $body, auth: !isset($params['signedTx'])));
    }

    public function getUserInfo(): UserInfo
    {
        return UserInfo::fromArray($this->http->request('GET', '/v2/user-info'));
    }

    public function getOrder(string $id): Order
    {
        return Order::fromArray($this->http->request('GET', '/v2/order/' . rawurlencode($id)));
    }

    public function getOrders(int $page = 0, int $pageSize = 10, ?OrderStatus $status = null): OrdersPage
    {
        $query = ['page' => $page, 'pageSize' => $pageSize];
        if ($status !== null) {
            $query['status'] = $status->value;
        }
        return OrdersPage::fromArray($this->http->request('GET', '/v2/orders', query: $query));
    }

    /**
     * @param array<string, mixed> $params
     * @return OrderBookLevel[]
     */
    public function getOrderBook(array $params = []): array
    {
        $query = ['resourceType' => $params['resourceType'] ?? self::DEFAULTS['resourceType']];
        foreach (['address', 'minDelegateAmount', 'durationSec'] as $k) {
            if (isset($params[$k])) {
                $query[$k] = $params[$k];
            }
        }
        $data = $this->http->request('GET', '/v2/order-book', query: $query);
        return array_map(static fn (array $l): OrderBookLevel => OrderBookLevel::fromArray($l), $data);
    }

    /** @param array<string, mixed> $params */
    public function getExtendableDelegates(array $params): ExtendableDelegates
    {
        $body = [
            'receiver' => $params['receiver'],
            'extendTo' => $params['extendTo'], // Unix seconds
            'resourceType' => $params['resourceType'] ?? self::DEFAULTS['resourceType'],
        ];
        if (isset($params['requester'])) {
            $body['requester'] = $params['requester'];
        }
        if (isset($params['maxPriceAccepted'])) {
            $body['maxPriceAccepted'] = $params['maxPriceAccepted'];
        }
        return ExtendableDelegates::fromArray($this->http->request('POST', '/v2/get-extendable-delegates', body: $body));
    }

    /** @param array<string, mixed> $params */
    public function extendRequest(array $params): BuyResourceResult
    {
        $body = [
            'receiver' => $params['receiver'],
            'extendData' => $params['extendData'],
            'resourceType' => $params['resourceType'] ?? self::DEFAULTS['resourceType'],
        ];
        if (isset($params['signedTx'])) {
            $body['signedTx'] = $params['signedTx'];
        }
        return BuyResourceResult::fromArray($this->http->request('POST', '/v2/extend-request', body: $body, auth: !isset($params['signedTx'])));
    }

    /**
     * Server-side signing (sends your private key — prefer local TronWeb signing).
     * @param array<string, mixed> $params
     * @return array<string, mixed> The raw signed-transaction object (no envelope).
     */
    public function getSignedTransaction(array $params): array
    {
        return $this->http->request('POST', '/v2/signed-tx', body: [
            'address' => $params['address'],
            'privateKey' => $params['privateKey'],
            'estimateTrx' => $params['estimateTrx'],
        ], auth: false, raw: true);
    }

    /**
     * Whitelist-only buyer order list.
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function listBuyerOrders(array $params = []): array
    {
        $query = [];
        foreach (['startTimestamp', 'endTimestamp', 'pageSize', 'cursor', 'direction'] as $k) {
            if (isset($params[$k])) {
                $query[$k] = $params[$k];
            }
        }
        return $this->http->request('GET', '/v2/internal/buyer/orders', query: $query);
    }

    /** Whitelist-only monthly stats. @return array<string, mixed> */
    public function getMonthlyStats(): array
    {
        return $this->http->request('GET', '/v2/internal/buyer/monthly-stats');
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function orderBody(array $params, array $options): array
    {
        $body = [
            'receiver' => $params['receiver'],
            'resourceAmount' => $params['resourceAmount'],
            'resourceType' => $params['resourceType'] ?? self::DEFAULTS['resourceType'],
            'unitPrice' => $params['unitPrice'] ?? self::DEFAULTS['unitPrice'],
            'durationSec' => $params['durationSec'] ?? self::DEFAULTS['durationSec'],
        ];
        foreach (['requester', 'sponsor', 'signedTx'] as $k) {
            if (isset($params[$k])) {
                $body[$k] = $params[$k];
            }
        }
        if ($options !== []) {
            $body['options'] = $options;
        }
        return $body;
    }

    /** @param array<string, mixed> $params */
    private function assertMinAmount(array $params): void
    {
        $type = $params['resourceType'] ?? self::DEFAULTS['resourceType'];
        $type = $type instanceof ResourceType ? $type->value : (string) $type;
        $min = self::MIN_RESOURCE_AMOUNT[$type] ?? null;
        if ($min !== null && !((int) $params['resourceAmount'] > $min)) {
            throw new TronsaveValidationException("resourceAmount must be greater than {$min} for {$type}", 'INVALID_PARAMS');
        }
    }
}
