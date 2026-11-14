<?php

declare(strict_types=1);

namespace InternetData\Tests;

use InternetData\Client;
use InternetData\ErrorKind;
use InternetData\InternetDataException;
use InternetData\Middleware\Condition;
use InternetData\Middleware\Core;
use InternetData\Middleware\Options as MwOptions;
use InternetData\Middleware\RequestView;
use InternetData\Middleware\Selectors;
use InternetData\Options;
use PHPUnit\Framework\TestCase;

/**
 * The middleware half of the shared conformance corpus, plus the PHP-specific parts.
 *
 * This SDK is camelCase throughout, so a condition written here says `isVpn` and
 * `rpkiStatus` while the corpus says `is_vpn` and `rpki_status`. The rename is a
 * general snake-to-camel rather than a lookup table, which is why a nested member,
 * and a path such as `asn.rpki_status`, needs no entry of its own.
 */
final class MiddlewareTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';

    /** @return array<string, mixed> */
    private static function corpus(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $data;
    }

    /**
     * A served answer from the corpus's lookup cases, decoded with objects so a detail
     * served as `{}` is not sent as `[]`.
     */
    private static function answer(string $name): object
    {
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            false,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach ($data->lookup as $case) {
            if ($case->name === $name) {
                return $case->body;
            }
        }
        self::fail("the corpus has no lookup case {$name}");
    }

    /** Turn the corpus's wire names into this SDK's idiom, at every depth. */
    private static function toIdiom(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'toIdiom'], $value);
        }
        $out = [];
        foreach ($value as $key => $entry) {
            $out[self::camel((string) $key)] = self::toIdiom($entry);
        }
        return $out;
    }

    private static function camel(string $name): string
    {
        return lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    private static function serving(object|array $body, int $status = 200): Stub
    {
        $ip = is_object($body) ? $body->ip : ($body['ip'] ?? self::PUBLIC_IP);
        return new Stub(Stub::lookups([$ip => ['status' => $status, 'body' => $body]]));
    }

    private static function client(Stub $stub): Client
    {
        return new Client(new Options(cache: false, retries: 0, httpClient: $stub->client));
    }

    private static function selectors(): Selectors
    {
        return new Selectors(fn (Req $request): RequestView => new RequestView(
            header: fn (string $name): ?string => $request->headers[strtolower($name)] ?? null,
            frameworkIp: fn (): ?string => $request->ip,
        ));
    }

    private static function core(MwOptions $options): Core
    {
        return new Core($options, self::selectors()->default());
    }

    public function testCorpusConditions(): void
    {
        $wire = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            false,
            512,
            JSON_THROW_ON_ERROR
        )->middleware->conditions;
        foreach (self::corpus()['middleware']['conditions'] as $i => $case) {
            $why = "{$case['name']}: {$case['why']}";
            $result = isset($case['bogon'])
                ? (new Client(new Options()))->lookup($case['bogon'])
                : self::client(self::serving($wire[$i]->body))->lookup($case['body']['ip']);

            /** @var array<mixed> $condition */
            $condition = self::toIdiom($case['condition']);
            self::assertSame(
                $case['expect']['blocked'],
                Condition::matches($condition, $result),
                $why
            );

            $missing = Condition::missingMembers($condition, $result);
            sort($missing);
            $want = array_map([self::class, 'camel'], $case['expect']['missing']);
            sort($want);
            self::assertSame($want, $missing, $why);
        }
    }

    public function testCorpusRefusesAConditionThatConstrainsNothing(): void
    {
        foreach (self::corpus()['middleware']['invalidConditions'] as $case) {
            $refused = false;
            try {
                self::core(new MwOptions(blockCondition: self::toIdiom($case['condition'])));
            } catch (\InvalidArgumentException $e) {
                $refused = str_contains($e->getMessage(), 'constrains nothing');
            }
            self::assertTrue($refused, "{$case['name']}: {$case['why']}");
        }
    }

    public function testEnrichesWithoutBlockingWhenNoConditionIsConfigured(): void
    {
        $stub = self::serving(self::answer('unlimited-answer'));
        $core = self::core(new MwOptions(client: self::client($stub)));
        $lookup = $core->evaluate(new Req());
        self::assertNotNull($lookup);
        self::assertFalse($lookup->blocked);
        self::assertSame('AT', $lookup->result?->location->country);
        self::assertSame(9009, $lookup->result?->asn->asn);
        self::assertSame(self::PUBLIC_IP, $lookup->ip);
    }

    public function testACountryConditionBlocksWithoutAKeyAndAnotherCountryPasses(): void
    {
        $blocking = self::core(new MwOptions(
            client: self::client(self::serving(self::answer('unlimited-answer'))),
            blockCondition: ['location' => ['country' => ['DE', 'AT']]],
        ));
        self::assertTrue($blocking->evaluate(new Req())?->blocked);

        $passing = self::core(new MwOptions(
            client: self::client(self::serving(self::answer('unlimited-answer'))),
            blockCondition: ['location' => ['country' => 'DE']],
        ));
        self::assertFalse($passing->evaluate(new Req())?->blocked);
    }

    public function testSkipClaimsTheRequest(): void
    {
        $stub = self::serving(self::answer('paid-full-shape'));
        $core = self::core(new MwOptions(
            client: self::client($stub),
            skip: fn (Req $r): bool => true,
        ));
        self::assertNull($core->evaluate(new Req()));
        self::assertSame([], $stub->calls);
    }

    public function testFailsOpenOnALookupErrorAndClosedOnlyWhenAsked(): void
    {
        $failing = self::client(self::serving(['ip' => self::PUBLIC_IP, 'error' => 'boom'], 500));
        $open = self::core(new MwOptions(
            client: $failing,
            blockCondition: ['location' => ['country' => 'AT']],
        ));
        $lookup = $open->evaluate(new Req());
        self::assertNotNull($lookup);
        self::assertFalse($lookup->blocked);
        self::assertInstanceOf(InternetDataException::class, $lookup->error);
        self::assertNull($lookup->result);

        $closed = self::core(new MwOptions(
            client: $failing,
            blockCondition: ['location' => ['country' => 'AT']],
            failClosed: true,
        ));
        self::assertTrue($closed->evaluate(new Req())?->blocked);
    }

    public function testARateLimitStillFailsOpen(): void
    {
        $limited = self::client(new Stub(Stub::lookups([self::PUBLIC_IP => [
            'status' => 429,
            'headers' => ['Retry-After' => '30'],
            'body' => ['error' => 'rate limit exceeded'],
        ]])));
        $core = self::core(new MwOptions(
            client: $limited,
            blockCondition: ['location' => ['country' => 'AT']],
        ));
        $lookup = $core->evaluate(new Req());
        self::assertFalse($lookup?->blocked);
        self::assertSame(ErrorKind::RateLimited, $lookup?->error?->kind);
    }

    public function testAPrivateClientAddressWarnsOnceAndNeverReachesTheNetwork(): void
    {
        $stub = self::serving(self::answer('paid-full-shape'));
        $warnings = [];
        $core = self::core(new MwOptions(
            client: self::client($stub),
            blockCondition: ['location' => ['country' => 'AT']],
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ));
        foreach ([1, 2] as $_) {
            $lookup = $core->evaluate(new Req([], '10.0.0.7'));
            self::assertFalse($lookup?->blocked);
            self::assertTrue($lookup?->result?->isBogon);
        }
        self::assertSame([], $stub->calls);
        self::assertCount(1, $warnings, 'a per-request warning is an outage of its own');
        self::assertStringContainsString('not a public address', $warnings[0]);
    }

    public function testAMissingMemberWarnsOnceOrThrowsOnRequest(): void
    {
        $free = self::client(self::serving(self::answer('unlimited-answer')));
        $warnings = [];
        $warned = self::core(new MwOptions(
            client: $free,
            blockCondition: ['isHosting' => true],
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ));
        $warned->evaluate(new Req());
        $warned->evaluate(new Req());
        self::assertCount(1, $warnings);
        self::assertStringContainsString('isHosting', $warnings[0]);

        $strict = self::core(new MwOptions(
            client: $free,
            blockCondition: ['isHosting' => true],
            onMissingField: 'throw',
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not include/');
        $strict->evaluate(new Req());
    }

    // location is on every plan and its city is not, so a city condition on Unlimited
    // can never fire: it is reported by its path, not passed over because location
    // itself was served.
    public function testAPaidMemberInsideAnObjectEveryPlanGetsIsWarnedAboutByItsPath(): void
    {
        $warnings = [];
        $core = self::core(new MwOptions(
            client: self::client(self::serving(self::answer('unlimited-answer'))),
            blockCondition: ['location' => ['city' => 'Vienna']],
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ));
        self::assertFalse($core->evaluate(new Req())?->blocked);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('location.city', $warnings[0]);
    }

    public function testSelectorsReadWhatTheySayTheyRead(): void
    {
        $selectors = self::selectors();
        $request = new Req(['x-forwarded-for' => '203.0.113.9, 70.41.3.18, 150.172.238.178'], '10.0.0.1');

        self::assertSame('10.0.0.1', ($selectors->default())($request));
        self::assertSame('203.0.113.9', ($selectors->xff())($request));
        self::assertSame('150.172.238.178', ($selectors->xff(1))($request));
        self::assertSame('70.41.3.18', ($selectors->xff(2))($request));
        self::assertSame('10.0.0.1', ($selectors->header('CF-Connecting-IP'))($request));

        $cf = new Req(['cf-connecting-ip' => '198.51.100.4'], '10.0.0.1');
        self::assertSame('198.51.100.4', ($selectors->header('CF-Connecting-IP'))($cf));
        self::assertSame('10.0.0.1', ($selectors->xff())(new Req([], '10.0.0.1')));
    }

    public function testAnUnresolvableAddressWarnsAndDoesNotBlock(): void
    {
        $warnings = [];
        $core = new Core(
            new MwOptions(
                blockCondition: ['location' => ['country' => 'AT']],
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ),
            fn (Req $r): ?string => null,
        );
        $lookup = $core->evaluate(new Req());
        self::assertFalse($lookup?->blocked);
        self::assertInstanceOf(InternetDataException::class, $lookup?->error);
        self::assertStringContainsString('could not resolve a client address', $warnings[0]);
    }

    // Every other test hands the core a client, so a key or a bound the core dropped on
    // its way to the client it builds would pass them all.
    public function testACoreBuiltFromAKeySendsItAndGivesUpAtItsOwnBound(): void
    {
        $origin = new Origin(['stallSeconds' => 6]);
        $core = self::core(new MwOptions(apiKey: 'k', baseUrl: $origin->baseUrl));
        $started = microtime(true);
        try {
            $lookup = $core->evaluate(new Req());
        } finally {
            $elapsed = microtime(true) - $started;
            $origin->stop();
        }

        self::assertFalse($lookup?->blocked);
        self::assertSame(ErrorKind::Network, $lookup?->error?->kind);
        $sent = $origin->requests();
        self::assertCount(1, $sent, 'a request path retries nothing by default');
        self::assertSame('/' . self::PUBLIC_IP, $sent[0]['path']);
        self::assertSame('Bearer k', $sent[0]['headers']['authorization'] ?? null);
        self::assertGreaterThanOrEqual(2.2, $elapsed, 'gave up before its 2.5 s bound');
        self::assertLessThan(4.0, $elapsed, 'held the request past its 2.5 s bound');
    }

    // The client refuses what is not an address before sending it, so a header that
    // carried something else is a failed lookup like any other: let through.
    public function testASelectorAnsweringSomethingThatIsNotAnAddressFailsOpen(): void
    {
        $stub = new Stub();
        $core = self::core(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Req $r): string => 'example.com',
            blockCondition: ['location' => ['country' => 'AT']],
        ));
        $lookup = $core->evaluate(new Req());
        self::assertFalse($lookup?->blocked);
        self::assertSame(ErrorKind::BadRequest, $lookup?->error?->kind);
        self::assertSame([], $stub->calls);
    }
}

/** The least a framework can offer, so the core is exercised without one. */
final class Req
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly array $headers = [],
        public readonly string $ip = '146.70.116.130',
    ) {
    }
}
