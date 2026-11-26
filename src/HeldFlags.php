<?php

declare(strict_types=1);

namespace InternetData;

/**
 * The eight lookup flags `Result` holds at a plain `bool` until the next major, by the
 * property a caller reads and the name the wire serves it under. Unlimited's answer leaves
 * them out and each reads false; one served as null is still refused.
 *
 * @internal
 */
final class HeldFlags
{
    public const WIRE = [
        'isBot' => 'is_bot',
        'isCarrier' => 'is_carrier',
        'isSatellite' => 'is_satellite',
        'isSsh' => 'is_ssh',
        'isSmtp' => 'is_smtp',
        'isWeb' => 'is_web',
        'isDnsResolver' => 'is_dns_resolver',
        'isBittorrent' => 'is_bittorrent',
    ];
}
