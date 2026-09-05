<?php

declare(strict_types=1);

namespace InternetData\Integration\Tests;

use GuzzleHttp\Client as GuzzleClient;
use InternetData\Checksums;
use InternetData\Client;
use InternetData\ErrorKind;
use InternetData\Integration\Staging;
use InternetData\InternetDataException;
use PHPUnit\Framework\TestCase;

/**
 * The whole surface, against staging, through the package a stranger installs.
 *
 * The transfer is budgeted before it starts. `metadata` publishes a size per
 * format, and that size is checked against the ceiling below FIRST, so a
 * mistaken id can never quietly pull one of the multi-gigabyte databases
 * through CI.
 */
final class DatabaseTest extends TestCase
{
    // The two smallest published databases, and the only ones the SDK CI
    // organization licenses. If a licence changes, the listing assertion below
    // fails first and names this constant.
    private const DATABASE_ID = 'bogon_ip_v1';
    private const FORMAT = 'csvgz';
    // 8 MiB against a sub-kilobyte file. Four orders of magnitude of headroom, so
    // tripping it means the suite is pointed somewhere unintended, which is
    // exactly when a transfer must not go ahead.
    private const CEILING = 8 * 1024 * 1024;

    private const STANDINGS = ['licensed', 'expired', 'unlicensed'];
    private const RIGHTS = ['evaluation', 'internal', 'redistribute'];
    private const FORMATS = ['csvgz', 'mmdb'];
    private const OUTCOMES = ['ok', 'unauthorized', 'denied', 'expired', 'unknown', 'unavailable'];

    /** @var list<array{host: string, path: string, carriedKey: bool}> */
    private static array $facts = [];

    private static ?Client $client = null;
    /** @var list<\InternetData\Database>|null */
    private static ?array $catalog = null;
    /** @var array{bytes: int, path: string, checksums: Checksums}|null */
    private static ?array $transfer = null;
    private static string $tmp = '';

    public static function setUpBeforeClass(): void
    {
        self::$tmp = sys_get_temp_dir() . '/internetdata-integration-' . bin2hex(random_bytes(6));
        mkdir(self::$tmp);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ((array) glob(self::$tmp . '/*') as $file) {
            unlink((string) $file);
        }
        rmdir(self::$tmp);
    }

    protected function setUp(): void
    {
        $reason = Staging::skipReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
    }

    /**
     * FIRST on purpose. PHPUnit runs the methods in declaration order, and every
     * assertion below this one is vacuous unless the key reached the wire: the
     * client accepts a keyless build and then sends no `Authorization` header at
     * all, which is exactly what an unset CI secret produces, so a whole green
     * run would mean nothing.
     */
    public function testTheKeyReachesTheApi(): void
    {
        self::client()->database->list();

        $api = array_filter(self::$facts, static fn (array $f): bool => $f['host'] === Staging::HOST);
        self::assertNotEmpty($api, 'no request reached the staging API');
        foreach ($api as $fact) {
            self::assertTrue($fact['carriedKey'], "the key never reached {$fact['path']}");
        }
    }

    /**
     * A licence covers a database FAMILY, and the ids a download takes hang off
     * `versions`. PHP is loud about a spec that has drifted from its service
     * where the other bindings are quiet: the generated getters are typed, so a
     * required field the payload does not carry is a TypeError out of
     * `getBase()` rather than a null field nothing notices.
     */
    public function testTheCatalogAnswersTheFamilyShape(): void
    {
        $catalog = self::catalog();

        self::assertNotEmpty($catalog, 'the catalog is empty, so nothing below can be exercised');
        foreach ($catalog as $database) {
            $where = $database->base;
            self::assertNotSame('', $database->base);
            self::assertNotSame('', $database->name);
            self::assertContains($database->standing, self::STANDINGS, "{$where}: undocumented standing");
            if ($database->redistribution !== null) {
                self::assertContains($database->redistribution, self::RIGHTS, "{$where}: undocumented right");
            }
            if ($database->standing === 'unlicensed') {
                self::assertNull($database->redistribution, "{$where}: a right without a licence");
            }
            self::assertNotEmpty($database->versions, "{$where}: no versions");
            foreach ($database->versions as $version) {
                self::assertNotSame('', $version->id, "{$where}: a version with no id");
                self::assertGreaterThan(0, $version->version);
                self::assertNotEmpty($version->formats, "{$version->id}: no formats");
                foreach ($version->formats as $format) {
                    self::assertContains($format, self::FORMATS, "{$version->id}: undocumented format");
                }
            }
        }

        // The precondition every transfer below rests on, stated once and by
        // name, so a licence change reads as a licence change rather than as a
        // 403 out of the middle of a download test.
        self::assertContains(
            self::DATABASE_ID,
            self::versionIds(self::licensed()),
            self::DATABASE_ID . ' is no longer licensed to this organization; update DATABASE_ID',
        );
    }

    /**
     * A database commissioned for one customer is ABSENT here rather than listed
     * as `unlicensed`, and the client must not put it back: it holds no catalog
     * of its own, so what arrives is what the server decided this key may see.
     */
    public function testTheCatalogIsWhateverTheServerServedForThisKey(): void
    {
        $first = self::client()->database->list();
        $again = self::client()->database->list();

        self::assertSame(
            array_column($first, 'base'),
            array_column($again, 'base'),
            'two listings on one key disagree',
        );
        self::assertNotEmpty(self::licensed(), 'nothing is licensed, so the download tests cannot mean anything');
    }

    public function testADatabaseTheOrganizationDoesNotLicenseIsRefusedCleanly(): void
    {
        $unlicensed = self::versionIds(array_filter(
            self::catalog(),
            static fn (object $d): bool => $d->standing !== 'licensed',
        ));
        if ($unlicensed === []) {
            self::markTestSkipped('this organization licenses the whole catalog, so nothing is refusable');
        }
        $id = $unlicensed[0];
        $before = count(self::$facts);

        try {
            self::client()->database->downloadUrl($id, self::FORMAT);
            self::fail("{$id} was served despite being unlicensed");
        } catch (InternetDataException $e) {
            self::assertSame(ErrorKind::Forbidden, $e->kind, $id);
            self::assertSame(403, $e->status, $id);
            self::assertFalse($e->isRetryable(), 'a licence refusal is not worth retrying');
            // The API says WHICH refusal this is (`{"rc":"NOT_LICENSED"}`).
            // Falling back to the status means the envelope went unread.
            self::assertContains($e->getMessage(), ['NOT_LICENSED', 'LICENSE_EXPIRED'], 'the rc went unread');
        }

        self::assertCount($before + 1, self::$facts, 'a 4xx must not be retried');
    }

    public function testAnUnknownDatabaseIsA404RatherThanARetryableFault(): void
    {
        $before = count(self::$facts);

        try {
            self::client()->database->metadata('no_such_database_v1');
            self::fail('an unknown id was served');
        } catch (InternetDataException $e) {
            self::assertSame(404, $e->status);
            self::assertSame(ErrorKind::BadRequest, $e->kind);
            self::assertFalse($e->isRetryable());
        }

        self::assertCount($before + 1, self::$facts, 'a 404 must not be retried');
    }

    public function testDownloadStreamsARealDatabaseToDiskIntact(): void
    {
        $dl = self::downloaded();

        self::assertGreaterThan(0, $dl['bytes'], 'nothing was transferred');
        self::assertSame(filesize($dl['path']), $dl['bytes'], 'the file is not the length the method reported');
        self::assertFileDoesNotExist($dl['path'] . '.part', 'the .part file outlived a successful transfer');
        $head = substr((string) file_get_contents($dl['path']), 0, 2);
        self::assertSame("\x1f\x8b", $head, 'the payload is not gzip');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $dl['checksums']->sha256);
        self::assertSame(
            hash_file('sha256', $dl['path']),
            $dl['checksums']->sha256,
            'the bytes are not the published file',
        );

        // The presigned URL authorizes itself, so the second request must carry
        // no credential.
        $storage = array_filter(self::$facts, static fn (array $f): bool => $f['host'] !== Staging::HOST);
        self::assertNotEmpty($storage, 'nothing was fetched from object storage, so no 302 was followed');
        foreach ($storage as $fact) {
            self::assertFalse($fact['carriedKey'], 'the API key was sent to object storage');
        }
    }

    public function testDownloadBytesAgreesWithTheStreamedCopy(): void
    {
        $dl = self::downloaded();

        $bytes = self::client()->database->downloadBytes(self::DATABASE_ID, self::FORMAT);

        self::assertSame($dl['bytes'], strlen($bytes), 'the in-memory copy is a different length');
        self::assertSame($dl['checksums']->sha256, hash('sha256', $bytes), 'the in-memory copy is not the file');
    }

    /**
     * The one method v2 makes possible: a link a caller can hand to something
     * else. It is only worth having if it is genuinely credential-free, so it is
     * fetched here by a client that has never seen the key.
     */
    public function testDownloadUrlIsAWorkingLinkThatCarriesNoCredential(): void
    {
        self::assertLessThanOrEqual(
            self::CEILING,
            self::budgetedSize(),
            'refusing to hand out a link to a large database',
        );
        $url = self::client()->database->downloadUrl(self::DATABASE_ID, self::FORMAT);

        self::assertStringStartsWith('https://', $url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        self::assertNotSame(Staging::HOST, $host, 'the link points back at the API rather than at storage');
        self::assertStringNotContainsString(Staging::key(), $url, 'the API key rode along in the link');

        $stranger = new GuzzleClient(['http_errors' => false]);
        $response = $stranger->get($url);

        self::assertSame(200, $response->getStatusCode(), 'the link needs a credential the caller was not given');
        self::assertSame(self::budgetedSize(), strlen((string) $response->getBody()));
    }

    /**
     * The ledger is written fire-and-forget by the API, deliberately: an audit
     * trail must not turn a Postgres blip into a failed download. So this
     * asserts the SHAPE of what is there rather than that the transfer above has
     * already landed in it, which would be a race.
     */
    public function testTheDownloadLedgerAnswersItsDocumentedShape(): void
    {
        $downloads = self::client()->database->downloads(5);

        self::assertLessThanOrEqual(5, count($downloads), 'the limit was not honored');
        foreach ($downloads as $download) {
            self::assertNotSame('', $download->databaseId);
            self::assertContains($download->outcome, self::OUTCOMES, 'undocumented outcome');
            if ($download->outcome !== 'ok') {
                continue;
            }
            self::assertNotNull($download->bytes, 'a successful attempt records no size');
            self::assertSame(302, $download->httpStatus, 'a v2 success is a redirect');
        }
    }

    private static function client(): Client
    {
        return self::$client ??= Staging::client(static function (array $fact): void {
            self::$facts[] = $fact;
        });
    }

    /**
     * One listing for the whole run: several tests read it, and it is the same
     * answer every time.
     *
     * @return list<\InternetData\Database>
     */
    private static function catalog(): array
    {
        return self::$catalog ??= self::client()->database->list();
    }

    /** @return list<\InternetData\Database> */
    private static function licensed(): array
    {
        return array_values(array_filter(
            self::catalog(),
            static fn (object $d): bool => $d->standing === 'licensed',
        ));
    }

    /**
     * @param iterable<\InternetData\Database> $databases
     * @return list<string>
     */
    private static function versionIds(iterable $databases): array
    {
        $ids = [];
        foreach ($databases as $database) {
            foreach ($database->versions as $version) {
                $ids[] = $version->id;
            }
        }
        return $ids;
    }

    // The budget, taken from the API rather than assumed, and checked before any
    // transfer starts.
    private static function budgetedSize(): int
    {
        static $size = null;
        if ($size !== null) {
            return $size;
        }
        $meta = self::client()->database->metadata(self::DATABASE_ID);
        self::assertSame(self::DATABASE_ID, $meta->id);
        $published = $meta->size[self::FORMAT] ?? null;
        self::assertIsInt($published, self::DATABASE_ID . ' publishes no size to check a transfer against');
        self::assertGreaterThan(0, $published);
        self::assertLessThanOrEqual(
            self::CEILING,
            $published,
            self::DATABASE_ID . " is {$published} bytes, past the ceiling",
        );
        return $size = $published;
    }

    /**
     * Memoized, so the transfer tests share one download rather than pulling the
     * database several times each.
     *
     * @return array{bytes: int, path: string, checksums: Checksums}
     */
    private static function downloaded(): array
    {
        if (self::$transfer !== null) {
            return self::$transfer;
        }
        $size = self::budgetedSize();

        $path = self::$tmp . '/' . self::DATABASE_ID . '.csv.gz';
        $bytes = self::client()->database->download(self::DATABASE_ID, self::FORMAT, $path);
        // Read after the transfer, so a rebuild between the two calls shows up as
        // a digest mismatch rather than passing against a digest of nothing.
        $checksums = self::client()->database->checksums(self::DATABASE_ID, self::FORMAT);
        print self::DATABASE_ID . '.' . self::FORMAT . ": {$bytes} bytes, metadata says {$size}\n";

        return self::$transfer = ['bytes' => $bytes, 'path' => $path, 'checksums' => $checksums];
    }
}
