<?php

declare(strict_types=1);

namespace InternetData\Tests;

use GuzzleHttp\RequestOptions;
use InternetData\AsnResult;
use InternetData\Bogon;
use InternetData\Client;
use InternetData\DomainResult;
use InternetData\Entitlement;
use InternetData\ErrorKind;
use InternetData\InternetDataException;
use InternetData\Options;
use InternetData\Result;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The lookups' PHP-specific surface, as distinct from the shared conformance
 * corpus in ConformanceTest: VPNDetection's, ported, plus the AS and domain
 * lookups.
 */
final class LookupTest extends TestCase
{
    /** @var list<string> */
    private const ADDRS = [
        '9.9.9.1', '9.9.9.2', '9.9.9.3', '9.9.9.4', '9.9.9.5', '9.9.9.6',
        '9.9.9.7', '9.9.9.8', '9.9.9.9', '9.9.9.10', '9.9.9.11', '9.9.9.12',
    ];

    private const ENTITLEMENT_BODY = [
        'org_id' => '85bb51e4-2eb6-4a31-8e4d-02ba8b98fe61',
        'apikey' => [
            'id' => '0ab424cc-7619-4dad-b027-afacdc2cedb0',
            'expires' => null,
            'allowed_cidrs' => [],
        ],
        'plan' => ['key' => 'max', 'tier' => 'max'],
        'usage' => [
            'requests' => 580,
            'quota' => 5000000,
            'hard_limit' => null,
            'window_start' => '2026-09-04T07:00:00Z',
            'window_end' => '2026-10-04T07:00:00Z',
        ],
    ];

    private const ASN_BODY = [
        'asn' => 9009,
        'name' => 'M247 Europe SRL',
        'country' => 'RO',
        'registry' => 'ripe',
        'allocated' => '2005-06-06',
        'domain' => 'm247global.com',
    ];

    private const DOMAIN_BODY = [
        'domain' => 'example.com',
        'is_registered' => true,
        'has_a' => true,
        'has_aaaa' => true,
        'has_mx' => true,
        'has_ns' => true,
        'has_cname' => false,
    ];

    public function testIsBogonIsOnTheClientAndAgreesWithTheStandaloneStatic(): void
    {
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $client = new Client();
        foreach ($data['isBogon'] as $case) {
            self::assertSame($case['expect'], $client->isBogon($case['ip']), $case['ip']);
            self::assertSame(
                Bogon::isBogon($case['ip']),
                $client->isBogon($case['ip']),
                "{$case['ip']}: client and static disagree",
            );
        }
    }

    // The corpus pins present-versus-absent through whole answers, so a per-field
    // slip in the mapper can survive it. Every member the spec leaves optional is
    // turned on and off here, one answer each way.
    public function testEveryTierGatedMemberKeepsFalseApartFromAbsent(): void
    {
        $flags = [
            'is_vpn' => 'isVpn', 'is_resproxy' => 'isResproxy', 'is_dcproxy' => 'isDcproxy',
            'is_mobproxy' => 'isMobproxy', 'is_pubproxy' => 'isPubproxy', 'is_hosting' => 'isHosting',
            'is_cdn' => 'isCdn', 'trial_expired' => 'trialExpired',
        ];
        $details = [
            'vpn' => 'vpn', 'resproxy' => 'resproxy', 'dcproxy' => 'dcproxy', 'mobproxy' => 'mobproxy',
            'pubproxy' => 'pubproxy', 'hosting' => 'hosting', 'cdn' => 'cdn', 'carrier' => 'carrier',
            'satellite' => 'satellite', 'ssh' => 'ssh', 'smtp' => 'smtp', 'dns_resolver' => 'dnsResolver',
            'bittorrent' => 'bittorrent',
        ];
        $inner = [
            'location' => [
                'region' => '', 'region_code' => '', 'city' => '', 'postal_code' => '', 'latitude' => 0,
                'longitude' => 0, 'accuracy_radius' => 0, 'geoname_id' => '', 'dma_code' => '',
                'last_changed' => '',
            ],
            'asn' => ['country' => '', 'rpki_status' => ''],
            'company' => ['type' => '', 'country' => ''],
            'abuse' => [
                'name' => '', 'phone' => '', 'address' => '', 'country' => '', 'network' => '',
                'validated' => false, 'unvalidated_since' => '',
            ],
        ];

        $body = Stub::answer('9.9.9.1');
        foreach (array_keys($flags) as $wire) {
            $body[$wire] = false;
        }
        foreach (array_keys($details) as $wire) {
            $body[$wire] = new \stdClass();
        }
        foreach ($inner as $object => $members) {
            $body[$object] = array_merge($body[$object], $members);
        }
        $body['web'] = [];
        $body['whois'] = ['id' => '', 'name' => '', 'org' => '', 'country' => '', 'status' => '', 'source' => '',
            'domain' => '', 'abuse_email' => '', 'asn' => 0];
        $body['rwhois'] = ['id' => '', 'name' => '', 'country' => '', 'source' => '', 'domain' => '',
            'abuse_email' => ''];
        $body['hosted_domains'] = ['total' => 0];

        $present = self::lookupOnce($body);
        foreach ($flags as $wire => $property) {
            self::assertFalse($present->{$property}, "{$wire} answered false must stay false");
        }
        foreach ($details as $wire => $property) {
            self::assertNotNull($present->{$property}, "{$wire} answered {} must stay present");
            self::assertTrue($present->{$property}->isEmpty(), "{$wire} answered {} must be empty");
        }
        foreach ($inner as $object => $members) {
            foreach ($members as $wire => $value) {
                $property = lcfirst(str_replace('_', '', ucwords($wire, '_')));
                self::assertEquals($value, $present->{$object}->{$property}, "{$object}.{$wire} answered stays");
                self::assertNotNull($present->{$object}->{$property}, "{$object}.{$wire} answered stays present");
            }
        }
        self::assertSame([], $present->web, 'web answered [] must stay present');
        self::assertSame(0, $present->whois?->asn);
        self::assertSame('', $present->rwhois?->id);
        self::assertSame(0, $present->hostedDomains?->total);

        $absent = self::lookupOnce(Stub::answer('9.9.9.1'));
        foreach ($flags as $wire => $property) {
            self::assertNull($absent->{$property}, "{$wire} left out must stay null, not false");
        }
        foreach ($details as $wire => $property) {
            self::assertNull($absent->{$property}, "{$wire} left out must stay null, not empty");
        }
        foreach ($inner as $object => $members) {
            foreach (array_keys($members) as $wire) {
                $property = lcfirst(str_replace('_', '', ucwords($wire, '_')));
                self::assertNull($absent->{$object}->{$property}, "{$object}.{$wire} left out must stay null");
            }
        }
        foreach (['web', 'whois', 'rwhois', 'hostedDomains'] as $property) {
            self::assertNull($absent->{$property}, "{$property} left out must stay null");
        }
    }

    public function testTheAsAndDomainAnswersKeepFalseApartFromAbsentToo(): void
    {
        $asnPaid = [
            'type' => '', 'num_ips' => 0, 'num_prefixes4' => 0, 'num_prefixes6' => 0, 'rpki_valid' => 0,
            'rpki_invalid' => 0, 'rpki_not_found' => 0, 'whois' => new \stdClass(), 'prefixes' => [],
            'trial_expired' => false,
        ];
        $stub = new Stub([
            '/asn/9009' => [Stub::ok(self::ASN_BODY + $asnPaid), Stub::ok(self::ASN_BODY)],
            '/domain/example.com' => [
                Stub::ok(self::DOMAIN_BODY + ['is_disposable' => false, 'disposable' => new \stdClass(),
                    'trial_expired' => false]),
                Stub::ok(self::DOMAIN_BODY),
            ],
        ]);
        $client = new Client(new Options(httpClient: $stub->client));

        $asn = $client->lookupAsn(9009);
        self::assertSame(['', 0, 0, 0, 0, 0, 0, [], [], false], [
            $asn->type, $asn->numIps, $asn->numPrefixes4, $asn->numPrefixes6, $asn->rpkiValid,
            $asn->rpkiInvalid, $asn->rpkiNotFound, $asn->whois, $asn->prefixes, $asn->trialExpired,
        ]);
        $bare = $client->lookupAsn(9009);
        foreach (['type', 'numIps', 'numPrefixes4', 'numPrefixes6', 'rpkiValid', 'rpkiInvalid', 'rpkiNotFound',
            'whois', 'prefixes', 'trialExpired'] as $property) {
            self::assertNull($bare->{$property}, "AS {$property} left out must stay null");
        }

        $domain = $client->lookupDomain('example.com');
        self::assertFalse($domain->isDisposable);
        self::assertTrue($domain->disposable?->isEmpty());
        self::assertFalse($domain->trialExpired);
        $bare = $client->lookupDomain('example.com');
        foreach (['isDisposable', 'disposable', 'dns', 'ipRanges', 'trialExpired'] as $property) {
            self::assertNull($bare->{$property}, "domain {$property} left out must stay null");
        }
    }

    public function testBatchConcurrencyIsConfigurablePerCall(): void
    {
        $stub = self::addressStub();
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $client->lookupBatch(self::manyAddresses(), ['concurrency' => 3]);

        self::assertCount(7, $stub->calls, 'one request per chunk of 1000');
        self::assertLessThanOrEqual(3, $stub->peak, "peak in flight was {$stub->peak}");
        self::assertGreaterThan(1, $stub->peak, 'requests should still overlap');
    }

    public function testAPerCallConcurrencyOverridesTheClientDefault(): void
    {
        $stub = self::addressStub();
        // Instance default of 2, raised to 6 for this one batch.
        $client = new Client(new Options(cache: false, concurrency: 2, httpClient: $stub->client));

        $client->lookupBatch(self::manyAddresses(), ['concurrency' => 6]);

        self::assertGreaterThan(2, $stub->peak, "override ignored: peak was {$stub->peak}");
        self::assertLessThanOrEqual(6, $stub->peak, "peak in flight was {$stub->peak}");
    }

    public function testWithoutAnOverrideTheClientConcurrencyStillApplies(): void
    {
        $stub = self::addressStub();
        $client = new Client(new Options(cache: false, concurrency: 2, httpClient: $stub->client));

        $client->lookupBatch(self::manyAddresses());

        self::assertLessThanOrEqual(2, $stub->peak, "peak in flight was {$stub->peak}");
    }

    public function testRetriesAreConfigurablePerCall(): void
    {
        $failing = ['status' => 500, 'body' => ['error' => 'lookup failed']];
        $calls = [
            '/9.9.9.9' => static fn (Client $c, array $o): mixed => $c->lookup('9.9.9.9', $o),
            '/asn/9009' => static fn (Client $c, array $o): mixed => $c->lookupAsn(9009, $o),
            '/domain/example.com' => static fn (Client $c, array $o): mixed => $c->lookupDomain('example.com', $o),
            '/myip' => static fn (Client $c, array $o): mixed => $c->myIp($o),
            '/api/v1/entitlement' => static fn (Client $c, array $o): mixed => $c->myEntitlement($o),
        ];
        foreach ($calls as $path => $call) {
            $stub = new Stub([$path => $failing]);
            $client = new Client(new Options(cache: false, retries: 0, httpClient: $stub->client));
            try {
                $call($client, ['retries' => 2]);
                self::fail("{$path}: answered");
            } catch (InternetDataException $e) {
                self::assertSame(ErrorKind::ServerError, $e->kind, $path);
            }
            // 1 initial attempt plus 2 retries, rather than the instance's 0.
            self::assertCount(3, $stub->calls, $path);
        }
    }

    public function testARateLimitIsRetriedAfterTheDelayTheServerAskedFor(): void
    {
        $stub = new Stub(Stub::lookups([
            '9.9.9.9' => [
                [
                    'status' => 429,
                    'headers' => ['Retry-After' => '2'],
                    'body' => ['error' => 'per-minute limit for Unlimited lookups exceeded'],
                ],
                Stub::ok(Stub::answer('9.9.9.9', ['is_tor' => true])),
            ],
        ]));
        $client = new Client(new Options(cache: false, retries: 1, httpClient: $stub->client));

        $result = $client->lookup('9.9.9.9');

        self::assertTrue($result->isTor);
        // Scheduled rather than slept: a batch keeps moving while one address waits.
        self::assertSame([0, 2000], $stub->delays);
    }

    /** An empty `error` says nothing, and must not hide the `rc` beside it. */
    public function testAnEmptyErrorLeavesTheRcAsTheMessage(): void
    {
        $stub = new Stub(['/9.9.9.9' => ['status' => 429, 'body' => ['error' => '', 'rc' => 'QUOTA_EXCEEDED']]]);
        $client = new Client(new Options(apiKey: 'k', cache: false, httpClient: $stub->client));
        try {
            $client->lookup('9.9.9.9');
            self::fail('answered');
        } catch (InternetDataException $e) {
            self::assertSame('QUOTA_EXCEEDED', $e->getMessage());
        }
    }

    /**
     * An answer that is not the shape the models declare is no answer: the
     * retried server_error any unreadable answer is, carrying the status.
     */
    public function testAnAnswerALookupCannotReadIsARetriedServerError(): void
    {
        $calls = [
            '/9.9.9.9' => static fn (Client $c) => $c->lookup('9.9.9.9'),
            '/asn/9009' => static fn (Client $c) => $c->lookupAsn(9009),
            '/domain/example.com' => static fn (Client $c) => $c->lookupDomain('example.com'),
            '/myip' => static fn (Client $c) => $c->myIp(),
            '/api/v1/entitlement' => static fn (Client $c) => $c->myEntitlement(),
        ];
        $bodies = [
            'an HTML page' => ['<html>gateway</html>', 'text/html'],
            'a cut-off body' => ['{"ip":', 'application/json'],
            'an empty body' => ['', 'application/json'],
            'an array' => ['[]', 'application/json'],
            'a string' => ['"x"', 'application/json'],
            'null' => ['null', 'application/json'],
            'an empty object' => ['{}', 'application/json'],
            'members of the wrong type' => [
                '{"ip":1,"asn":"x","domain":1,"apikey":"x","plan":"x","usage":"x"}',
                'application/json',
            ],
            'entries without their members' => [
                '{"location":{},"apikey":{},"plan":{},"usage":{}}',
                'application/json',
            ],
        ];
        foreach ($calls as $path => $call) {
            foreach ($bodies as $name => [$body, $type]) {
                $stub = new Stub([$path => [
                    'status' => 200,
                    'headers' => ['Content-Type' => $type],
                    'body' => $body,
                ]]);
                $client = new Client(
                    new Options(apiKey: 'k', cache: false, retries: 2, httpClient: $stub->client),
                );
                try {
                    $call($client);
                    self::fail("{$path}, {$name}: returned an answer");
                } catch (InternetDataException $e) {
                    self::assertSame(ErrorKind::ServerError, $e->kind, "{$path}, {$name}");
                    self::assertSame(200, $e->status, "{$path}, {$name}: the status");
                }
                self::assertCount(3, $stub->calls, "{$path}, {$name}: retried like an outage");
            }
        }
    }

    /**
     * A detail object is `{}` under a false flag, but one that is populated
     * carries every member its schema requires, and a shared object always does.
     */
    public function testAPartlyPopulatedObjectIsARetriedServerError(): void
    {
        $spec = 'breaks the spec';
        $with = static fn (array $members): array => Stub::answer('9.9.9.9', $members);
        $bodies = [
            'a detail of the wrong type' => ['of the wrong type', $with(['tor' => ['provider' => 1]])],
            'a location without its country' => [$spec, $with(['location' => ['continent' => 'EU']])],
            'an empty location' => [$spec, $with(['location' => new \stdClass()])],
            'an empty AS' => [$spec, $with(['asn' => new \stdClass()])],
            'a web row without its port' => [$spec, $with(['is_web' => true, 'web' => [['tls_ok' => true]]])],
            'an empty web row' => [$spec, $with(['is_web' => true, 'web' => [new \stdClass()]])],
            'tor absent' => [$spec, array_diff_key($with([]), ['tor' => 1])],
            'is_bogon absent' => [$spec, array_diff_key($with([]), ['is_bogon' => 1])],
        ];
        foreach ($bodies as $name => [$why, $body]) {
            $stub = new Stub(['/9.9.9.9' => Stub::ok($body)]);
            $client = new Client(new Options(cache: false, retries: 2, httpClient: $stub->client));
            try {
                $client->lookup('9.9.9.9');
                self::fail("{$name}: returned an answer");
            } catch (InternetDataException $e) {
                self::assertSame(ErrorKind::ServerError, $e->kind, $name);
                self::assertSame(200, $e->status, "{$name}: the status");
                // Read as the spec says, never left to a typed getter's TypeError.
                self::assertStringContainsString($why, $e->getMessage(), $name);
            }
            self::assertCount(3, $stub->calls, "{$name}: retried like an outage");
        }
    }

    /**
     * A detail requires no member, so one served partly is read as served, the
     * rest null: only a member of the wrong type, or a shared object without what
     * it requires, makes an answer unreadable.
     */
    public function testADetailNeedNotCarryEveryMember(): void
    {
        $result = self::lookupOnce(Stub::answer('9.9.9.1', ['is_tor' => true, 'tor' => ['provider' => 'tor']]));

        self::assertSame('tor', $result->tor->provider);
        self::assertNull($result->tor->providerName);
        self::assertFalse($result->tor->isEmpty());
        self::assertTrue($result->relay->isEmpty());
    }

    public function testABatchAnswerItCannotReadIsEveryAddressesRetriedServerError(): void
    {
        $bodies = [
            'an HTML page' => '<html>gateway</html>',
            'a cut-off body' => '{"results":{',
            'an empty body' => '',
            'an array' => '[]',
            'a string' => '"x"',
            'null' => 'null',
            'an empty object' => '{}',
            'no errors member' => '{"results":{}}',
            'members of the wrong type' => '{"results":"x","errors":"x"}',
        ];
        foreach ($bodies as $name => $body) {
            $stub = new Stub(['/batch' => ['status' => 200, 'body' => $body]]);
            $client = new Client(new Options(cache: false, retries: 2, httpClient: $stub->client));

            $answers = $client->lookupBatch(['9.9.9.1', '9.9.9.2']);

            self::assertSame(['9.9.9.1', '9.9.9.2'], array_keys($answers), $name);
            foreach ($answers as $ip => $answer) {
                self::assertInstanceOf(InternetDataException::class, $answer, "{$name}: {$ip}");
                self::assertSame(ErrorKind::ServerError, $answer->kind, "{$name}: {$ip}");
                self::assertSame(200, $answer->status, "{$name}: {$ip}");
            }
            self::assertCount(3, $stub->calls, "{$name}: retried like an outage");
        }
    }

    public function testABatchEntryItCannotReadFailsThatAddressAlone(): void
    {
        $stub = new Stub(['/batch' => Stub::ok([
            'results' => [
                '9.9.9.1' => Stub::answer('9.9.9.1', ['is_vpn' => true]),
                '9.9.9.2' => [],
                '9.9.9.3' => 'x',
            ],
            'errors' => (object) [],
        ])]);
        $client = new Client(new Options(retries: 2, httpClient: $stub->client));

        $answers = $client->lookupBatch(['9.9.9.1', '9.9.9.2', '9.9.9.3', '9.9.9.4']);

        self::assertInstanceOf(Result::class, $answers['9.9.9.1']);
        self::assertTrue($answers['9.9.9.1']->isVpn);
        foreach (['9.9.9.2', '9.9.9.3', '9.9.9.4'] as $ip) {
            self::assertInstanceOf(InternetDataException::class, $answers[$ip], $ip);
            self::assertSame(ErrorKind::ServerError, $answers[$ip]->kind, $ip);
            self::assertSame(200, $answers[$ip]->status, $ip);
        }
        self::assertCount(1, $stub->calls, 'an entry is never retried on its own');
        // Only the answer it could read was cached.
        $client->lookupBatch(['9.9.9.1']);
        self::assertCount(1, $stub->calls);
    }

    public function testAnUnknownPerCallOptionIsRejectedRatherThanIgnored(): void
    {
        $client = new Client();

        $this->expectException(InvalidArgumentException::class);
        $client->lookupBatch(['1.1.1.1'], ['concurency' => 4]);
    }

    // A batch that can put no chunk in flight never finishes, so this has to be
    // refused before any request rather than discovered by a caller left waiting.
    public function testAConcurrencyBelowOneIsRefusedBeforeAnyRequest(): void
    {
        $stub = self::addressStub();
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        foreach ([0, -1] as $bad) {
            try {
                $client->lookupBatch(['9.9.9.1', '9.9.9.2'], ['concurrency' => $bad]);
                self::fail("concurrency {$bad} was accepted");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('concurrency', $e->getMessage());
            }
            try {
                new Options(concurrency: $bad);
                self::fail("a client concurrency of {$bad} was accepted");
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $stub->calls);
    }

    /**
     * A per-call `retries` of -1 would read as none, anything but an int fail the
     * call as a raw TypeError, and a NaN or fractional `concurrency` be taken,
     * while a bogon, a cache hit or a bogon-only batch answered any of them. Each
     * is refused where it is set, before anything can answer.
     */
    public function testABadPerCallRetryCountOrConcurrencyIsRefusedBeforeAnythingAnswers(): void
    {
        $stub = self::addressStub();
        $client = new Client(new Options(httpClient: $stub->client));
        $client->lookup('9.9.9.1');
        self::assertCount(1, $stub->calls);

        $bad = [-1, NAN, INF, 1.5, 2.0, '2', 'x', '', [], true, new \stdClass()];
        $calls = [
            'lookup' => static fn (array $o): mixed => $client->lookup('9.9.9.2', $o),
            'a bogon' => static fn (array $o): mixed => $client->lookup('127.0.0.1', $o),
            'a cache hit' => static fn (array $o): mixed => $client->lookup('9.9.9.1', $o),
            'lookupAsn' => static fn (array $o): mixed => $client->lookupAsn(9009, $o),
            'lookupDomain' => static fn (array $o): mixed => $client->lookupDomain('example.com', $o),
            'myIp' => static fn (array $o): mixed => $client->myIp($o),
            'myEntitlement' => static fn (array $o): mixed => $client->myEntitlement($o),
            'lookupBatch' => static fn (array $o): mixed => $client->lookupBatch(['9.9.9.3', '9.9.9.4'], $o),
            'a bogon-only batch' => static fn (array $o): mixed => $client->lookupBatch(['10.0.0.1'], $o),
        ];
        $batches = ['lookupBatch', 'a bogon-only batch'];
        foreach ($calls as $name => $call) {
            foreach ($bad as $value) {
                $options = [['retries' => $value]];
                if (in_array($name, $batches, true)) {
                    $options[] = ['concurrency' => $value];
                }
                foreach ($options as $option) {
                    $label = $name . ' ' . key($option) . ' ' . var_export($value, true);
                    try {
                        $call($option);
                        self::fail("{$label} was accepted");
                    } catch (InvalidArgumentException $e) {
                        self::assertStringContainsString((string) key($option), $e->getMessage(), $label);
                    }
                }
            }
        }
        self::assertCount(1, $stub->calls);

        // An int in range, and null for unset, still read.
        self::assertInstanceOf(Result::class, $client->lookup('9.9.9.5', ['retries' => 0]));
        self::assertInstanceOf(Result::class, $client->lookup('9.9.9.6', ['retries' => null]));
        self::assertCount(2, $client->lookupBatch(['9.9.9.7', '9.9.9.8'], ['retries' => 1, 'concurrency' => 1]));
        self::assertCount(2, $client->lookupBatch(['9.9.9.9', '9.9.9.10'], ['concurrency' => null]));
    }

    public function testConcurrencyIsNotAcceptedOnASingleLookup(): void
    {
        $client = new Client();
        $calls = [
            'lookup' => static fn () => $client->lookup('1.1.1.1', ['concurrency' => 4]),
            'lookupAsn' => static fn () => $client->lookupAsn(9009, ['concurrency' => 4]),
            'lookupDomain' => static fn () => $client->lookupDomain('example.com', ['concurrency' => 4]),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("{$name} took a concurrency");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('concurrency', $e->getMessage(), $name);
            }
        }
    }

    public function testTheCacheEvictsTheLeastRecentlyUsedAddress(): void
    {
        $stub = self::addressStub();
        $client = new Client(new Options(cacheMaxSize: 2, httpClient: $stub->client));

        $client->lookup('9.9.9.1');
        $client->lookup('9.9.9.2');
        $client->lookup('9.9.9.1');
        $client->lookup('9.9.9.3');
        self::assertCount(3, $stub->calls);

        // 9.9.9.1 was used again so 9.9.9.2 is the one that went.
        $client->lookup('9.9.9.1');
        self::assertCount(3, $stub->calls);
        $client->lookup('9.9.9.2');
        self::assertCount(4, $stub->calls);
    }

    public function testACachedAnswerExpires(): void
    {
        $stub = self::addressStub();
        $client = new Client(new Options(cacheTtlSeconds: 0.05, httpClient: $stub->client));

        $client->lookup('9.9.9.1');
        $client->lookup('9.9.9.1');
        self::assertCount(1, $stub->calls);

        usleep(60_000);
        $client->lookup('9.9.9.1');
        self::assertCount(2, $stub->calls);
    }

    // The cache is keyed by address, so only the address lookups use it: an AS or
    // a domain is asked for every time.
    public function testTheAsAndDomainLookupsAreNeverCached(): void
    {
        $stub = new Stub([
            '/asn/9009' => Stub::ok(self::ASN_BODY),
            '/domain/example.com' => Stub::ok(self::DOMAIN_BODY),
        ]);
        $client = new Client(new Options(httpClient: $stub->client));

        $client->lookupAsn(9009);
        $client->lookupAsn(9009);
        $client->lookupDomain('example.com');
        $client->lookupDomain('example.com');

        self::assertSame(['/asn/9009', '/asn/9009', '/domain/example.com', '/domain/example.com'], $stub->calls);
    }

    /**
     * An AS number and a domain go out as given, escaped for the path, and the
     * API answers what it can read: nothing is normalized or refused here.
     */
    public function testTheAsAndDomainAreSentAsGiven(): void
    {
        $refused = ['status' => 400, 'body' => ['error' => 'not a valid IP address, AS number or domain']];
        $stub = new Stub([
            '/asn/9009' => Stub::ok(self::ASN_BODY),
            '/asn/4294967295' => Stub::ok(self::ASN_BODY),
            '/asn/-1' => $refused,
            '/domain/Example.COM' => Stub::ok(self::DOMAIN_BODY),
            '/domain/a/b c?d#e' => $refused,
        ]);
        $client = new Client(new Options(retries: 2, httpClient: $stub->client));

        self::assertInstanceOf(AsnResult::class, $client->lookupAsn(9009));
        self::assertSame(9009, $client->lookupAsn(4294967295)->asn);
        self::assertInstanceOf(DomainResult::class, $client->lookupDomain('Example.COM'));
        $unreadable = [static fn () => $client->lookupAsn(-1), static fn () => $client->lookupDomain('a/b c?d#e')];
        foreach ($unreadable as $call) {
            try {
                $call();
                self::fail('answered');
            } catch (InternetDataException $e) {
                self::assertSame(ErrorKind::BadRequest, $e->kind);
                self::assertSame('not a valid IP address, AS number or domain', $e->getMessage());
            }
        }
        self::assertSame('/domain/a%2Fb%20c%3Fd%23e', $stub->requests[4]->getUri()->getPath());
        self::assertSame('', $stub->requests[4]->getUri()->getQuery());
        self::assertCount(5, $stub->calls, 'each sent once');
    }

    // Under PHP-FPM every request builds its own client, so a store is the only
    // cache a second request can see: here, two clients stand for two requests.
    public function testAStoreSharesAnswersBetweenClients(): void
    {
        $stub = self::addressStub();
        $store = new ArrayStore();
        $first = new Client(new Options(apiKey: 'k', cacheStore: $store, httpClient: $stub->client));
        $second = new Client(new Options(apiKey: 'k', cacheStore: $store, httpClient: $stub->client));

        $answer = $first->lookup('9.9.9.1');
        $again = $second->lookup('9.9.9.1');

        self::assertCount(1, $stub->calls);
        self::assertSame($answer->raw, $again->raw);
        self::assertSame('9.9.9.1', $again->ip);
        self::assertFalse($again->isTor);
        self::assertTrue($again->tor->isEmpty());
        self::assertSame([3600], $store->ttls);
    }

    // Two keys can be on different plans, entitled to different fields.
    public function testAStoreKeepsTwoKeysApart(): void
    {
        $stub = self::addressStub();
        $store = new ArrayStore();
        (new Client(new Options(apiKey: 'a', cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.1');
        (new Client(new Options(apiKey: 'b', cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.1');
        (new Client(new Options(cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.1');

        self::assertCount(3, $stub->calls);
        self::assertCount(3, $store->values);
    }

    public function testAStoreKeyIsPortableAndHoldsNothingInTheClear(): void
    {
        $stub = self::addressStub();
        $store = new ArrayStore();
        (new Client(new Options(apiKey: 'secret-key', cacheStore: $store, httpClient: $stub->client)))
            ->lookup('9.9.9.1');

        $key = (string) array_key_first($store->values);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]{1,64}$/', $key);
        self::assertStringNotContainsString('secret-key', $key);
        self::assertStringNotContainsString('9.9.9.1', $key);
    }

    public function testABatchReadsTheStoreOnceAndFillsIt(): void
    {
        $stub = self::addressStub();
        $store = new ArrayStore();
        (new Client(new Options(apiKey: 'k', cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.1');

        $batch = (new Client(new Options(apiKey: 'k', cacheStore: $store, httpClient: $stub->client)))
            ->lookupBatch(['9.9.9.1', '9.9.9.2', '9.9.9.3', '10.0.0.1']);

        self::assertSame(['/9.9.9.1', '/batch'], $stub->calls);
        self::assertSame(['9.9.9.2', '9.9.9.3'], json_decode((string) $stub->requests[1]->getBody(), true)['ips']);
        self::assertSame(2, $store->reads, 'one read for the lookup, one for the whole batch');
        self::assertSame('9.9.9.1', $batch['9.9.9.1']->ip);

        (new Client(new Options(apiKey: 'k', cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.3');
        self::assertCount(2, $stub->calls);
    }

    public function testAFailingStoreIsAMissAndNeverAFailedLookup(): void
    {
        $stub = self::addressStub();
        $store = new ArrayStore();
        $store->failing = true;
        $client = new Client(new Options(cacheStore: $store, httpClient: $stub->client));

        self::assertSame('9.9.9.1', $client->lookup('9.9.9.1')->ip);
        self::assertSame('9.9.9.2', $client->lookupBatch(['9.9.9.2'])['9.9.9.2']->ip);
        self::assertCount(2, $stub->calls);
    }

    // An entry another release wrote, or one the store mangled, is a miss.
    public function testAnEntryTheClientCannotReadIsAMiss(): void
    {
        $stub = self::addressStub();
        $store = new ArrayStore();
        (new Client(new Options(cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.1');
        foreach ([['ip' => '9.9.9.1'], 'garbage', ['is_tor' => 'no']] as $bad) {
            $store->values[(string) array_key_first($store->values)] = $bad;
            self::assertSame(
                '9.9.9.1',
                (new Client(new Options(cacheStore: $store, httpClient: $stub->client)))->lookup('9.9.9.1')->ip,
            );
        }
        self::assertCount(4, $stub->calls);
    }

    public function testAStoreWithTheCacheOffIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Options(cache: false, cacheStore: new ArrayStore());
    }

    public function testAKeyIsSentAsABearerTokenAndOmittedEntirelyWithoutOne(): void
    {
        $stub = new Stub(Stub::lookups(['9.9.9.1' => Stub::ok(Stub::answer('9.9.9.1'))]));

        (new Client(new Options(apiKey: 'secret', httpClient: $stub->client)))->lookup('9.9.9.1');
        (new Client(new Options(httpClient: $stub->client)))->lookup('9.9.9.1');

        self::assertSame('Bearer secret', $stub->requests[0]->getHeaderLine('Authorization'));
        // A key must be absent rather than empty: `Authorization: Bearer ` is a 401.
        self::assertFalse($stub->requests[1]->hasHeader('Authorization'));
        self::assertStringStartsWith('internetdata-php/', $stub->requests[0]->getHeaderLine('User-Agent'));
        self::assertSame('', $stub->requests[0]->getUri()->getQuery(), 'the key never rides in the query');
    }

    /**
     * Every call goes to the API host by default, the database's included: they
     * answer there as on the apex.
     */
    public function testTheApiHostIsTheDefaultBaseUrl(): void
    {
        $stub = new Stub([
            '/9.9.9.1' => Stub::ok(Stub::answer('9.9.9.1')),
            '/asn/9009' => Stub::ok(self::ASN_BODY),
            '/domain/example.com' => Stub::ok(self::DOMAIN_BODY),
            '/myip' => Stub::ok(Stub::answer('9.9.9.2')),
            '/api/v1/entitlement' => Stub::ok(self::ENTITLEMENT_BODY),
            Stub::LIST => Stub::ok(['databases' => []]),
        ]);
        $client = new Client(new Options(apiKey: 'k', httpClient: $stub->client));

        $client->lookup('9.9.9.1');
        $client->lookupAsn(9009);
        $client->lookupDomain('example.com');
        $client->myIp();
        $client->myEntitlement();
        $client->lookupBatch(['9.9.9.3', '9.9.9.4']);
        $client->database->list();

        self::assertSame('https://api.internetdata.io', Client::DEFAULT_BASE_URL);
        self::assertSame(
            ['/9.9.9.1', '/asn/9009', '/domain/example.com', '/myip', '/api/v1/entitlement', '/batch', Stub::LIST],
            $stub->calls,
        );
        foreach ($stub->requests as $request) {
            self::assertSame('api.internetdata.io', $request->getUri()->getHost());
        }
    }

    // The spec names a host for the lookups alone, and a generated request builder
    // used it over the client's: a staging client looked its addresses up in prod.
    public function testEveryCallGoesToTheBaseUrlTheClientWasGiven(): void
    {
        $stub = new Stub([
            '/9.9.9.1' => Stub::ok(Stub::answer('9.9.9.1')),
            '/asn/9009' => Stub::ok(self::ASN_BODY),
            '/domain/example.com' => Stub::ok(self::DOMAIN_BODY),
            '/myip' => Stub::ok(Stub::answer('9.9.9.2')),
            '/api/v1/entitlement' => Stub::ok(self::ENTITLEMENT_BODY),
            Stub::LIST => Stub::ok(['databases' => []]),
        ]);
        $client = new Client(new Options(
            apiKey: 'k',
            baseUrl: 'http://api-staging.example.test:8080/',
            httpClient: $stub->client,
        ));

        $client->lookup('9.9.9.1');
        $client->lookupAsn(9009);
        $client->lookupDomain('example.com');
        $client->myIp();
        $client->myEntitlement();
        $client->lookupBatch(['9.9.9.3', '9.9.9.4']);
        $client->database->list();

        self::assertCount(7, $stub->requests);
        foreach ($stub->requests as $request) {
            self::assertSame('api-staging.example.test:8080', $request->getUri()->getAuthority());
        }
    }

    public function testApiCallsCarryATimeout(): void
    {
        $stub = new Stub(Stub::lookups(['1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1'))]));

        (new Client(new Options(timeout: 7.5, httpClient: $stub->client)))->lookup('1.1.1.1');

        // Guzzle defaults both to 0, meaning unlimited, so an unset option here
        // is a caller held until the process is killed.
        self::assertSame(7.5, $stub->options[0][RequestOptions::TIMEOUT]);
        self::assertSame(7.5, $stub->options[0][RequestOptions::CONNECT_TIMEOUT]);
    }

    /**
     * Guzzle refuses these only once a request is built, so the client and the
     * call would both take them and every call then fail. Refused where set,
     * having sent nothing, a bogon's and a cache hit's included.
     */
    public function testATimeoutNoAttemptCanMeetIsRefusedWhereItIsSet(): void
    {
        $stub = new Stub(Stub::lookups(['9.9.9.9' => Stub::ok(Stub::answer('9.9.9.9'))]));
        $client = new Client(new Options(httpClient: $stub->client));
        $client->lookup('9.9.9.9');
        foreach ([NAN, INF, 0.0005, 9e15, 1e300] as $bad) {
            $sets = [
                'client' => fn () => new Options(timeout: $bad),
                'call' => fn () => $client->lookup('9.9.9.8', ['timeout' => $bad]),
                'a bogon' => fn () => $client->lookup('10.0.0.1', ['timeout' => $bad]),
                'a cache hit' => fn () => $client->lookup('9.9.9.9', ['timeout' => $bad]),
                'an AS' => fn () => $client->lookupAsn(9009, ['timeout' => $bad]),
                'a domain' => fn () => $client->lookupDomain('example.com', ['timeout' => $bad]),
                'a bogon-only batch' => fn () => $client->lookupBatch(['10.0.0.1'], ['timeout' => $bad]),
            ];
            foreach ($sets as $where => $set) {
                try {
                    $set();
                    self::fail("{$where} took timeout {$bad}");
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('timeout', $e->getMessage());
                }
            }
        }
        self::assertCount(1, $stub->calls, 'and not one request was spent finding out');

        foreach ([0, 0.001, 8.9e15] as $good) {
            new Options(timeout: $good);
            self::assertFalse($client->lookup('9.9.9.9', ['timeout' => $good])->isTor, "timeout {$good}");
        }
    }

    /**
     * A body stalled after the headers, and one trickled a byte every 20 ms so
     * that no single read ever waits long. A bound that stopped at the headers,
     * or bounded each read, would outlast both.
     *
     * @return array<string, array{array{stallSeconds?: int, trickleMs?: int}}>
     */
    public static function slowBodies(): array
    {
        return [
            'stalled' => [['stallSeconds' => 5]],
            'trickled' => [['trickleMs' => 20]],
        ];
    }

    /** @param array{stallSeconds?: int, trickleMs?: int} $body */
    #[DataProvider('slowBodies')]
    public function testAPerCallTimeoutBelowTheClientsFiresOnASlowBody(array $body): void
    {
        $calls = [
            'lookup' => static fn (Client $c, array $o): mixed => $c->lookup('9.9.9.9', $o),
            'lookupAsn' => static fn (Client $c, array $o): mixed => $c->lookupAsn(9009, $o),
            'lookupDomain' => static fn (Client $c, array $o): mixed => $c->lookupDomain('example.com', $o),
            'myIp' => static fn (Client $c, array $o): mixed => $c->myIp($o),
            'myEntitlement' => static fn (Client $c, array $o): mixed => $c->myEntitlement($o),
            'lookupBatch' => static fn (Client $c, array $o): mixed
                => $c->lookupBatch(['9.9.9.9'], $o)['9.9.9.9'],
        ];

        foreach ($calls as $name => $call) {
            $timed = static fn (Client $c): mixed => $call($c, ['timeout' => 0.25]);
            Origin::assertTimesOut($body, 3.0, $timed, $name);
        }
    }

    // A per-call value written into something the client shares would pass the
    // test above and leave every later call on the wrong bound.
    /** @param array{stallSeconds?: int, trickleMs?: int} $body */
    #[DataProvider('slowBodies')]
    public function testWithoutAnOverrideTheClientsOwnTimeoutFiresOnASlowBody(array $body): void
    {
        Origin::assertTimesOut($body, 0.25, static fn (Client $c): mixed => $c->lookup('9.9.9.9'), 'lookup');
    }

    public function testAPerCallTimeoutBoundsEveryAttemptAndOnlyThatCall(): void
    {
        $stub = new Stub(Stub::lookups(['9.9.9.9' => Stub::transportFailure()]));
        $client = new Client(new Options(cache: false, retries: 1, timeout: 7.5, httpClient: $stub->client));

        foreach ([['timeout' => 2], []] as $options) {
            try {
                $client->lookup('9.9.9.9', $options);
                self::fail('expected a failure');
            } catch (InternetDataException) {
            }
        }

        // The retry keeps the call's bound rather than falling back to the client's.
        self::assertSame([2.0, 2.0, 7.5, 7.5], array_column($stub->options, RequestOptions::TIMEOUT));
        self::assertSame([2.0, 2.0, 7.5, 7.5], array_column($stub->options, RequestOptions::CONNECT_TIMEOUT));
    }

    public function testAPerCallTimeoutThatCannotBoundAnythingIsRefusedBeforeAnyRequest(): void
    {
        $stub = new Stub();
        $client = new Client(new Options(httpClient: $stub->client));

        foreach ([-1, '5'] as $bad) {
            try {
                $client->lookupBatch(['9.9.9.9'], ['timeout' => $bad]);
                self::fail('expected a refusal for ' . var_export($bad, true));
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame([], $stub->calls);
    }

    public function testMyIpAnswersForTheCallingAddress(): void
    {
        $stub = new Stub(['/myip' => Stub::ok(Stub::answer('45.83.91.1', ['is_vpn' => true]))]);
        $client = new Client(new Options(httpClient: $stub->client));

        $result = $client->myIp();

        self::assertSame('45.83.91.1', $result->ip);
        self::assertTrue($result->isVpn);
    }

    public function testMyIpIsNotCached(): void
    {
        // The cache is keyed by address, and which address this is IS the question.
        $stub = new Stub(['/myip' => Stub::ok(Stub::answer('45.83.91.1'))]);
        $client = new Client(new Options(httpClient: $stub->client));

        $client->myIp();
        $client->myIp();

        self::assertCount(2, $stub->calls);
    }

    public function testMyEntitlementReportsThePlanAndTheUsage(): void
    {
        $stub = new Stub(['/api/v1/entitlement' => Stub::ok(self::ENTITLEMENT_BODY)]);
        $client = new Client(new Options(httpClient: $stub->client));

        $ent = $client->myEntitlement();

        self::assertInstanceOf(Entitlement::class, $ent);
        self::assertSame('max', $ent->plan->key);
        self::assertSame('max', $ent->plan->tier);
        self::assertSame(580, $ent->usage->requests);
        self::assertSame(5000000, $ent->usage->quota);
        // Null means NEVER stop, which is not the same as a limit of zero.
        self::assertNull($ent->usage->hardLimit);
        self::assertSame([], $ent->apikey->allowedCidrs);
        self::assertSame('2026-10-04', $ent->usage->windowEnd->format('Y-m-d'));
    }

    public function testMyEntitlementIsNotCached(): void
    {
        // The whole point is what has been spent.
        $stub = new Stub(['/api/v1/entitlement' => Stub::ok(self::ENTITLEMENT_BODY)]);
        $client = new Client(new Options(httpClient: $stub->client));

        $client->myEntitlement();
        $client->myEntitlement();

        self::assertCount(2, $stub->calls);
    }

    public function testMyEntitlementSurfacesAnUnauthorizedKey(): void
    {
        $stub = new Stub([
            '/api/v1/entitlement' => ['status' => 401, 'body' => ['error' => 'an API key is required']],
        ]);
        $client = new Client(new Options(retries: 0, httpClient: $stub->client));

        try {
            $client->myEntitlement();
            self::fail('answered');
        } catch (InternetDataException $e) {
            self::assertSame(ErrorKind::Unauthorized, $e->kind);
            self::assertSame('an API key is required', $e->getMessage());
        }
    }

    public function testTheAnswersAreReadonly(): void
    {
        $result = self::lookupOnce(Stub::answer('9.9.9.1'));

        $this->expectException(\Error::class);
        $result->location->country = 'XX';
    }

    /** @param array<string, mixed> $body */
    private static function lookupOnce(array $body): Result
    {
        $stub = new Stub(Stub::lookups([$body['ip'] => Stub::ok($body)]));
        return (new Client(new Options(httpClient: $stub->client)))->lookup($body['ip']);
    }

    private static function addressStub(): Stub
    {
        $routes = [];
        foreach ([...self::ADDRS, ...self::manyAddresses()] as $ip) {
            $routes[$ip] = Stub::ok(Stub::answer($ip));
        }
        return new Stub(Stub::lookups($routes));
    }

    /**
     * Enough addresses for seven chunks of the batch endpoint's 1000, so a
     * concurrency bound has something to bound: one request per chunk, and only
     * the chunks overlap.
     *
     * @return list<string>
     */
    private static function manyAddresses(): array
    {
        $addresses = [];
        for ($i = 0; $i < 6001; $i++) {
            $addresses[] = sprintf('9.%d.%d.%d', 1 + intdiv($i, 65536), intdiv($i, 256) % 256, $i % 256);
        }
        return $addresses;
    }
}
