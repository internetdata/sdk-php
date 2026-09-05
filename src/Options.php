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
         * Your API key, carrying the `db.download` scope. Omit it to send no
         * `Authorization` header at all, which reaches only what this API
         * serves without a licence.
         */
        public readonly ?string $apiKey = null,
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
        if ($retries < 0) {
            throw new InvalidArgumentException('retries cannot be negative');
        }
    }
}
