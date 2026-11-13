<?php

declare(strict_types=1);

namespace InternetData\Tests;

use InternetData\Client;
use InternetData\Options;
use PHPUnit\Framework\TestCase;

/**
 * Hits production. Skipped unless INTERNETDATA_LIVE=1, so the normal suite stays
 * offline and costs no quota.
 *
 *   INTERNETDATA_LIVE=1 ./scripts/test.sh --filter LiveTest
 */
final class LiveTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('INTERNETDATA_LIVE') !== '1') {
            self::markTestSkipped('set INTERNETDATA_LIVE=1 to run the live check');
        }
    }

    public function testALookupWithoutAKeyAnswersTheShapeItPromises(): void
    {
        $client = new Client();

        $result = $client->lookup('1.1.1.1');
        self::assertSame('1.1.1.1', $result->ip);
        self::assertTrue($result->isAnycast);
        self::assertSame(13335, $result->asn->asn);

        // The assertion a stub cannot make honestly: without a key the members a
        // key adds are ABSENT, which is not the same answer as false.
        self::assertNull($result->isVpn);
        self::assertNull($result->isHosting);
        self::assertNull($result->hosting);
        self::assertNull($result->location->city);
        self::assertArrayNotHasKey('is_hosting', $result->raw);

        $asn = $client->lookupAsn(13335);
        self::assertSame(13335, $asn->asn);
        self::assertNull($asn->numIps);
        self::assertTrue($client->lookupDomain('cloudflare.com')->isRegistered);
    }

    public function testAKeyRaisesTheShape(): void
    {
        $key = getenv('INTERNETDATA_API_KEY');
        if ($key === false || $key === '') {
            self::markTestSkipped('set INTERNETDATA_API_KEY to check a keyed plan');
        }
        $client = new Client(new Options(apiKey: $key));

        $result = $client->lookup('1.1.1.1');

        // A free-plan key past its day's trial answers as Unlimited does, and says so.
        if ($result->trialExpired === true) {
            self::assertNull($result->isHosting);
            return;
        }
        self::assertTrue($result->isHosting);
        self::assertNotNull($result->hosting);
    }
}
