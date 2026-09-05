<?php

declare(strict_types=1);

namespace InternetData;

use GuzzleHttp\ClientInterface;
use InvalidArgumentException;

/**
 * How a client behaves. Build one with named arguments and pass only what you
 * want to change: `new Options(apiKey: $key, retries: 4)`.
 */
final class Options
{
    public function __construct(
        /**
         * Your API key, carrying the `db.download` scope. There is no
         * unauthenticated tier: every endpoint here is licensed data.
         */
        public readonly string $apiKey,
        public readonly string $baseUrl = Client::DEFAULT_BASE_URL,
        /** Retry attempts for a transient failure. */
        public readonly int $retries = 2,
        /**
         * Override the HTTP implementation, mostly for tests.
         *
         * Guzzle rather than PSR-18 because the generated core is built on it,
         * and a download needs the streaming and redirect options PSR-18 has no
         * way to express.
         */
        public readonly ?ClientInterface $httpClient = null,
    ) {
        // Caught here rather than at the first 401, because an empty key is
        // almost always an environment variable that was never set.
        if (trim($apiKey) === '') {
            throw new InvalidArgumentException('apiKey is required');
        }
        if ($retries < 0) {
            throw new InvalidArgumentException('retries cannot be negative');
        }
    }
}
