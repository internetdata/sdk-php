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
 *
 * Their getters for a lookup's seen days return one too, which `day()` turns
 * back into the string the public classes carry.
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

    /**
     * A seen day back in the form it was served. The spec gives these members
     * `format: date`, so the wire layer checks each against RFC 3339's
     * full-date on a real day and parses it; the public classes keep the
     * string they held before that format was published.
     *
     * @return ($value is null ? null : string)
     */
    public static function day(?DateTimeInterface $value): ?string
    {
        return $value?->format('Y-m-d');
    }
}
