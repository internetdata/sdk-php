<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainIpRanges as WireDomainIpRanges;

/**
 * The organization's IP ranges, from Domain IP Ranges.
 */
final class DomainIpRanges
{
    public function __construct(
        /** The organization's domain. */
        public readonly string $domain,
        /** Where its home page redirects, when that is how it was reached. */
        public readonly string $redirectsTo,
        /** How many ranges it holds. */
        public readonly int $total,
        /** IPv4 addresses across them. */
        public readonly int $ipv4,
        /**
         * The first 1000, IPv4 before IPv6, the largest first.
         *
         * @var list<DomainIpRange>
         */
        public readonly array $ranges,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainIpRanges $w): self
    {
        return new self(
            domain: $w->getDomain(),
            redirectsTo: $w->getRedirectsTo(),
            total: $w->getTotal(),
            ipv4: $w->getIpv4(),
            ranges: array_map(DomainIpRange::fromWire(...), array_values($w->getRanges())),
        );
    }
}
