<?php

declare(strict_types=1);

namespace InternetData\Tests;

use InternetData\AsnResult;
use InternetData\Bogon;
use InternetData\Client;
use InternetData\DomainResult;
use InternetData\InternetDataException;
use InternetData\Options;
use InternetData\Result;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the shared conformance corpus that every InternetData SDK asserts.
 *
 * The corpus is generated into testdata/ and is identical across languages, so a
 * behavior that drifts here fails here rather than surfacing as two client
 * libraries quietly disagreeing about the same address or refusal.
 */
final class ConformanceTest extends TestCase
{
    /**
     * The visibility rules this file covers, by the corpus's own names. A rule
     * added to the corpus and not to this list fails the last test below, which
     * is what stops a new rule landing in twelve repositories unimplemented.
     *
     * @var list<string>
     */
    private const COVERED_RULES = [
        'listing-is-returned-as-served',
        'no-catalog-is-compiled-into-the-client',
        'a-listing-is-never-reused-across-clients',
    ];

    /** @var array<string, mixed> */
    private static array $data;

    public static function setUpBeforeClass(): void
    {
        self::$data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * The lookups answer a refusal with an `error`, the database calls with an
     * `rc`, and the corpus holds both: each is read the same way whichever call
     * it reaches.
     */
    public function testEveryRefusalIsClassifiedByStatusAndRetryAfter(): void
    {
        foreach (self::$data['errors'] as $case) {
            foreach (self::refusable() as $call => [$path, $send]) {
                $stub = new Stub([$path => [
                    'status' => $case['status'],
                    'headers' => $case['headers'],
                    'body' => $case['body'],
                ]]);
                $name = "{$case['name']}, {$call}";

                try {
                    // No retries, so a retryable error still surfaces rather than looping.
                    $send(self::client($stub, retries: 0));
                    self::fail("{$name}: expected a failure");
                } catch (InternetDataException $e) {
                    self::assertSame($case['expect']['kind'], $e->kind->value, $name);
                    self::assertSame($case['expect']['retryable'], $e->isRetryable(), "{$name}: retryable");
                    self::assertSame($case['status'], $e->status, "{$name}: status");
                    if (isset($case['expect']['message'])) {
                        self::assertSame($case['expect']['message'], $e->getMessage(), "{$name}: message");
                    }
                    self::assertSame(
                        $case['expect']['retryAfterSeconds'] ?? null,
                        $e->retryAfterSeconds,
                        "{$name}: retryAfterSeconds",
                    );
                }
            }
        }
    }

    /**
     * The classification above decides whether a request is repeated, so it is
     * asserted as behavior too: a 404 mapped to the retryable default costs
     * three requests and a delay before failing with the same message.
     */
    public function testOnlyTheRetryableRefusalsAreActuallyRetried(): void
    {
        foreach (self::$data['errors'] as $case) {
            foreach (self::refusable() as $call => [$path, $send]) {
                $stub = new Stub([$path => [
                    'status' => $case['status'],
                    'headers' => $case['headers'],
                    'body' => $case['body'],
                ]]);

                try {
                    $send(self::client($stub, retries: 2));
                    self::fail("{$case['name']}, {$call}: expected a failure");
                } catch (InternetDataException) {
                    // The classification is what the count proves.
                }

                $expected = $case['expect']['retryable'] ? 3 : 1;
                self::assertCount($expected, $stub->calls, "{$case['name']}, {$call}: request count");
            }
        }
    }

    /**
     * A server-supplied delay is honored, and a retry without one still backs
     * off. Guzzle's own `delay` option carries it, so a stub can read it back.
     */
    public function testARetryAfterHeaderSetsTheDelayOfTheNextAttempt(): void
    {
        $case = self::errorCase('rate-limited-transient');
        $stub = new Stub([Stub::METADATA => [
            ['status' => $case['status'], 'headers' => $case['headers'], 'body' => $case['body']],
            Stub::ok(self::metadataBody()),
        ]]);

        self::client($stub)->database->metadata('bogon_ip_v1');

        self::assertSame([0, $case['expect']['retryAfterSeconds'] * 1000], $stub->delays);
    }

    public function testEveryStandingAndLicenseTypeSurvivesTheMapping(): void
    {
        $rows = [];
        foreach (self::$data['standings'] as $i => $standing) {
            foreach ([...self::$data['license_type'], null] as $j => $right) {
                $rows[] = self::databaseBody("fam_{$i}_{$j}", $standing, $right);
            }
        }
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => $rows])]);

        $got = self::client($stub)->database->list();

        self::assertCount(count($rows), $got);
        foreach ($rows as $n => $row) {
            self::assertSame($row['base'], $got[$n]->base);
            self::assertSame($row['standing'], $got[$n]->standing, 'standing must survive verbatim');
            self::assertSame($row['license_type'], $got[$n]->licenseType, 'license_type');
            self::assertSame($row['starts'], $got[$n]->starts?->format('Y-m-d\TH:i:s\Z'));
            self::assertNull($got[$n]->expires, 'a null term end must stay null, not become a date');
        }
    }

    public function testEveryPublishedFormatSurvivesTheMapping(): void
    {
        $formats = self::$data['formats'];
        $row = self::databaseBody('bogon_ip', 'licensed', 'standard');
        $row['versions'][0]['formats'] = $formats;
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => [$row]])]);

        $version = self::client($stub)->database->list()[0]->versions[0];

        self::assertSame($formats, $version->formats);
        self::assertSame($row['versions'][0]['id'], $version->id, 'a download takes the VERSION id');
    }

    // A listing is the server's answer for one key at one moment. Anything the
    // client adds, drops or reorders is a claim about a catalog it cannot make.
    public function testAListingIsReturnedExactlyAsServed(): void
    {
        $rows = [
            self::databaseBody('bogon_asn', 'licensed', 'standard'),
            self::databaseBody('bogon_ip', 'expired', 'evaluation'),
            self::databaseBody('tor_ip', 'unlicensed', null),
        ];
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => $rows])]);

        $got = self::client($stub)->database->list();

        self::assertSame(['bogon_asn', 'bogon_ip', 'tor_ip'], array_column($got, 'base'));
    }

    /**
     * A database built for one customer is ABSENT from everyone else's listing,
     * so a client holding its own copy of the catalog would put back exactly what
     * the server took out. An empty answer has to stay empty, and a base the
     * client has never heard of has to come through.
     */
    public function testNoCatalogIsCompiledIntoTheClient(): void
    {
        $empty = new Stub([Stub::LIST => Stub::ok(['databases' => []])]);
        self::assertSame([], self::client($empty)->database->list(), 'an empty catalog must not be backfilled');

        $unknown = self::databaseBody('a_family_this_client_has_never_heard_of', 'licensed', 'redistribute');
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => [$unknown]])]);

        $got = self::client($stub)->database->list();

        self::assertCount(1, $got);
        self::assertSame($unknown['base'], $got[0]->base);
        self::assertSame($unknown['name'], $got[0]->name);
    }

    /**
     * Two keys are two organizations with two entitlements, so an answer cached
     * for one and served to the other would show a customer a database they
     * cannot see. Nothing is cached at all, which the repeat call proves.
     */
    public function testAListingIsNeverReusedAcrossClients(): void
    {
        $mine = self::databaseBody('bogon_ip', 'licensed', 'standard');
        $theirs = self::databaseBody('tor_ip', 'licensed', 'standard');
        $stub = new Stub([Stub::LIST => [
            Stub::ok(['databases' => [$mine]]),
            Stub::ok(['databases' => [$theirs]]),
        ]]);

        $a = self::client($stub, key: 'key-a')->database->list();
        $b = self::client($stub, key: 'key-b')->database->list();

        self::assertSame(['bogon_ip'], array_column($a, 'base'));
        self::assertSame(['tor_ip'], array_column($b, 'base'), 'the second key was served the first key catalog');
        self::assertCount(2, $stub->calls);

        $repeat = new Stub([Stub::LIST => [
            Stub::ok(['databases' => [$mine]]),
            Stub::ok(['databases' => []]),
        ]]);
        $client = self::client($repeat);
        $client->database->list();
        self::assertSame([], $client->database->list(), 'the same client reused a listing it had already taken');
    }

    public function testEveryVisibilityRuleInTheCorpusHasATest(): void
    {
        self::assertSame(
            self::$data['visibility']['clientRules'],
            self::COVERED_RULES,
            'a visibility rule was added to the corpus without a test in this file',
        );
    }

    public function testIsBogonMatchesTheCanonicalRanges(): void
    {
        foreach (self::$data['isBogon'] as $case) {
            self::assertSame(
                $case['expect'],
                Bogon::isBogon($case['ip']),
                sprintf('%s (%s)', $case['ip'], $case['why']),
            );
        }
    }

    /**
     * A server listening on :: sees an IPv4 visitor as ::ffff:a.b.c.d, which read
     * whole is inside ::ffff:0:0/96, so unmapped as nothing it would be answered
     * as a bogon with no request made.
     */
    public function testAnIpv4MappedAddressIsTheIpv4AddressItCarries(): void
    {
        foreach (self::$data['ipv4Mapped'] as $case) {
            ['ip' => $ip, 'carries' => $carries, 'expect' => $bogon] = $case;
            self::assertSame($bogon, Bogon::isBogon($ip), sprintf('%s (%s)', $ip, $case['why']));
            $routes = Stub::lookups([$carries => Stub::ok(Stub::answer($carries))]);
            $wantSent = $bogon ? [] : [$carries];

            $stub = new Stub($routes);
            $client = new Client(new Options(httpClient: $stub->client));
            $result = $client->lookup($ip);
            self::assertSame($bogon, $result->isBogon, $ip);
            self::assertSame($carries, $result->ip, $ip);
            $client->lookup($carries);
            self::assertSame(array_map(fn (string $a): string => '/' . $a, $wantSent), $stub->calls, $ip);

            $batchStub = new Stub($routes);
            $uncached = new Client(new Options(httpClient: $batchStub->client, cache: false));
            $asked = array_values(array_unique([$ip, $carries]));
            $got = $uncached->lookupBatch($asked);
            self::assertSame($asked, array_map(strval(...), array_keys($got)), $ip);
            self::assertInstanceOf(Result::class, $got[$ip], $ip);
            self::assertSame($carries, $got[$ip]->ip, $ip);
            $sent = [];
            foreach ($batchStub->requests as $request) {
                $sent = [...$sent, ...json_decode((string) $request->getBody(), true)['ips']];
            }
            self::assertSame($wantSent, $sent, $ip);
        }
    }

    public function testABogonIsAnsweredLocallyInTheShapeTheCorpusGives(): void
    {
        $stub = new Stub();
        $client = new Client(new Options(httpClient: $stub->client));
        $bogon = self::$data['bogonResponse'];

        foreach (['10.0.0.1' => $client->lookup('10.0.0.1'), '::1' => $client->lookupBatch(['::1'])['::1']] as $ip => $result) {
            self::assertInstanceOf(Result::class, $result, $ip);
            self::assertSame(
                self::canonical(['ip' => $ip, ...$bogon['answer']]),
                self::canonical(self::asWire($result)),
                "{$ip}: the corpus answer with the address filled in",
            );
            foreach ($bogon['flagsFalse'] as $flag) {
                self::assertFalse($result->{self::camel($flag)}, "{$ip}: {$flag} must be present and false");
            }
            foreach ($bogon['emptyObjects'] as $object) {
                self::assertTrue($result->{self::camel($object)}->isEmpty(), "{$ip}: {$object} must be present and empty");
            }
            foreach ($bogon['emptyLists'] as $list) {
                self::assertSame([], $result->{self::camel($list)}, "{$ip}: {$list} must be present and empty");
            }
            self::assertTrue($result->isBogon, $ip);
            self::assertSame([], $result->raw, "{$ip}: nothing came off the wire");
        }
        self::assertSame([], $stub->calls, 'a bogon must not reach the network');
    }

    /**
     * Absent is "not in your plan": a member the plan leaves out is null, never
     * false, zero or empty, inside a shared object too, while a detail served as
     * `{}` (Web IP's `[]`) is present and empty.
     */
    public function testEveryLookupKeepsAbsentApartFromFalseAndEmpty(): void
    {
        foreach (self::$data['lookup'] as $i => $case) {
            $name = $case['name'];
            $target = $case['target'];
            [$path, $class] = match ($case['call']) {
                'lookup' => ["/{$target}", Result::class],
                'lookupAsn' => ["/asn/{$target}", AsnResult::class],
                'lookupDomain' => ["/domain/{$target}", DomainResult::class],
            };
            $stub = new Stub([$path => ['status' => $case['status'], 'body' => self::wire('lookup', $i)]]);
            $client = new Client(new Options(httpClient: $stub->client));
            $result = match ($case['call']) {
                'lookup' => $client->lookup($target),
                'lookupAsn' => $client->lookupAsn($target),
                'lookupDomain' => $client->lookupDomain($target),
            };
            $expect = $case['expect'];

            self::assertInstanceOf($class, $result, $name);
            foreach ($expect['present'] as $key => $value) {
                self::assertSame($value, $result->{self::camel($key)}, "{$name}: {$key}");
            }
            foreach ($expect['objects'] as $key) {
                $value = $result->{self::camel($key)};
                self::assertNotNull($value, "{$name}: {$key} must be present");
                $empty = is_array($value) ? $value === [] : (method_exists($value, 'isEmpty') && $value->isEmpty());
                self::assertSame(in_array($key, $expect['empty'], true), $empty, "{$name}: {$key} empty");
            }
            foreach ($expect['absent'] as $key) {
                self::assertNull($result->{self::camel($key)}, "{$name}: {$key} must be ABSENT, not false or empty");
            }
            foreach ($expect['absentIn'] ?? [] as $object => $members) {
                foreach ($members as $member) {
                    self::assertNull(
                        $result->{self::camel($object)}->{self::camel($member)},
                        "{$name}: {$object}.{$member} must be ABSENT",
                    );
                }
            }
            self::assertSame($case['body'], $result->raw, "{$name}: raw is the untouched wire object");
            self::assertSame([$path], $stub->calls, $name);
        }
    }

    public function testBatchDedupesShortCircuitsBogonsAndKeysByAddress(): void
    {
        $case = self::batchCase('dedup-bogon-and-order-free-keying');
        $stub = new Stub(Stub::lookups([
            '1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1')),
            '8.8.8.8' => Stub::ok(Stub::answer('8.8.8.8')),
        ]));
        $client = new Client(new Options(httpClient: $stub->client));

        $got = $client->lookupBatch($case['input']);

        self::assertSame($case['expect']['keys'], array_keys($got));
        self::assertCount($case['expect']['httpRequests'], $stub->calls);
        self::assertSame(
            ['1.1.1.1', '8.8.8.8'],
            json_decode((string) $stub->requests[0]->getBody(), true)['ips'],
            'each servable address asked once, and no bogon',
        );
        foreach ($case['expect']['bogonKeys'] as $key) {
            self::assertTrue($got[$key]->isBogon, "{$key} should be a local answer");
        }
    }

    public function testOneBadAddressDoesNotLoseTheRestOfTheBatch(): void
    {
        $case = self::batchCase('partial-failure-does-not-fail-the-batch');
        $stub = new Stub(Stub::lookups(['1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1', ['is_anycast' => true]))]));
        $client = new Client(new Options(httpClient: $stub->client, retries: 0));

        $got = $client->lookupBatch($case['input']);

        self::assertSame($case['expect']['keys'], array_keys($got));
        foreach ($case['expect']['errorKeys'] as $key) {
            self::assertInstanceOf(InternetDataException::class, $got[$key], "{$key} should carry its error");
            self::assertSame($case['expect']['errorKinds'][$key], $got[$key]->kind->value, $key);
        }
        self::assertInstanceOf(Result::class, $got['1.1.1.1']);
        self::assertTrue($got['1.1.1.1']->isAnycast, 'the good address still answered');
    }

    public function testACacheHitIssuesNoSecondRequest(): void
    {
        $case = self::batchCase('cache-hit-issues-no-second-request');
        $stub = new Stub(Stub::lookups(['1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1'))]));
        $client = new Client(new Options(httpClient: $stub->client));

        for ($i = 0; $i < $case['repeat']; $i++) {
            $client->lookupBatch($case['input']);
        }
        self::assertCount($case['expect']['httpRequests'], $stub->calls);
    }

    public function testALargeBatchIsSentInChunksOfAThousand(): void
    {
        $case = self::batchCase('chunks-of-one-thousand');
        $stub = new Stub(self::answering($case['input']));
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $got = $client->lookupBatch($case['input']);

        self::assertCount($case['expect']['keyCount'], $got);
        self::assertCount($case['expect']['httpRequests'], $stub->calls);
        foreach ($case['input'] as $ip) {
            self::assertInstanceOf(Result::class, $got[$ip], $ip);
            self::assertSame($ip, $got[$ip]->ip, "{$ip} should be answered for itself");
        }
    }

    public function testAnUncappedBatchIsChunkedAndAnswersEveryAddressOnce(): void
    {
        $case = self::batchCase('uncapped-input-is-chunked');
        $stub = new Stub(self::answering($case['input']));
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $got = $client->lookupBatch($case['input']);

        // Chunking to the endpoint's 1000 is the library's job, so a batch has no
        // cap of its own: never a refusal, and one POST per chunk.
        self::assertSame(array_fill(0, $case['expect']['httpRequests'], '/batch'), $stub->calls);
        $sizes = array_map(
            static fn ($request): int => count(json_decode((string) $request->getBody(), true)['ips']),
            $stub->requests,
        );
        self::assertSame([1000, 1000, 500], $sizes);
        self::assertSame($case['input'], array_keys($got));
        self::assertCount($case['expect']['keyCount'], $got);
        foreach ($case['input'] as $ip) {
            self::assertInstanceOf(Result::class, $got[$ip], $ip);
            self::assertSame($ip, $got[$ip]->ip, "{$ip} should be answered for itself");
        }
    }

    // A per-entry failure carries no headers, so its 429 can only be a spent
    // allowance, and a 500 is the server's; neither is retried per entry, because
    // retries belong to the call and the call succeeded.
    public function testAnEntryErrorIsClassifiedByItsStatus(): void
    {
        $case = self::batchCase('an-entry-error-is-classified-by-its-status');
        $stub = new Stub(Stub::lookups([
            '1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1')),
            '8.8.8.8' => [
                'status' => 429,
                'body' => ['error' => 'overage cap reached; raise or remove it in Billing'],
            ],
            '9.9.9.9' => ['status' => 500, 'body' => ['error' => 'lookup failed']],
        ]));
        $client = new Client(new Options(httpClient: $stub->client, retries: 3));

        $got = $client->lookupBatch($case['input']);

        self::assertSame($case['expect']['keys'], array_keys($got));
        foreach ($case['expect']['errorKinds'] as $ip => $kind) {
            self::assertInstanceOf(InternetDataException::class, $got[$ip], "{$ip} should carry its error");
            self::assertSame($kind, $got[$ip]->kind->value, $ip);
        }
        self::assertCount($case['expect']['httpRequests'], $stub->calls);
    }

    public function testTwoClientsNeverShareACachedAnswer(): void
    {
        $stub = new Stub(Stub::lookups(['1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1'))]));
        $a = new Client(new Options(apiKey: 'key-a', httpClient: $stub->client));
        $b = new Client(new Options(apiKey: 'key-b', httpClient: $stub->client));

        $a->lookup('1.1.1.1');
        $b->lookup('1.1.1.1');

        // Two keys can be on different plans and so entitled to different fields;
        // a shared cache would serve one of them the other's shape.
        self::assertCount(2, $stub->calls);
    }

    public function testCachingCanBeTurnedOff(): void
    {
        $stub = new Stub(Stub::lookups(['1.1.1.1' => Stub::ok(Stub::answer('1.1.1.1'))]));
        $client = new Client(new Options(cache: false, httpClient: $stub->client));

        $client->lookup('1.1.1.1');
        $client->lookup('1.1.1.1');

        self::assertCount(2, $stub->calls);
    }

    private static function client(Stub $stub, int $retries = 2, string $key = 'secret-key'): Client
    {
        return new Client(new Options(apiKey: $key, retries: $retries, httpClient: $stub->client));
    }

    /** @return array<string, mixed> */
    private static function errorCase(string $name): array
    {
        foreach (self::$data['errors'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        self::fail("no error fixture named {$name}");
    }

    /** @return array<string, mixed> */
    private static function databaseBody(string $base, string $standing, ?string $licenseType): array
    {
        return [
            'base' => $base,
            'name' => ucwords(str_replace('_', ' ', $base)),
            'summary' => "everything in {$base}",
            'standing' => $standing,
            'open' => false,
            'license_type' => $licenseType,
            'starts' => '2026-01-01T00:00:00Z',
            'expires' => null,
            'versions' => [[
                'id' => "{$base}_v1",
                'version' => 1,
                'summary' => "version 1 of {$base}",
                'formats' => ['csvgz'],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private static function metadataBody(): array
    {
        return [
            'id' => 'bogon_ip_v1',
            'updated' => '2026-09-04',
            'entries' => 12,
            'schema' => ['csvgz' => [['name' => 'range', 'type' => 'string']]],
            'size' => ['csvgz' => 760],
        ];
    }

    /**
     * A lookup and a database call, each with the path its refusal is served on.
     *
     * @return array<string, array{string, \Closure(Client): mixed}>
     */
    private static function refusable(): array
    {
        return [
            'a lookup' => ['/1.1.1.1', static fn (Client $c): mixed => $c->lookup('1.1.1.1')],
            'a database call' => [Stub::METADATA, static fn (Client $c): mixed => $c->database->metadata('bogon_ip_v1')],
        ];
    }

    /** @return array<string, mixed> */
    private static function batchCase(string $name): array
    {
        foreach (self::$data['batch'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        self::fail("no batch fixture named {$name}");
    }

    /**
     * Routes answering every address with its own Unlimited answer.
     *
     * @param list<string> $ips
     * @return array<string, mixed>
     */
    private static function answering(array $ips): array
    {
        $routes = [];
        foreach ($ips as $ip) {
            $routes[$ip] = Stub::ok(Stub::answer($ip));
        }
        return Stub::lookups($routes);
    }

    /**
     * A case's body decoded with objects, as it goes on the wire: decoded into
     * arrays, a detail served as `{}` would be sent as the list `[]`.
     */
    private static function wire(string $section, int $i): object
    {
        $raw = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        return $raw->{$section}[$i]->body;
    }

    private static function camel(string $wire): string
    {
        return lcfirst(str_replace('_', '', ucwords($wire, '_')));
    }

    /**
     * Renders a value object back to its wire form, leaving out what it does not
     * hold. The corpus is language-neutral JSON, so its expectations are
     * snake_case, and an empty detail is an empty array either way.
     */
    private static function asWire(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::asWire(...), $value);
        }
        if (!is_object($value)) {
            return $value;
        }
        $out = [];
        foreach (get_object_vars($value) as $name => $member) {
            if ($member === null || $name === 'raw') {
                continue;
            }
            $out[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name))] = self::asWire($member);
        }
        return $out;
    }

    /**
     * JSON with every object's keys sorted, so two answers compare by content
     * alone: a float that holds a whole number encodes as the integer the corpus
     * writes, and nothing else is loosened.
     */
    private static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }
            return array_map($sort, $v);
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}
