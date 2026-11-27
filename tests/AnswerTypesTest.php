<?php

declare(strict_types=1);

namespace InternetData\Tests;

use InternetData\Client;
use InternetData\ErrorKind;
use InternetData\InternetDataException;
use InternetData\Options;
use InternetData\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lookup's answer must be of the types the spec gives it, at every depth,
 * before anything reads it: the generated deserializer casts each scalar with
 * settype(), so a wrong one would come back as a made-up value, sent once. The
 * database answers' cases are in ClientTest.
 */
final class AnswerTypesTest extends TestCase
{
    private const ENTITLEMENT = [
        'org_id' => '85bb51e4-2eb6-4a31-8e4d-02ba8b98fe61',
        'apikey' => ['id' => '0ab424cc-7619-4dad-b027-afacdc2cedb0', 'expires' => null, 'allowed_cidrs' => []],
        'plan' => ['key' => 'max', 'name' => 'Max', 'tier' => 'max'],
        'usage' => [
            'requests' => 580,
            'quota' => 5000000,
            'hard_limit' => null,
            'window_start' => '2026-09-04T07:00:00Z',
            'window_end' => '2026-10-04T07:00:00Z',
        ],
    ];

    /** The flags a lookup holds at a plain `bool`: absent reads false, a wrong type is refused. */
    private const HELD_FLAGS = [
        'is_bot', 'is_carrier', 'is_satellite', 'is_ssh', 'is_smtp', 'is_web', 'is_dns_resolver', 'is_bittorrent',
    ];

    private const ASN = [
        'asn' => 9009,
        'name' => 'M247 Europe SRL',
        'country' => 'RO',
        'registry' => 'ripe',
        'allocated' => '2005-06-06',
        'domain' => 'm247global.com',
    ];

    /** A hosting entry's `asn`, every member the spec requires. */
    private const HOSTING_ASN = [
        'asn' => 64496, 'name' => '', 'domain' => '', 'prefix' => '', 'country' => '', 'rpki_status' => '',
    ];

    private const DOMAIN = [
        'domain' => 'example.com',
        'is_registered' => true,
        'has_a' => true,
        'has_aaaa' => true,
        'has_mx' => true,
        'has_ns' => true,
        'has_cname' => false,
    ];

    /** @return array<string, array{array<string, mixed>}> */
    public static function lookupBodies(): array
    {
        $lookup = static fn (array $patch): array => [Stub::answer('9.9.9.9', $patch)];
        $held = [];
        foreach (self::HELD_FLAGS as $flag) {
            $held["{$flag} \"false\""] = $lookup([$flag => 'false']);
            $held["{$flag} 0"] = $lookup([$flag => 0]);
            $held["{$flag} null"] = $lookup([$flag => null]);
        }
        return [
            ...$held,
            'is_tor "false"' => $lookup(['is_tor' => 'false']),
            'is_tor "yes"' => $lookup(['is_tor' => 'yes']),
            'is_tor 0' => $lookup(['is_tor' => 0]),
            'is_tor null' => $lookup(['is_tor' => null]),
            'is_vpn "false"' => $lookup(['is_vpn' => 'false']),
            'ip 1' => $lookup(['ip' => 1]),
            'hostname false' => $lookup(['hostname' => false]),
            'a detail as a string' => $lookup(['vpn' => 'x']),
            'a detail as a list' => $lookup(['vpn' => ['x']]),
            'vpn.provider 1' => $lookup(['vpn' => ['provider' => 1]]),
            'dcproxy.hits "3"' => $lookup(['dcproxy' => ['hits' => '3']]),
            'pubproxy.protocols "http"' => $lookup(['pubproxy' => ['protocols' => 'http']]),
            'pubproxy.protocols [null]' => $lookup(['pubproxy' => ['protocols' => [null]]]),
            'web as an object' => $lookup(['web' => ['port' => 80]]),
            'web [null]' => $lookup(['web' => [null]]),
            'asn.asn "13335"' => $lookup(['asn' => [
                'asn' => '13335', 'name' => '', 'domain' => '', 'type' => '', 'prefix' => '',
            ]]),
            'asn.type null' => $lookup(['asn' => [...Stub::answer('9.9.9.9')['asn'], 'type' => null]]),
            'asn.type 1' => $lookup(['asn' => [...Stub::answer('9.9.9.9')['asn'], 'type' => 1]]),
            'asn.type ["hosting"]' => $lookup(['asn' => [...Stub::answer('9.9.9.9')['asn'], 'type' => ['hosting']]]),
            'location.latitude "48.2"' => $lookup(['location' => [
                ...Stub::answer('9.9.9.9')['location'], 'latitude' => '48.2',
            ]]),
            'abuse.validated "yes"' => $lookup(['abuse' => ['email' => '', 'validated' => 'yes']]),
            'hosted_domains.total "4"' => $lookup(['hosted_domains' => ['total' => '4']]),
            'hosted_domains.domains "x"' => $lookup(['hosted_domains' => ['total' => 1, 'domains' => 'x']]),
            'hosted_domains.domains [1]' => $lookup(['hosted_domains' => ['total' => 1, 'domains' => [1]]]),
            'hosted_domains.domains [null]' => $lookup(['hosted_domains' => ['total' => 1, 'domains' => [null]]]),
            'trial_expired 1' => $lookup(['trial_expired' => 1]),
            'cap_reached "true"' => $lookup(['cap_reached' => 'true']),
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('lookupBodies')]
    public function testALookupOfTheWrongTypeIsARetriedServerError(array $body): void
    {
        $this->assertUnreadable('/9.9.9.9', $body, static fn (Client $c) => $c->lookup('9.9.9.9'));
        $this->assertUnreadable('/myip', $body, static fn (Client $c) => $c->myIp());
    }

    /** @return array<string, array{string, mixed, \Closure(Client): mixed}> */
    public static function otherBodies(): array
    {
        $asn = static fn (Client $c) => $c->lookupAsn(9009);
        $domain = static fn (Client $c) => $c->lookupDomain('example.com');
        $entitlement = static fn (Client $c) => $c->myEntitlement();
        $usage = static fn (array $patch): array => array_replace_recursive(self::ENTITLEMENT, ['usage' => $patch]);
        return [
            'AS: asn "9009"' => ['/asn/9009', array_replace(self::ASN, ['asn' => '9009']), $asn],
            'AS: name absent' => ['/asn/9009', array_diff_key(self::ASN, ['name' => 1]), $asn],
            'AS: num_ips "5"' => ['/asn/9009', self::ASN + ['num_ips' => '5'], $asn],
            'AS: a whois value of 1' => ['/asn/9009', self::ASN + ['whois' => ['abuse' => 1]], $asn],
            'AS: whois as a list' => ['/asn/9009', self::ASN + ['whois' => ['x']], $asn],
            'AS: rank "341"' => ['/asn/9009', self::ASN + ['rank' => '341'], $asn],
            'AS: tier1 "false"' => ['/asn/9009', self::ASN + ['tier1' => 'false'], $asn],
            'AS: cap_reached 1' => ['/asn/9009', self::ASN + ['cap_reached' => 1], $asn],
            'domain: cap_reached "false"' => ['/domain/example.com', self::DOMAIN + ['cap_reached' => 'false'], $domain],
            'AS: upstreams ["174"]' => ['/asn/9009', self::ASN + ['upstreams' => ['174']], $asn],
            'AS: downstreams [null]' => ['/asn/9009', self::ASN + ['downstreams' => [null]], $asn],
            'AS: hosted_domains without total' => ['/asn/9009', self::ASN + ['hosted_domains' => ['domains' => []]], $asn],
            'AS: hosted_domains.domains [1]' => [
                '/asn/9009', self::ASN + ['hosted_domains' => ['total' => 1, 'domains' => [1]]], $asn,
            ],
            'AS: a prefix without its size' => ['/asn/9009', self::ASN + ['prefixes' => [[
                'prefix' => '146.70.116.0/24', 'peer_count' => 1, 'rpki_status' => 'valid',
                'first_seen' => '2020-01-01', 'country' => 'AT', 'other_origins' => [],
            ]]], $asn],
            'domain: is_registered "true"' => [
                '/domain/example.com', array_replace(self::DOMAIN, ['is_registered' => 'true']), $domain,
            ],
            'domain: has_cname absent' => [
                '/domain/example.com', array_diff_key(self::DOMAIN, ['has_cname' => 1]), $domain,
            ],
            'domain: a dns without its mx' => ['/domain/example.com', self::DOMAIN + ['dns' => [
                'a' => [], 'aaaa' => [], 'ns' => [], 'cname' => [],
            ]], $domain],
            'domain: an mx priority "10"' => ['/domain/example.com', self::DOMAIN + ['dns' => [
                'a' => [], 'aaaa' => [], 'ns' => [], 'cname' => [],
                'mx' => [['priority' => '10', 'host' => 'mx.example.com', 'last_seen' => '2026-11-08']],
            ]], $domain],
            'domain: disposable.providers "x"' => ['/domain/example.com', self::DOMAIN + ['disposable' => [
                'confidence' => 'high', 'providers' => 'x', 'first_seen' => '', 'last_seen' => '',
            ]], $domain],
            'domain: hosting as an object' => ['/domain/example.com', self::DOMAIN + ['hosting' => ['ip' => 'x']], $domain],
            'domain: hosting [null]' => ['/domain/example.com', self::DOMAIN + ['hosting' => [null]], $domain],
            'domain: a hosting entry without asn' => [
                '/domain/example.com', self::DOMAIN + ['hosting' => [['ip' => '192.0.2.10']]], $domain,
            ],
            'domain: a hosting asn "64496"' => ['/domain/example.com', self::DOMAIN + ['hosting' => [[
                'ip' => '192.0.2.10', 'asn' => ['asn' => '64496', 'name' => '', 'domain' => '', 'type' => '', 'prefix' => ''],
            ]]], $domain],
            'domain: a hosting asn type null' => ['/domain/example.com', self::DOMAIN + ['hosting' => [[
                'ip' => '192.0.2.10', 'asn' => [...self::HOSTING_ASN, 'type' => null],
            ]]], $domain],
            'domain: a hosting asn type 1' => ['/domain/example.com', self::DOMAIN + ['hosting' => [[
                'ip' => '192.0.2.10', 'asn' => [...self::HOSTING_ASN, 'type' => 1],
            ]]], $domain],
            'domain: a hosting asn without its prefix' => ['/domain/example.com', self::DOMAIN + ['hosting' => [[
                'ip' => '192.0.2.10', 'asn' => ['asn' => 64496, 'name' => '', 'domain' => '', 'type' => ''],
            ]]], $domain],
            'domain: cohosted_domains.total "3"' => ['/domain/example.com', self::DOMAIN + ['cohosted_domains' => [
                'ip' => '192.0.2.10', 'total' => '3', 'domains' => [],
            ]], $domain],
            'domain: cohosted_domains without its ip' => ['/domain/example.com', self::DOMAIN + ['cohosted_domains' => [
                'total' => 3, 'domains' => [],
            ]], $domain],
            'domain: cohosted_domains.domains [1]' => ['/domain/example.com', self::DOMAIN + ['cohosted_domains' => [
                'ip' => '192.0.2.10', 'total' => 1, 'domains' => [1],
            ]], $domain],
            'domain: web as a list' => ['/domain/example.com', self::DOMAIN + ['web' => []], $domain],
            'domain: web without last_seen' => ['/domain/example.com', self::DOMAIN + ['web' => [
                'url' => 'https://example.com/', 'title' => '', 'description' => '',
                'links' => ['total' => 0, 'hosts' => []],
            ]], $domain],
            'domain: web.title null' => ['/domain/example.com', self::DOMAIN + ['web' => [
                'url' => 'https://example.com/', 'title' => null, 'description' => '', 'last_seen' => '2026-11-18',
                'links' => ['total' => 0, 'hosts' => []],
            ]], $domain],
            'domain: web.links.hosts [1]' => ['/domain/example.com', self::DOMAIN + ['web' => [
                'url' => 'https://example.com/', 'title' => '', 'description' => '', 'last_seen' => '2026-11-18',
                'links' => ['total' => 1, 'hosts' => [1]],
            ]], $domain],
            'domain: redirects.total 1.5' => [
                '/domain/example.com', self::DOMAIN + ['redirects' => ['total' => 1.5, 'domains' => []]], $domain,
            ],
            'domain: redirects without its domains' => [
                '/domain/example.com', self::DOMAIN + ['redirects' => ['total' => 0]], $domain,
            ],
            'domain: backlinks.domains [null]' => [
                '/domain/example.com', self::DOMAIN + ['backlinks' => ['total' => 1, 'domains' => [null]]], $domain,
            ],
            'domain: backlinks "3"' => ['/domain/example.com', self::DOMAIN + ['backlinks' => '3'], $domain],
            'entitlement: requests "5"' => ['/api/v1/entitlement', $usage(['requests' => '5']), $entitlement],
            'entitlement: window_start "x"' => ['/api/v1/entitlement', $usage(['window_start' => 'x']), $entitlement],
            'entitlement: window_start at 24:00' => [
                '/api/v1/entitlement', $usage(['window_start' => '2026-09-04T24:00:00Z']), $entitlement,
            ],
            'entitlement: window_start 30 February' => [
                '/api/v1/entitlement', $usage(['window_start' => '2026-02-30T07:00:00Z']), $entitlement,
            ],
            'entitlement: window_start ending in a newline' => [
                '/api/v1/entitlement', $usage(['window_start' => "2026-09-04T07:00:00Z\n"]), $entitlement,
            ],
            'entitlement: a plan name of 1' => [
                '/api/v1/entitlement', array_replace_recursive(self::ENTITLEMENT, ['plan' => ['name' => 1]]), $entitlement,
            ],
            'entitlement: a plan without its name' => [
                '/api/v1/entitlement',
                array_replace(self::ENTITLEMENT, ['plan' => ['key' => 'max', 'tier' => 'max']]),
                $entitlement,
            ],
            'entitlement: a tier outside the spec' => [
                '/api/v1/entitlement',
                array_replace_recursive(self::ENTITLEMENT, ['plan' => ['tier' => 'gold']]),
                $entitlement,
            ],
            'entitlement: org_id absent' => [
                '/api/v1/entitlement', array_diff_key(self::ENTITLEMENT, ['org_id' => 1]), $entitlement,
            ],
        ];
    }

    /** @param \Closure(Client): mixed $call */
    #[DataProvider('otherBodies')]
    public function testAnAnswerOfTheWrongTypeIsARetriedServerError(string $path, mixed $body, \Closure $call): void
    {
        $this->assertUnreadable($path, $body, $call);
    }

    public function testAnswersOfTheRightTypesStillRead(): void
    {
        $stub = new Stub([
            '/9.9.9.9' => Stub::ok(Stub::answer('9.9.9.9', [
                'is_vpn' => true,
                'vpn' => [
                    'provider' => 'm247', 'provider_name' => 'M247', 'confidence' => 'high', 'last_seen' => '2026-10-19',
                ],
                'pubproxy' => new \stdClass(),
                'web' => [[
                    'port' => 443, 'tls_ok' => true, 'session_ok' => true, 'status_code' => 200, 'server' => 'nginx',
                    'location' => '', 'title' => 'x', 'cert_subject_cn' => 'x', 'cert_subject_o' => '',
                    'cert_issuer_dn' => 'x', 'cert_browser_trusted' => true, 'tls_version' => 'TLS1.3',
                    'first_seen' => '2026-10-01', 'last_seen' => '2026-11-01',
                ]],
            ])),
            '/asn/9009' => Stub::ok(self::ASN + ['whois' => ['abuse_email' => 'abuse@m247.ro'], 'prefixes' => [[
                'prefix' => '146.70.116.0/24', 'peer_count' => 1, 'rpki_status' => 'valid',
                'first_seen' => '2020-01-01', 'country' => 'AT', 'size' => 256, 'other_origins' => [174],
            ]]]),
            '/domain/example.com' => Stub::ok(self::DOMAIN + ['dns' => [
                'a' => [['ip' => '192.0.2.10', 'last_seen' => '2026-11-08']], 'aaaa' => [], 'ns' => [], 'cname' => [],
                'mx' => [['priority' => 10, 'host' => 'mail.example.com', 'last_seen' => '2026-11-08']],
            ]]),
            '/api/v1/entitlement' => Stub::ok(self::ENTITLEMENT),
        ]);
        $client = new Client(new Options(apiKey: 'k', httpClient: $stub->client));

        $result = $client->lookup('9.9.9.9');
        self::assertSame('M247', $result->vpn?->providerName);
        self::assertSame('2026-10-19', $result->vpn?->lastSeen);
        self::assertTrue($result->pubproxy?->isEmpty(), 'a detail served as {} stays present');
        self::assertSame(443, $result->web[0]->port ?? null);
        $asn = $client->lookupAsn(9009);
        self::assertSame(['abuse_email' => 'abuse@m247.ro'], $asn->whois);
        self::assertSame([174], $asn->prefixes[0]->otherOrigins ?? null);
        self::assertSame(10, $client->lookupDomain('example.com')->dns?->mx[0]->priority);
        self::assertSame(580, $client->myEntitlement()->usage->requests);
        self::assertCount(4, $stub->calls, 'each sent once');
    }

    public function testAnAnswerWithoutTheHeldFlagsReadsThemFalseAndShowsThemAbsent(): void
    {
        $flags = array_map(
            static fn (string $wire): string => lcfirst(str_replace('_', '', ucwords($wire, '_'))),
            self::HELD_FLAGS,
        );
        $present = array_fill_keys(self::HELD_FLAGS, true);
        $stub = new Stub([
            '/9.9.9.1' => Stub::ok(Stub::answer('9.9.9.1')),
            '/9.9.9.2' => Stub::ok(Stub::answer('9.9.9.2', $present)),
            '/9.9.9.3' => Stub::ok(Stub::answer('9.9.9.3', array_fill_keys(self::HELD_FLAGS, false))),
            '/batch' => Stub::ok([
                'results' => [
                    '9.9.9.4' => Stub::answer('9.9.9.4'),
                    '9.9.9.5' => Stub::answer('9.9.9.5', $present),
                ],
                'errors' => new \stdClass(),
            ]),
        ]);
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $none = [$client->lookup('9.9.9.1'), $client->lookupBatch(['9.9.9.4', '9.9.9.5'])['9.9.9.4']];
        foreach ($none as $i => $result) {
            self::assertInstanceOf(Result::class, $result, "answer {$i}");
            foreach ($flags as $n => $flag) {
                self::assertFalse($result->{$flag}, "answer {$i}: {$flag} reads false");
                self::assertArrayNotHasKey(self::HELD_FLAGS[$n], $result->raw, "answer {$i}: {$flag}");
            }
        }
        $served = [$client->lookup('9.9.9.2'), $client->lookupBatch(['9.9.9.4', '9.9.9.5'])['9.9.9.5']];
        foreach ($served as $i => $result) {
            self::assertInstanceOf(Result::class, $result, "served {$i}");
            foreach ($flags as $n => $flag) {
                self::assertTrue($result->{$flag}, "served {$i}: {$flag} reads as served");
                self::assertTrue($result->raw[self::HELD_FLAGS[$n]], "served {$i}: {$flag}");
            }
        }
        $falses = $client->lookup('9.9.9.3');
        foreach (self::HELD_FLAGS as $wire) {
            self::assertArrayHasKey($wire, $falses->raw, "{$wire} served false is present");
        }
    }

    public function testAHeldFlagLeftOutOneAtATimeStillReads(): void
    {
        foreach (self::HELD_FLAGS as $wire) {
            $body = array_diff_key(
                Stub::answer('9.9.9.9', array_fill_keys(self::HELD_FLAGS, true)),
                [$wire => 1],
            );
            $stub = new Stub(['/9.9.9.9' => Stub::ok($body)]);
            $result = (new Client(new Options(cache: false, httpClient: $stub->client)))->lookup('9.9.9.9');

            $name = lcfirst(str_replace('_', '', ucwords($wire, '_')));
            self::assertFalse($result->{$name}, "{$wire} left out reads false");
            self::assertArrayNotHasKey($wire, $result->raw, $wire);
            self::assertCount(1, $stub->calls, "{$wire}: read at once, not retried");
        }
    }

    public function testAnAnswerWithoutTheAsnTypeReadsItEmptyAndShowsItAbsent(): void
    {
        $asn = Stub::answer('9.9.9.1')['asn'];
        $served = ['asn' => [...$asn, 'type' => 'hosting']];
        $empty = ['asn' => [...$asn, 'type' => '']];
        $stub = new Stub([
            '/9.9.9.1' => Stub::ok(Stub::answer('9.9.9.1')),
            '/9.9.9.2' => Stub::ok(Stub::answer('9.9.9.2', $served)),
            '/9.9.9.3' => Stub::ok(Stub::answer('9.9.9.3', $empty)),
            '/batch' => Stub::ok([
                'results' => [
                    '9.9.9.4' => Stub::answer('9.9.9.4'),
                    '9.9.9.5' => Stub::answer('9.9.9.5', $served),
                ],
                'errors' => new \stdClass(),
            ]),
            '/domain/example.com' => Stub::ok(self::DOMAIN + ['hosting' => [
                ['ip' => '192.0.2.10', 'asn' => self::HOSTING_ASN],
                ['ip' => '192.0.2.11', 'asn' => [...self::HOSTING_ASN, 'type' => 'isp']],
            ]]),
        ]);
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $batch = $client->lookupBatch(['9.9.9.4', '9.9.9.5']);
        foreach ([$client->lookup('9.9.9.1'), $batch['9.9.9.4']] as $i => $result) {
            self::assertInstanceOf(Result::class, $result, "answer {$i}");
            self::assertSame('', $result->asn->type, "answer {$i}: type reads ''");
            self::assertSame('M247 Europe SRL', $result->asn->name, "answer {$i}");
            self::assertArrayNotHasKey('type', $result->raw['asn'], "answer {$i}");
        }
        foreach ([$client->lookup('9.9.9.2'), $batch['9.9.9.5']] as $i => $result) {
            self::assertInstanceOf(Result::class, $result, "served {$i}");
            self::assertSame('hosting', $result->asn->type, "served {$i}: type reads as served");
            self::assertSame('hosting', $result->raw['asn']['type'], "served {$i}");
        }
        $blank = $client->lookup('9.9.9.3');
        self::assertSame('', $blank->asn->type);
        self::assertArrayHasKey('type', $blank->raw['asn'], "a type served as '' is present");

        $hosting = $client->lookupDomain('example.com')->hosting;
        self::assertSame(['', 'isp'], [$hosting[0]->asn->type, $hosting[1]->asn->type]);
        self::assertSame(64496, $hosting[0]->asn->asn);
        self::assertCount(5, $stub->calls, 'each read at once, never retried');
    }

    public function testABatchEntryOfTheWrongTypeFailsThatAddressAlone(): void
    {
        $stub = new Stub(['/batch' => Stub::ok([
            'results' => [
                '9.9.9.1' => Stub::answer('9.9.9.1', ['is_vpn' => true, 'vpn' => new \stdClass()]),
                '9.9.9.2' => Stub::answer('9.9.9.2', ['is_tor' => 'false']),
                '9.9.9.3' => Stub::answer('9.9.9.3', ['tor' => ['provider' => 1]]),
            ],
            'errors' => new \stdClass(),
        ])]);
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $answers = $client->lookupBatch(['9.9.9.1', '9.9.9.2', '9.9.9.3']);

        self::assertInstanceOf(Result::class, $answers['9.9.9.1']);
        self::assertTrue($answers['9.9.9.1']->vpn?->isEmpty(), 'a detail served as {} stays present in a batch');
        foreach (['9.9.9.2', '9.9.9.3'] as $ip) {
            self::assertInstanceOf(InternetDataException::class, $answers[$ip], $ip);
            self::assertSame(ErrorKind::ServerError, $answers[$ip]->kind, $ip);
            self::assertSame(200, $answers[$ip]->status, $ip);
        }
        self::assertCount(1, $stub->calls, 'an entry is never retried on its own');
    }

    /**
     * A PSR-16 store keeps the answer as PHP arrays, which write `{}` as `[]`,
     * so the reader must not take a stored empty detail for a list.
     */
    public function testAStoredAnswerWithAnEmptyDetailIsStillAHit(): void
    {
        $store = new ArrayStore();
        $first = new Stub(['/9.9.9.9' => Stub::ok(Stub::answer('9.9.9.9', [
            'is_vpn' => false, 'vpn' => new \stdClass(), 'web' => [],
        ]))]);
        (new Client(new Options(cacheStore: $store, httpClient: $first->client)))->lookup('9.9.9.9');

        $second = new Stub();
        $result = (new Client(new Options(cacheStore: $store, httpClient: $second->client)))->lookup('9.9.9.9');

        self::assertSame([], $second->calls, 'the stored answer must be read, not fetched again');
        self::assertTrue($result->vpn?->isEmpty());
        self::assertTrue($result->tor->isEmpty());
        self::assertSame([], $result->web);
    }

    /** @param \Closure(Client): mixed $call */
    private function assertUnreadable(string $path, mixed $body, \Closure $call): void
    {
        $stub = new Stub([$path => Stub::ok($body)]);
        $client = new Client(new Options(apiKey: 'k', cache: false, retries: 2, httpClient: $stub->client));
        try {
            $answer = $call($client);
            self::fail("{$path}: answered " . json_encode($answer));
        } catch (InternetDataException $e) {
            self::assertSame(ErrorKind::ServerError, $e->kind, $path);
            self::assertSame(200, $e->status, "{$path}: the status");
        }
        self::assertCount(3, $stub->calls, "{$path}: retried like an outage");
    }
}
