<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\Asn as WireAsn;

/**
 * The network announcing the address, from IP ASN: `asn` is 0 where none does.
 *
 * A nullable property is null where the answer leaves it out. The rest are set whenever the
 * object is.
 */
final class Asn
{
    public function __construct(
        /** The AS announcing the address, 0 where none does. */
        public readonly int $asn,
        /** The AS's operator. */
        public readonly string $name,
        /** The operator's domain. */
        public readonly string $domain,
        /** The kind of network it runs. */
        public readonly string $type,
        /** The announced prefix covering the address. */
        public readonly string $prefix,
        /** The country its registry assigned it to. */
        public readonly ?string $country = null,
        /** The announcement's RPKI status. */
        public readonly ?string $rpkiStatus = null,
    ) {
    }

    /** @internal */
    public static function fromWire(WireAsn $w): self
    {
        return new self(
            asn: $w->getAsn(),
            name: $w->getName(),
            domain: $w->getDomain(),
            type: $w->getType(),
            prefix: $w->getPrefix(),
            country: $w->getCountry(),
            rpkiStatus: $w->getRpkiStatus(),
        );
    }
}
