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
    // The API host, where the lookups answer and the database answers as on the
    // apex; the client signs in at the apex, ISSUER.
    public const BASE_URL = 'https://api-staging.internetdata.io';
    public const HOST = 'api-staging.internetdata.io';
    public const ISSUER = 'https://staging.internetdata.io';

    // Production, for the keyless lookups alone.
    public const PROD = 'https://api.internetdata.io';

    // The spec's own example: a VPN server, so a paid answer holds every detail.
    public const PROBE = '146.70.116.130';

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

    /** @param (callable(array<string, mixed>): void)|null $onRequest */
    public static function client(?callable $onRequest = null): Client
    {
        return self::build(self::key(), self::BASE_URL, $onRequest);
    }

    /**
     * A client with no key at all, against staging or production.
     *
     * @param (callable(array<string, mixed>): void)|null $onRequest
     */
    public static function keyless(string $baseUrl, ?callable $onRequest = null): Client
    {
        return self::build('', $baseUrl, $onRequest);
    }

    /**
     * What a test is allowed to remember about a request it made.
     *
     * Only derived facts leave here. A failing assertion prints its operands, so
     * holding on to the request itself is how a key ends up in a public CI log:
     * whether the key was carried is a boolean, and the caller never sees it.
     * `ips` is what a `POST /batch` body asked about, public addresses all.
     *
     * @return array{host: string, path: string, ips: list<string>, carriedKey: bool, authorized: bool}
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
        $body = json_decode((string) $request->getBody(), true);
        $request->getBody()->rewind();
        $ips = is_array($body) && is_array($body['ips'] ?? null) ? array_map(strval(...), $body['ips']) : [];
        return [
            'host' => $uri->getHost(),
            'path' => $uri->getPath(),
            'ips' => $ips,
            'carriedKey' => $carried,
            'authorized' => $request->hasHeader('Authorization'),
        ];
    }

    /** @param (callable(array<string, mixed>): void)|null $onRequest */
    private static function build(string $key, string $baseUrl, ?callable $onRequest): Client
    {
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
            apiKey: $key === '' ? null : $key,
            baseUrl: $baseUrl,
            httpClient: new GuzzleClient(['handler' => $stack]),
        ));
    }
}
