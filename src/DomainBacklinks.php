<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainBacklinks as WireDomainBacklinks;

/**
 * The registered domains whose home page links to this one, how many and the 10 most popular.
 */
final class DomainBacklinks
{
    public function __construct(
        /** How many registered domains have a home page that links to this domain. */
        public readonly int $total,
        /**
         * Up to 10 of them, the most popular first; `[]` when none does.
         *
         * @var list<string>
         */
        public readonly array $domains,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainBacklinks $w): self
    {
        return new self(
            total: $w->getTotal(),
            domains: array_values($w->getDomains()),
        );
    }
}
