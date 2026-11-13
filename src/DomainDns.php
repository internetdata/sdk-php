<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\DomainDns as WireDomainDns;

/**
 * A domain's records in Domain DNS, up to 100 of each type.
 */
final class DomainDns
{
    public function __construct(
        /**
         * Its A records.
         *
         * @var list<DnsAddressRecord>
         */
        public readonly array $a,
        /**
         * Its AAAA records.
         *
         * @var list<DnsAddressRecord>
         */
        public readonly array $aaaa,
        /**
         * Its MX records.
         *
         * @var list<DnsMxRecord>
         */
        public readonly array $mx,
        /**
         * Its NS records.
         *
         * @var list<DnsNsRecord>
         */
        public readonly array $ns,
        /**
         * Its CNAME records.
         *
         * @var list<DnsCnameRecord>
         */
        public readonly array $cname,
    ) {
    }

    /** @internal */
    public static function fromWire(WireDomainDns $w): self
    {
        return new self(
            a: array_map(DnsAddressRecord::fromWire(...), array_values($w->getA())),
            aaaa: array_map(DnsAddressRecord::fromWire(...), array_values($w->getAaaa())),
            mx: array_map(DnsMxRecord::fromWire(...), array_values($w->getMx())),
            ns: array_map(DnsNsRecord::fromWire(...), array_values($w->getNs())),
            cname: array_map(DnsCnameRecord::fromWire(...), array_values($w->getCname())),
        );
    }
}
