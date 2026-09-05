<?php

declare(strict_types=1);

namespace InternetData\Tests;

use InternetData\Client;
use InternetData\InternetDataException;
use InternetData\Options;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the shared conformance corpus that every InternetData SDK asserts.
 *
 * The corpus is generated into testdata/ and is identical across languages, so a
 * behavior that drifts here fails here rather than surfacing as two client
 * libraries quietly disagreeing about the same refusal.
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

    public function testEveryRefusalIsClassifiedByStatusAndRetryAfter(): void
    {
        foreach (self::$data['errors'] as $case) {
            $stub = new Stub([Stub::METADATA => [
                'status' => $case['status'],
                'headers' => $case['headers'],
                'body' => $case['body'],
            ]]);
            // No retries, so a retryable error still surfaces rather than looping.
            $client = self::client($stub, retries: 0);
            $name = $case['name'];

            try {
                $client->metadata('bogon_ip_v1');
                self::fail("{$name}: expected a failure");
            } catch (InternetDataException $e) {
                self::assertSame($case['expect']['kind'], $e->kind->value, $name);
                self::assertSame($case['expect']['retryable'], $e->isRetryable(), "{$name}: retryable");
                self::assertSame($case['status'], $e->status, "{$name}: status");
                self::assertSame($case['expect']['message'], $e->getMessage(), "{$name}: message");
                self::assertSame(
                    $case['expect']['retryAfterSeconds'] ?? null,
                    $e->retryAfterSeconds,
                    "{$name}: retryAfterSeconds",
                );
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
            $stub = new Stub([Stub::METADATA => [
                'status' => $case['status'],
                'headers' => $case['headers'],
                'body' => $case['body'],
            ]]);
            $client = self::client($stub, retries: 2);

            try {
                $client->metadata('bogon_ip_v1');
                self::fail("{$case['name']}: expected a failure");
            } catch (InternetDataException) {
                // The classification is what the count proves.
            }

            $expected = $case['expect']['retryable'] ? 3 : 1;
            self::assertCount($expected, $stub->calls, "{$case['name']}: request count");
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

        self::client($stub)->metadata('bogon_ip_v1');

        self::assertSame([0, $case['expect']['retryAfterSeconds'] * 1000], $stub->delays);
    }

    public function testEveryStandingAndRedistributionSurvivesTheMapping(): void
    {
        $rows = [];
        foreach (self::$data['standings'] as $i => $standing) {
            foreach ([...self::$data['redistribution'], null] as $j => $right) {
                $rows[] = self::databaseBody("fam_{$i}_{$j}", $standing, $right);
            }
        }
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => $rows])]);

        $got = self::client($stub)->list();

        self::assertCount(count($rows), $got);
        foreach ($rows as $n => $row) {
            self::assertSame($row['base'], $got[$n]->base);
            self::assertSame($row['standing'], $got[$n]->standing, 'standing must survive verbatim');
            self::assertSame($row['redistribution'], $got[$n]->redistribution, 'redistribution');
            self::assertSame($row['starts'], $got[$n]->starts?->format('Y-m-d\TH:i:s\Z'));
            self::assertNull($got[$n]->expires, 'a null term end must stay null, not become a date');
        }
    }

    public function testEveryPublishedFormatSurvivesTheMapping(): void
    {
        $formats = self::$data['formats'];
        $row = self::databaseBody('bogon_ip', 'licensed', 'internal');
        $row['versions'][0]['formats'] = $formats;
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => [$row]])]);

        $version = self::client($stub)->list()[0]->versions[0];

        self::assertSame($formats, $version->formats);
        self::assertSame($row['versions'][0]['id'], $version->id, 'a download takes the VERSION id');
    }

    // A listing is the server's answer for one key at one moment. Anything the
    // client adds, drops or reorders is a claim about a catalog it cannot make.
    public function testAListingIsReturnedExactlyAsServed(): void
    {
        $rows = [
            self::databaseBody('bogon_asn', 'licensed', 'internal'),
            self::databaseBody('bogon_ip', 'expired', 'evaluation'),
            self::databaseBody('tor_ip', 'unlicensed', null),
        ];
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => $rows])]);

        $got = self::client($stub)->list();

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
        self::assertSame([], self::client($empty)->list(), 'an empty catalog must not be backfilled');

        $unknown = self::databaseBody('a_family_this_client_has_never_heard_of', 'licensed', 'redistribute');
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => [$unknown]])]);

        $got = self::client($stub)->list();

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
        $mine = self::databaseBody('bogon_ip', 'licensed', 'internal');
        $theirs = self::databaseBody('tor_ip', 'licensed', 'internal');
        $stub = new Stub([Stub::LIST => [
            Stub::ok(['databases' => [$mine]]),
            Stub::ok(['databases' => [$theirs]]),
        ]]);

        $a = self::client($stub, key: 'key-a')->list();
        $b = self::client($stub, key: 'key-b')->list();

        self::assertSame(['bogon_ip'], array_column($a, 'base'));
        self::assertSame(['tor_ip'], array_column($b, 'base'), 'the second key was served the first key catalog');
        self::assertCount(2, $stub->calls);

        $repeat = new Stub([Stub::LIST => [
            Stub::ok(['databases' => [$mine]]),
            Stub::ok(['databases' => []]),
        ]]);
        $client = self::client($repeat);
        $client->list();
        self::assertSame([], $client->list(), 'the same client reused a listing it had already taken');
    }

    public function testEveryVisibilityRuleInTheCorpusHasATest(): void
    {
        self::assertSame(
            self::$data['visibility']['clientRules'],
            self::COVERED_RULES,
            'a visibility rule was added to the corpus without a test in this file',
        );
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
    private static function databaseBody(string $base, string $standing, ?string $redistribution): array
    {
        return [
            'base' => $base,
            'name' => ucwords(str_replace('_', ' ', $base)),
            'summary' => "everything in {$base}",
            'standing' => $standing,
            'redistribution' => $redistribution,
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
}
