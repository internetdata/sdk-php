<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\ProxyDetail as WireProxyDetail;

/**
 * A row of Residential Proxy IP, Datacenter Proxy IP or Mobile Proxy IP, each measured over a
 * rolling 90-day window.
 *
 * Every property is set when the object is populated; the whole object is EMPTY (`isEmpty()`) when
 * its flag is false.
 */
final class ProxyDetail
{
    public function __construct(
        /** The proxy pool's ID in its catalog, empty where unattributed. */
        public readonly ?string $provider = null,
        /** The name the catalog gives it. */
        public readonly ?string $providerName = null,
        /** The first day of the window the address was seen as an exit. */
        public readonly ?string $firstSeen = null,
        /** The last day of the window it was seen as an exit. */
        public readonly ?string $lastSeen = null,
        /** How many times it was seen in the window. */
        public readonly ?int $hits = null,
        /** On what share of the window's days, in percent. */
        public readonly ?int $hitsDaysPct = null,
        /** How many pools it was seen in. */
        public readonly ?int $providersNum = null,
    ) {
    }

    /** True when the API answered `{}`, which means the flag above it is false. */
    public function isEmpty(): bool
    {
        return $this->provider === null && $this->providerName === null && $this->firstSeen === null
            && $this->lastSeen === null && $this->hits === null && $this->hitsDaysPct === null
            && $this->providersNum === null;
    }

    /** @internal */
    public static function fromWire(WireProxyDetail $w): self
    {
        return new self(
            provider: $w->getProvider(),
            providerName: $w->getProviderName(),
            firstSeen: $w->getFirstSeen(),
            lastSeen: $w->getLastSeen(),
            hits: $w->getHits(),
            hitsDaysPct: $w->getHitsDaysPct(),
            providersNum: $w->getProvidersNum(),
        );
    }
}
