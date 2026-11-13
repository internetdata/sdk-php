<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\Company as WireCompany;

/**
 * Who uses the address, from IP Company.
 *
 * A property only a paid plan or the trial answers is null on Unlimited, which means not in your
 * plan. The rest are set whenever the object is.
 */
final class Company
{
    public function __construct(
        /** Who uses the address. */
        public readonly string $name,
        /** Their domain. */
        public readonly string $domain,
        /**
         * What kind of organization: hosting, isp, business, education, government. Paid plans and
         * the trial.
         */
        public readonly ?string $type = null,
        /** ISO 3166 country code. Paid plans and the trial. */
        public readonly ?string $country = null,
    ) {
    }

    /** @internal */
    public static function fromWire(WireCompany $w): self
    {
        return new self(
            name: $w->getName(),
            domain: $w->getDomain(),
            type: $w->getType(),
            country: $w->getCountry(),
        );
    }
}
