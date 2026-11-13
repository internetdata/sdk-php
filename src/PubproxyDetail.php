<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\PubproxyDetail as WirePubproxyDetail;

/**
 * Public Proxy IP's row for the address.
 *
 * Every property is set when the object is populated; the whole object is EMPTY (`isEmpty()`) when
 * its flag is false.
 */
final class PubproxyDetail
{
    public function __construct(
        /** A public proxy listens here. */
        public readonly ?bool $entry = null,
        /** Public proxies' traffic leaves from here. */
        public readonly ?bool $exit = null,
        /**
         * The protocols at either end, `[]` when unknown.
         *
         * @var list<string>|null
         */
        public readonly ?array $protocols = null,
        /** How many ports a proxy listens on here. */
        public readonly ?int $numPorts = null,
        /** How many proxies' traffic left from here. */
        public readonly ?int $numProxies = null,
        /** The last day one was seen. */
        public readonly ?string $lastSeen = null,
        /** The last day one answered a request, empty when none did. */
        public readonly ?string $lastUsable = null,
    ) {
    }

    /** True when the API answered `{}`, which means the flag above it is false. */
    public function isEmpty(): bool
    {
        return $this->entry === null && $this->exit === null && $this->protocols === null
            && $this->numPorts === null && $this->numProxies === null && $this->lastSeen === null
            && $this->lastUsable === null;
    }

    /** @internal */
    public static function fromWire(WirePubproxyDetail $w): self
    {
        return new self(
            entry: $w->getEntry(),
            exit: $w->getExit(),
            protocols: $w->getProtocols() === null ? null : array_values($w->getProtocols()),
            numPorts: $w->getNumPorts(),
            numProxies: $w->getNumProxies(),
            lastSeen: $w->getLastSeen(),
            lastUsable: $w->getLastUsable(),
        );
    }
}
