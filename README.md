# [<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" height="28"/>](https://internetdata.io/) InternetData PHP Client Library

[![Packagist](https://img.shields.io/packagist/v/internetdata/internetdata.svg)](https://packagist.org/packages/internetdata/internetdata)
[![license](https://img.shields.io/packagist/l/internetdata/internetdata.svg)](LICENSE)

The official PHP client library for the [InternetData](https://internetdata.io) database API.

The library downloads and verifies the IP, ASN and domain databases your organization is licensed for, and tells you what is in each one before you fetch it.

## Getting Started

```bash
composer require internetdata/internetdata
```

Requires PHP 8.1 or newer.

## Usage

Every database is licensed by contract, so you always start with a key carrying the `db.download` scope. Write to [dev@internetdata.io](mailto:dev@internetdata.io) for one, then:

```php
use InternetData\Client;
use InternetData\Options;

$client = new Client(new Options(apiKey: getenv('INTERNETDATA_API_KEY')));

foreach ($client->database->list() as $database) {
    echo "{$database->base}: {$database->standing}\n";   // bogon_ip: licensed
}
```

Every call hangs off `$client->database`, which is the whole of this API and is where the sibling VPNDetection library keeps the same seven calls.

A licence is held against a database FAMILY, while a download names one version, so the ids you pass everywhere else come from `versions`:

```php
$database->base;                    // 'bogon_ip'
$database->versions[0]->id;         // 'bogon_ip_v1', the id a download takes
$database->versions[0]->formats;    // ['csvgz', 'mmdb']
$database->licenseType;             // 'standard', or null when there is no licence
```

### Downloading

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

### What is inside, and whether it changed

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

### Download history

`downloads` lists your organization's recent attempts, newest first. Refusals are listed too, because a denial is what answers "it stopped working" and its absence answers nothing:

```php
foreach ($client->database->downloads(20) as $attempt) {
    echo "{$attempt->created->format('c')} {$attempt->databaseId} {$attempt->outcome}\n";
}
```

### Errors

Failures throw an `InternetDataException` carrying a `kind` and an `isRetryable()` flag:

```php
use InternetData\InternetDataException;

try {
    $client->database->download('bogon_ip_v1', 'mmdb', '/srv/data/bogon_ip_v1.mmdb');
} catch (InternetDataException $err) {
    echo $err->kind->value, ' ', $err->isRetryable() ? 'retryable' : 'final', "\n";
}
```

`kind` is one of `bad_request`, `unauthorized`, `forbidden`, `rate_limited`, `quota_exceeded`, `server_error` or `network`. The message is the API's own result code where it sent one, so a refusal says which refusal it was: `NOT_LICENSED` and `LICENSE_EXPIRED` are both a `forbidden`, and only one of them is fixed by renewing.

Note that `rate_limited` and `quota_exceeded` both arrive as HTTP 429 and are not the same thing. A rate limit is the API protecting itself under a burst, so retrying later works; a spent quota needs your allowance raised or the window to roll over. The library retries rate limits for you, and never retries a spent quota.

Retries are configurable, and only ever repeat a request that could still succeed:

```php
$client = new Client(new Options(apiKey: $key, retries: 4));
```

### What your key can see

`list` is the catalog **as the API served it for your key**, and the library keeps no copy of its own.

## Other Libraries

There are official InternetData client libraries available for many languages including PHP, Python, Go, Java, Ruby, and many popular frameworks such as Django, Rails, and Laravel. See our GitHub at https://github.com/internetdata for more.

## About InternetData

IP, ASN and Domain data to reveal unique insights about the internet. APIs, databases and live feeds available.

[<img src="https://s3.internetdata.io/internetdata-public/brand/mark.svg" alt="InternetData" width="96"/>](https://internetdata.io/)

## License

This project is licensed under the [MIT License](LICENSE).
