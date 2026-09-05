<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DatabaseVersion as WireDatabaseVersion;

/** One published version of a database family. */
final class DatabaseVersion
{
    public function __construct(
        /** The versioned id, e.g. `bogon_ip_v1`. This is what a download takes. */
        public readonly string $id,
        public readonly int $version,
        public readonly string $summary,
        /**
         * The formats this version is BUILT in. Asking for another is a 400
         * rather than a gap: the `_provider` catalogs are keyed by provider id,
         * so no MMDB exists for them.
         *
         * @var list<string>
         */
        public readonly array $formats,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDatabaseVersion $w): self
    {
        return new self(
            id: $w->getId(),
            version: $w->getVersion(),
            summary: $w->getSummary(),
            formats: array_values($w->getFormats()),
        );
    }
}
