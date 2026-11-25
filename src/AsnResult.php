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
        /** The kind of network it runs. On a paid plan or the trial. */
        public readonly ?string $type = null,
        /** IPv4 addresses it announces. On a paid plan or the trial. */
        public readonly ?int $numIps = null,
        /** IPv4 prefixes it announces. On a paid plan or the trial. */
        public readonly ?int $numPrefixes4 = null,
        /** IPv6 prefixes it announces. On a paid plan or the trial. */
        public readonly ?int $numPrefixes6 = null,
        /** Its announcements RPKI calls valid. On a paid plan or the trial. */
        public readonly ?int $rpkiValid = null,
        /** Its announcements RPKI calls invalid. On a paid plan or the trial. */
        public readonly ?int $rpkiInvalid = null,
        /** Its announcements with no RPKI record. On a paid plan or the trial. */
        public readonly ?int $rpkiNotFound = null,
        /**
         * Its ASN Whois record, every column, empty where the registry states nothing. Paid plans
         * and the trial.
         *
         * @var array<string, string>|null
         */
        public readonly ?array $whois = null,
        /**
         * What it announces, IPv4 then IPv6, up to 20000 a family. On a paid plan or the trial.
         *
         * @var list<AsnPrefix>|null
         */
        public readonly ?array $prefixes = null,
        /**
         * On every answer to a key on the free plan, and on no other: `false` while the trial of
         * every field lasts, `true` once the answer has Unlimited's fields.
         */
        public readonly ?bool $trialExpired = null,
        /**
         * The response exactly as it came off the wire, with its original names.
         *
         * @var array<string, mixed>
         */
        public readonly array $raw = [],
        /**
         * Its rank in ASN Extended by customer cone, 1 the largest; `0` for an AS seen in no AS
         * path. On a paid plan or the trial.
         */
        public readonly ?int $rank = null,
        /**
         * Whether it is one of the transit-free networks at the top of the hierarchy. Paid plans
         * and the trial.
         */
        public readonly ?bool $tier1 = null,
        /** How many AS numbers it gets transit from. On a paid plan or the trial. */
        public readonly ?int $numUpstreams = null,
        /**
         * Those AS numbers, ascending, up to 20000. On a paid plan or the trial.
         *
         * @var list<int>|null
         */
        public readonly ?array $upstreams = null,
        /** How many AS numbers it exchanges its own and its customers' routes with. On a paid plan or the trial. */
        public readonly ?int $numPeers = null,
        /**
         * Those AS numbers, ascending, up to 20000. On a paid plan or the trial.
         *
         * @var list<int>|null
         */
        public readonly ?array $peers = null,
        /** How many AS numbers it provides transit to. On a paid plan or the trial. */
        public readonly ?int $numDownstreams = null,
        /**
         * Those AS numbers, ascending, up to 20000. On a paid plan or the trial.
         *
         * @var list<int>|null
         */
        public readonly ?array $downstreams = null,
        /** How many registered domains it hosts, and the 10 most popular. On a paid plan or the trial. */
        public readonly ?AsnHostedDomains $hostedDomains = null,
        /**
         * On every answer to a key on a paid plan, and on no other: `false` while the answer has
         * every field, `true` once its organization's overage cap is reached and the answer has
         * Unlimited's. Lookups answered past the cap are not billed.
         */
        public readonly ?bool $capReached = null,
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
            capReached: $w->getCapReached(),
            raw: $raw,
            rank: $w->getRank(),
            tier1: $w->getTier1(),
            numUpstreams: $w->getNumUpstreams(),
            upstreams: $w->getUpstreams() === null ? null : array_values($w->getUpstreams()),
            numPeers: $w->getNumPeers(),
            peers: $w->getPeers() === null ? null : array_values($w->getPeers()),
            numDownstreams: $w->getNumDownstreams(),
            downstreams: $w->getDownstreams() === null ? null : array_values($w->getDownstreams()),
            hostedDomains: $w->getHostedDomains() === null
                ? null
                : AsnHostedDomains::fromWire($w->getHostedDomains()),
        );
    }
}
