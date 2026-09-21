<?php

declare(strict_types=1);

namespace InternetData;

use Composer\InstalledVersions;
use GuzzleHttp\Client as GuzzleClient;
use InternetData\Internal\Api\DatabaseV2Api;
use InternetData\Internal\Configuration;
use OutOfBoundsException;

/**
 * A client for the InternetData API.
 *
 * Access is granted by contract, one database family at a time, so every
 * database published today needs a key carrying the `db.download` scope. The key
 * is optional nonetheless, and a client built without one sends no
 * `Authorization` header at all: what this API serves without a license is a
 * product decision, not the client's to refuse. `$oauth` needs no key at all.
 */
final class Client
{
    public const DEFAULT_BASE_URL = 'https://internetdata.io';

    /**
     * The database catalog and its downloads.
     *
     * They hang off here rather than off the client itself, which is where the
     * sibling VPNDetection library keeps the same calls, so one program holding
     * both spells the two the same way.
     */
    public readonly DatabaseApi $database;

    /**
     * Sign a person in on their own machine with the OAuth device flow, so a
     * program can be handed one of their API keys instead of asking for it.
     */
    public readonly OauthApi $oauth;

    public function __construct(Options $options = new Options())
    {
        $config = (new Configuration())
            ->setHost(rtrim($options->baseUrl, '/'))
            ->setUserAgent(self::userAgent());
        // Set only when there is one: `Authorization: Bearer ` with nothing
        // after it reads as a wrong key rather than as no key.
        if ($options->apiKey !== null && $options->apiKey !== '') {
            $config->setAccessToken($options->apiKey);
        }

        $http = $options->httpClient ?? new GuzzleClient();
        $transport = new Transport($http, $options->retries, $options->timeout);
        $this->database = new DatabaseApi(new DatabaseV2Api($http, $config), $transport);
        $this->oauth = new OauthApi($transport, rtrim($options->baseUrl, '/'), self::userAgent());
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
