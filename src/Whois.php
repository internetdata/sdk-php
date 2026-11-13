<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\Whois as WireWhois;

/**
 * IP Whois's most specific record covering the address. Every property is set.
 */
final class Whois
{
    public function __construct(
        /** Network name of the most specific record covering the address. */
        public readonly string $id,
        /** The record's holder. */
        public readonly string $name,
        /** The registry's handle for the holder. */
        public readonly string $org,
        /** ISO 3166 country code. */
        public readonly string $country,
        /** The registry's assignment status. */
        public readonly string $status,
        /** The registry, or rwhois. */
        public readonly string $source,
        /** The holder's domain. */
        public readonly string $domain,
        /** The record's abuse contact. */
        public readonly string $abuseEmail,
        /** The AS announcing the record's start address. */
        public readonly int $asn,
    ) {
    }

    /** @internal */
    public static function fromWire(WireWhois $w): self
    {
        return new self(
            id: $w->getId(),
            name: $w->getName(),
            org: $w->getOrg(),
            country: $w->getCountry(),
            status: $w->getStatus(),
            source: $w->getSource(),
            domain: $w->getDomain(),
            abuseEmail: $w->getAbuseEmail(),
            asn: $w->getAsn(),
        );
    }
}
