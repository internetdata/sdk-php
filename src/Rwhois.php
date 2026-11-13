<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\Rwhois as WireRwhois;

/**
 * IP RWHOIS's most specific network covering the address. Every property is set.
 */
final class Rwhois
{
    public function __construct(
        /** The network's identifier on the operator's RWHOIS server. */
        public readonly string $id,
        /** The network's assignee. */
        public readonly string $name,
        /** ISO 3166 country code. */
        public readonly string $country,
        /** The operator that published it. */
        public readonly string $source,
        /** The contact emails' domain. */
        public readonly string $domain,
        /** The network's abuse contact. */
        public readonly string $abuseEmail,
    ) {
    }

    /** @internal */
    public static function fromWire(WireRwhois $w): self
    {
        return new self(
            id: $w->getId(),
            name: $w->getName(),
            country: $w->getCountry(),
            source: $w->getSource(),
            domain: $w->getDomain(),
            abuseEmail: $w->getAbuseEmail(),
        );
    }
}
