<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\AsnLookupResponse;

/**
 * What an AS lookup answers: what every database says about one AS.
 *
 * A NULL property is one your plan does not include. It never means "we could not check", and it
 * is never the same answer as zero or an empty string: null is "not in your plan".
 */
final class AsnResult
{
    public function __construct(
        /** The AS number. */
        public readonly int $asn,
        /** Its operator. */
        public readonly string $name,
        /** The country its registry assigned it to. */
        public readonly string $country,
        /** The registry that allocated it. */
        public readonly string $registry,
        /** When its registry allocated it. */
        public readonly string $allocated,
        /** Its operator's domain. */
        public readonly string $domain,
        /** The kind of network it runs. Paid plans and the trial. */
        public readonly ?string $type = null,
        /** IPv4 addresses it announces. Paid plans and the trial. */
        public readonly ?int $numIps = null,
        /** IPv4 prefixes it announces. Paid plans and the trial. */
        public readonly ?int $numPrefixes4 = null,
        /** IPv6 prefixes it announces. Paid plans and the trial. */
        public readonly ?int $numPrefixes6 = null,
        /** Its announcements RPKI calls valid. Paid plans and the trial. */
        public readonly ?int $rpkiValid = null,
        /** Its announcements RPKI calls invalid. Paid plans and the trial. */
        public readonly ?int $rpkiInvalid = null,
        /** Its announcements with no RPKI record. Paid plans and the trial. */
        public readonly ?int $rpkiNotFound = null,
        /**
         * Its ASN Whois record, every column, empty where the registry states nothing. Paid plans
         * and the trial.
         *
         * @var array<string, string>|null
         */
        public readonly ?array $whois = null,
        /**
         * What it announces, IPv4 then IPv6, up to 20000 a family. Paid plans and the trial.
         *
         * @var list<AsnPrefix>|null
         */
        public readonly ?array $prefixes = null,
        /**
         * On every answer to a key on the free plan, and on no other: `false` while the day's
         * trial of every field lasts, `true` once the answer has Unlimited's fields.
         */
        public readonly ?bool $trialExpired = null,
        /**
         * The response exactly as it came off the wire, with its original names.
         *
         * @var array<string, mixed>
         */
        public readonly array $raw = [],
    ) {
    }

    /**
     * @internal
     *
     * The one place the wire's snake_case becomes idiomatic camelCase. The
     * generated model already distinguishes an ABSENT member from one that is
     * present and false, so every assignment here is a straight copy and a plan
     * that includes a field and answers `false` keeps it.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromWire(AsnLookupResponse $w, array $raw): self
    {
        return new self(
            asn: $w->getAsn(),
            name: $w->getName(),
            country: $w->getCountry(),
            registry: $w->getRegistry(),
            allocated: $w->getAllocated(),
            domain: $w->getDomain(),
            type: $w->getType(),
            numIps: $w->getNumIps(),
            numPrefixes4: $w->getNumPrefixes4(),
            numPrefixes6: $w->getNumPrefixes6(),
            rpkiValid: $w->getRpkiValid(),
            rpkiInvalid: $w->getRpkiInvalid(),
            rpkiNotFound: $w->getRpkiNotFound(),
            whois: $w->getWhois(),
            prefixes: $w->getPrefixes() === null
                ? null
                : array_map(AsnPrefix::fromWire(...), array_values($w->getPrefixes())),
            trialExpired: $w->getTrialExpired(),
            raw: $raw,
        );
    }
}
