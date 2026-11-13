# [<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="28"/>](https://internetdata.io/) InternetData PHP Client Library

[![Packagist](https://img.shields.io/packagist/v/internetdata/internetdata.svg)](https://packagist.org/packages/internetdata/internetdata)
[![license](https://img.shields.io/packagist/l/internetdata/internetdata.svg)](LICENSE)

The official PHP client library for the [InternetData](https://internetdata.io) API.

It looks up what every InternetData database says about an IP address, an AS or a domain, and downloads and verifies the databases your organization is licensed for.

## Getting Started

```bash
composer require internetdata/internetdata
```

Requires PHP 8.2 or newer. Everything is typed, and the fields a plan leaves out are nullable so an absent answer never reads as a false one.

## Usage

**No API key needed to start.** Unlimited, the free plan, answers the Tor, relay, anycast, carrier, satellite and service flags, `location`, `asn`, `company`, `hostname` and the abuse email, with no daily or monthly cap, with a key or without one.

```php
use InternetData\Client;

$client = new Client();

$result = $client->lookup('146.70.116.130');
echo $result->location->country;   // AT
echo $result->asn->name;           // M247 Europe SRL
echo $result->company->name;       // who uses the address
```

### With an API key

Every paid plan answers every field: the VPN, proxy, hosting and CDN flags as well, the detail behind each flag, the city, both Whois records and how many domains an address hosts. The plans differ only in how many lookups they include, and a free account tries every field, 100 lookups a day. Create a key in the [console](https://app.internetdata.io), then pass it in:

```php
use InternetData\Client;
use InternetData\Options;

$client = new Client(new Options(apiKey: getenv('INTERNETDATA_API_KEY') ?: null));

$result = $client->lookup('146.70.116.130');
$result->isVpn;                // true, on a paid plan
$result->vpn->providerName;    // 'Mullvad', on a paid plan
$result->location->city;       // 'Vienna', on a paid plan
```

### An AS or a domain

```php
$as = $client->lookupAsn(9009);
echo $as->name;          // M247 Europe SRL
echo $as->allocated;     // 2005-06-06
$as->prefixes;           // what it announces, on a paid plan

$domain = $client->lookupDomain('example.com');
$domain->isRegistered;   // true
$domain->hasMx;          // whether it has MX records
$domain->dns->mx;        // the records themselves, on a paid plan
```

Unlike an address, an AS or a domain is never cached and has no batch.

### Your own address

```php
$result = $client->myIp();
echo $result->ip;   // the address we saw this call come from
```

Same answer `lookup` would give for that address, and the same cost against your allowance. It is deliberately not cached: which address you are is the whole question, and a machine that moves between networks would otherwise be told where it used to be.

### Your plan and usage

```php
$acct = $client->myEntitlement();
echo $acct->plan->key;          // the plan your organization is on
echo $acct->usage->requests;    // 580
echo $acct->usage->windowEnd->format('c');   // when the allowance resets
```

Usage counts against the anniversary of your subscription on a paid plan, not the calendar month and not the billing period, and against the UTC day on the free plan; it is the same number a lookup is gated on. `hardLimit` is `null` on an uncapped plan, which is not the same as zero.

### Batch lookup

Look up many addresses at once. Bogons and cached answers are handled locally, and everything else goes to the batch endpoint in chunks of up to 1000 addresses, in parallel. The batch endpoint needs a paid plan:

```php
use InternetData\InternetDataException;

$results = $client->lookupBatch(['146.70.116.130', '8.8.8.8', '1.1.1.1']);

foreach ($results as $ip => $result) {
    if ($result instanceof InternetDataException) {
        echo "{$ip}: {$result->getMessage()}\n";
        continue;
    }
    echo "{$ip}: {$result->location->country}\n";
}
```

Results are keyed by address, in the order you first listed each one, so duplicates in your list collapse into a single entry and one address failing never loses the rest: it carries its error as its value, with the status the API would have given that address on its own.

There's no limit on how many addresses you pass. How many chunks are in flight at once, how many times a failed chunk is retried, and how long each attempt may take are configurable per call:

```php
$results = $client->lookupBatch($manyIps, ['concurrency' => 4, 'retries' => 4, 'timeout' => 10]);
```

### Caching

Answers are cached by default, so repeat lookups of the same address are free:

```php
$client = new Client();

$result = $client->lookup('146.70.116.130');   // API request
$result2 = $client->lookup('146.70.116.130');  // no API request, result was cached
```

You can change the default cache variables (max size, TTL in seconds, etc) on initialization, or even disable it:

```php
$client = new Client(new Options(cacheMaxSize: 50_000, cacheTtlSeconds: 6 * 60 * 60));
$clientNoCache = new Client(new Options(cache: false));
```

The cache lives on the client instance, so how much it buys you depends on how long that instance lives. A worker, a queue consumer or a long-running server gets the full benefit. Under PHP-FPM or mod_php the client is built for every request, so give it a PSR-16 cache that outlives the request, such as your framework's:

```php
$client = new Client(new Options(apiKey: $key, cacheStore: $cache));
```

Entries are keyed by a hash of your API key, the base URL and the address, so two keys never share an answer, and they live `cacheTtlSeconds`. The store's own eviction takes the place of `cacheMaxSize`, and a store that fails is a miss, never a failed lookup.

### Private and reserved addresses

Private, loopback, link-local, documentation and multicast addresses (and their IPv6 equivalents, including the 6to4 and Teredo ranges) aren't on the public internet. The library answers them locally, so they cost no request and no quota:

```php
$result = $client->lookup('192.168.1.1');
$result->isBogon;   // true, this answer was computed rather than served
$result->isVpn;     // false
```

The check is available on the client, which is handy when your inputs are addresses anyway:

```php
$client->isBogon('10.0.0.1');   // true
$client->isBogon('8.8.8.8');    // false
```

It is also callable on its own, if you want it without a client:

```php
use InternetData\Bogon;

Bogon::isBogon('10.0.0.1');   // true
```

### Errors

Failures throw an `InternetDataException` carrying a `kind` and an `isRetryable()` flag:

```php
use InternetData\InternetDataException;

try {
    $client->lookup('1.1.1.1');
} catch (InternetDataException $err) {
    echo $err->kind->value, ' ', $err->isRetryable() ? 'retryable' : 'final', "\n";
}
```

`kind` is one of `bad_request`, `unauthorized`, `forbidden`, `rate_limited`, `quota_exceeded`, `server_error` or `network`. The message is the API's own, and a database call's is its result code, so a refusal says which refusal it was: `NOT_LICENSED` and `LICENSE_EXPIRED` are both a `forbidden`, and only one of them is fixed by renewing.

Note that `rate_limited` and `quota_exceeded` both arrive as HTTP 429 and are not the same thing. A rate limit is when the API faces extreme traffic bursts and so retrying later works; but a spent quota needs your allowance raised or the window to roll over. The library retries rate limits for you, but not if your quota is exceeded. The database API has no quota, so you won't see `quota_exceeded` from it.

### Timeouts and retries

Each attempt gives up after 30 seconds, and a transient failure is retried twice. Both can be changed for the client, and for a single lookup:

```php
$client = new Client(new Options(timeout: 10, retries: 4));

$result = $client->lookup('146.70.116.130', ['timeout' => 2.5, 'retries' => 0]);
```

The timeout is in seconds and applies to each attempt, so a retried call can take longer in total. An attempt that runs out of time fails as a retryable `network` error. A database download isn't bound by it, since a large file takes as long as it takes.

### Database downloads

Every database call needs a key carrying the `db.download` scope, which you create in the [console](https://app.internetdata.io). The Open databases are licensed to every organization from the start and are free under [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/), and `list()` marks them `open`. The rest are licensed by contract; if your organization doesn't have a license yet, [request an evaluation](https://internetdata.io/contact?purpose=evaluation&subject=Evaluation+license). Then:

```php
foreach ($client->database->list() as $database) {
    echo "{$database->base}: {$database->standing}\n";   // bogon_ip: licensed
}
```

Every database call hangs off `$client->database`, which is where the sibling VPNDetection library keeps the same seven calls.

A license is held against a database FAMILY, while a download names one version, so the IDs you pass everywhere else come from `versions`:

```php
$database->base;                    // 'bogon_ip'
$database->versions[0]->id;         // 'bogon_ip_v1', the ID a download takes
$database->versions[0]->formats;    // ['csvgz', 'mmdb']
$database->licenseType;             // 'standard', or null when there is no license
```

#### Downloading

`download` streams a file straight to disk, so nothing bigger than a chunk is ever held in memory whatever the database weighs. It writes through a neighboring `.part` file and renames on success, so a transfer that dies half way leaves no truncated file that reads as a whole database:

```php
$written = $client->database->download('bogon_ip_v1', 'mmdb', '/srv/data/bogon_ip_v1.mmdb');
echo "{$written} bytes";
```

Pass a stream you opened instead of a path, and it stays yours to close:

```php
$handle = fopen('php://temp', 'w+b');
$client->database->download('bogon_ip_v1', 'csvgz', $handle);
```

`downloadUrl` hands back the time-limited link the API redirects to, without following it. The link authorizes itself, so it carries none of your credentials and you can pass it to whatever should run the transfer:

```php
$url = $client->database->downloadUrl('bogon_ip_v1', 'mmdb');
```

`downloadBytes` returns the file as a string. It holds the whole thing in memory, and the catalog spans seven orders of magnitude, so check `metadata` first for anything you have not measured: past your `memory_limit` this is a fatal error, not merely a slow one.

```php
$bytes = $client->database->downloadBytes('bogon_asn_v1', 'csvgz');
```

Each of the three downloads the build that is live unless you name one. A `database.updated` webhook names the build it announces and carries its `sha256`, so a receiver that downloads after the next publish asks for that build to get the file the hash is of:

```php
$client->database->download('vpn_ip_v1', 'mmdb', './vpn_ip_v1.mmdb', $event['data']['build']);
```

A build is kept 7 days. Past that the call throws `bad_request` with the message `BUILD_GONE`, and downloading without a build fetches the live one. A name that is not a build's shape (`20261105.0t53s5`) throws `InvalidArgumentException` before anything is sent.

#### What is inside, and whether it changed

`metadata` answers the schema, a few real rows, the row count and the size of every published format, without moving the file. Poll it to decide whether today's build is worth fetching, and to budget a transfer before you start it:

```php
$meta = $client->database->metadata('bogon_ip_v1');

$meta->updated->format('Y-m-d');   // '2026-09-04'
$meta->entries;                    // 44
$meta->size['csvgz'];              // 760
$meta->schema['csvgz'][0]->name;   // 'start_ip'
```

`checksums` publishes four digests per file, so a download can be verified against what the API says it served:

```php
$sums = $client->database->checksums('bogon_ip_v1', 'csvgz');

hash_file('sha256', '/srv/data/bogon_ip_v1.csv.gz') === $sums->sha256;   // true
```

#### Download history

`downloads` lists your organization's recent attempts, newest first. Refusals are listed too, because a denial is what answers "it stopped working" and its absence answers nothing:

```php
foreach ($client->database->downloads(20) as $attempt) {
    echo "{$attempt->created->format('c')} {$attempt->databaseId} {$attempt->outcome}\n";
}
```

#### What your key can see

`list` is the catalog **as the API served it for your key**, and the library keeps no copy of its own.

### Sign in with OAuth (device flow)

A program running on the person's own machine can let them sign in with a browser and pick one of their API keys, instead of asking them to paste it:

```php
$client = new Client();

$device = $client->oauth->deviceAuthorization('your-client-id', [
    'scope' => 'account.read apikeys.read apikeys.reveal',
]);
echo "Open {$device->verificationUri} and enter {$device->userCode}\n";

$token = $client->oauth->pollDeviceToken('your-client-id', $device);
if ($token->apikey === null) {
    throw new RuntimeException("no API key came back: none was picked, or it can't be shown again");
}
$keyed = new Client(new Options(apiKey: $token->apikey));
```

A denied sign-in throws `OauthAccessDeniedException` and a code that ran out `OauthExpiredTokenException`. Client IDs are issued on request from support@internetdata.io, and `$client->oauth->revoke('your-client-id', $token->refreshToken)` signs the machine out again.

### Sign in with OAuth (authorization code)

An app that can take a browser redirect signs the person in there instead, with a PKCE pair made for that one sign-in:

```php
$client = new Client();
$redirectUri = 'http://127.0.0.1:8765/callback';
$pkce = $client->oauth->createPkce();

$url = $client->oauth->authorizationUrl('your-client-id', $redirectUri, $pkce->challenge, [
    'scope' => 'apikeys.use', 'state' => 'your-state',
]);
// Open $url in the browser. Its redirect to $redirectUri carries code and state.
$token = $client->oauth->exchangeAuthorizationCode('your-client-id', $code, $pkce->verifier, $redirectUri);
```

Check that `state` came back as you sent it before you exchange `code`, which works once. The client ID can also be the https URL of a client metadata document your app serves, and such an app is never handed a key, so `$token->apikey` stays `null`.

### Register a client

An app with no client ID, and no client metadata document to use as one, registers itself, then signs in with the `clientId` it gets back as the client ID for `authorizationUrl` and `exchangeAuthorizationCode`:

```php
$client = new Client();
$registration = $client->oauth->register(['http://127.0.0.1:8765/callback'], ['clientName' => 'Your App']);
```

Each redirect URI must be https on a public host name, or http on a loopback address, which then matches any port. Nothing is kept until a person approves the client, so an app with no client ID saved can simply register again.

### Absent is not false

Unlimited leaves out the VPN, proxy, hosting and CDN flags, the detail behind each flag but Tor's and relay's, the city, both Whois records and the hosted domains; a free-plan key's answer carries `trialExpired`, true once its daily trial is spent. A field your key does not include is `null`, which is not the same answer as `false`: `null` means "not in your plan", `false` means "checked, and no".

```php
$result->isVpn ?? false;          // when you only want the flag
$result->isVpn === null;          // on Unlimited: not in your plan
$result->location->city;          // null on Unlimited, never ''
$result->trialExpired === true;   // a free-plan key's daily trial is spent
```

## Other Libraries

There are official InternetData client libraries available for many languages including PHP, Python, Go, Java, and Ruby. See our GitHub at https://github.com/internetdata for more.

## About InternetData

Geolocation, anonymity, ownership and network data for IP addresses, AS numbers and domains, looked up through one API or licensed as files you download and query yourself.

[<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="64"/>](https://internetdata.io/)

## License

This project is licensed under the [MIT License](LICENSE).
