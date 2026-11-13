<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\HostedDomains as WireHostedDomains;

/**
 * From IP Hosted Domains: how many registered domains resolve to the address, never the list.
 */
final class HostedDomains
{
    public function __construct(
        /** How many registered domains resolve to the address. */
        public readonly int $total,
    ) {
    }

    /** @internal */
    public static function fromWire(WireHostedDomains $w): self
    {
        return new self(
            total: $w->getTotal(),
        );
    }
}
