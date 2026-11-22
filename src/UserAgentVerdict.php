<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\UserAgentVerdict as WireUserAgentVerdict;

/**
 * The agent a request's `User-Agent` names, and whether the address bears it out. Present only on
 * an answer to a lookup that sent a user agent, with every field.
 */
final class UserAgentVerdict
{
    public function __construct(
        /** The agent the user agent names, such as `Googlebot` or `Chrome`; `Unknown` where it names none. */
        public readonly string $agent,
        /**
         * `browser`, `inapp`, `automation`, `bot`, `ai-bot`, `http-client` or `unknown`: what kind
         * of client the user agent says it is.
         */
        public readonly string $kind,
        /** `verified`, `spoofed`, `unverifiable` or `not_bot`. */
        public readonly string $verdict,
    ) {
    }

    /** @internal */
    public static function fromWire(WireUserAgentVerdict $w): self
    {
        return new self(agent: $w->getAgent(), kind: $w->getKind(), verdict: $w->getVerdict());
    }
}
