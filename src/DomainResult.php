<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainLookupResponse;

/**
 * What a domain lookup answers: what every database says about one domain.
 *
 * A NULL property is one your plan does not include. It never means "we could not check", so null
 * and `false` are genuinely different answers: null is "not in your plan", `false` is "checked,
 * and no". Write `$result->isDisposable ?? false` when you only care whether the domain is
 * flagged.
 *
 * A detail object that is present but empty (`isEmpty()`) means the flag above it is false. A
 * populated one always carries every one of its properties.
 */
final class DomainResult
{
    public function __construct(
        /** The domain, lowercase and in punycode. */
        public readonly string $domain,
        /** In Domain List: a registered domain. */
        public readonly bool $isRegistered,
        /** It has A records. */
        public readonly bool $hasA,
        /** It has AAAA records. */
        public readonly bool $hasAaaa,
        /** It has MX records. */
        public readonly bool $hasMx,
        /** It has NS records. */
        public readonly bool $hasNs,
        /** It has a CNAME record. */
        public readonly bool $hasCname,
        /** In Disposable Domain. Paid plans and the trial. */
        public readonly ?bool $isDisposable = null,
        /** Its records in Domain DNS, up to 100 of each type. Paid plans and the trial. */
        public readonly ?DomainDns $dns = null,
        /** Its organization's IP ranges, from Domain IP Ranges. Paid plans and the trial. */
        public readonly ?DomainIpRanges $ipRanges = null,
        /** Disposable Domain's row, empty when `isDisposable` is false. Paid plans and the trial. */
        public readonly ?DisposableDetail $disposable = null,
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
        /**
         * On every answer to a key on a paid plan, and on no other: `false` while the answer has
         * every field, `true` once its organization's overage cap is reached and the answer has
         * Unlimited's. Lookups answered past the cap are not billed.
         */
        public readonly ?bool $capReached = null,
        /**
         * The network announcing each address in `dns.a` and then `dns.aaaa`, from IP ASN; `[]` where
         * it resolves to none. Paid plans and the trial.
         *
         * @var list<DomainHost>|null
         */
        public readonly ?array $hosting = null,
        /** The other registered domains on its first IPv4 address. Paid plans and the trial. */
        public readonly ?CohostedDomains $cohostedDomains = null,
        /** What our crawl last read of its home page. Paid plans and the trial. */
        public readonly ?DomainWeb $web = null,
        /** The registered domains whose home page redirects to it. Paid plans and the trial. */
        public readonly ?DomainRedirects $redirects = null,
        /** The registered domains whose home page links to it. Paid plans and the trial. */
        public readonly ?DomainBacklinks $backlinks = null,
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
    public static function fromWire(DomainLookupResponse $w, array $raw): self
    {
        return new self(
            domain: $w->getDomain(),
            isRegistered: $w->getIsRegistered(),
            hasA: $w->getHasA(),
            hasAaaa: $w->getHasAaaa(),
            hasMx: $w->getHasMx(),
            hasNs: $w->getHasNs(),
            hasCname: $w->getHasCname(),
            isDisposable: $w->getIsDisposable(),
            dns: $w->getDns() === null ? null : DomainDns::fromWire($w->getDns()),
            ipRanges: $w->getIpRanges() === null ? null : DomainIpRanges::fromWire($w->getIpRanges()),
            disposable: $w->getDisposable() === null ? null : DisposableDetail::fromWire($w->getDisposable()),
            trialExpired: $w->getTrialExpired(),
            capReached: $w->getCapReached(),
            raw: $raw,
            hosting: $w->getHosting() === null ? null : array_map(DomainHost::fromWire(...), array_values($w->getHosting())),
            cohostedDomains: $w->getCohostedDomains() === null
                ? null : CohostedDomains::fromWire($w->getCohostedDomains()),
            web: $w->getWeb() === null ? null : DomainWeb::fromWire($w->getWeb()),
            redirects: $w->getRedirects() === null ? null : DomainRedirects::fromWire($w->getRedirects()),
            backlinks: $w->getBacklinks() === null ? null : DomainBacklinks::fromWire($w->getBacklinks()),
        );
    }
}
