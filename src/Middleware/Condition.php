<?php

declare(strict_types=1);

namespace InternetData\Middleware;

use InternetData\Result;

/**
 * Deciding whether an answer is worth blocking.
 *
 * A condition is written in the shape of a `Result` and keyed by the same names it
 * uses, so what you write here reads like what you get back:
 *
 *     ['location' => ['country' => ['CU', 'IR', 'KP']]]
 *     ['asn' => ['type' => 'hosting']]
 *     ['isResproxy' => true, 'resproxy' => ['hits' => ['gte' => 5]]]
 *     ['isVpn' => true, 'vpn' => ['provider' => 'nordvpn']]
 *
 * A value may be a scalar (equality, strings without regard to case), a LIST meaning
 * any-of, a map of `gte`/`gt`/`lte`/`lt` bounding a number, or a nested condition. A
 * member set to `false` or `null` is ignored entirely - a condition states the
 * positive signals you act on, so there is no way to write "block when this is false",
 * which would otherwise read as blocking everybody.
 *
 * A LIST of conditions at the top level means any one of them blocking is enough.
 */
final class Condition
{
    private const BOUND_KEYS = ['gte', 'gt', 'lte', 'lt'];

    /**
     * Whether an answer satisfies the condition, and should therefore be blocked.
     *
     * @param array<mixed> $condition
     */
    public static function matches(array $condition, Result $result): bool
    {
        foreach (self::asList($condition) as $one) {
            if (self::matchesObject($one, $result)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The members a condition names that this answer did not carry: a top-level member,
     * or a paid one inside an object every plan gets, by its path (`location.city`).
     *
     * A field your plan does not include is absent rather than false, so a condition
     * naming one can never match and the block would silently never fire. A detail
     * object present but empty (`isEmpty()`) is a real answer meaning the flag above it
     * is false, so nothing inside one is reported: `vpn` empty is not a plan gap. A
     * populated object carries every member the plan includes, so a member missing
     * there is one.
     *
     * A locally answered bogon needs no special case: it is synthesized in the widest
     * shape, so every member is present and nothing reads as missing.
     *
     * @param array<mixed> $condition
     * @return list<string>
     */
    public static function missingMembers(array $condition, Result $result): array
    {
        $missing = [];
        foreach (self::asList($condition) as $one) {
            self::collectMissing($one, $result, '', $missing);
        }
        return $missing;
    }

    /**
     * Refuse a condition that constrains nothing.
     *
     * Ignoring `false` means `['isVpn' => false]` and `[]` have no terms left to
     * satisfy, so they would match every answer and block all traffic. Nobody writes
     * that on purpose, and failing when the middleware is built beats discovering it
     * in production.
     *
     * @param array<mixed>|null $condition
     */
    public static function validate(?array $condition): void
    {
        if ($condition === null) {
            return;
        }
        foreach (self::asList($condition) as $one) {
            if (self::constraintCount($one) === 0) {
                throw new \InvalidArgumentException(
                    'internetdata: block condition constrains nothing, which would block every '
                    . 'request; a member set to false or null is ignored, so state the positive '
                    . 'signals you act on'
                );
            }
        }
    }

    /** How many leaf constraints a condition actually carries. */
    public static function constraintCount(mixed $condition): int
    {
        if ($condition === null || $condition === false) {
            return 0;
        }
        if (is_array($condition)) {
            if (self::isBound($condition)) {
                return 1;
            }
            $n = 0;
            foreach ($condition as $entry) {
                $n += self::constraintCount($entry);
            }
            return $n;
        }
        return 1;
    }

    /**
     * @param array<mixed> $condition
     * @return list<array<string, mixed>>
     */
    private static function asList(array $condition): array
    {
        if ($condition !== [] && array_is_list($condition)) {
            /** @var list<array<string, mixed>> */
            return $condition;
        }
        /** @var array<string, mixed> $condition */
        return [$condition];
    }

    /**
     * @param array<string, mixed> $condition
     * @param list<string> $missing
     */
    private static function collectMissing(
        array $condition,
        object $value,
        string $prefix,
        array &$missing,
    ): void {
        foreach ($condition as $member => $want) {
            if (self::constraintCount($want) === 0) {
                continue;
            }
            $path = $prefix . $member;
            $got = property_exists($value, (string) $member) ? $value->{$member} : null;
            if ($got === null) {
                if (!in_array($path, $missing, true)) {
                    $missing[] = $path;
                }
                continue;
            }
            if (is_array($want) && !array_is_list($want) && !self::isBound($want) && self::populated($got)) {
                /** @var array<string, mixed> $want */
                self::collectMissing($want, $got, "{$path}.", $missing);
            }
        }
    }

    /**
     * Whether a member is an object holding at least one property: an empty detail
     * object is the answer under a false flag, never a plan gap.
     */
    private static function populated(mixed $value): bool
    {
        if (!is_object($value)) {
            return false;
        }
        foreach (get_object_vars($value) as $property) {
            if ($property !== null) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $condition */
    private static function matchesObject(array $condition, object $value): bool
    {
        foreach ($condition as $member => $want) {
            if (self::constraintCount($want) === 0) {
                continue;
            }
            $got = property_exists($value, (string) $member) ? $value->{$member} : null;
            if (!self::matchesValue($want, $got)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether one value satisfies one want.
     *
     * An ABSENT member arrives here as null, which is exactly what "not in your plan"
     * looks like. Every branch below must therefore reject it, which is what makes an
     * unserved member fail a match rather than pass it.
     */
    private static function matchesValue(mixed $want, mixed $got): bool
    {
        if (is_array($want)) {
            if (self::isBound($want)) {
                return self::matchesBound($want, $got);
            }
            if (array_is_list($want)) {
                foreach ($want as $entry) {
                    if (self::matchesValue($entry, $got)) {
                        return true;
                    }
                }
                return false;
            }
            /** @var array<string, mixed> $want */
            return is_object($got) && self::matchesObject($want, $got);
        }
        if (is_string($want)) {
            // Providers are lowercase slugs and countries uppercase codes on the wire, and
            // a caller should not have to know which, so a string compares without case.
            return is_string($got) && strcasecmp($want, $got) === 0;
        }
        if (is_bool($want)) {
            return $want === $got;
        }
        if (is_int($want) || is_float($want)) {
            return (is_int($got) || is_float($got)) && $want == $got;
        }
        return $want === $got;
    }

    /** @param array<string, mixed> $bound */
    private static function matchesBound(array $bound, mixed $got): bool
    {
        if (!is_int($got) && !is_float($got)) {
            return false;
        }
        if (isset($bound['gte']) && $got < $bound['gte']) {
            return false;
        }
        if (isset($bound['gt']) && $got <= $bound['gt']) {
            return false;
        }
        if (isset($bound['lte']) && $got > $bound['lte']) {
            return false;
        }
        return !(isset($bound['lt']) && $got >= $bound['lt']);
    }

    /** @param array<mixed> $value */
    private static function isBound(array $value): bool
    {
        return $value !== [] && array_diff(array_keys($value), self::BOUND_KEYS) === [];
    }
}
