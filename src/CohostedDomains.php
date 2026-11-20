<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\CohostedDomains as WireCohostedDomains;

/**
 * From IP Hosted Domains: the other registered domains on the domain's first IPv4 address, how many
 * and the 10 most popular.
 */
final class CohostedDomains
{
    public function __construct(
        /** The domain's first IPv4 address, `''` where it has none. */
        public readonly string $ip,
        /** How many other registered domains resolve to it. */
        public readonly int $total,
        /**
         * Up to 10 of them, the most popular first, the domain itself left out; `[]` when none does.
         *
         * @var list<string>
         */
        public readonly array $domains,
    ) {
    }

    /** @internal */
    public static function fromWire(WireCohostedDomains $w): self
    {
        return new self(
            ip: $w->getIp(),
            total: $w->getTotal(),
            domains: array_values($w->getDomains()),
        );
    }
}
