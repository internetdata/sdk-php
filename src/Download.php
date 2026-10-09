<?php

declare(strict_types=1);

namespace InternetData;

use DateTimeImmutable;
use InternetData\Internal\Model\Download as WireDownload;

/** One download ATTEMPT your organization made, refusals included. */
final class Download
{
    public function __construct(
        public readonly string $databaseId,
        public readonly string $format,
        /** `ok`, `unauthorized`, `denied`, `expired`, `unknown` or `unavailable`. */
        public readonly string $outcome,
        /**
         * Object size when the link was minted, NOT bytes delivered: the
         * transfer runs straight from object storage, so the API never observes
         * how much of it was taken.
         */
        public readonly ?int $bytes,
        public readonly ?int $httpStatus,
        /** The key that made the request. Null when it could not be resolved. */
        public readonly ?string $apiKeyId,
        public readonly ?string $clientIp,
        public readonly ?string $userAgent,
        public readonly DateTimeImmutable $created,
        /** The evaluation sample rather than the database itself. */
        public readonly bool $sample = false,
        /**
         * Taken under the Open license rather than one of your licenses: an Open
         * database downloaded while your organization held no license in term
         * for it.
         */
        public readonly bool $open = false,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDownload $w): self
    {
        return new self(
            databaseId: $w->getDatasetId(),
            format: $w->getFormat(),
            outcome: $w->getOutcome(),
            bytes: $w->getBytes(),
            httpStatus: $w->getHttpStatus(),
            apiKeyId: $w->getApikeyId(),
            clientIp: $w->getClientIp(),
            userAgent: $w->getUserAgent(),
            created: Dates::required($w->getCreated()),
            sample: $w->getSample(),
            open: $w->getOpen(),
        );
    }
}
