<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\AbuseContact as WireAbuseContact;

/**
 * Where to report abuse from the address, from IP Abuse Contact.
 *
 * A property only a paid plan or the trial answers is null on Unlimited, which means not in your
 * plan. The rest are set whenever the object is.
 */
final class AbuseContact
{
    public function __construct(
        /** Where to report abuse from the address. */
        public readonly string $email,
        /** The contact. Paid plans and the trial. */
        public readonly ?string $name = null,
        /** Its phone number. Paid plans and the trial. */
        public readonly ?string $phone = null,
        /** Its postal address. Paid plans and the trial. */
        public readonly ?string $address = null,
        /** ISO 3166 country code. Paid plans and the trial. */
        public readonly ?string $country = null,
        /** The registry block it was derived from. Paid plans and the trial. */
        public readonly ?string $network = null,
        /** ARIN reports the contact answered its yearly validation. Paid plans and the trial. */
        public readonly ?bool $validated = null,
        /** Since when it has not. Paid plans and the trial. */
        public readonly ?string $unvalidatedSince = null,
    ) {
    }

    /** @internal */
    public static function fromWire(WireAbuseContact $w): self
    {
        return new self(
            email: $w->getEmail(),
            name: $w->getName(),
            phone: $w->getPhone(),
            address: $w->getAddress(),
            country: $w->getCountry(),
            network: $w->getNetwork(),
            validated: $w->getValidated(),
            unvalidatedSince: $w->getUnvalidatedSince(),
        );
    }
}
