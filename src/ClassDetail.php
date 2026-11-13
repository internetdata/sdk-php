<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\ClassDetail as WireClassDetail;

/**
 * A row of VPN IP, Tor IP, Relay IP, Hosting IP or CDN IP, the databases that share this shape.
 *
 * Every property is set when the object is populated; the whole object is EMPTY (`isEmpty()`) when
 * its flag is false.
 */
final class ClassDetail
{
    public function __construct(
        /** The provider's ID in its catalog; empty for an unattributed range, and on Tor. */
        public readonly ?string $provider = null,
        /** The name the catalog gives the provider, empty where it names none. */
        public readonly ?string $providerName = null,
        /** `high`, `medium` or `low`: how sure we are the range is what the flag says. */
        public readonly ?string $confidence = null,
        /** The last day the range was seen as such. */
        public readonly ?string $lastSeen = null,
    ) {
    }

    /** True when the API answered `{}`, which means the flag above it is false. */
    public function isEmpty(): bool
    {
        return $this->provider === null && $this->providerName === null && $this->confidence === null
            && $this->lastSeen === null;
    }

    /** @internal */
    public static function fromWire(WireClassDetail $w): self
    {
        return Transport::isEmpty($w) ? new self() : new self(
            provider: $w->getProvider(),
            providerName: $w->getProviderName(),
            confidence: $w->getConfidence(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
