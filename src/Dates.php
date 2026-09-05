<?php

declare(strict_types=1);

namespace InternetData;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * @internal
 *
 * The generated models parse a date into a mutable `DateTime`. Every value
 * object here is readonly, so handing one a date a caller can mutate would
 * leave a hole in the middle of an otherwise immutable object.
 */
final class Dates
{
    public static function immutable(?DateTimeInterface $value): ?DateTimeImmutable
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value);
    }

    public static function required(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value);
    }
}
