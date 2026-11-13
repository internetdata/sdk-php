<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainIpRange as WireDomainIpRange;

/**
 * One of an organization's IP ranges.
 */
final class DomainIpRange
{
    public function __construct(
        /** The range. */
        public readonly string $range,
        /** The range's registry name. */
        public readonly string $name,
        /** ISO 3166 country code. */
        public readonly string $country,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainIpRange $w): self
    {
        return new self(
            range: $w->getRange(),
            name: $w->getName(),
            country: $w->getCountry(),
        );
    }
}
