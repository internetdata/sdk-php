<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\HostedDomains as WireHostedDomains;

/**
 * From IP Hosted Domains: how many registered domains resolve to the address, and the 10 most popular.
 */
final class HostedDomains
{
    public function __construct(
        /** How many registered domains resolve to the address. */
        public readonly int $total,
        /**
         * Up to 10 of them, the most popular first; `[]` when none does.
         *
         * @var list<string>|null
         */
        public readonly ?array $domains = null,
    ) {
    }

    /** @internal */
    public static function fromWire(WireHostedDomains $w): self
    {
        return new self(
            total: $w->getTotal(),
            domains: $w->getDomains() === null ? null : array_values($w->getDomains()),
        );
    }
}
