<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DnsMxRecord as WireDnsMxRecord;

/**
 * An MX record.
 */
final class DnsMxRecord
{
    public function __construct(
        /** The record's priority. */
        public readonly int $priority,
        /** The mail host. */
        public readonly string $host,
        /** The last day seen. */
        public readonly string $lastSeen,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDnsMxRecord $w): self
    {
        return new self(
            priority: $w->getPriority(),
            host: $w->getHost(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
