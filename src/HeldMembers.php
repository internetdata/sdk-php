<?php

declare(strict_types=1);

namespace InternetData;

/**
 * The lookup members `Result` and `Asn` hold at their plain type until the next major, though
 * Unlimited's answer leaves them out: eight flags that read false and the AS's `type`, which
 * reads `''`. One served as null is still refused.
 *
 * @internal
 */
final class HeldMembers
{
    /** The eight flags, by the property a caller reads and the name the wire serves it under. */
    public const FLAGS = [
        'isBot' => 'is_bot',
        'isCarrier' => 'is_carrier',
        'isSatellite' => 'is_satellite',
        'isSsh' => 'is_ssh',
        'isSmtp' => 'is_smtp',
        'isWeb' => 'is_web',
        'isDnsResolver' => 'is_dns_resolver',
        'isBittorrent' => 'is_bittorrent',
    ];

    /** The member of an `Asn` the answer may leave out, by wire name. */
    public const ASN = 'type';

    /**
     * Whether a member held at its plain type was left out of the answer the result was read
     * from, by its path in a condition (`isCarrier`, `asn.type`). A result with no wire answer
     * behind it (`raw` empty: a bogon answered locally) carries every member, so none is unserved.
     */
    public static function unserved(Result $result, string $path): bool
    {
        if ($result->raw === []) {
            return false;
        }
        if (isset(self::FLAGS[$path])) {
            return !array_key_exists(self::FLAGS[$path], $result->raw);
        }
        $asn = $result->raw['asn'] ?? null;
        return $path === 'asn.' . self::ASN && is_array($asn) && !array_key_exists(self::ASN, $asn);
    }
}
