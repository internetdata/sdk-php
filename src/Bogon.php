<?php

declare(strict_types=1);

namespace InternetData;

/**
 * The standalone bogon predicate.
 *
 * PHP forbids a static and an instance method of the same name in one class, so
 * the form that needs no client lives here while `Client::isBogon()` is what the
 * README teaches.
 */
final class Bogon
{
    /**
     * Whether an address is a bogon: private, loopback, link-local,
     * documentation, multicast or otherwise not routable on the public internet,
     * including the IPv6 equivalents and the 6to4 and Teredo ranges that wrap
     * them.
     *
     * The client answers them itself, so they never cost a request.
     */
    public static function isBogon(string $ip): bool
    {
        $ip = self::unmapped($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach (strlen($packed) === 4 ? self::v4() : self::v6() as [$net, $mask]) {
            if (($packed & $mask) === $net) {
                return true;
            }
        }
        return false;
    }

    /**
     * The IPv4 address an IPv4-mapped IPv6 address carries, dotted, and any other
     * string as given. A server listening on `::` sees an IPv4 visitor in the
     * mapped form, which read whole is inside `::ffff:0:0/96` and so would be
     * answered as a bogon with no request made.
     *
     * @internal What `isBogon` judges and a lookup sends; not part of the API.
     */
    public static function unmapped(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = inet_pton($ip);
        if ($packed === false || substr($packed, 0, 12) !== "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return $ip;
        }
        return (string) inet_ntop(substr($packed, 12));
    }

    /**
     * The answer a bogon gets, in the full shape the API answers one on a paid
     * plan: every flag present and false, every detail object present and empty
     * (Web IP's list empty), the location, AS, company, abuse contact and both
     * Whois records with every member empty or 0, no hostname and no hosted
     * domains, and `isBogon` true.
     *
     * Note this is deliberately the WIDEST shape regardless of your plan, so do
     * not infer which fields your plan includes from a bogon answer.
     */
    public static function result(string $ip): Result
    {
        return new Result(
            ip: $ip,
            isBogon: true,
            isTor: false,
            isRelay: false,
            isAnycast: false,
            isCarrier: false,
            isSatellite: false,
            isSsh: false,
            isSmtp: false,
            isWeb: false,
            isDnsResolver: false,
            isBittorrent: false,
            tor: new ClassDetail(),
            relay: new ClassDetail(),
            location: new Location(
                continent: '',
                continentName: '',
                country: '',
                countryName: '',
                timezone: '',
                currencyCode: '',
                currencyName: '',
                currencySymbol: '',
                flagEmoji: '',
                flagUnicode: '',
                region: '',
                regionCode: '',
                city: '',
                postalCode: '',
                latitude: 0.0,
                longitude: 0.0,
                accuracyRadius: 0,
                geonameId: '',
                dmaCode: '',
                lastChanged: '',
            ),
            asn: new Asn(asn: 0, name: '', domain: '', type: '', prefix: '', country: '', rpkiStatus: ''),
            company: new Company(name: '', domain: '', type: '', country: ''),
            hostname: '',
            abuse: new AbuseContact(
                email: '',
                name: '',
                phone: '',
                address: '',
                country: '',
                network: '',
                validated: false,
                unvalidatedSince: '',
            ),
            isVpn: false,
            isResproxy: false,
            isDcproxy: false,
            isMobproxy: false,
            isPubproxy: false,
            isHosting: false,
            isCdn: false,
            vpn: new ClassDetail(),
            resproxy: new ProxyDetail(),
            dcproxy: new ProxyDetail(),
            mobproxy: new ProxyDetail(),
            pubproxy: new PubproxyDetail(),
            hosting: new ClassDetail(),
            cdn: new ClassDetail(),
            carrier: new CarrierDetail(),
            satellite: new SatelliteDetail(),
            ssh: new SshDetail(),
            smtp: new SmtpDetail(),
            web: [],
            dnsResolver: new DnsResolverDetail(),
            bittorrent: new BitTorrentDetail(),
            whois: new Whois(
                id: '',
                name: '',
                org: '',
                country: '',
                status: '',
                source: '',
                domain: '',
                abuseEmail: '',
                asn: 0,
            ),
            rwhois: new Rwhois(id: '', name: '', country: '', source: '', domain: '', abuseEmail: ''),
            hostedDomains: new HostedDomains(total: 0),
            raw: [],
        );
    }

    /** @var list<array{0: string, 1: string}>|null */
    private static ?array $v4 = null;

    /** @var list<array{0: string, 1: string}>|null */
    private static ?array $v6 = null;

    // Parsed on first use rather than at class load: a consumer that never looks
    // up an address should not pay for the table, which matters more in PHP than
    // elsewhere because a web request pays it again on every hit.

    /** @return list<array{0: string, 1: string}> */
    private static function v4(): array
    {
        return self::$v4 ??= self::parse(Bogons::V4, 4);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function v6(): array
    {
        return self::$v6 ??= self::parse(Bogons::V6, 16);
    }

    /**
     * CIDRs become packed network and mask byte strings, so containment is one
     * bytewise AND rather than any integer arithmetic 32 bits too wide for PHP.
     *
     * @param list<string> $cidrs
     * @return list<array{0: string, 1: string}>
     */
    private static function parse(array $cidrs, int $width): array
    {
        $out = [];
        foreach ($cidrs as $cidr) {
            [$net, $bits] = explode('/', $cidr, 2);
            $mask = self::mask((int) $bits, $width);
            $out[] = [inet_pton($net) & $mask, $mask];
        }
        return $out;
    }

    private static function mask(int $bits, int $width): string
    {
        $full = intdiv($bits, 8);
        $rem = $bits % 8;
        return str_repeat("\xff", $full)
            . ($rem === 0 ? '' : chr((0xff << (8 - $rem)) & 0xff))
            . str_repeat("\x00", $width - $full - ($rem === 0 ? 0 : 1));
    }
}
