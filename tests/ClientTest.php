<?php

declare(strict_types=1);

namespace InternetData\Tests;

use Error;
use GuzzleHttp\RequestOptions;
use InternetData\Client;
use InternetData\ErrorKind;
use InternetData\InternetDataException;
use InternetData\Options;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The PHP-specific API surface, as distinct from the shared conformance corpus
 * in ConformanceTest.
 */
final class ClientTest extends TestCase
{
    /**
     * The key is optional because what this API serves without a license is a
     * product decision, and a client that could not be built without one would
     * have to change shape to follow it. What must never go out is
     * `Authorization: Bearer ` with nothing after it, which reads as a wrong key
     * rather than as none.
     */
    public function testAKeylessClientSendsNoAuthorizationHeader(): void
    {
        foreach ([new Options(), new Options(apiKey: '')] as $i => $options) {
            $stub = new Stub([Stub::LIST => Stub::ok(['databases' => []])]);
            $client = new Client(new Options(
                apiKey: $options->apiKey,
                httpClient: $stub->client,
            ));

            $client->database->list();

            self::assertFalse($stub->requests[0]->hasHeader('Authorization'), "case {$i}");
        }
    }

    public function testUnusableSettingsAreRefusedWhereTheyAreSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Options(apiKey: 'k', retries: -1);
    }

    // Deleting the auth header passed a whole suite in another language until
    // something asserted the wire.
    public function testTheKeyReachesTheWireAsABearerToken(): void
    {
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => []])]);

        self::client($stub)->database->list();

        self::assertSame('Bearer secret-key', $stub->requests[0]->getHeaderLine('Authorization'));
        self::assertStringContainsString('internetdata-php/', $stub->requests[0]->getHeaderLine('User-Agent'));
    }

    // The digests nest under `checksums`. Reading a top-level `sha256` returns
    // nothing against a perfectly healthy API, which is how nodejs 1.0.x shipped.
    public function testChecksumsAreUnwrappedFromTheirEnvelope(): void
    {
        $digests = [
            'md5' => str_repeat('a', 32),
            'sha1' => str_repeat('b', 40),
            'sha256' => str_repeat('c', 64),
            'sha512' => str_repeat('d', 128),
        ];
        $stub = new Stub([Stub::CHECKSUM => Stub::ok([
            'id' => 'bogon_ip_v1',
            'format' => 'mmdb',
            'checksums' => $digests,
        ])]);

        $got = self::client($stub)->database->checksums('bogon_ip_v1', 'mmdb');

        self::assertSame($digests['md5'], $got->md5);
        self::assertSame($digests['sha1'], $got->sha1);
        self::assertSame($digests['sha256'], $got->sha256);
        self::assertSame($digests['sha512'], $got->sha512);
        self::assertSame(['id' => 'bogon_ip_v1', 'format' => 'mmdb'], $stub->queryOf(0));
    }

    public function testMetadataCarriesTheSizeATransferIsBudgetedAgainst(): void
    {
        $stub = new Stub([Stub::METADATA => Stub::ok([
            'id' => 'bogon_ip_v1',
            'update_freq' => 'daily',
            'updated' => '2026-09-04',
            'entries' => 1234,
            'schema' => ['csvgz' => [
                ['name' => 'range', 'type' => 'string', 'description' => 'CIDR'],
                ['name' => 'note', 'type' => 'string'],
            ]],
            'sample' => ['csvgz' => [['range' => '10.0.0.0/8', 'note' => 'private']]],
            'size' => ['csvgz' => 760, 'mmdb' => 3524],
        ])]);

        $got = self::client($stub)->database->metadata('bogon_ip_v1');

        self::assertSame('bogon_ip_v1', $got->id);
        self::assertSame('daily', $got->updateFreq);
        self::assertSame('2026-09-04', $got->updated->format('Y-m-d'));
        self::assertSame(1234, $got->entries);
        self::assertSame(['csvgz' => 760, 'mmdb' => 3524], $got->size);
        self::assertSame('range', $got->schema['csvgz'][0]->name);
        self::assertSame('CIDR', $got->schema['csvgz'][0]->description);
        self::assertNull($got->schema['csvgz'][1]->description, 'an absent description must not become empty');
        self::assertSame([['range' => '10.0.0.0/8', 'note' => 'private']], $got->sample['csvgz']);
    }

    public function testMetadataWithoutOptionalMembersDoesNotInventThem(): void
    {
        $stub = new Stub([Stub::METADATA => Stub::ok([
            'id' => 'bogon_asn_v1',
            'updated' => '2026-09-04',
            'entries' => 7,
            'schema' => [],
            'size' => ['csvgz' => 264],
        ])]);

        $got = self::client($stub)->database->metadata('bogon_asn_v1');

        self::assertNull($got->updateFreq);
        self::assertSame([], $got->sample);
        self::assertSame([], $got->schema);
    }

    public function testDownloadsCarryTheRefusalsThatExplainWhyItStoppedWorking(): void
    {
        $stub = new Stub([Stub::DOWNLOADS => Stub::ok(['downloads' => [
            [
                'dataset_id' => 'bogon_ip_v1',
                'format' => 'mmdb',
                'outcome' => 'ok',
                'bytes' => 3524,
                'http_status' => 302,
                'apikey_id' => 'ak_1',
                'client_ip' => '203.0.113.7',
                'user_agent' => 'internetdata-php/1.0.0',
                'created' => '2026-09-04T10:11:12Z',
            ],
            [
                'dataset_id' => 'tor_ip_v1',
                'format' => 'csvgz',
                'outcome' => 'denied',
                'bytes' => null,
                'http_status' => 403,
                'apikey_id' => null,
                'client_ip' => null,
                'user_agent' => null,
                'created' => '2026-09-04T10:11:13Z',
            ],
        ]])]);

        $got = self::client($stub)->database->downloads(25);

        self::assertCount(2, $got);
        self::assertSame('bogon_ip_v1', $got[0]->databaseId);
        self::assertSame(3524, $got[0]->bytes);
        self::assertSame('ak_1', $got[0]->apiKeyId);
        self::assertSame('2026-09-04 10:11:12', $got[0]->created->format('Y-m-d H:i:s'));
        self::assertSame('denied', $got[1]->outcome);
        self::assertNull($got[1]->bytes, 'a refusal moved no bytes, which is not the same as zero');
        self::assertNull($got[1]->apiKeyId);
        self::assertSame(['limit' => '25'], $stub->queryOf(0));
    }

    public function testDownloadUrlHandsBackTheLinkWithoutFollowingIt(): void
    {
        $link = 'https://example-storage.invalid/bogon_ip_v1.mmdb?X-Amz-Signature=deadbeef';
        $stub = new Stub([Stub::DOWNLOAD => ['status' => 302, 'headers' => ['Location' => $link]]]);

        $got = self::client($stub)->database->downloadUrl('bogon_ip_v1', 'mmdb');

        self::assertSame($link, $got);
        self::assertSame([Stub::DOWNLOAD], $stub->calls, 'the redirect was chased');
        self::assertSame(['id' => 'bogon_ip_v1', 'format' => 'mmdb'], $stub->queryOf(0));
    }

    public function testARedirectWithNoLocationIsAServerFaultRatherThanAnEmptyUrl(): void
    {
        $stub = new Stub([Stub::DOWNLOAD => ['status' => 302]]);

        try {
            self::client($stub)->database->downloadUrl('bogon_ip_v1', 'mmdb');
            self::fail('an empty link was handed out');
        } catch (InternetDataException $e) {
            self::assertSame(ErrorKind::ServerError, $e->kind);
        }
    }

    public function testATransportFailureIsRetriedAndThenReportedAsNetwork(): void
    {
        $stub = new Stub([Stub::LIST => Stub::transportFailure()]);

        try {
            self::client($stub)->database->list();
            self::fail('a refused connection was not reported');
        } catch (InternetDataException $e) {
            self::assertSame(ErrorKind::Network, $e->kind);
            self::assertTrue($e->isRetryable());
        }
        self::assertCount(3, $stub->calls);
    }

    // Every value object is handed out of a mapping the caller does not own, so
    // it must not be writable from underneath whoever else holds it.
    public function testTheValueObjectsAreReadonly(): void
    {
        $stub = new Stub([Stub::LIST => Stub::ok(['databases' => [[
            'base' => 'bogon_ip',
            'name' => 'Bogon IP',
            'summary' => 'Reserved ranges.',
            'standing' => 'licensed',
            'license_type' => 'standard',
            'starts' => null,
            'expires' => null,
            'versions' => [[
                'id' => 'bogon_ip_v1',
                'version' => 1,
                'summary' => 'v1',
                'formats' => ['csvgz', 'mmdb'],
            ]],
        ]]])]);

        $database = self::client($stub)->database->list()[0];

        $this->expectException(Error::class);
        $database->standing = 'unlicensed';
    }

    public function testApiCallsCarryATimeout(): void
    {
        $stub = new Stub(['/api/v2/database/list' => Stub::ok(['databases' => []])]);

        (new Client(new Options(apiKey: 'k', timeout: 7.5, httpClient: $stub->client)))
            ->database->list();

        // Guzzle defaults both to 0, meaning unlimited, so an unset option here
        // is a caller held until the process is killed.
        $this->assertSame(7.5, $stub->options[0][RequestOptions::TIMEOUT]);
        $this->assertSame(7.5, $stub->options[0][RequestOptions::CONNECT_TIMEOUT]);
    }

    public function testATransferIsExemptFromTheWholeRequestTimeout(): void
    {
        $stub = new Stub([
            '/api/v2/database/download' => [
                'status' => 302,
                'headers' => ['Location' => 'https://storage.invalid/bogon_ip_v1.csv.gz'],
            ],
            '/bogon_ip_v1.csv.gz' => ['status' => 200, 'body' => 'payload'],
        ]);

        (new Client(new Options(apiKey: 'k', timeout: 7.5, httpClient: $stub->client)))
            ->database->downloadBytes('bogon_ip_v1', 'csvgz');

        // 7.5s is right for the redirect and wrong for a body that reaches
        // gigabytes, so only the connect phase keeps it.
        $transfer = $stub->options[1];
        $this->assertSame(0, $transfer[RequestOptions::TIMEOUT]);
        $this->assertSame(7.5, $transfer[RequestOptions::CONNECT_TIMEOUT]);
    }

    /**
     * A body stalled after the headers, and one trickled a byte every 20 ms.
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

    // Asserted on a real transport: a stub answers whatever it likes and never
    // reaches a deadline over the body at all. The origin is slower than the
    // bound, so a regression fails the timing assertion instead of hanging.
    /** @param array{stallSeconds?: int, trickleMs?: int} $body */
    #[DataProvider('slowBodies')]
    public function testTheTimeoutBoundsTheWholeBodyNotJustTheHeaders(array $body): void
    {
        $origin = new Origin($body);
        $client = new Client(new Options(apiKey: 'k', baseUrl: $origin->baseUrl, retries: 0, timeout: 0.25));
        $started = microtime(true);
        try {
            $client->database->list();
            $this->fail('a slow body was not cut off');
        } catch (InternetDataException $e) {
            $this->assertSame(ErrorKind::Network, $e->kind);
            $this->assertTrue($e->isRetryable(), 'a timeout is worth another attempt');
        } finally {
            $elapsed = microtime(true) - $started;
            $origin->stop();
        }

        $this->assertGreaterThanOrEqual(0.2, $elapsed, 'failed without waiting');
        $this->assertLessThan(1.5, $elapsed, 'waited past its 0.25s bound');
    }

    public function testANegativeTimeoutIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Options(timeout: -1);
    }

    private static function client(Stub $stub, int $retries = 2): Client
    {
        return new Client(new Options(apiKey: 'secret-key', retries: $retries, httpClient: $stub->client));
    }
}
