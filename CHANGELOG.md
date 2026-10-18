# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff. Releases before 2.2.2 are described by their release commits.

## 2.7.0 - 2026-10-18

### Features

- Add oauth->register, for RFC 7591 dynamic client registration ([`9719b8e`](https://github.com/internetdata/sdk-php/commit/9719b8e58c2e24fc27e89ed72fff908c53a62320))

## 2.6.1 - 2026-10-17

### Fixes

- Refuse a database answer whose member has the wrong type ([`c7f4e69`](https://github.com/internetdata/sdk-php/commit/c7f4e693b196dfeeb893210cc8fa7b19df6b6123))

## 2.6.0 - 2026-10-17

### Features

- Re-pin the spec to 2026.10.16: dynamic client registration ([`bc7e343`](https://github.com/internetdata/sdk-php/commit/bc7e3436fa6064ad66bb9dcd7899a0c6d258d063))

## 2.5.5 - 2026-10-16

### Fixes

- Re-pin the spec to 2026.10.15: three descriptions in US spelling ([`f4205c7`](https://github.com/internetdata/sdk-php/commit/f4205c74136755aca75f3df9a60885251bf35dd9))

## 2.5.4 - 2026-10-15

### Fixes

- Retry an unreadable answer to OAuth discovery and device authorization ([`9607f30`](https://github.com/internetdata/sdk-php/commit/9607f304e25c0166ceb636e13f6c0070456a3aca))

## 2.5.3 - 2026-10-13

### Fixes

- Re-pin the spec to 2026.10.13: Open databases come with a license ([`3391523`](https://github.com/internetdata/sdk-php/commit/33915239c4a6b025bafd21723f5b4a7f8f579dc8))

## 2.5.2 - 2026-10-10

### Fixes

- Send the API key trimmed, none for blanks, and refuse a control character ([`4881642`](https://github.com/internetdata/sdk-php/commit/4881642ed72a35d790574977a2e05227c39da768))
- Read a Retry-After date only when it names a real day ([`b7e996c`](https://github.com/internetdata/sdk-php/commit/b7e996c0fcf8e71b3a4ee9ed66739d9c80778c4a))

## 2.5.1 - 2026-10-10

### Fixes

- Re-pin the spec to 2026.10.09: rotating a key needs apikeys.reveal ([`5e813dc`](https://github.com/internetdata/sdk-php/commit/5e813dc4aa2615c52b6b9ae645a5067022c196f7))

## 2.5.0 - 2026-10-09

### Features

- Re-pin the spec to 2026.10.08, adding the Open databases' open flag ([`d9671ee`](https://github.com/internetdata/sdk-php/commit/d9671eec88363cd8803556a43bd9a3924f509560))

### Fixes

- Carry renews_at and notice_due_at on Database ([`93266f1`](https://github.com/internetdata/sdk-php/commit/93266f1f8c2fb1617cfac9d20944d18aaec85253))

## 2.4.3 - 2026-10-08

### Fixes

- Retry an unreadable download link answer, as a server_error ([`42d0be9`](https://github.com/internetdata/sdk-php/commit/42d0be9a99af70dcc13180c7ed6703680b1ee7ac))

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
