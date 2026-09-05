<?php

declare(strict_types=1);

namespace InternetData;

use Composer\InstalledVersions;
use GuzzleHttp\Client as GuzzleClient;
use InternetData\Internal\Api\DatabaseV2Api;
use InternetData\Internal\Configuration;
use OutOfBoundsException;

/**
 * A client for the InternetData database API.
 *
 * Access is granted by contract, one database family at a time, so everything
 * here needs a key carrying the `db.download` scope.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://internetdata.io';

    /**
     * The database catalog and its downloads, which is every call this API
     * serves.
     *
     * They hang off here rather than off the client itself, which is where the
     * sibling VPNDetection library keeps the same calls, so one program holding
     * both spells the two the same way.
     */
    public readonly DatabaseApi $database;

    public function __construct(Options $options)
    {
        $config = (new Configuration())
            ->setHost(rtrim($options->baseUrl, '/'))
            ->setUserAgent(self::userAgent())
            ->setAccessToken($options->apiKey);

        $http = $options->httpClient ?? new GuzzleClient();
        $this->database = new DatabaseApi(
            new DatabaseV2Api($http, $config),
            new Transport($http, $options->retries),
        );
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
