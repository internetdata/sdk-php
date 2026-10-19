<?php

declare(strict_types=1);

namespace InternetData;

use Closure;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\RequestOptions;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use InternetData\Internal\Model\ModelInterface;
use InternetData\Internal\ObjectSerializer;
use TypeError;
use ValueError;

/**
 * @internal
 *
 * Sends a generated request and retries it when that is worth doing.
 *
 * Nothing in Guzzle knows about a server-supplied `Retry-After`, and honoring it
 * is most of the logic, so the schedule is here rather than in a middleware.
 */
final class Transport
{
    private const BACKOFF_BASE_MS = 250;

    /**
     * The longest Retry-After honored, in seconds: 2**31 - 1 ms, about 24.8
     * days, the same bound as the .NET SDK's. Past it the wait is the backoff.
     */
    private const LONGEST_RETRY_AFTER = 2_147_483;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly int $retries,
        private readonly float $timeout,
    ) {
    }

    public function send(RequestInterface $request): ResponseInterface
    {
        // The declared return type is what enforces the narrowing: `wait()` is
        // typed `mixed`, and strict_types turns anything else into a TypeError.
        return $this->sendAsync($request)->wait();
    }

    /**
     * Sends a request and reads its answer with `$read`, inside the attempt.
     *
     * An answer under 400 that `$read` cannot turn into what the call returns -
     * a proxy's HTML page, a cut-off or empty body, an object without the member
     * the call returns - is no answer at all. So it is the server_error any other
     * unreadable answer is, carrying the status, and it is retried like an outage
     * rather than reaching the caller as a raw TypeError from a generated model.
     *
     * @template T
     * @param Closure(ResponseInterface): T $read
     * @return T
     */
    public function read(RequestInterface $request, Closure $read): mixed
    {
        return $this->sendAsync($request, read: $read)->wait();
    }

    /**
     * @param float|null $timeout Replaces the client's bound for every attempt of this call.
     * @param (Closure(ResponseInterface): InternetDataException)|null $classify Reports a response
     *        of 400 or more, in place of the API's own envelope; what it returns is retried exactly
     *        when it says it is retryable.
     * @param (Closure(ResponseInterface): mixed)|null $read Turns a response under 400 into what the
     *        call returns, inside the attempt; without it the response itself is returned.
     */
    public function sendAsync(
        RequestInterface $request,
        ?int $retries = null,
        ?float $timeout = null,
        ?Closure $classify = null,
        ?Closure $read = null,
    ): PromiseInterface {
        $bound = $timeout === null
            ? []
            : [RequestOptions::TIMEOUT => $timeout, RequestOptions::CONNECT_TIMEOUT => $timeout];
        return $this->attempt(
            $request,
            $retries ?? $this->retries,
            0,
            0,
            $bound,
            $classify ?? static fn (ResponseInterface $r): InternetDataException => Errors::fromResponse($r),
            $read,
        );
    }

    /**
     * A GET whose body is read lazily, for the object storage link the download
     * endpoint hands out.
     *
     * The whole-request timeout is dropped and only the connect phase keeps it:
     * 30s is a sane bound on a metadata call and the wrong one on a body that
     * reaches gigabytes.
     *
     * Only the headers are awaited, so a retry here cannot duplicate bytes a
     * caller's destination already holds. Redirects are followed, unlike every
     * other request this client makes: this one IS the far side of a redirect,
     * and the guard exists to stop the API's own 302 pulling a database into
     * memory. `$errorMessage` stands in for the response body on a failure,
     * which is what keeps an unbounded error page unread.
     */
    public function sendStreaming(RequestInterface $request, string $errorMessage): ResponseInterface
    {
        return $this->attempt(
            $request,
            $this->retries,
            0,
            0,
            [
                RequestOptions::STREAM => true,
                RequestOptions::ALLOW_REDIRECTS => true,
                RequestOptions::TIMEOUT => 0,
                RequestOptions::CONNECT_TIMEOUT => $this->timeout,
            ],
            static fn (ResponseInterface $r): InternetDataException
                => Errors::fromResponse($r, $errorMessage),
        )->wait();
    }

    /** @return array<string, mixed> */
    public static function toArray(string $body, ?int $status = null): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw Errors::malformed($e->getMessage(), $status);
        }
        if (!is_array($decoded)) {
            throw Errors::malformed('expected a JSON object', $status);
        }
        return $decoded;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function toModel(ResponseInterface $response, string $class): object
    {
        $status = $response->getStatusCode();
        try {
            $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw Errors::malformed($e->getMessage(), $status);
        }
        $wrong = self::nonconforming($decoded, $class, '');
        if ($wrong !== null) {
            throw Errors::malformed(sprintf('the API answered %s of the wrong type', $wrong), $status);
        }
        $model = ObjectSerializer::deserialize($decoded, $class);
        if (!$model instanceof $class) {
            throw Errors::malformed(sprintf('expected %s', $class), $status);
        }
        return $model;
    }

    /**
     * Where a decoded answer is not of the type its generated model gives it, by
     * the member's path, or null when it is. The generated deserializer casts a
     * scalar with settype(), so a version served as "x" would become 0, an
     * `open` of "yes" true and a `size` of "760" [760]: every member is checked
     * first, at every depth. A null or absent member is left to the typed
     * getters, which refuse the ones the spec requires, but no list entry or
     * map value the spec describes may be null: through 2.7.1 a format of null
     * came back with a warning, and a sample row or a size of null as served.
     */
    private static function nonconforming(mixed $value, string $type, string $at): ?string
    {
        if ($value === null) {
            return null;
        }
        $here = $at === '' ? 'the answer' : $at;
        if (str_ends_with($type, '[]')) {
            if (!is_array($value) || !array_is_list($value)) {
                return $here;
            }
            foreach ($value as $i => $item) {
                $wrong = $item === null ? "{$at}[{$i}]" : self::nonconforming($item, substr($type, 0, -2), "{$at}[{$i}]");
                if ($wrong !== null) {
                    return $wrong;
                }
            }
            return null;
        }
        if (preg_match('/^array<string,(.+)>$/', $type, $m) === 1) {
            if (!$value instanceof \stdClass) {
                return $here;
            }
            foreach (get_object_vars($value) as $key => $item) {
                $wrong = $item === null ? "{$at}.{$key}" : self::nonconforming($item, $m[1], "{$at}.{$key}");
                if ($wrong !== null) {
                    return $wrong;
                }
            }
            return null;
        }
        $scalar = match ($type) {
            'int' => is_int($value),
            'float' => is_int($value) || is_float($value),
            'bool' => is_bool($value),
            'string', '\DateTime' => is_string($value),
            'object' => $value instanceof \stdClass,
            'mixed' => true,
            default => null,
        };
        if ($scalar !== null) {
            return $scalar ? null : $here;
        }
        if (is_subclass_of($type, \BackedEnum::class)) {
            return is_string($value) ? null : $here;
        }
        if (is_subclass_of($type, ModelInterface::class)) {
            if (!$value instanceof \stdClass) {
                return $here;
            }
            $wire = $type::attributeMap();
            foreach ($type::openAPITypes() as $property => $memberType) {
                $name = $wire[$property];
                $path = $at === '' ? $name : "{$at}.{$name}";
                $wrong = self::nonconforming($value->{$name} ?? null, $memberType, $path);
                if ($wrong !== null) {
                    return $wrong;
                }
            }
            return null;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $extraOptions
     * @param Closure(ResponseInterface): InternetDataException $classify
     * @param (Closure(ResponseInterface): mixed)|null $read
     */
    private function attempt(
        RequestInterface $request,
        int $left,
        int $attempt,
        int $delayMs,
        array $extraOptions,
        Closure $classify,
        ?Closure $read = null,
    ): PromiseInterface {
        $options = [
            // Errors are classified here rather than thrown by Guzzle, so the
            // retry decision and the exception both come from one place.
            RequestOptions::HTTP_ERRORS => false,
            // The download endpoint answers 302 to object storage, and chasing it
            // would pull a multi-gigabyte database into memory. Nothing this
            // client calls is meant to redirect.
            RequestOptions::ALLOW_REDIRECTS => false,
            // Guzzle defaults both of these to 0, meaning unlimited, so without
            // them a hung API holds the caller until the process is killed.
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->timeout,
            ...$extraOptions,
        ];
        if ($delayMs > 0) {
            // Guzzle's curl handler SCHEDULES a delayed transfer rather than
            // sleeping, which keeps a backoff off the calling thread.
            $options[RequestOptions::DELAY] = $delayMs;
        }

        return $this->http->sendAsync($request, $options)->then(
            function (ResponseInterface $response) use (
                $request, $left, $attempt, $extraOptions, $classify, $read,
            ): mixed {
                if ($response->getStatusCode() >= 400) {
                    $error = $classify($response);
                } elseif ($read === null) {
                    return $response;
                } else {
                    try {
                        return $read($response);
                    } catch (InternetDataException $e) {
                        $error = $e;
                    } catch (Exception | TypeError | ValueError $e) {
                        // What the generated models throw for an answer that is not
                        // the shape they declare: a TypeError from a typed getter, an
                        // InvalidArgumentException from the deserializer.
                        $error = Errors::malformed($e->getMessage(), $response->getStatusCode(), $e);
                    }
                }
                if ($left <= 0 || !$error->isRetryable()) {
                    throw $error;
                }
                return $this->attempt(
                    $request, $left - 1, $attempt + 1, self::delayFor($error, $attempt),
                    $extraOptions, $classify, $read,
                );
            },
            function (mixed $reason) use (
                $request, $left, $attempt, $extraOptions, $classify, $read,
            ): PromiseInterface {
                $error = Errors::coerce($reason);
                if ($left <= 0 || !$error->isRetryable()) {
                    throw $error;
                }
                return $this->attempt(
                    $request, $left - 1, $attempt + 1, self::backoffMs($attempt),
                    $extraOptions, $classify, $read,
                );
            },
        );
    }

    private static function delayFor(InternetDataException $error, int $attempt): int
    {
        // Unbounded, the product overflowed into a float and the int return type
        // threw a raw TypeError, and a smaller one scheduled a wait of millennia.
        $seconds = $error->retryAfterSeconds;
        return $seconds === null || $seconds <= 0 || $seconds > self::LONGEST_RETRY_AFTER
            ? self::backoffMs($attempt)
            : $seconds * 1000;
    }

    private static function backoffMs(int $attempt): int
    {
        return self::BACKOFF_BASE_MS << min($attempt, 6);
    }
}
