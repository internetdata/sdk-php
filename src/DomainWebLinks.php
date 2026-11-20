<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainWebLinks as WireDomainWebLinks;

/**
 * The external hosts a domain's home page links to.
 */
final class DomainWebLinks
{
    public function __construct(
        /** How many external hosts the home page links to. */
        public readonly int $total,
        /**
         * Up to 10 of them; `[]` when it links to none.
         *
         * @var list<string>
         */
        public readonly array $hosts,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainWebLinks $w): self
    {
        return new self(
            total: $w->getTotal(),
            hosts: array_values($w->getHosts()),
        );
    }
}
