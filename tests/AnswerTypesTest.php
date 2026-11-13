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
        'plan' => ['key' => 'max', 'tier' => 'max'],
        'usage' => [
            'requests' => 580,
            'quota' => 5000000,
            'hard_limit' => null,
            'window_start' => '2026-09-04T07:00:00Z',
            'window_end' => '2026-10-04T07:00:00Z',
        ],
    ];

    private const ASN = [
        'asn' => 9009,
        'name' => 'M247 Europe SRL',
        'country' => 'RO',
        'registry' => 'ripe',
        'allocated' => '2005-06-06',
        'domain' => 'm247global.com',
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
        return [
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
            'location.latitude "48.2"' => $lookup(['location' => [
                ...Stub::answer('9.9.9.9')['location'], 'latitude' => '48.2',
            ]]),
            'abuse.validated "yes"' => $lookup(['abuse' => ['email' => '', 'validated' => 'yes']]),
            'hosted_domains.total "4"' => $lookup(['hosted_domains' => ['total' => '4']]),
            'trial_expired 1' => $lookup(['trial_expired' => 1]),
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
