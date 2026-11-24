<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainWeb as WireDomainWeb;

/**
 * What our crawl last read of the domain's own home page, every member empty where it holds none.
 */
final class DomainWeb
{
    public function __construct(
        /** The URL of the home page read. */
        public readonly string $url,
        /** An English page's title. */
        public readonly string $title,
        /** An English page's description. */
        public readonly string $description,
        /** The day it was last read, empty when the crawl holds no page. */
        public readonly string $lastSeen,
        /** The external hosts the home page links to. */
        public readonly DomainWebLinks $links,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainWeb $w): self
    {
        return new self(
            url: $w->getUrl(),
            title: $w->getTitle(),
            description: $w->getDescription(),
            lastSeen: $w->getLastSeen(),
            links: DomainWebLinks::fromWire($w->getLinks()),
        );
    }
}
