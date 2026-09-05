<?php

declare(strict_types=1);

namespace InternetData;

use Composer\InstalledVersions;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use InternetData\Internal\Api\DatabaseV2Api;
use InternetData\Internal\Configuration;
use InternetData\Internal\Model\ChecksumsResponse;
use InternetData\Internal\Model\DatabaseList;
use InternetData\Internal\Model\DatabaseMetadata as WireDatabaseMetadata;
use InternetData\Internal\Model\DownloadList;
use InvalidArgumentException;
use OutOfBoundsException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * A client for the InternetData database API.
 *
 * Access is granted by contract, one database family at a time, so everything
 * here needs a key carrying the `db.download` scope.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://internetdata.io';

    // One chunk of a transfer, and therefore the ceiling on what a download of
    // any size costs in memory.
    private const CHUNK_BYTES = 1024 * 1024;

    private readonly DatabaseV2Api $api;
    private readonly Transport $transport;

    public function __construct(Options $options)
    {
        $config = (new Configuration())
            ->setHost(rtrim($options->baseUrl, '/'))
            ->setUserAgent(self::userAgent())
            ->setAccessToken($options->apiKey);

        $http = $options->httpClient ?? new GuzzleClient();
        $this->api = new DatabaseV2Api($http, $config);
        $this->transport = new Transport($http, $options->retries);
    }

    /**
     * Every database your organization may see, with its licence standing.
     *
     * The catalog is not the same for every key and is never cached here: a
     * database commissioned for one customer is ABSENT from this list for
     * everyone else rather than present as `unlicensed`, so a listing taken with
     * one key says nothing about what another key may see, and a listing taken
     * an hour ago says nothing about a family licensed since.
     *
     * @return list<Database>
     * @throws InternetDataException
     */
    public function list(): array
    {
        $response = $this->transport->send($this->api->listDatabasesRequest());
        $wire = Transport::toModel($response, DatabaseList::class);
        return array_map(Database::fromWire(...), $wire->getDatabases());
    }

    /**
     * What is inside one database: schema, sample rows, row count and sizes.
     *
     * @param string $id A versioned id, e.g. `bogon_ip_v1`.
     * @throws InternetDataException
     */
    public function metadata(string $id): DatabaseMetadata
    {
        $response = $this->transport->send($this->api->databaseMetadataV2Request($id));
        return DatabaseMetadata::fromWire(Transport::toModel($response, WireDatabaseMetadata::class));
    }

    /**
     * The digests for one published file.
     *
     * @param string $format `csvgz` or `mmdb`.
     * @throws InternetDataException
     */
    public function checksums(string $id, string $format): Checksums
    {
        $response = $this->transport->send($this->api->databaseChecksumV2Request($id, $format));
        // The digests are nested one level down, under `checksums`. Unwrapping a
        // generated response type rather than a hand-written shape is what keeps
        // the depth honest; reading a top-level `sha256` returns nothing against
        // a perfectly healthy API.
        $wire = Transport::toModel($response, ChecksumsResponse::class);
        return Checksums::fromWire($wire->getChecksums());
    }

    /**
     * Your organization's recent download attempts, newest first.
     *
     * Refusals are listed too: a denial is what answers "it stopped working",
     * and its absence answers nothing.
     *
     * @param int $limit How many attempts to return. The API clamps this to 200.
     * @return list<Download>
     * @throws InternetDataException
     */
    public function downloads(int $limit = 50): array
    {
        $response = $this->transport->send($this->api->listDownloadsRequest($limit));
        $wire = Transport::toModel($response, DownloadList::class);
        return array_map(Download::fromWire(...), $wire->getDownloads());
    }

    /**
     * The time-limited URL for one database file.
     *
     * The API answers `302` to object storage and the redirect is NOT followed
     * here, so what comes back is a link that authorizes itself and carries no
     * credential of yours. Hand it to whatever should run the transfer: the link
     * authorizes the START of one, so a download already running is not
     * interrupted when it lapses.
     *
     * @param string $format `csvgz` or `mmdb`.
     * @throws InternetDataException
     */
    public function downloadUrl(string $id, string $format): string
    {
        $response = $this->transport->send($this->api->downloadDatabaseV2Request($id, $format));
        $location = $response->getHeaderLine('Location');
        if ($response->getStatusCode() === 302 && $location !== '') {
            return $location;
        }
        throw new InternetDataException(
            ErrorKind::ServerError,
            'expected a redirect to object storage',
            $response->getStatusCode(),
        );
    }

    /**
     * Download one database file, streaming it to `$destination`.
     *
     * `$destination` is either a path to write or a stream resource you opened
     * yourself. A path is written through a neighboring `.part` file and renamed
     * on completion, so a transfer that dies half way leaves no truncated file
     * that reads as a whole database; a stream you pass is written as-is and
     * stays yours to close. Nothing larger than one chunk is ever held in
     * memory, whatever the database weighs.
     *
     * Returns the number of bytes written.
     *
     * @param string $format `csvgz` or `mmdb`.
     * @param string|resource $destination
     * @throws InternetDataException
     */
    public function download(string $id, string $format, mixed $destination): int
    {
        // Checked before the request, so a bad destination costs no quota.
        if (!is_string($destination) && !is_resource($destination)) {
            throw new InvalidArgumentException('destination must be a path or a stream resource');
        }
        $response = $this->fetchFile($id, $format);
        if (!is_string($destination)) {
            return self::drain($response, $destination);
        }

        $partial = $destination . '.part';
        $handle = Utils::tryFopen($partial, 'wb');
        try {
            $written = self::drain($response, $handle);
        } catch (Throwable $e) {
            fclose($handle);
            unlink($partial);
            throw $e;
        }
        fclose($handle);
        if (!rename($partial, $destination)) {
            unlink($partial);
            throw new RuntimeException(sprintf('could not move the database into place at %s', $destination));
        }
        return $written;
    }

    /**
     * Download one database file and hand back its bytes.
     *
     * **This holds the entire file in memory**, and the catalog spans seven
     * orders of magnitude: `bogon_asn_v1` is 264 bytes while the largest
     * published database is over 5 GiB, which PHP's `memory_limit` turns into a
     * fatal error rather than mere pressure. Reach for this at the small end,
     * where the bytes go straight into a parser; use `download` for anything you
     * have not checked against `metadata`'s `size`.
     *
     * @param string $format `csvgz` or `mmdb`.
     * @throws InternetDataException
     */
    public function downloadBytes(string $id, string $format): string
    {
        $response = $this->fetchFile($id, $format);
        $bytes = (string) $response->getBody();
        self::assertWholeTransfer($response, strlen($bytes));
        return $bytes;
    }

    // Follows the 302 as a SECOND, unauthenticated request: the presigned URL
    // carries its own authorization, so forwarding the API key would hand a
    // credential to a host that has no business holding it.
    private function fetchFile(string $id, string $format): ResponseInterface
    {
        return $this->transport->sendStreaming(
            new Request('GET', $this->downloadUrl($id, $format)),
            'object storage refused the download link',
        );
    }

    /** @param resource $handle */
    private static function drain(ResponseInterface $response, $handle): int
    {
        $body = $response->getBody();
        $written = 0;
        while (true) {
            $chunk = $body->read(self::CHUNK_BYTES);
            if ($chunk === '') {
                break;
            }
            while ($chunk !== '') {
                // A failure writing is the caller's to read: a full disk and a
                // reset socket are different problems, and only one is ours.
                $n = fwrite($handle, $chunk);
                if ($n === false || $n === 0) {
                    throw new RuntimeException('could not write the database to the destination');
                }
                $written += $n;
                $chunk = substr($chunk, $n);
            }
        }
        self::assertWholeTransfer($response, $written);
        return $written;
    }

    // A transfer that dies mid-body reaches PHP as a plain EOF, so a short read
    // is silent unless what arrived is checked against what was promised. Node's
    // fetch raises this for itself; here it has to be asserted.
    private static function assertWholeTransfer(ResponseInterface $response, int $written): void
    {
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && (int) $declared !== $written) {
            throw new InternetDataException(
                ErrorKind::Network,
                sprintf('the transfer ended after %d of %s bytes', $written, $declared),
                $response->getStatusCode(),
            );
        }
    }

    private static function userAgent(): string
    {
        static $agent = null;
        return $agent ??= sprintf('internetdata-php/%s php/%s', self::version(), PHP_VERSION);
    }

    // Read from composer's own install metadata rather than a constant, which
    // would be one more thing to remember to bump alongside the release tag.
    private static function version(): string
    {
        if (!class_exists(InstalledVersions::class)) {
            return 'dev';
        }
        try {
            return InstalledVersions::getPrettyVersion('internetdata/internetdata') ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }
}
