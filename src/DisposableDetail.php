<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DisposableDetail as WireDisposableDetail;

/**
 * Disposable Domain's row for the domain.
 *
 * Every property is set when the object is populated; the whole object is EMPTY (`isEmpty()`) when
 * its flag is false.
 */
final class DisposableDetail
{
    public function __construct(
        /** `high`, `medium` or `low`. */
        public readonly ?string $confidence = null,
        /**
         * The providers behind it.
         *
         * @var list<string>|null
         */
        public readonly ?array $providers = null,
        /** The first day seen in use. */
        public readonly ?string $firstSeen = null,
        /** The last day seen in use. */
        public readonly ?string $lastSeen = null,
    ) {
    }

    /** True when the API answered `{}`, which means the flag above it is false. */
    public function isEmpty(): bool
    {
        return $this->confidence === null && $this->providers === null && $this->firstSeen === null
            && $this->lastSeen === null;
    }

    /** @internal */
    public static function fromWire(WireDisposableDetail $w): self
    {
        return Transport::isEmpty($w) ? new self() : new self(
            confidence: $w->getConfidence(),
            providers: array_values($w->getProviders()),
            firstSeen: $w->getFirstSeen(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
