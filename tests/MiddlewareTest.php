<?php

declare(strict_types=1);

namespace InternetData\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use InternetData\Client;
use InternetData\ErrorKind;
use InternetData\InternetDataException;
use InternetData\Middleware\Condition;
use InternetData\Middleware\Core;
use InternetData\Middleware\Lookup;
use InternetData\Middleware\Options as MwOptions;
use InternetData\Middleware\RequestView;
use InternetData\Middleware\Selectors;
use InternetData\Options;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

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

    // A bot is a paid answer's: a condition on `isBot` is judged on a paid answer, and the
    // `bot` row reaches a condition by its members.
    public function testABotConditionBlocksABotsAddressAndPassesAnother(): void
    {
        $bot = self::answer('paid-bot');
        $core = fn (object $body, array $condition): Core => self::core(new MwOptions(
            client: self::client(self::serving($body)),
            blockCondition: $condition,
            onMissingField: 'throw',
        ));
        $visitor = fn (object $body): Req => new Req(ip: $body->ip);

        self::assertTrue($core($bot, ['isBot' => true])->evaluate($visitor($bot))?->blocked);
        self::assertTrue($core($bot, ['bot' => ['verified' => true]])->evaluate($visitor($bot))?->blocked);
        $unlimited = self::answer('unlimited-answer');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/isBot, which your plan does not include/');
        $core($unlimited, ['isBot' => true])->evaluate($visitor($unlimited));
    }

    // Unlimited leaves out the eight flags `Result` holds at false, so a condition on one is a
    // plan gap though it reads false; the same flag served false on a paid answer is no gap.
    public function testAHeldFlagTheAnswerLeavesOutIsReportedAsAPlanGap(): void
    {
        $flags = [
            'isBot', 'isCarrier', 'isSatellite', 'isSsh', 'isSmtp', 'isWeb', 'isDnsResolver', 'isBittorrent',
        ];
        $unlimited = self::answer('unlimited-answer');
        $paid = self::answer('paid-full-shape');
        foreach ($flags as $flag) {
            $warnings = [];
            $core = self::core(new MwOptions(
                client: self::client(self::serving($unlimited)),
                blockCondition: [$flag => true],
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ));
            $lookup = $core->evaluate(new Req(ip: $unlimited->ip));
            self::assertFalse($lookup?->result?->{$flag}, "{$flag} reads false");
            self::assertFalse($lookup->blocked, $flag);
            self::assertCount(1, $warnings, $flag);
            self::assertStringContainsString("blockCondition names {$flag},", $warnings[0]);

            $warnings = [];
            $core = self::core(new MwOptions(
                client: self::client(self::serving($paid)),
                blockCondition: [$flag => true],
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ));
            $core->evaluate(new Req(ip: $paid->ip));
            self::assertSame([], $warnings, "{$flag} is served on a paid answer, even as false");
        }
    }

    // Unlimited leaves out the AS's `type`, which `Asn` holds at '': a condition on it is a plan
    // gap and never matches, and the same condition on a paid answer that serves it is no gap.
    public function testAnAsnTypeTheAnswerLeavesOutIsReportedAsAPlanGapAndNeverMatches(): void
    {
        $unlimited = self::answer('unlimited-answer');
        $paid = self::answer('paid-full-shape');
        $condition = ['asn' => ['type' => ['hosting', 'business']]];

        $warnings = [];
        $core = self::core(new MwOptions(
            client: self::client(self::serving($unlimited)),
            blockCondition: $condition,
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ));
        $lookup = $core->evaluate(new Req(ip: $unlimited->ip));
        self::assertSame('', $lookup?->result?->asn->type);
        self::assertFalse($lookup->blocked);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('blockCondition names asn.type,', $warnings[0]);

        $warnings = [];
        $core = self::core(new MwOptions(
            client: self::client(self::serving($paid)),
            blockCondition: $condition,
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ));
        $lookup = $core->evaluate(new Req(ip: $paid->ip));
        self::assertSame('hosting', $lookup?->result?->asn->type);
        self::assertTrue($lookup->blocked);
        self::assertSame([], $warnings);
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

    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    /** @return array<string, mixed> An answer for the public peer carrying this verdict, or none. */
    private static function judged(?string $verdict, bool $isHosting = true): array
    {
        $members = ['is_hosting' => $isHosting];
        if ($verdict !== null) {
            $members['user_agent'] = ['agent' => 'Googlebot', 'kind' => 'bot', 'verdict' => $verdict];
        }
        return Stub::answer(self::PUBLIC_IP, $members);
    }

    /** @return array<string, mixed> */
    private static function sentQuery(Stub $stub): array
    {
        parse_str($stub->requests[0]->getUri()->getQuery(), $query);
        return $query;
    }

    public function testAllowBotsSendsTheRequestsOwnUserAgent(): void
    {
        $stub = self::serving(self::judged('verified'));
        $request = new Req(['user-agent' => self::GOOGLEBOT]);
        $lookup = self::core(new MwOptions(client: self::client($stub), allowBots: true))
            ->evaluate($request, $request->headers['user-agent']);

        self::assertSame(['/' . self::PUBLIC_IP], $stub->calls, 'a public peer, looked up');
        self::assertSame(['user_agent' => self::GOOGLEBOT], self::sentQuery($stub));
        self::assertSame('verified', $lookup?->result?->userAgent?->verdict);
    }

    public function testAllowBotsOffSendsNoUserAgent(): void
    {
        $stub = self::serving(self::judged(null));
        self::core(new MwOptions(client: self::client($stub)))->evaluate(new Req(), self::GOOGLEBOT);

        self::assertSame(['/' . self::PUBLIC_IP], $stub->calls);
        self::assertSame([], self::sentQuery($stub));
    }

    public function testAnAbsentOrEmptyUserAgentSendsNoneAndWarnsNothing(): void
    {
        foreach ([null, ''] as $agent) {
            $warnings = [];
            $stub = self::serving(self::judged(null));
            self::core(new MwOptions(
                client: self::client($stub),
                allowBots: true,
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ))->evaluate(new Req(), $agent);

            self::assertSame([], self::sentQuery($stub), var_export($agent, true));
            self::assertSame([], $warnings, var_export($agent, true));
        }
    }

    public function testAVerifiedCrawlerPassesAConditionItOtherwiseMeets(): void
    {
        foreach ([['verified', false], ['spoofed', true], ['not_bot', true], ['unverifiable', true], [null, true]] as [$verdict, $blocked]) {
            $stub = self::serving(self::judged($verdict));
            $lookup = self::core(new MwOptions(
                client: self::client($stub),
                blockCondition: ['isHosting' => true],
                allowBots: true,
                onMissingField: 'ignore',
            ))->evaluate(new Req(), self::GOOGLEBOT);

            $why = var_export($verdict, true);
            self::assertSame($blocked, $lookup?->blocked, $why);
            self::assertTrue($lookup->result?->isHosting, "{$why}: the answer is still attached");
        }
    }

    public function testWithAllowBotsOffAVerifiedAnswerIsStillRefused(): void
    {
        $stub = self::serving(self::judged('verified'));
        $lookup = self::core(new MwOptions(client: self::client($stub), blockCondition: ['isHosting' => true]))
            ->evaluate(new Req(), self::GOOGLEBOT);

        self::assertTrue($lookup?->blocked);
    }

    public function testAConditionNamingTheUserAgentWorksWithAllowBots(): void
    {
        foreach ([['spoofed', true], ['not_bot', false], ['verified', false]] as [$verdict, $blocked]) {
            $stub = self::serving(self::judged($verdict, isHosting: false));
            $lookup = self::core(new MwOptions(
                client: self::client($stub),
                blockCondition: ['userAgent' => ['verdict' => 'spoofed']],
                allowBots: true,
            ))->evaluate(new Req(), self::GOOGLEBOT);

            self::assertSame($blocked, $lookup?->blocked, $verdict);
        }
    }

    public function testAnAnswerWithoutAVerdictWarnsOnceOrThrows(): void
    {
        $warnings = [];
        $stub = self::serving(self::judged(null, isHosting: false));
        $core = self::core(new MwOptions(
            client: self::client($stub),
            allowBots: true,
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ));
        $core->evaluate(new Req(), self::GOOGLEBOT);
        $core->evaluate(new Req(), self::GOOGLEBOT);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('user_agent', $warnings[0]);

        $quiet = [];
        self::core(new MwOptions(
            client: self::client($stub),
            allowBots: true,
            onMissingField: 'ignore',
            onWarn: function (string $m) use (&$quiet): void {
                $quiet[] = $m;
            },
        ))->evaluate(new Req(), self::GOOGLEBOT);
        self::assertSame([], $quiet);

        $this->expectException(\RuntimeException::class);
        self::core(new MwOptions(client: self::client($stub), allowBots: true, onMissingField: 'throw'))
            ->evaluate(new Req(), self::GOOGLEBOT);
    }

    private const PEER = '66.249.66.1';
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';

    private static function crawler(int $n): string
    {
        return "Mozilla/5.0 (compatible; Examplebot/{$n}.0)";
    }

    /** @return array<string, mixed> */
    private static function crawlerCorpus(): array
    {
        return self::corpus()['crawlerAgents'];
    }

    /**
     * A bot's address, answering `verified` to any agent that arrives, with `$extra` members
     * on every answer, and recording the agent each lookup carried (null for none).
     *
     * @param list<?string> $agents
     * @param array<string, mixed> $extra
     * @param string $verdict What an agent that arrives is judged
     */
    private static function botsAddress(array &$agents, array $extra = [], string $verdict = 'verified'): Client
    {
        $handler = function (RequestInterface $request) use (&$agents, $extra, $verdict) {
            parse_str($request->getUri()->getQuery(), $query);
            $agent = $query['user_agent'] ?? null;
            $agents[] = $agent;
            $body = Stub::answer(ltrim($request->getUri()->getPath(), '/'), ['is_bot' => true] + $extra);
            if ($agent !== null) {
                $body['user_agent'] = ['agent' => 'Googlebot', 'kind' => 'bot', 'verdict' => $verdict];
            }
            return Create::promiseFor(new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($body, JSON_THROW_ON_ERROR),
            ));
        };
        return new Client(new Options(
            cache: false,
            retries: 0,
            httpClient: new GuzzleClient(['handler' => HandlerStack::create($handler)]),
        ));
    }

    public function testOnlyAnAgentNamingACrawlerIsSentAsTheCorpusSays(): void
    {
        $corpus = self::crawlerCorpus();
        self::assertNotEmpty($corpus['crawlers']);
        self::assertNotEmpty($corpus['others']);
        foreach ($corpus['crawlers'] as $agent) {
            self::assertTrue(Core::namesCrawler($agent), $agent);
        }
        foreach ($corpus['others'] as $agent) {
            self::assertFalse(Core::namesCrawler($agent), $agent);
        }
        foreach ($corpus['terms'] as $term) {
            self::assertTrue(Core::namesCrawler('x ' . strtoupper($term) . ' y'), $term);
        }

        $agents = [];
        $lookup = self::core(new MwOptions(
            client: self::botsAddress($agents),
            blockCondition: ['isBot' => true],
            allowBots: true,
            onMissingField: 'ignore',
        ))->evaluate(new Req(ip: self::PEER), self::CHROME);
        self::assertSame([null], $agents, 'a browser\'s agent stays off the lookup');
        self::assertTrue($lookup?->blocked);
    }

    public function testOneAddressIsLookedUpWithAtMostFourCrawlerAgentsAndNonePassesPastThem(): void
    {
        self::assertSame(4, Core::MAX_AGENTS_PER_ADDRESS);
        $agents = [];
        $core = self::core(new MwOptions(
            client: self::botsAddress($agents),
            blockCondition: ['isBot' => true],
            allowBots: true,
            onMissingField: 'ignore',
        ));
        $eval = fn (int $n, string $ip = self::PEER): ?Lookup => $core->evaluate(new Req(ip: $ip), self::crawler($n));

        for ($n = 1; $n <= 4; $n++) {
            self::assertFalse($eval($n)?->blocked, "agent {$n}");
        }
        $past = $eval(5);
        self::assertTrue($past?->blocked, 'an agent past the bound is never let through as verified');
        self::assertNull($past->result?->userAgent);
        self::assertFalse($eval(1)?->blocked, 'a known agent is still sent');
        self::assertSame(
            [self::crawler(1), self::crawler(2), self::crawler(3), self::crawler(4), null, self::crawler(1)],
            $agents,
        );

        self::assertFalse($eval(5, '66.249.66.2')?->blocked, 'each address has its own bound');
        self::assertSame(self::crawler(5), $agents[array_key_last($agents)]);
    }

    public function testPastTheBoundAnAddressItsOperatorVouchesForStillPasses(): void
    {
        $agents = [];
        $core = self::core(new MwOptions(
            client: self::botsAddress($agents, ['bot' => ['verified' => true]]),
            blockCondition: ['isBot' => true],
            allowBots: true,
            onMissingField: 'ignore',
        ));
        for ($n = 1; $n <= 5; $n++) {
            self::assertFalse($core->evaluate(new Req(ip: self::PEER), self::crawler($n))?->blocked, "agent {$n}");
        }
        self::assertNull($agents[array_key_last($agents)]);
        self::assertTrue(
            $core->evaluate(new Req(ip: self::PEER), self::CHROME)?->blocked,
            'a browser on that address is judged as the condition says',
        );
    }

    public function testAConditionOnTheVerdictIsNoPlanGapForARequestThatSentNoAgent(): void
    {
        $forged = ['userAgent' => ['verdict' => 'spoofed']];
        foreach ([self::CHROME, null] as $header) {
            $warnings = [];
            $agents = [];
            $lookup = self::core(new MwOptions(
                client: self::botsAddress($agents, verdict: 'spoofed'),
                blockCondition: $forged,
                allowBots: true,
                onMissingField: 'throw',
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ))->evaluate(new Req(ip: self::PEER), $header);
            self::assertFalse($lookup?->blocked, var_export($header, true));
            self::assertSame([null], $agents);
            self::assertSame([], $warnings);
        }

        $agents = [];
        $core = self::core(new MwOptions(
            client: self::botsAddress($agents, verdict: 'spoofed'),
            blockCondition: $forged,
            allowBots: true,
            onMissingField: 'throw',
        ));
        for ($n = 1; $n <= 4; $n++) {
            $core->evaluate(new Req(ip: self::PEER), self::crawler($n));
        }
        self::assertFalse(
            $core->evaluate(new Req(ip: self::PEER), self::crawler(5))?->blocked,
            'past the bound, no gap either',
        );

        $warned = [];
        $stub = [];
        self::core(new MwOptions(
            client: self::botsAddress($stub, verdict: 'spoofed'),
            blockCondition: $forged,
            onWarn: function (string $m) use (&$warned): void {
                $warned[] = $m;
            },
        ))->evaluate(new Req(ip: self::PEER));
        self::assertCount(1, $warned, 'off, a condition on the verdict can never match, and says so');
    }

    public function testABogonPeerIsAnsweredLocallyAndLetsNothingThrough(): void
    {
        $warnings = [];
        $stub = self::serving(self::judged('verified'));
        $lookup = self::core(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Req $r): string => '10.0.0.1',
            blockCondition: ['isBogon' => true],
            allowBots: true,
            onWarn: function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ))->evaluate(new Req(), self::GOOGLEBOT);

        self::assertSame([], $stub->calls);
        self::assertTrue($lookup?->blocked);
        self::assertCount(1, $warnings, 'the private-address warning alone');
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
