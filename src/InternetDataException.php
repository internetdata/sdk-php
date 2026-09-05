<?php

declare(strict_types=1);

namespace InternetData;

use RuntimeException;
use Throwable;

/**
 * Every failure the library reports.
 *
 * Unchecked, so a call drops into a callback or an array_map without a wrapper.
 */
final class InternetDataException extends RuntimeException
{
    public function __construct(
        public readonly ErrorKind $kind,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** Whether retrying this exact request could succeed. */
    public function isRetryable(): bool
    {
        return $this->kind === ErrorKind::RateLimited
            || $this->kind === ErrorKind::ServerError
            || $this->kind === ErrorKind::Network;
    }
}
