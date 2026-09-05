<?php

declare(strict_types=1);

namespace InternetData\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use InternetData\Client;
use InternetData\Options;
use Psr\Http\Message\RequestInterface;

/**
 * Where the suite points and what it is allowed to remember about a request.
 *
 * Loaded by `scripts/run.php` as well as by the tests, so nothing here may run
 * at include time: the runner reads it BEFORE composer has put an autoloader on
 * disk, and a `use` of a class that does not exist yet is only an alias.
 */
final class Staging
{
    public const BASE_URL = 'https://staging.internetdata.io';
    public const HOST = 'staging.internetdata.io';

    public const SECRET = 'INTERNETDATA_STAGING_KEY';

    /**
     * The key, or an empty string when there is none.
     *
     * EMPTY COUNTS AS MISSING. Actions interpolates a secret that does not exist
     * to an empty string rather than leaving the variable unset, so a workflow
     * `env:` mapping always SETS it, and a client built with an empty key would
     * be refused by every endpoint here with a 401 that reads like a broken SDK.
     */
    public static function key(): string
    {
        return trim((string) getenv(self::SECRET));
    }

    /** A reason to skip, or null when the suite can run. */
    public static function skipReason(): ?string
    {
        return self::key() === ''
            ? self::SECRET . ' is not set, so nothing can be exercised against staging'
            : null;
    }

    /** @param (callable(array{host: string, path: string, carriedKey: bool}): void)|null $onRequest */
    public static function client(?callable $onRequest = null): Client
    {
        $key = self::key();
        $stack = HandlerStack::create();
        $stack->push(Middleware::mapRequest(
            static function (RequestInterface $request) use ($key, $onRequest): RequestInterface {
                if ($onRequest !== null) {
                    $onRequest(self::factsFor($request, $key));
                }
                return $request;
            },
        ));
        return new Client(new Options(
            apiKey: $key,
            baseUrl: self::BASE_URL,
            httpClient: new GuzzleClient(['handler' => $stack]),
        ));
    }

    /**
     * What a test is allowed to remember about a request it made.
     *
     * Only derived facts leave here. A failing assertion prints its operands, so
     * holding on to the request itself is how a key ends up in a public CI log:
     * whether the key was carried is a boolean, and the caller never sees it.
     *
     * @return array{host: string, path: string, carriedKey: bool}
     */
    public static function factsFor(RequestInterface $request, string $key): array
    {
        $uri = $request->getUri();
        $carried = false;
        if ($key !== '') {
            $carried = str_contains($uri->getQuery(), $key);
            foreach ($request->getHeaders() as $values) {
                foreach ($values as $value) {
                    $carried = $carried || str_contains($value, $key);
                }
            }
        }
        return ['host' => $uri->getHost(), 'path' => $uri->getPath(), 'carriedKey' => $carried];
    }
}
