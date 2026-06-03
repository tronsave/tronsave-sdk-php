<?php

declare(strict_types=1);

namespace Tronsave;

/**
 * Response DTOs. Each keeps the raw decoded payload so `jsonSerialize()` round-trips exactly
 * (which the conformance suite relies on) while exposing typed, readonly accessors.
 */

final class UserInfo implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $balance,
        public readonly string $representAddress,
        public readonly string $depositAddress,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self($a['id'], $a['balance'], $a['representAddress'], $a['depositAddress'], $a);
    }

    public function jsonSerialize(): array { return $this->raw; }
}

final class EstimateBuyResource implements \JsonSerializable
{
    public function __construct(
        public readonly int|float $unitPrice,
        public readonly int $durationSec,
        public readonly int|float $estimateTrx,
        public readonly int $availableResource,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self($a['unitPrice'], $a['durationSec'], $a['estimateTrx'], $a['availableResource'], $a);
    }

    public function jsonSerialize(): array { return $this->raw; }
}

final class BuyResourceResult implements \JsonSerializable
{
    public function __construct(public readonly string $orderId, private readonly array $raw) {}

    public static function fromArray(array $a): self { return new self($a['orderId'], $a); }

    public function jsonSerialize(): array { return $this->raw; }
}

final class Delegate implements \JsonSerializable
{
    public function __construct(
        public readonly string $delegator,
        public readonly int $amount,
        public readonly string $txid,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self($a['delegator'], $a['amount'], $a['txid'], $a);
    }

    public function jsonSerialize(): array { return $this->raw; }
}

final class Order implements \JsonSerializable
{
    /** @param Delegate[] $delegates */
    public function __construct(
        public readonly string $id,
        public readonly string $receiver,
        public readonly int $resourceAmount,
        public readonly ResourceType $resourceType,
        public readonly int $remainAmount,
        public readonly int|float $price,
        public readonly int $durationSec,
        public readonly OrderType $orderType,
        public readonly bool $allowPartialFill,
        public readonly int|float $payoutAmount,
        public readonly int|float $fulfilledPercent,
        public readonly array $delegates,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            $a['id'],
            $a['receiver'],
            $a['resourceAmount'],
            ResourceType::from($a['resourceType']),
            $a['remainAmount'],
            $a['price'],
            $a['durationSec'],
            OrderType::from($a['orderType']),
            $a['allowPartialFill'],
            $a['payoutAmount'],
            $a['fulfilledPercent'],
            array_map(static fn (array $d): Delegate => Delegate::fromArray($d), $a['delegates'] ?? []),
            $a,
        );
    }

    public function jsonSerialize(): array { return $this->raw; }
}

final class OrdersPage implements \JsonSerializable
{
    /** @param Order[] $data */
    public function __construct(
        public readonly array $data,
        public readonly int $total,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            array_map(static fn (array $o): Order => Order::fromArray($o), $a['data'] ?? []),
            $a['total'],
            $a,
        );
    }

    public function jsonSerialize(): array { return $this->raw; }
}

final class OrderBookLevel implements \JsonSerializable
{
    public function __construct(
        public readonly int|float $price,
        public readonly int $availableResourceAmount,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self($a['price'], $a['availableResourceAmount'], $a);
    }

    public function jsonSerialize(): array { return $this->raw; }
}

final class ExtendableDelegates implements \JsonSerializable
{
    public function __construct(
        public readonly array $extendOrderBook,
        public readonly int $totalDelegateAmount,
        public readonly int $totalAvailableExtendAmount,
        public readonly int|float $totalEstimateTrx,
        public readonly int|float $yourBalance,
        public readonly bool $isAbleToExtend,
        public readonly array $extendData,
        private readonly array $raw,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            $a['extendOrderBook'] ?? [],
            $a['totalDelegateAmount'],
            $a['totalAvailableExtendAmount'],
            $a['totalEstimateTrx'],
            $a['yourBalance'],
            $a['isAbleToExtend'],
            $a['extendData'] ?? [],
            $a,
        );
    }

    public function jsonSerialize(): array { return $this->raw; }
}
