<?php

declare(strict_types=1);

namespace InternetData;

use DateTimeImmutable;
use InternetData\Internal\Model\Database as WireDatabase;

/**
 * One database FAMILY, with your organization's license beside it.
 *
 * A license is held against the family, while a download names one version, so
 * the ids you pass to `download`, `downloadBytes`, `downloadUrl`, `checksums`
 * and `metadata` come from `versions` rather than from here.
 */
final class Database
{
    public function __construct(
        /** The family, e.g. `bogon_ip`. What a license is held against. */
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
         * What your license permits: `evaluation`, `standard` or `redistribute`.
         * Null when there is no license.
         */
        public readonly ?string $licenseType,
        public readonly ?DateTimeImmutable $starts,
        /** Null when the license has no end date, or when there is none. */
        public readonly ?DateTimeImmutable $expires,
        /**
         * Every published version of this family, oldest first.
         *
         * @var list<DatabaseVersion>
         */
        public readonly array $versions,
        /**
         * When a rolling license next renews. Null when the license has no
         * defined term, when `expires` sets a hard stop instead, and when there
         * is none.
         */
        public readonly ?DateTimeImmutable $renewsAt = null,
        /**
         * The last day notice of non-renewal can be given for the term ending at
         * `renewsAt`. Null whenever that is, and when the agreement records no
         * notice period.
         */
        public readonly ?DateTimeImmutable $noticeDueAt = null,
        /**
         * An Open database: any organization downloads it, and fetches its
         * checksums, with no license, under CC BY-SA 4.0. `standing` still
         * reports your own license, which grants more where you hold one.
         */
        public readonly bool $open = false,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDatabase $w): self
    {
        return new self(
            base: $w->getBase(),
            name: $w->getName(),
            summary: $w->getSummary(),
            standing: $w->getStanding()->value,
            licenseType: $w->getLicenseType(),
            starts: Dates::immutable($w->getStarts()),
            expires: Dates::immutable($w->getExpires()),
            versions: array_map(DatabaseVersion::fromWire(...), $w->getVersions()),
            renewsAt: Dates::immutable($w->getRenewsAt()),
            noticeDueAt: Dates::immutable($w->getNoticeDueAt()),
            open: $w->getOpen(),
        );
    }
}
