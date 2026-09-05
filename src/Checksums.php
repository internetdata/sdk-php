<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DbChecksums as WireChecksums;

/** The digests published alongside one database file, all hex-encoded. */
final class Checksums
{
    public function __construct(
        public readonly string $md5,
        public readonly string $sha1,
        public readonly string $sha256,
        public readonly string $sha512,
    ) {
    }

    /** @internal */
    public static function fromWire(WireChecksums $w): self
    {
        return new self(
            md5: $w->getMd5(),
            sha1: $w->getSha1(),
            sha256: $w->getSha256(),
            sha512: $w->getSha512(),
        );
    }
}
