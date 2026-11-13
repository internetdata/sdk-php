<?php

declare(strict_types=1);

namespace InternetData\Integration\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use InternetData\AsnResult;
use InternetData\Bogon;
use InternetData\Client;
use InternetData\DomainResult;
use InternetData\ErrorKind;
use InternetData\Integration\Staging;
use InternetData\InternetDataException;
use InternetData\Options;
use InternetData\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * The published package looking up an address, an AS and a domain: keyless
 * against production and staging, and with the CI key against staging.
 *
 * Nothing here pins a field COUNT: the keyed answer is asserted as a superset of
 * the keyless one, so a pricing change stays a pricing change instead of
 * arriving as a red SDK build.
 */
final class LookupTest extends TestCase
{
    // What Unlimited leaves out of an address's answer, by the property a caller
    // reads: each must be null, never false or empty.
    private const PAID = [
        'is_vpn' => 'isVpn', 'is_resproxy' => 'isResproxy', 'is_dcproxy' => 'isDcproxy',
        'is_mobproxy' => 'isMobproxy', 'is_pubproxy' => 'isPubproxy', 'is_hosting' => 'isHosting',
        'is_cdn' => 'isCdn', 'vpn' => 'vpn', 'resproxy' => 'resproxy', 'dcproxy' => 'dcproxy',
        'mobproxy' => 'mobproxy', 'pubproxy' => 'pubproxy', 'hosting' => 'hosting', 'cdn' => 'cdn',
        'carrier' => 'carrier', 'satellite' => 'satellite', 'ssh' => 'ssh', 'smtp' => 'smtp', 'web' => 'web',
        'dns_resolver' => 'dnsResolver', 'bittorrent' => 'bittorrent', 'whois' => 'whois', 'rwhois' => 'rwhois',
        'hosted_domains' => 'hostedDomains',
    ];

    /** @return iterable<string, array{string}> */
    public static function hosts(): iterable
    {
        yield 'production' => [Staging::PROD];
        yield 'staging' => [Staging::BASE_URL];
    }

    #[DataProvider('hosts')]
    public function testAKeylessLookupAnswersUnlimitedAndLeavesThePaidMembersOut(string $baseUrl): void
    {
        $facts = [];
        $result = Staging::keyless($baseUrl, static function (array $fact) use (&$facts): void {
            $facts[] = $fact;
        })->lookup(Staging::PROBE);

        self::assertSame(Staging::PROBE, $result->ip);
        self::assertFalse($result->isBogon);
        self::assertSame(2, strlen($result->location->country), 'the country is an ISO 3166 code');
        self::assertGreaterThan(0, $result->asn->asn, 'the probe is announced');
        // The assertions a stub cannot make honestly: without a key the members a
        // key adds are ABSENT, which is not the same answer as false.
        foreach (self::PAID as $wire => $property) {
            self::assertNull($result->{$property}, "{$property} is not on Unlimited, so it must read as null");
            self::assertArrayNotHasKey($wire, $result->raw);
        }
        self::assertNull($result->location->city);
        self::assertNull($result->asn->rpkiStatus);
        self::assertNull($result->company->type);
        self::assertNull($result->abuse->phone);
        self::assertCount(1, $facts);
        self::assertFalse($facts[0]['authorized'], 'a keyless client sent an Authorization header');
    }

    #[DataProvider('hosts')]
    public function testAKeylessAsAndDomainLookupAnswerUnlimited(string $baseUrl): void
    {
        $client = Staging::keyless($baseUrl);

        $asn = $client->lookupAsn(9009);
        self::assertInstanceOf(AsnResult::class, $asn);
        self::assertSame(9009, $asn->asn);
        self::assertNotSame('', $asn->name);
        foreach (['type', 'numIps', 'numPrefixes4', 'numPrefixes6', 'rpkiValid', 'whois', 'prefixes'] as $property) {
            self::assertNull($asn->{$property}, "AS {$property} is not on Unlimited");
        }

        $domain = $client->lookupDomain('example.com');
        self::assertInstanceOf(DomainResult::class, $domain);
        self::assertSame('example.com', $domain->domain);
        self::assertTrue($domain->isRegistered);
        foreach (['isDisposable', 'disposable', 'dns', 'ipRanges'] as $property) {
            self::assertNull($domain->{$property}, "domain {$property} is not on Unlimited");
        }
    }

    #[DataProvider('hosts')]
    public function testMyIpAnswersForTheAddressTheRequestCameFrom(string $baseUrl): void
    {
        $result = Staging::keyless($baseUrl)->myIp();

        self::assertNotFalse(filter_var($result->ip, FILTER_VALIDATE_IP), 'myIp answered no address');
        self::assertFalse($result->isBogon);
    }

    #[DataProvider('hosts')]
    public function testAKeylessBatchOfTwoIsRefusedOnceAndItsBogonStillAnswered(string $baseUrl): void
    {
        $paths = [];
        $client = Staging::keyless($baseUrl, static function (array $fact) use (&$paths): void {
            $paths[] = $fact['path'];
        });

        // Two addresses on the wire: a keyless batch of one is answered as its lookup.
        $got = $client->lookupBatch([Staging::PROBE, '8.8.8.8', '10.0.0.1']);

        self::assertTrue($got['10.0.0.1']->isBogon);
        foreach ([Staging::PROBE, '8.8.8.8'] as $ip) {
            $refused = $got[$ip];
            self::assertInstanceOf(InternetDataException::class, $refused, "{$ip}: a batch without a key must fail");
            self::assertSame(ErrorKind::Unauthorized, $refused->kind);
            self::assertSame(401, $refused->status);
            self::assertFalse($refused->isRetryable());
        }
        self::assertSame(['/batch'], $paths, 'one request, never retried');
    }

    #[DataProvider('hosts')]
    public function testAKeylessEntitlementIsUnauthorizedOnce(string $baseUrl): void
    {
        $paths = [];
        $client = Staging::keyless($baseUrl, static function (array $fact) use (&$paths): void {
            $paths[] = $fact['path'];
        });

        try {
            $client->myEntitlement();
            self::fail('a keyless entitlement answered');
        } catch (InternetDataException $e) {
            self::assertSame(ErrorKind::Unauthorized, $e->kind);
            self::assertSame(401, $e->status);
        }
        self::assertSame(['/api/v1/entitlement'], $paths);
    }

    public function testABogonIsAnsweredWithoutTouchingTheNetwork(): void
    {
        $offline = new Client(new Options(
            baseUrl: Staging::BASE_URL,
            httpClient: new GuzzleClient(['handler' => HandlerStack::create(
                static function (RequestInterface $request): never {
                    throw new RuntimeException('the bogon path reached the network');
                },
            )]),
        ));

        $r = $offline->lookup('10.0.0.1');

        self::assertTrue($r->isBogon);
        self::assertFalse($r->isVpn);
        self::assertTrue($r->vpn?->isEmpty());
        self::assertSame([], $r->web);
        self::assertTrue(Bogon::isBogon('10.0.0.1'), 'the standalone form must agree');
    }

    /**
     * FIRST of the keyed tests on purpose: every comparison below is vacuous
     * unless the key reached the wire, since an unsent key answers the keyless
     * shape and satisfies a superset check by construction.
     */
    public function testTheKeyReachesTheWireAndItsAnswerHoldsUnlimitedsAtLeast(): void
    {
        $this->skipWithoutTheKey();
        $facts = [];
        $keyed = Staging::client(static function (array $fact) use (&$facts): void {
            $facts[] = $fact;
        })->lookup(Staging::PROBE);
        self::assertNotEmpty($facts, 'no request reached the staging API');
        foreach ($facts as $fact) {
            self::assertTrue($fact['carriedKey'], "the key never reached {$fact['path']}");
        }

        $keyless = Staging::keyless(Staging::BASE_URL)->lookup(Staging::PROBE);
        // trial_expired marks a free-plan key's answers, and no other: it is not
        // a field a plan serves.
        $fields = array_values(array_diff(array_keys($keyed->raw), ['trial_expired']));
        foreach (array_keys($keyless->raw) as $field) {
            self::assertContains($field, $fields, "the key's answer drops {$field}, which Unlimited serves");
        }
        print 'keyed: ' . count($fields) . ' fields, keyless: ' . count($keyless->raw) . "\n";
    }

    public function testTheKeysEntitlementNamesItsPlan(): void
    {
        $this->skipWithoutTheKey();
        $entitlement = Staging::client()->myEntitlement();

        self::assertNotSame('', $entitlement->plan->key);
        self::assertContains($entitlement->plan->tier, ['free', 'starter', 'scale', 'max']);
        self::assertGreaterThanOrEqual(0, $entitlement->usage->requests);
        self::assertLessThan($entitlement->usage->windowEnd, $entitlement->usage->windowStart);
        print "plan {$entitlement->plan->key}, tier {$entitlement->plan->tier}\n";
    }

    /**
     * A batch needs a paid plan: the free plan's batch of two is a 402, sent once,
     * and a paid one answers both addresses in one request. Which the CI key holds
     * is read off its entitlement.
     */
    public function testABatchWithTheKeyIsWhatItsPlanAllows(): void
    {
        $this->skipWithoutTheKey();
        // Distinct requests rather than a call count, so a retry against a wobbling
        // staging cannot read as a failure to deduplicate.
        $asked = [];
        $client = Staging::client(static function (array $fact) use (&$asked): void {
            $asked[$fact['path'] . ' ' . implode(',', $fact['ips'])] = true;
        });
        $free = $client->myEntitlement()->plan->tier === 'free';

        $got = $client->lookupBatch([Staging::PROBE, '8.8.8.8', Staging::PROBE, '10.0.0.1']);

        self::assertSame([Staging::PROBE, '8.8.8.8', '10.0.0.1'], array_keys($got));
        self::assertSame(['/api/v1/entitlement ', '/batch ' . Staging::PROBE . ',8.8.8.8'], array_keys($asked));
        self::assertTrue($got['10.0.0.1']->isBogon);
        foreach ([Staging::PROBE, '8.8.8.8'] as $ip) {
            if ($free) {
                self::assertInstanceOf(InternetDataException::class, $got[$ip], "{$ip}: a free plan's batch of two");
                self::assertSame(402, $got[$ip]->status);
                self::assertSame(ErrorKind::BadRequest, $got[$ip]->kind);
                continue;
            }
            self::assertInstanceOf(Result::class, $got[$ip], "{$ip} failed");
            self::assertSame($ip, $got[$ip]->ip);
        }
    }

    private function skipWithoutTheKey(): void
    {
        $reason = Staging::skipReason();
        if ($reason !== null) {
            self::markTestSkipped($reason);
        }
    }
}
