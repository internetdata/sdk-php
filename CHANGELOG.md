# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff. Releases before 2.2.2 are described by their release commits.

## 2.4.2 - 2026-10-07

### Fixes

- Retry a database answer the call cannot read, as a server_error ([`a59828d`](https://github.com/internetdata/sdk-php/commit/a59828d1d0bbc81fb88a2f29d7ca26fa2739680c))
- Read a Retry-After as seconds or an HTTP date, and nothing else ([`d2d9c89`](https://github.com/internetdata/sdk-php/commit/d2d9c89e7a72100eb328d3445fcc930057228db1))

## 2.4.1 - 2026-10-04

### Fixes

- Re-pin the spec to 2026.10.03: metadata needs no license ([`614b615`](https://github.com/internetdata/sdk-php/commit/614b615b3e37a9ef76f43d9e1dbf29bf3cd5f3d7))

## 2.4.0 - 2026-09-29

### Features

- Add the authorization code sign-in, with PKCE ([`89af9ae`](https://github.com/internetdata/sdk-php/commit/89af9aee7f75c8d396126eedb850c10f500e33a1))

## 2.3.1 - 2026-09-29

### Fixes

- README: link the evaluation request, not a mailbox ([`0aae01d`](https://github.com/internetdata/sdk-php/commit/0aae01d6cef635c56c10e3351f74cd6b97f02bbf))

## 2.3.0 - 2026-09-27

### Features

- Re-pin the spec to 2026.09.26, adding its evaluation-sample fields ([`c12f5bd`](https://github.com/internetdata/sdk-php/commit/c12f5bdf1287bc3a65d065de42f978642e1f786f))

## 2.2.2 - 2026-09-25

### Fixes

- Bound Retry-After and the poll's sleep, refuse a timeout where set ([`25ff15d`](https://github.com/internetdata/sdk-php/commit/25ff15d661fb68946f0b0ac588a1fa95e04bf007))
