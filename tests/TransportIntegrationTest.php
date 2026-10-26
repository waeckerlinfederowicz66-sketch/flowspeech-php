<?php
declare(strict_types=1);

namespace FlowSpeech\Tests;

use FlowSpeech\FlowSpeechClient;
use FlowSpeech\FlowSpeechException;
use PHPUnit\Framework\TestCase;

final class TransportIntegrationTest extends TestCase
{
    private const REQUEST_ID = '51382b81-9d36-49a5-994e-9b67686c6bd1';
    private array $endpoints;

    protected function setUp(): void
    {
        $json = getenv('FLOWSPEECH_FIXTURE_ENDPOINTS');
        if (!$json) { self::markTestSkipped('Run through node scripts/run-transport.mjs for loopback HTTPS fixtures.'); }
        $this->endpoints = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach ($this->endpoints as $url) {
            self::assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST), 'Fixture must be loopback-only.');
        }
        $this->control('success');
    }

    private function control(?string $scenario = null): array
    {
        $context = $scenario === null ? null : stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['scenario' => $scenario]), 'timeout' => 2,
        ]]);
        return json_decode(file_get_contents($this->endpoints['http'] . '/control', false, $context), true, 512, JSON_THROW_ON_ERROR);
    }

    private function client(array $options = [], int $deadline = 30, ?string $origin = null): FlowSpeechClient
    {
        return new FlowSpeechClient('fixture-only', $origin ?? $this->endpoints['api'], $deadline, null,
            $options + ['max_retries' => 0, 'request_timeout' => 5]);
    }

    private function generate(FlowSpeechClient $client): array
    {
        return $client->generateSpeech('Hello', [['voiceName' => 'Kore']], self::REQUEST_ID);
    }

    private function expectCode(callable $action, string $code): FlowSpeechException
    {
        try { $action(); self::fail('Expected error: ' . $code); }
        catch (FlowSpeechException $error) { self::assertSame($code, $error->errorCode); return $error; }
    }

    public function testRealQuotaVoicesAndCredentialFreeWav(): void
    {
        self::assertTrue(extension_loaded('curl'));
        self::assertTrue(extension_loaded('openssl'));
        $client = $this->client();
        $quota = $client->getQuota();
        self::assertSame(20, $quota['quota']['available']);
        self::assertArrayHasKey('subscription', $quota);
        self::assertNull($quota['subscription']);
        self::assertArrayNotHasKey('entitlement', $quota);
        $this->control('quota-active');
        self::assertSame(['plan' => 'basic', 'status' => 'active'], $client->getQuota()['subscription']);
        self::assertSame('Kore', $client->getVoices()['voices'][0]['name']);
        $result = $this->generate($client);
        self::assertSame('success', $result['status']);
        self::assertSame('RIFF', substr(base64_decode($result['data']['audioBase64']), 0, 4));
        $state = $this->control();
        self::assertSame(1, $state['created']);
        foreach ($state['records'] as $request) {
            if ($request['role'] === 'api') self::assertSame('Bearer fixture-only', $request['headers']['authorization']);
            if ($request['role'] === 'storage') {
                foreach (['authorization', 'cookie', 'idempotency-key', 'x-api-key'] as $key) {
                    self::assertArrayNotHasKey($key, $request['headers']);
                }
            }
        }
    }

    public function testRealMultiSpeakerBody(): void
    {
        $this->client()->generateMultiSpeakerSpeech("Alice: Hi\nBob: Welcome",
            [['speaker' => 'Alice', 'voiceName' => 'Kore'], ['speaker' => 'Bob', 'voiceName' => 'Puck']], self::REQUEST_ID);
        $body = json_decode($this->control()['records'][0]['body'], true);
        self::assertSame(['input' => ['mode' => 'multi', 'segments' => [
            ['speaker' => 'Alice', 'text' => 'Hi', 'voiceName' => 'Kore'],
            ['speaker' => 'Bob', 'text' => 'Welcome', 'voiceName' => 'Puck'],
        ]]], $body);
    }

    public function testRealPollingWaitsAtLeastFiveSeconds(): void
    {
        $this->control('queued');
        $result = $this->generate($this->client());
        self::assertSame('success', $result['status']);
        $records = $this->control()['records'];
        self::assertGreaterThanOrEqual(4900, $records[1]['at'] - $records[0]['at']);
    }

    public function testRealRetryAfterPreservesUuidAndBytes(): void
    {
        $this->control('retry');
        $this->generate($this->client(['max_retries' => 1]));
        $records = $this->control()['records'];
        self::assertSame($records[0]['body'], $records[1]['body']);
        self::assertSame(self::REQUEST_ID, $records[1]['headers']['idempotency-key']);
        self::assertGreaterThanOrEqual(5900, $records[1]['at'] - $records[0]['at']);
    }

    public function testLostSubmissionReplaysSameRecording(): void
    {
        $this->control('lost-submit');
        $this->generate($this->client(['max_retries' => 1]));
        $records = $this->control()['records'];
        self::assertSame($records[0]['body'], $records[1]['body']);
        self::assertSame($records[0]['headers']['idempotency-key'], $records[1]['headers']['idempotency-key']);
        self::assertSame(1, $this->control()['downloaded']);
    }

    public function testRealRedirectsAreNeverFollowed(): void
    {
        foreach (['api-redirect', 'storage-redirect'] as $scenario) {
            $this->control($scenario);
            $this->expectCode(fn() => $this->generate($this->client()), 'redirect_refused');
            foreach ($this->control()['records'] as $request) self::assertNotSame('/sink', $request['path']);
        }
    }

    public function testHttpAndCredentialUrlsNeverReceiveARequest(): void
    {
        $this->expectCode(fn() => $this->client([], 30, $this->endpoints['http']), 'invalid_url');
        foreach (['http-result', 'credential-url'] as $scenario) {
            $this->control($scenario);
            $this->expectCode(fn() => $this->generate($this->client()), 'invalid_url');
            self::assertCount(1, $this->control()['records']);
        }
        $this->control('success');
        $this->expectCode(fn() => FlowSpeechClient::curlTransport([
            'method' => 'GET', 'url' => $this->endpoints['http'] . '/sink',
            'headers' => [], 'body' => null, 'timeout' => 1, 'maxBytes' => 100,
        ]), 'network_error');
        self::assertCount(0, $this->control()['records'], 'cURL protocol restriction blocks direct HTTP too.');
    }

    public function testRealTlsRejectsUntrustedCaAndWrongHostname(): void
    {
        foreach (['untrusted', 'wrongHost'] as $origin) {
            $this->expectCode(fn() => $this->client([], 3, $this->endpoints[$origin])->getQuota(), 'network_error');
        }
        self::assertCount(0, $this->control()['records'], 'TLS failure occurs before HTTP headers are sent.');
    }

    public function testRealFailedCancelledAndDeletedJobsDoNotDownload(): void
    {
        foreach (['failed' => 'FIXTURE_TERMINAL', 'cancelled' => 'FIXTURE_TERMINAL',
            'deleted' => 'result_deleted', 'malformed' => 'invalid_result'] as $scenario => $code) {
            $this->control($scenario);
            $error = $this->expectCode(fn() => $this->generate($this->client()), $code);
            self::assertSame(self::REQUEST_ID, $error->requestId);
            self::assertSame(0, $this->control()['downloaded']);
        }
    }

    public function testRealDownloadRetryDoesNotSubmitAgain(): void
    {
        $this->control('download-retry');
        $this->generate($this->client(['max_retries' => 1]));
        $state = $this->control();
        self::assertSame(1, $state['created']);
        self::assertSame(2, $state['downloaded']);
        self::assertSame($state['records'][1]['path'], $state['records'][2]['path']);
    }

    public function testRealOversizeTruncatedAndInvalidAudio(): void
    {
        foreach (['oversize' => 'response_too_large', 'truncated' => 'network_error', 'invalid-wav' => 'invalid_audio'] as $scenario => $code) {
            $this->control($scenario);
            $this->expectCode(fn() => $this->generate($this->client(['max_download_bytes' => 64])), $code);
            self::assertSame(1, $this->control()['created']);
        }
    }

public function testRealPollingPinsRequestIdentityAndNeverDownloadsMismatch(): void
    {
        foreach (['identity-poll-missing', 'identity-poll-wrong'] as $scenario) {
            $this->control($scenario);
            $client = $this->client();
            $error = $this->expectCode(fn() => $this->generate($client), 'invalid_response');
            self::assertSame(self::REQUEST_ID, $error->requestId);
            self::assertSame('b698824b-de05-486d-8833-05d5c4522a39', $error->jobId);
            self::assertSame(self::REQUEST_ID, $client->getLastRequestId());
            self::assertSame(0, $this->control()['downloaded']);
            self::assertSame(1, $this->control()['reads']);
        }
    }

    public function testRealUppercaseResponseIdsAreNormalized(): void
    {
        $this->control('identity-poll-uppercase');
        $client = $this->client();
        $job = $this->generate($client);
        self::assertSame(self::REQUEST_ID, $job['requestId']);
        self::assertSame('b698824b-de05-486d-8833-05d5c4522a39', $job['id']);
        self::assertSame($job['requestId'], $client->getLastRequestId());
        self::assertSame($job['id'], $client->getLastJobId());
        self::assertSame('/api/v1/tts/jobs/' . $job['id'], $this->control()['records'][1]['path']);
    }

    public function testRealStandaloneReadsAndCancelClearPreviousRequestIdentity(): void
    {
        $otherId = 'af451078-92bd-4539-9e20-cbcf0b21e4f8';
        foreach (['getJob', 'cancelJob'] as $operation) {
            $this->control('success');
            $client = $this->client();
            $this->generate($client);
            $this->control('identity-other');
            $job = $client->$operation($otherId);
            self::assertSame('e767d2eb-620c-4c1c-a4a2-202bdd16a783', $job['requestId']);
            self::assertSame($job['requestId'], $client->getLastRequestId());
            $this->control('identity-missing');
            $error = $this->expectCode(fn() => $client->$operation('b698824b-de05-486d-8833-05d5c4522a39'), 'invalid_response');
            self::assertNull($error->requestId);
            self::assertSame('b698824b-de05-486d-8833-05d5c4522a39', $error->jobId);
            self::assertNull($client->getLastRequestId());
        }
    }

    public function testRealDeadlineBoundsSlowBody(): void
    {
        $this->control('slow');
        $start = microtime(true);
        try { $this->generate($this->client(['request_timeout' => 1], 1)); self::fail('Expected timeout'); }
        catch (FlowSpeechException $error) { self::assertContains($error->errorCode, ['network_error', 'deadline_exceeded']); }
        self::assertLessThan(1.8, microtime(true) - $start);
        self::assertSame(1, $this->control()['created']);
    }
}
