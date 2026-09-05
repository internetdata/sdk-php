<?php

declare(strict_types=1);

namespace InternetData;

use DateTimeImmutable;
use InternetData\Internal\Model\DatabaseMetadata as WireDatabaseMetadata;

/**
 * What is inside one database.
 *
 * Poll this to decide whether today's build is worth fetching: `updated` and
 * `entries` come back without downloading anything, and `size` is what a
 * transfer can be budgeted against before it starts.
 */
final class DatabaseMetadata
{
    public function __construct(
        public readonly string $id,
        /** How often a new build is published. */
        public readonly ?string $updateFreq,
        /** The date the published build was generated on. */
        public readonly DateTimeImmutable $updated,
        /** Row count in the current build. */
        public readonly int $entries,
        /**
         * Columns, keyed by format.
         *
         * @var array<string, list<DatabaseMetadataColumn>>
         */
        public readonly array $schema,
        /**
         * A few real rows, keyed by format.
         *
         * @var array<string, list<array<string, mixed>>>
         */
        public readonly array $sample,
        /**
         * Bytes per format.
         *
         * @var array<string, int>
         */
        public readonly array $size,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDatabaseMetadata $w): self
    {
        $schema = [];
        foreach ($w->getSchema() as $format => $columns) {
            $schema[$format] = array_map(DatabaseMetadataColumn::fromWire(...), $columns);
        }
        return new self(
            id: $w->getId(),
            updateFreq: $w->getUpdateFreq(),
            updated: Dates::required($w->getUpdated()),
            entries: $w->getEntries(),
            schema: $schema,
            sample: self::rows($w->getSample() ?? []),
            size: $w->getSize(),
        );
    }

    /**
     * A sample row has no schema to generate against, so it arrives as the
     * `stdClass` the JSON decoder produced. An array is what the rest of this
     * object hands out and what a caller can `foreach` over without knowing
     * which half of the response they are holding.
     */
    private static function rows(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        return array_map(self::rows(...), $value);
    }
}
