<?php

declare(strict_types=1);

namespace InternetData;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @internal
 *
 * Turns a response or a transport failure into the one exception type this
 * library throws.
 */
final class Errors
{
    /**
     * The three forms of HTTP date a recipient accepts (RFC 9110, section 5.6.7):
     * IMF-fixdate, RFC 850 and asctime, all in GMT.
     */
    private const HTTP_DATE = '/^(?:[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT'
        . '|[A-Z][a-z]{5,8}, \d{2}-[A-Z][a-z]{2}-\d{2} \d{2}:\d{2}:\d{2} GMT'
        . '|[A-Z][a-z]{2} [A-Z][a-z]{2} [ \d]\d \d{2}:\d{2}:\d{2} \d{4})$/';

    /**
     * @param string|null $context Replaces the body as the source of the message, for a response
     *                             whose body must NOT be read: nothing bounds the size of an object
     *                             storage error page, and the status is what separates a lapsed
     *                             link from a refused one.
     */
    public static function fromResponse(
        ResponseInterface $response,
        ?string $context = null,
    ): InternetDataException {
        $status = $response->getStatusCode();
        $message = $context !== null
            ? sprintf('%s (status %d)', $context, $status)
            : self::messageOf((string) $response->getBody())
                ?? sprintf('request failed with status %d', $status);
        $retryAfter = self::parseRetryAfter($response->getHeaderLine('Retry-After'));

        if ($status === 429) {
            // Present means transient, absent means an allowance is spent.
            // Nothing else in the response separates the two.
            return $retryAfter === null
                ? new InternetDataException(ErrorKind::QuotaExceeded, $message, $status)
                : new InternetDataException(ErrorKind::RateLimited, $message, $status, $retryAfter);
        }
        if ($status === 400) {
            return new InternetDataException(ErrorKind::BadRequest, $message, $status);
        }
        if ($status === 401) {
            return new InternetDataException(ErrorKind::Unauthorized, $message, $status);
        }
        if ($status === 403) {
            return new InternetDataException(ErrorKind::Forbidden, $message, $status);
        }
        // Any other 4xx is a CLIENT error. Falling through to the server_error
        // default would make it retryable, so a 404 from a database id that is
        // not published yet would be retried twice before failing. Only 5xx and
        // transport failures are worth a retry.
        if ($status < 500) {
            return new InternetDataException(ErrorKind::BadRequest, $message, $status);
        }
        return new InternetDataException(ErrorKind::ServerError, $message, $status);
    }

    public static function coerce(mixed $reason): InternetDataException
    {
        if ($reason instanceof InternetDataException) {
            return $reason;
        }
        if ($reason instanceof Throwable) {
            return new InternetDataException(
                ErrorKind::Network, $reason->getMessage(), null, null, $reason,
            );
        }
        return new InternetDataException(ErrorKind::Network, self::describe($reason));
    }

    public static function malformed(
        string $detail,
        ?int $status,
        ?Throwable $previous = null,
    ): InternetDataException {
        return new InternetDataException(
            ErrorKind::ServerError,
            sprintf('could not read the API response: %s', $detail),
            $status,
            null,
            $previous,
        );
    }

    // The API's refusals carry a machine-readable `rc`, which says WHICH refusal
    // this is where the status only says what class it belongs to. An
    // intermediary in front of the API can also answer with an HTML page
    // carrying no envelope at all.
    private static function messageOf(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['rc']) || !is_string($decoded['rc'])) {
            return null;
        }
        return $decoded['rc'];
    }

    private static function parseRetryAfter(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        // The header also permits an HTTP date, and only that reaches the date
        // parser, which reads English too: `-1` as a time zone an hour away, `x` as
        // one eleven hours away, `tomorrow` and `+1 day`, each a wait the server
        // never asked for. Every form is GMT, asctime's without saying so, so the
        // zone is given rather than taken from the process's default.
        if (preg_match(self::HTTP_DATE, $value) !== 1) {
            return null;
        }
        try {
            $when = (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception) {
            return null;
        }
        // A day its month lacks (31 Nov, 30 Feb) parses as the next month's, with
        // a warning, and was waited out as that day (2.5.1, measured 2026-10-10).
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && $errors['warning_count'] > 0) {
            return null;
        }
        return max(0, $when - time());
    }

    private static function describe(mixed $reason): string
    {
        return is_scalar($reason) ? (string) $reason : sprintf('request failed (%s)', get_debug_type($reason));
    }
}
