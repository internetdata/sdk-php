<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DnsNsRecord as WireDnsNsRecord;

/**
 * An NS record.
 */
final class DnsNsRecord
{
    public function __construct(
        /** The name server. */
        public readonly string $host,
        /** The last day seen. */
        public readonly string $lastSeen,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDnsNsRecord $w): self
    {
        return new self(
            host: $w->getHost(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
