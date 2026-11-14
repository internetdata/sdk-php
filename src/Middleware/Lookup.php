<?php

declare(strict_types=1);

namespace InternetData\Middleware;

use InternetData\InternetDataException;
use InternetData\Result;

/** What a middleware attached to the request, whether or not it succeeded. */
final class Lookup
{
    public function __construct(
        /** Whether the condition matched. Always false when no condition was configured. */
        public readonly bool $blocked,
        /** The address that was classified, as the selector resolved it. */
        public readonly ?string $ip = null,
        /** The answer. Null when the lookup failed. */
        public readonly ?Result $result = null,
        /** Why the lookup failed. Null when it succeeded. */
        public readonly ?InternetDataException $error = null,
    ) {
    }
}
