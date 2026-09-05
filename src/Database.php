<?php

declare(strict_types=1);

namespace InternetData;

use DateTimeImmutable;
use InternetData\Internal\Model\Database as WireDatabase;

/**
 * One database FAMILY, with your organization's licence beside it.
 *
 * A licence is held against the family, while a download names one version, so
 * the ids you pass to `download`, `downloadBytes`, `downloadUrl`, `checksums`
 * and `metadata` come from `versions` rather than from here.
 */
final class Database
{
    public function __construct(
        /** The family, e.g. `bogon_ip`. What a licence is held against. */
        public readonly string $base,
        public readonly string $name,
        /** One line on what the newest version contains. */
        public readonly string $summary,
        /**
         * `licensed` is a live grant, `expired` one whose term has ended, and
         * `unlicensed` a database published but never bought.
         */
        public readonly string $standing,
        /**
         * What your licence permits: `evaluation`, `internal` or `redistribute`.
         * Null when there is no licence.
         */
        public readonly ?string $license_type,
        public readonly ?DateTimeImmutable $starts,
        /** Null when the licence has no end date, or when there is none. */
        public readonly ?DateTimeImmutable $expires,
        /**
         * Every published version of this family, oldest first.
         *
         * @var list<DatabaseVersion>
         */
        public readonly array $versions,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDatabase $w): self
    {
        return new self(
            base: $w->getBase(),
            name: $w->getName(),
            summary: $w->getSummary(),
            standing: $w->getStanding(),
            license_type: $w->getLicenseType(),
            starts: Dates::immutable($w->getStarts()),
            expires: Dates::immutable($w->getExpires()),
            versions: array_map(DatabaseVersion::fromWire(...), $w->getVersions()),
        );
    }
}
