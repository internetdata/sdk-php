<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DnsAddressRecord as WireDnsAddressRecord;

/**
 * An A or AAAA record.
 */
final class DnsAddressRecord
{
    public function __construct(
        /** The address. */
        public readonly string $ip,
        /** The last day the snapshot saw it. */
        public readonly string $lastSeen,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDnsAddressRecord $w): self
    {
        return new self(
            ip: $w->getIp(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
