<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\LookupResponse;

/**
 * What a lookup answers: what every database says about one address.
 *
 * A NULL property is one your plan does not include. It never means "we could not check", so null
 * and `false` are genuinely different answers: null is "not in your plan", `false` is "checked,
 * and no". Write `$result->isVpn ?? false` when you only care whether the address is flagged.
 *
 * A detail object that is present but empty (`isEmpty()`) means the flag above it is false. A
 * populated one always carries every one of its properties.
 */
final class Result
{
    public function __construct(
        /** The address looked up, as parsed: `::ffff:a.b.c.d` answers as `a.b.c.d`. */
        public readonly string $ip,
        /**
         * Whether the address is reserved space. This library answers those itself, without a
         * request.
         */
        public readonly bool $isBogon,
        /** In Tor IP: a Tor exit. */
        public readonly bool $isTor,
        /** In Relay IP: an anonymizing relay such as iCloud Private Relay. */
        public readonly bool $isRelay,
        /** In Anycast IP: announced from many places at once. */
        public readonly bool $isAnycast,
        /** In Carrier IP: a mobile carrier's address space. */
        public readonly bool $isCarrier,
        /** In Satellite IP: a satellite operator's address space. */
        public readonly bool $isSatellite,
        /** In SSH IP: an SSH server answered here. */
        public readonly bool $isSsh,
        /** In SMTP IP: a mail server answered here. */
        public readonly bool $isSmtp,
        /** In Web IP: a web server answered here on port 80 or 443. */
        public readonly bool $isWeb,
        /** In DNS Resolver IP: an open DNS resolver answered here. */
        public readonly bool $isDnsResolver,
        /** In BitTorrent IP: a BitTorrent client was seen here in the last 90 days. */
        public readonly bool $isBittorrent,
        /** Tor IP's row, empty when `isTor` is false. */
        public readonly ClassDetail $tor,
        /** Relay IP's row, empty when `isRelay` is false. */
        public readonly ClassDetail $relay,
        /** Where the address is. */
        public readonly Location $location,
        /** The network announcing the address. */
        public readonly Asn $asn,
        /** Who uses the address. */
        public readonly Company $company,
        /**
         * The name the address's reverse DNS (PTR) record points to in IP RDNS, empty when it has
         * none.
         */
        public readonly string $hostname,
        /** Where to report abuse from the address. */
        public readonly AbuseContact $abuse,
        /** In VPN IP: a VPN service's server. Paid plans and the trial. */
        public readonly ?bool $isVpn = null,
        /**
         * In Residential Proxy IP's 90-day window: a residential proxy exit. Paid plans and the
         * trial.
         */
        public readonly ?bool $isResproxy = null,
        /** In Datacenter Proxy IP's 90-day window. Paid plans and the trial. */
        public readonly ?bool $isDcproxy = null,
        /** In Mobile Proxy IP's 90-day window. Paid plans and the trial. */
        public readonly ?bool $isMobproxy = null,
        /**
         * In Public Proxy IP: a public proxy listens here, or its traffic left from here. Paid
         * plans and the trial.
         */
        public readonly ?bool $isPubproxy = null,
        /** In Hosting IP: a hosting or cloud network. Paid plans and the trial. */
        public readonly ?bool $isHosting = null,
        /** In CDN IP: a content delivery network. Paid plans and the trial. */
        public readonly ?bool $isCdn = null,
        /** VPN IP's row, empty when `isVpn` is false. Paid plans and the trial. */
        public readonly ?ClassDetail $vpn = null,
        /** Residential Proxy IP's row, empty when `isResproxy` is false. Paid plans and the trial. */
        public readonly ?ProxyDetail $resproxy = null,
        /** Datacenter Proxy IP's row, empty when `isDcproxy` is false. Paid plans and the trial. */
        public readonly ?ProxyDetail $dcproxy = null,
        /** Mobile Proxy IP's row, empty when `isMobproxy` is false. Paid plans and the trial. */
        public readonly ?ProxyDetail $mobproxy = null,
        /** Public Proxy IP's row, empty when `isPubproxy` is false. Paid plans and the trial. */
        public readonly ?PubproxyDetail $pubproxy = null,
        /** Hosting IP's row, empty when `isHosting` is false. Paid plans and the trial. */
        public readonly ?ClassDetail $hosting = null,
        /** CDN IP's row, empty when `isCdn` is false. Paid plans and the trial. */
        public readonly ?ClassDetail $cdn = null,
        /** Carrier IP's row, empty when `isCarrier` is false. Paid plans and the trial. */
        public readonly ?CarrierDetail $carrier = null,
        /** Satellite IP's row, empty when `isSatellite` is false. Paid plans and the trial. */
        public readonly ?SatelliteDetail $satellite = null,
        /** SSH IP's row, empty when `isSsh` is false. Paid plans and the trial. */
        public readonly ?SshDetail $ssh = null,
        /** SMTP IP's row, empty when `isSmtp` is false. Paid plans and the trial. */
        public readonly ?SmtpDetail $smtp = null,
        /**
         * A row per port that answered, lowest first; `[]` when `isWeb` is false. Paid plans and
         * the trial.
         *
         * @var list<WebDetail>|null
         */
        public readonly ?array $web = null,
        /** DNS Resolver IP's row, empty when `isDnsResolver` is false. Paid plans and the trial. */
        public readonly ?DnsResolverDetail $dnsResolver = null,
        /** BitTorrent IP's row, empty when `isBittorrent` is false. Paid plans and the trial. */
        public readonly ?BitTorrentDetail $bittorrent = null,
        /** IP Whois's most specific record covering the address. Paid plans and the trial. */
        public readonly ?Whois $whois = null,
        /** IP RWHOIS's most specific network covering the address. Paid plans and the trial. */
        public readonly ?Rwhois $rwhois = null,
        /** How many registered domains resolve to the address. Paid plans and the trial. */
        public readonly ?HostedDomains $hostedDomains = null,
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
    public static function fromWire(LookupResponse $w, array $raw): self
    {
        return new self(
            ip: $w->getIp(),
            isBogon: $w->getIsBogon(),
            isTor: $w->getIsTor(),
            isRelay: $w->getIsRelay(),
            isAnycast: $w->getIsAnycast(),
            isCarrier: $w->getIsCarrier(),
            isSatellite: $w->getIsSatellite(),
            isSsh: $w->getIsSsh(),
            isSmtp: $w->getIsSmtp(),
            isWeb: $w->getIsWeb(),
            isDnsResolver: $w->getIsDnsResolver(),
            isBittorrent: $w->getIsBittorrent(),
            tor: ClassDetail::fromWire($w->getTor()),
            relay: ClassDetail::fromWire($w->getRelay()),
            location: Location::fromWire($w->getLocation()),
            asn: Asn::fromWire($w->getAsn()),
            company: Company::fromWire($w->getCompany()),
            hostname: $w->getHostname(),
            abuse: AbuseContact::fromWire($w->getAbuse()),
            isVpn: $w->getIsVpn(),
            isResproxy: $w->getIsResproxy(),
            isDcproxy: $w->getIsDcproxy(),
            isMobproxy: $w->getIsMobproxy(),
            isPubproxy: $w->getIsPubproxy(),
            isHosting: $w->getIsHosting(),
            isCdn: $w->getIsCdn(),
            vpn: $w->getVpn() === null ? null : ClassDetail::fromWire($w->getVpn()),
            resproxy: $w->getResproxy() === null ? null : ProxyDetail::fromWire($w->getResproxy()),
            dcproxy: $w->getDcproxy() === null ? null : ProxyDetail::fromWire($w->getDcproxy()),
            mobproxy: $w->getMobproxy() === null ? null : ProxyDetail::fromWire($w->getMobproxy()),
            pubproxy: $w->getPubproxy() === null ? null : PubproxyDetail::fromWire($w->getPubproxy()),
            hosting: $w->getHosting() === null ? null : ClassDetail::fromWire($w->getHosting()),
            cdn: $w->getCdn() === null ? null : ClassDetail::fromWire($w->getCdn()),
            carrier: $w->getCarrier() === null ? null : CarrierDetail::fromWire($w->getCarrier()),
            satellite: $w->getSatellite() === null ? null : SatelliteDetail::fromWire($w->getSatellite()),
            ssh: $w->getSsh() === null ? null : SshDetail::fromWire($w->getSsh()),
            smtp: $w->getSmtp() === null ? null : SmtpDetail::fromWire($w->getSmtp()),
            web: $w->getWeb() === null
                ? null
                : array_map(WebDetail::fromWire(...), array_values($w->getWeb())),
            dnsResolver: $w->getDnsResolver() === null
                ? null
                : DnsResolverDetail::fromWire($w->getDnsResolver()),
            bittorrent: $w->getBittorrent() === null ? null : BitTorrentDetail::fromWire($w->getBittorrent()),
            whois: $w->getWhois() === null ? null : Whois::fromWire($w->getWhois()),
            rwhois: $w->getRwhois() === null ? null : Rwhois::fromWire($w->getRwhois()),
            hostedDomains: $w->getHostedDomains() === null
                ? null
                : HostedDomains::fromWire($w->getHostedDomains()),
            trialExpired: $w->getTrialExpired(),
            raw: $raw,
        );
    }
}
