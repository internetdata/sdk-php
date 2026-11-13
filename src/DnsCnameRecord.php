<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DnsCnameRecord as WireDnsCnameRecord;

/**
 * A CNAME record.
 */
final class DnsCnameRecord
{
    public function __construct(
        /** Where it points. */
        public readonly string $target,
        /** The last day seen. */
        public readonly string $lastSeen,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDnsCnameRecord $w): self
    {
        return new self(
            target: $w->getTarget(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
