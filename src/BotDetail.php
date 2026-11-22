<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\BotDetail as WireBotDetail;

/**
 * Bot IP's row for the address: whose bot uses it, what that bot does, and whether its operator
 * vouches for the address.
 *
 * Every property is set when the object is populated; the whole object is EMPTY (`isEmpty()`) when
 * its flag is false.
 */
final class BotDetail
{
    public function __construct(
        /** The bot operator's ID in Bot Provider, empty where no operator is known. */
        public readonly ?string $provider = null,
        /** The operator's name, empty where no operator is known. */
        public readonly ?string $providerName = null,
        /**
         * The bots the address serves, as their user agents name them (`Googlebot`, `GPTBot`); for
         * an address that is not verified, the bots it claimed to be. `[]` when none is named.
         *
         * @var list<string>|null
         */
        public readonly ?array $agents = null,
        /**
         * What those bots do (`search_engine`, `ai_training`, `monitoring`, ...), `[]` where
         * unknown.
         *
         * @var list<string>|null
         */
        public readonly ?array $categories = null,
        /** Whether the operator vouches for the address, so the bots in `agents` really are its. */
        public readonly ?bool $verified = null,
        /** `verified`, `declared`, `spoofed` or `undeclared`. */
        public readonly ?string $identity = null,
        /** `list`, `rdns` or `owner` for a verified address; empty when not verified. */
        public readonly ?string $verifiedBy = null,
        /** `high`, `medium` or `low`. */
        public readonly ?string $confidence = null,
        /** The first day the address was seen as such. */
        public readonly ?string $firstSeen = null,
        /** The last day it was. */
        public readonly ?string $lastSeen = null,
    ) {
    }

    /** True when the API answered `{}`, which means the flag above it is false. */
    public function isEmpty(): bool
    {
        return $this->provider === null && $this->providerName === null && $this->agents === null
            && $this->categories === null && $this->verified === null && $this->identity === null
            && $this->verifiedBy === null && $this->confidence === null && $this->firstSeen === null
            && $this->lastSeen === null;
    }

    /** @internal */
    public static function fromWire(WireBotDetail $w): self
    {
        return new self(
            provider: $w->getProvider(),
            providerName: $w->getProviderName(),
            agents: $w->getAgents() === null ? null : array_values($w->getAgents()),
            categories: $w->getCategories() === null ? null : array_values($w->getCategories()),
            verified: $w->getVerified(),
            identity: $w->getIdentity(),
            verifiedBy: $w->getVerifiedBy(),
            confidence: $w->getConfidence(),
            firstSeen: $w->getFirstSeen(),
            lastSeen: $w->getLastSeen(),
        );
    }
}
