<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainHost as WireDomainHost;

/**
 * An address the domain resolves to and the network announcing it.
 */
final class DomainHost
{
    public function __construct(
        /** One address the domain resolves to. */
        public readonly string $ip,
        /** The network announcing it, as an address lookup's `asn`; every member empty where IP ASN holds none. */
        public readonly Asn $asn,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainHost $w): self
    {
        return new self(
            ip: $w->getIp(),
            asn: Asn::fromWire($w->getAsn()),
        );
    }
}
