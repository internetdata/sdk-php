<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainRedirects as WireDomainRedirects;

/**
 * The registered domains whose home page redirects to this one, how many and the 10 most popular.
 */
final class DomainRedirects
{
    public function __construct(
        /** How many registered domains have a home page that redirects to this domain. */
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
    public static function fromWire(WireDomainRedirects $w): self
    {
        return new self(
            total: $w->getTotal(),
            domains: array_values($w->getDomains()),
        );
    }
}
