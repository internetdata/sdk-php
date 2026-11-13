<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\AsnPrefix as WireAsnPrefix;

/**
 * A prefix an AS announces, from BGP Route. Every property is set.
 */
final class AsnPrefix
{
    public function __construct(
        /** The announced prefix. */
        public readonly string $prefix,
        /** How many peers saw it. */
        public readonly int $peerCount,
        /** The announcement's RPKI status. */
        public readonly string $rpkiStatus,
        /** The first day BGP Route saw it. */
        public readonly string $firstSeen,
        /** Where its first address is. */
        public readonly string $country,
        /** IPv4 addresses it covers; 0 for IPv6. */
        public readonly int $size,
        /**
         * Every other AS announcing it.
         *
         * @var list<int>
         */
        public readonly array $otherOrigins,
    ) {
    }

    /** @internal */
    public static function fromWire(WireAsnPrefix $w): self
    {
        return new self(
            prefix: $w->getPrefix(),
            peerCount: $w->getPeerCount(),
            rpkiStatus: $w->getRpkiStatus(),
            firstSeen: $w->getFirstSeen(),
            country: $w->getCountry(),
            size: $w->getSize(),
            otherOrigins: array_values($w->getOtherOrigins()),
        );
    }
}
