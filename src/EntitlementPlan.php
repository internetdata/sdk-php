<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\EntitlementPlan as WireEntitlementPlan;

/** The plan behind the key, and the field tier it buys. */
final class EntitlementPlan
{
    public function __construct(
        /** The plan the organization is on, as an identifier that is never renamed or reused: `free` or a paid plan's key. */
        public readonly string $key,
        /**
         * The field tier, which decides how much of a lookup answer comes back:
         * `free`, `starter`, `scale` or `max`. What each tier includes is
         * documented on the lookup endpoint rather than repeated here.
         */
        public readonly string $tier,
        /**
         * The plan's name as the site and console show it: `Unlimited` for the free plan. Show
         * this, and compare against `key`, which is never renamed.
         */
        public readonly string $name = '',
    ) {
    }

    /** @internal */
    public static function fromWire(WireEntitlementPlan $w): self
    {
        return new self(key: $w->getKey(), tier: $w->getTier(), name: $w->getName());
    }
}
