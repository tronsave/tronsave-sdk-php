<?php

declare(strict_types=1);

namespace Tronsave;

/** The resource being rented. */
enum ResourceType: string
{
    case ENERGY = 'ENERGY';
    case BANDWIDTH = 'BANDWIDTH';
}

/** Named unit-price tiers (a raw int SUN price may also be passed where a tier is accepted). */
enum PriceTier: string
{
    case FAST = 'FAST';
    case MEDIUM = 'MEDIUM';
    case SLOW = 'SLOW';
}

/** Order type as reported by the API. */
enum OrderType: string
{
    case NORMAL = 'NORMAL';
    case EXTEND = 'EXTEND';
    case FAST = 'FAST';
}

/** Lifecycle status in history listings. */
enum OrderStatus: string
{
    case Active = 'Active';
    case Completed = 'Completed';
}

/** Target network. */
enum Network: string
{
    case Mainnet = 'mainnet';
    case Testnet = 'testnet';

    public function baseUrl(): string
    {
        return $this === self::Mainnet ? 'https://api.tronsave.io' : 'https://api-dev.tronsave.io';
    }

    public function fundAddress(): string
    {
        return $this === self::Mainnet
            ? 'TWZEhq5JuUVvGtutNgnRBATbF8BnHGyn4S'
            : 'TATT1UzHRikft98bRFqApFTsaSw73ycfoS';
    }
}
