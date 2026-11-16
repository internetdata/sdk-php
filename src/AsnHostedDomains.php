<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\AsnHostedDomains as WireAsnHostedDomains;

/**
 * From ASN Hosted Domains: how many registered domains the AS hosts, and the 10 most popular.
 */
final class AsnHostedDomains
{
    public function __construct(
        /** How many registered domains resolve to an address the AS announces. */
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
    public static function fromWire(WireAsnHostedDomains $w): self
    {
        return new self(
            total: $w->getTotal(),
            domains: $w->getDomains() === null ? null : array_values($w->getDomains()),
        );
    }
}
