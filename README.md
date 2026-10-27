# FlowSpeech PHP

## 0.2.1 Security Follow-up

If a response body is truncated or times out after headers arrive, the default transport retains HTTP status and Retry-After. Permanent HTTP failures do not retry; transient retries respect the server cooldown and total deadline. Diagnostics remain local and recovery IDs are retained.

PHP client for the FlowSpeech public API v1. Requires PHP 8.1+,
JSON and cURL for the default HTTPS transport.

## Install and Authenticate

```bash
composer require flowspeech/flowspeech-php:^0.2.1
```

Version 0.2.1 is a prepared release candidate, not a publication claim.
Its fixes are not included in the previously published 0.2.0 package.

An active paid Basic, Pro or Scale subscription and a developer API key from
[API settings](https://flowspeech.io/settings/apikeys) are required. Trial,
browser-login and plugin-scoped tokens do not grant public API access.
Keep the key server-side in FLOWSPEECH_API_KEY; never expose it to a browser.

```php
use FlowSpeech\FlowSpeechClient;

$client = new FlowSpeechClient((string) getenv('FLOWSPEECH_API_KEY'));
$quota = $client->getQuota(); // quota, subscription and limits, at the top level
$voices = $client->getVoices(); // voices, at the top level
$requestId = FlowSpeechClient::newRequestId(); // persist BEFORE submission
$job = $client->createJob([
    'mode' => 'single', 'text' => 'Hello.', 'voiceName' => 'Kore',
], $requestId);
$job = $client->waitForJob($job);
file_put_contents('speech.wav', $client->downloadResult($job));
```

## Compatibility Helpers

Existing method signatures remain available:

```php
$result = $client->generateSpeech('Hello.', [['voiceName' => 'Kore']], $requestId);
file_put_contents('speech.wav', base64_decode($result['data']['audioBase64'], true));

$result = $client->generateMultiSpeakerSpeech(
    "Alice: Welcome.\nBob: Thank you.",
    [['speaker' => 'Alice', 'voiceName' => 'Kore'],
     ['speaker' => 'Bob', 'voiceName' => 'Puck']],
);
```

The multi-speaker helper requires one labeled turn per nonempty line. It rejects
unlabeled text and unknown or duplicate speaker labels. For arbitrary text or
multiline turns, use generateSegments() with ordered speaker/text/voiceName
objects. The same speaker must use the same voice.

Generation helpers submit, poll and download within one total deadline. They
return the completed v1 job (id, requestId, status, result, usage) plus a **locally
generated** data.audioBase64 compatibility field. v1 itself returns neither a
legacy code/data envelope nor base64 audio. The downloaded WAV is not re-encoded:
24,000 Hz, mono, 16 bit. model, source, speed, format and legacy speaker options
are unsupported and rejected rather than silently forwarded.

## Recovery and Errors

- createJob(input, requestId), getJob(jobId), findJob(requestId), cancelJob(jobId).
- waitForJob(jobOrId) polls queued/running/merging/settling; failed/cancelled throw.
- downloadResult(job) retries only that result; getAudio(jobId) reads and downloads
  an existing job within one shared deadline and returns binary and job.
- HTTP 200 is not generation success. Unknown states, missing IDs and malformed
  results are rejected. A deleted result throws errorCode=result_deleted.
- FlowSpeechException exposes errorCode, HTTP getCode(), retryable, traceId,
  requestId, jobId and retryAfterSeconds. Client getters expose the last IDs.
- Persist the UUID before submitting. Reuse the exact input and UUID after a
  timeout, or use findJob(). A 404 lookup permits replay with the same UUID/body.
  A different UUID starts a new recording and may consume credits.
- Retry a failed download with downloadResult() or getAudio(), never generateSpeech().
  Stopping polling does not cancel the server job.

## Timeouts and Transport

### Error Privacy Hardening

HTTP and terminal-job messages use fixed local diagnostics, never remote text.
Error codes and trace IDs are retained only when they are 1-128 ASCII letters,
digits, underscores or hyphens and do not contain this client's API key.
Invalid codes use a local fallback; invalid trace IDs become null. Job error
objects retain a safe message and boolean retryable; error: null remains null.
HTTP status, Retry-After and recovery IDs remain available.

Exception strings retain frame names but omit arguments, which can contain
signed URLs. Do not dump raw exception traces or client/transport objects;
configure zend.exception_ignore_args=1 when collecting PHP diagnostic traces.
These safeguards do not sanitize application-owned custom transport exceptions.

Constructor: (apiKey, baseUrl='https://flowspeech.io', timeoutSeconds=120,
transport=null, options=[]). The third argument is now a total deadline per
high-level operation; separate public method calls each start a new deadline.
Options: request_timeout (30s), max_retries (2), poll_interval (at least 5s),
max_download_bytes (32 MiB). clock and sleep callables support deterministic tests.

Retries are bounded, retain the serialized body and UUID, and honor Retry-After
(seconds or HTTP date), including 429 responses. Each request is capped by the
remaining deadline. Redirects are refused for both API and audio requests.
Only HTTPS URLs without embedded credentials/fragments are accepted. Audio
downloads use a fresh credential-free request, TLS verification and a byte cap.

A custom callable transport receives method/url/headers/body/timeout/maxBytes and
returns status/headers/body. It must enforce bounds, TLS, no redirects and no
implicit auth/cookies; transient failures should throw a retryable
FlowSpeechException. The built-in production transport uses cURL.
Result links grant access to anyone holding them; do not log them.

## Verify and Package

```bash
php tests/contract.php
node scripts/check-error-output.mjs
node scripts/build-runtime.mjs
# Install Composer dev dependencies into .tools/phpunit/vendor, then:
node scripts/run-transport.mjs
node scripts/build.mjs
FLOWSPEECH_TEST_ROOT="$PWD/dist/verify/flowspeech-php" php tests/contract.php
FLOWSPEECH_TEST_ROOT="$PWD/dist/verify/flowspeech-php" node scripts/check-error-output.mjs
```

Tests use fake HTTP only, with no credentials, generation or paid credits.
The loopback suite uses real PHPUnit and the production cURL implementation
against local HTTP/HTTPS servers, including certificate verification. It does
not mock cURL. The older curl-transport.php shim suite requires a PHP binary
without the cURL extension and is not the real transport gate.
The archive is built from an explicit allowlist and excludes tests, local tools,
VCS metadata and release reports.

The isolated runtime builder pins and verifies official PHP 8.4.27, OpenSSL
3.5.9 and Composer 2.10.3 downloads. It installs only under this SDK's .tools;
the system PHP and global tools are not changed. Composer/PHPUnit installation:

```bash
COMPOSER_HOME="$PWD/.tools/composer-home" \
COMPOSER_CACHE_DIR="$PWD/.tools/composer-cache" \
COMPOSER_VENDOR_DIR="$PWD/.tools/phpunit/vendor" \
TMPDIR="$PWD/.tools/tmp" \
.tools/php/bin/php .tools/composer.phar install --no-interaction --prefer-dist --no-scripts --no-plugins
.tools/php/bin/php .tools/composer.phar validate --strict
```

The local lock records PHPUnit 11.5.57. Keep the lock for repeatable test installs.
Fixture certificates and private keys remain under ignored .tools and are never
packaged. The runner binds only 127.0.0.1, trusts only its test CA for local
transfers and closes all listeners on completion.

[API documentation](https://flowspeech.io/docs/public-api) |
[OpenAPI](https://flowspeech.io/openapi.json). MIT license.
