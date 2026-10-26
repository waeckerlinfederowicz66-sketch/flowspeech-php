<?php
declare(strict_types=1);

// No network, framework or cURL extension is used by this suite.
$sourceRoot = getenv('FLOWSPEECH_TEST_ROOT') ?: dirname(__DIR__);
require_once $sourceRoot . '/src/FlowSpeechException.php';
require_once $sourceRoot . '/src/FlowSpeechClient.php';

use FlowSpeech\FlowSpeechClient;
use FlowSpeech\FlowSpeechException;

const JOB_ID = 'b698824b-de05-486d-8833-05d5c4522a39';
const REQUEST_ID = '51382b81-9d36-49a5-994e-9b67686c6bd1';
const INPUT = ['mode' => 'single', 'text' => 'Hello', 'voiceName' => 'Kore'];
$checks = 0;

function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function raises(callable $fn, ?string $code = null): FlowSpeechException {
    try { $fn(); } catch (FlowSpeechException $error) {
        check($code === null || $error->errorCode === $code, 'Wrong error code: ' . $error->errorCode . ', expected ' . $code);
        return $error;
    }
    throw new RuntimeException('Expected FlowSpeechException: ' . $code);
}
function job(string $status = 'success', array $overrides = []): array {
    return array_replace([
        'id' => JOB_ID, 'requestId' => REQUEST_ID, 'status' => $status,
        'usage' => ['billableCharacters' => 5, 'reservedCredits' => 0, 'chargedCredits' => 5],
        'result' => $status === 'success' ? ['url' => 'https://audio.example.test/recording.wav',
            'mimeType' => 'audio/wav', 'sampleRate' => 24000, 'channels' => 1, 'bitsPerSample' => 16] : null,
    ], $overrides);
}
function wav(): string {
    return 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16) . 'data' . pack('V', 2) . "\0\0";
}
function response(array|string $data, int $status = 200, array $headers = []): array {
    return ['status' => $status, 'headers' => $headers, 'body' => is_string($data) ? $data : json_encode($data, JSON_THROW_ON_ERROR)];
}
function fake(array $queue, array $options = [], int $timeout = 120): array {
    $state = (object) ['queue' => $queue, 'requests' => [], 'time' => 0.0, 'waits' => []];
    $client = new FlowSpeechClient('fixture-only', 'https://flowspeech.io', $timeout,
        static function (array $request) use ($state): array {
            $state->requests[] = $request;
            if (!$state->queue) { throw new RuntimeException('Unexpected HTTP request: ' . $request['url']); }
            $next = array_shift($state->queue);
            if ($next instanceof Throwable) { throw $next; }
            if (is_callable($next)) { return $next($request, $state); }
            return $next;
        },
        $options + [
            'clock' => static fn(): float => $state->time,
            'sleep' => static function (float $seconds) use ($state): void { $state->waits[] = $seconds; $state->time += $seconds; },
        ]);
    return [$client, $state];
}

foreach (['', ' ', "bad\nkey", "bad\rkey", "bad\tkey"] as $key) {
    raises(static fn() => new FlowSpeechClient($key));
}
foreach (['http://flowspeech.io', 'https://u:p@flowspeech.io', 'https://flowspeech.io/x', 'https://flowspeech.io?x=1', 'https://flowspeech.io/#x', "https://flowspeech.io\n"] as $url) {
    raises(static fn() => new FlowSpeechClient('fixture-only', $url));
}
foreach ([['speed' => 2], ['poll_interval' => 1], ['max_retries' => -1], ['max_retries' => 11], ['max_download_bytes' => 0], ['request_timeout' => 0]] as $options) {
    raises(static fn() => new FlowSpeechClient('fixture-only', 'https://flowspeech.io', 30, null, $options));
}
raises(static fn() => new FlowSpeechClient('fixture-only', 'https://flowspeech.io', 0));
check((bool) preg_match('/^[0-9a-f-]{14}4[0-9a-f-]{4}[89ab]/', FlowSpeechClient::newRequestId()), 'UUID v4');
check(FlowSpeechClient::newRequestId() !== FlowSpeechClient::newRequestId(), 'UUID uniqueness');

[$client, $state] = fake([
    response(['quota' => ['available' => 12], 'subscription' => null, 'limits' => []]),
    response(['voices' => [['name' => 'Kore', 'description' => 'Firm', 'gender' => 'female']]]),
]);
check($client->getQuota()['quota']['available'] === 12, 'Top-level quota');
check($client->getVoices()['voices'][0]['name'] === 'Kore', 'Top-level voices');
check($state->requests[0]['url'] === 'https://flowspeech.io/api/v1/quota', 'Quota endpoint');
check($state->requests[1]['url'] === 'https://flowspeech.io/api/v1/voices', 'Voices endpoint');
foreach ($state->requests as $request) {
    check($request['headers']['Authorization'] === 'Bearer fixture-only', 'Bearer header');
    check($request['body'] === null, 'GET without body');
}

[$client, $state] = fake([
    new FlowSpeechException('Temporary network failure', 0, 'network_error', true),
    response(['error' => ['code' => 'busy', 'message' => 'Busy', 'retryable' => true]], 503, ['Retry-After' => '8']),
    response(job('queued'), 202, ['Retry-After' => '9']),
    response(job('running'), 200, ['retry-after' => ['6']]),
    response(job('merging')),
    response(job('settling')),
    response(job()),
    response(wav()),
]);
$result = $client->generateSpeech('Hello', [['voiceName' => 'Kore']], REQUEST_ID);
check($state->requests[0]['body'] === json_encode(['input' => INPUT]), 'Single payload contains only v1 input');
check($state->requests[0] === $state->requests[1], 'First retry preserves full request');
check($state->requests[1] === $state->requests[2], 'Second retry preserves full request');
check($state->requests[0]['headers']['Idempotency-Key'] === REQUEST_ID, 'Stable UUID header');
check($state->waits === [5.0, 10.0, 9.0, 6.0, 5.0, 5.0], 'Backoff and Retry-After');
check(base64_decode($result['data']['audioBase64']) === wav(), 'Local compatibility base64');
check($result['usage']['chargedCredits'] === 5, 'Usage preserved');
check($result['result']['channels'] === 1, 'Direct v1 job preserved');
$download = end($state->requests);
check($download['headers'] === ['Accept' => 'audio/wav'], 'Download has no auth/cookie/API headers');
check($download['maxBytes'] === 33554432, 'Bounded download');
check($client->getLastRequestId() === REQUEST_ID && $client->getLastJobId() === JOB_ID, 'Recovery IDs');

[$client, $state] = fake([response(job()), response(wav())]);
$client->generateMultiSpeakerSpeech("Alice: Hello: there\nBob: Welcome\nAlice: Again",
    [['speaker' => 'Alice', 'voiceName' => 'Kore'], ['speaker' => 'Bob', 'voiceName' => 'Puck']], REQUEST_ID);
$input = json_decode($state->requests[0]['body'], true)['input'];
check($input === ['mode' => 'multi', 'segments' => [
    ['speaker' => 'Alice', 'text' => 'Hello: there', 'voiceName' => 'Kore'],
    ['speaker' => 'Bob', 'text' => 'Welcome', 'voiceName' => 'Puck'],
    ['speaker' => 'Alice', 'text' => 'Again', 'voiceName' => 'Kore'],
]], 'Multi mapping preserves turn order');
[$client, $state] = fake([response(job()), response(wav())]);
$client->generateSegments([['speaker' => 'Alice', 'text' => "Hello\nAgain", 'voiceName' => 'Kore']], REQUEST_ID);
check(json_decode($state->requests[0]['body'], true)['input']['segments'][0]['text'] === "Hello\nAgain", 'Structured multiline turns');
foreach ([
    ['mode' => 'single', 'text' => '', 'voiceName' => 'Kore'],
    INPUT + ['model' => 'legacy'], INPUT + ['speed' => 1], INPUT + ['format' => 'mp3'],
    INPUT + ['source' => 'web'], INPUT + ['speakers' => []],
    ['mode' => 'multi', 'segments' => []],
    ['mode' => 'multi', 'segments' => [['speaker' => 'A', 'text' => 'Hi', 'voiceName' => 'Kore', 'speed' => 1]]],
    ['mode' => 'multi', 'segments' => [
        ['speaker' => 'A', 'text' => 'Hi', 'voiceName' => 'Kore'],
        ['speaker' => 'A', 'text' => 'Hi', 'voiceName' => 'Puck'],
    ]],
] as $input) {
    [$client, $state] = fake([]);
    raises(static fn() => $client->createJob($input), 'invalid_input');
    check(count($state->requests) === 0, 'Invalid input sends no HTTP');
}
[$client, $state] = fake([]);
raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore', 'speaker' => 'A']]), 'invalid_input');
raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore'], ['voiceName' => 'Puck']]), 'invalid_input');
raises(static fn() => $client->generateMultiSpeakerSpeech('Unlabeled', [['speaker' => 'A', 'voiceName' => 'Kore']]), 'invalid_input');
raises(static fn() => $client->generateMultiSpeakerSpeech('A: Hi', [['speaker' => 'A', 'voiceName' => 'Kore'], ['speaker' => 'A', 'voiceName' => 'Puck']]), 'invalid_input');
foreach (['../quota', 'id?x=1', '', JOB_ID . "\n"] as $id) {
    raises(static fn() => $client->getJob($id), 'invalid_id');
    raises(static fn() => $client->findJob($id), 'invalid_id');
    raises(static fn() => $client->cancelJob($id), 'invalid_id');
}
check(!$state->requests, 'Invalid IDs never enter a URL');

[$client, $state] = fake([response(job()), response(job('cancelled'))]);
check($client->findJob(REQUEST_ID)['id'] === JOB_ID, 'Recovery lookup');
check($state->requests[0]['url'] === 'https://flowspeech.io/api/v1/tts/jobs?requestId=' . REQUEST_ID, 'Recovery query');
check($client->cancelJob(JOB_ID)['status'] === 'cancelled', 'Cancellation response');
check($state->requests[1]['method'] === 'POST' && str_ends_with($state->requests[1]['url'], '/cancel'), 'Cancellation endpoint');

foreach (['failed', 'cancelled'] as $terminal) {
    [$client, $state] = fake([response(job($terminal, ['error' => ['code' => 'terminal_error']]))]);
    $error = raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore']], REQUEST_ID), 'terminal_error');
    check(count($state->requests) === 1 && $error->jobId === JOB_ID, 'HTTP 200 failure never downloads');
}
foreach ([
    job('mystery'),
    ['status' => 'queued'],
    job('success', ['result' => null]),
    job('success', ['result' => ['url' => 'https://audio.example.test/file.wav']]),
    job('success', ['result' => array_replace(job()['result'], ['channels' => 2])]),
] as $malformed) {
    [$client, $state] = fake([response($malformed)]);
    raises(static fn() => $client->getJob(JOB_ID));
    check(count($state->requests) === 1, 'Malformed job is not retried');
}
[$client] = fake([response(job('success', ['id' => REQUEST_ID]))]);
raises(static fn() => $client->getJob(JOB_ID), 'invalid_response');
[$client, $state] = fake([response(job('success', ['result' => null, 'resultDeleted' => true]))]);
$error = raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore']], REQUEST_ID), 'result_deleted');
check(count($state->requests) === 1 && $error->jobId === JOB_ID, 'Deleted result explicit and no download');

foreach (['http://audio.example.test/a.wav', 'file:///tmp/a.wav', 'https://user:password@audio.example.test/a.wav', 'https://audio.example.test/a.wav#secret', "https://audio.example.test/\r\n"] as $url) {
    [$client, $state] = fake([]);
    raises(static fn() => $client->downloadResult(job('success', ['result' => array_replace(job()['result'], ['url' => $url])])), 'invalid_url');
    check(!$state->requests, 'Unsafe download URL never fetched');
}
foreach ([301, 302, 307, 308] as $status) {
    [$client, $state] = fake([response('', $status, ['Location' => 'https://other.example.test'])]);
    raises(static fn() => $client->createJob(INPUT, REQUEST_ID), 'redirect_refused');
    check(count($state->requests) === 1, 'API redirect refused');
    [$client, $state] = fake([response('', $status)]);
    raises(static fn() => $client->downloadResult(job()), 'redirect_refused');
    check(count($state->requests) === 1, 'Storage redirect refused');
}
[$client, $state] = fake([
    response(job()),
    response('', 503),
    response(wav()),
]);
$client->generateSpeech('Hi', [['voiceName' => 'Kore']], REQUEST_ID);
check(count(array_filter($state->requests, static fn($r) => $r['method'] === 'POST')) === 1, 'Download retry never submits');
check($state->requests[1]['url'] === $state->requests[2]['url'], 'Download retry uses same URL');
[$client, $state] = fake([response(job()), response('', 404)]);
$error = raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore']], REQUEST_ID), 'http_error');
check($error->jobId === JOB_ID && count($state->requests) === 2, 'Download failure preserves job without resubmission');
[$client] = fake([response('not wav')]);
raises(static fn() => $client->downloadResult(job()), 'invalid_audio');
[$client] = fake([response(str_repeat('a', 50))], ['max_download_bytes' => 44]);
raises(static fn() => $client->downloadResult(job()), 'response_too_large');

foreach ([400, 401, 402, 403, 404, 409, 413, 415, 503] as $status) {
    [$client, $state] = fake([response(['error' => ['code' => 'test_error', 'message' => 'Test error', 'retryable' => false], 'traceId' => 'fixture-trace'], $status)]);
    $error = raises(static fn() => $client->createJob(INPUT, REQUEST_ID), 'test_error');
    check($error->getCode() === $status && $error->traceId === 'fixture-trace' &&
        $error->requestId === REQUEST_ID && !$error->retryable, 'Structured API error preserved');
    check(count($state->requests) === 1, 'Nonretryable error only once');
}
[$client, $state] = fake([
    response(['error' => ['code' => 'rate_limit', 'message' => 'Wait', 'retryable' => true]], 429, ['Retry-After' => '11']),
    response(job()),
]);
$client->createJob(INPUT, REQUEST_ID);
check($state->waits === [11.0], '429 Retry-After respected');
check($state->requests[0] === $state->requests[1], '429 stable UUID/body');
[$client, $state] = fake([
    response(job('queued'), 202, ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 30) . ' GMT']),
    response(job()),
]);
$client->waitForJob($client->createJob(INPUT, REQUEST_ID));
check($state->waits[0] >= 29 && $state->waits[0] <= 30, 'HTTP-date Retry-After');
[$client, $state] = fake(array_fill(0, 3, new FlowSpeechException('Offline', 0, 'network_error', true)));
$error = raises(static fn() => $client->createJob(INPUT, REQUEST_ID), 'network_error');
check(count($state->requests) === 3 && $error->requestId === REQUEST_ID, 'Bounded network retries preserve recovery ID');
[$client, $state] = fake([response(job('queued'), 202, ['Retry-After' => '90'])], [], 60);
$error = raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore']], REQUEST_ID), 'deadline_exceeded');
check(!$state->waits && count($state->requests) === 1 && $error->jobId === JOB_ID, 'Deadline refuses excessive sleep');
[$client, $state] = fake([
    static function ($request, $state): array { $state->time += 7; return response(job('queued')); },
    response(job()),
    static function ($request, $state): array {
        check($request['timeout'] === 8.0, 'Download shares remaining total deadline');
        $state->time += 8;
        return response(wav());
    },
], [], 20);
raises(static fn() => $client->generateSpeech('Hi', [['voiceName' => 'Kore']], REQUEST_ID), 'deadline_exceeded');
check($state->requests[0]['timeout'] === 20.0 && $state->requests[1]['timeout'] === 8.0, 'Request timeout bounded by remaining deadline');
foreach (['not json', '[]', '{}', '{"error":{"code":"bad"}}'] as $body) {
    [$client] = fake([response($body)]);
    raises(static fn() => $client->getQuota(), 'invalid_response');
}
[$client] = fake([response(['voices' => ['name' => 'Kore']])]);
raises(static fn() => $client->getVoices(), 'invalid_response');
[$client, $state] = fake([response(job('queued', ['requestId' => JOB_ID]))]);
$error = raises(static fn() => $client->createJob(INPUT, REQUEST_ID), 'invalid_response');
check($error->requestId === REQUEST_ID, 'Mismatched server request ID cannot replace recovery UUID');
[$client] = fake([response(job()), response(wav())]);
$audio = $client->getAudio(JOB_ID);
check($audio['binary'] === wav() && $audio['job']['id'] === JOB_ID, 'Read/download existing job');
[$client, $state] = fake([
    static function ($request, $state): array { $state->time += 7; return response(job()); },
    static function ($request, $state): array {
        check($request['timeout'] === 3.0, 'Read/download shares total deadline');
        $state->time += 3;
        return response(wav());
    },
], [], 10);
raises(static fn() => $client->getAudio(JOB_ID), 'deadline_exceeded');
foreach (["\xff", str_repeat('a', 1048577)] as $text) {
    [$client, $state] = fake([]);
    raises(static fn() => $client->createJob(['mode' => 'single', 'text' => $text, 'voiceName' => 'Kore'], REQUEST_ID), 'invalid_input');
    check(!$state->requests, 'Invalid JSON or excessive payload is not sent');
}
foreach ([
    substr(wav(), 0, -1),
    substr_replace(wav(), pack('V', 48000), 24, 4),
    substr_replace(wav(), pack('v', 2), 22, 2),
    substr_replace(wav(), pack('V', 1000), 40, 4),
] as $invalidAudio) {
    [$client] = fake([response($invalidAudio)]);
    raises(static fn() => $client->downloadResult(job()), 'invalid_audio');
}
foreach ([null, ['id' => 'fixture-subscription', 'plan' => 'basic']] as $subscription) {
    [$client] = fake([response(['quota' => ['available' => 5], 'subscription' => $subscription, 'limits' => []])]);
    $quota = $client->getQuota();
    check(array_key_exists('subscription', $quota) && $quota['subscription'] === $subscription, 'Actual subscription response is retained unchanged');
    check(!array_key_exists('entitlement', $quota), 'No obsolete entitlement key required or invented');
}
// Every job response must carry a valid request UUID, including standalone reads.
$missingRequest = job('queued');
unset($missingRequest['requestId']);
$invalidIdentities = [$missingRequest];
foreach ([null, 42, '', 'not-a-uuid', REQUEST_ID . "\n"] as $badRequestId) {
    $invalidIdentities[] = job('queued', ['requestId' => $badRequestId]);
}
foreach ($invalidIdentities as $malformed) {
    foreach (['getJob', 'cancelJob', 'createJob', 'findJob'] as $operation) {
        [$client, $state] = fake([response($malformed)]);
        $error = raises(static fn() => match ($operation) {
            'createJob' => $client->createJob(INPUT, REQUEST_ID),
            'findJob' => $client->findJob(REQUEST_ID),
            default => $client->$operation(JOB_ID),
        }, 'invalid_response');
        check(count($state->requests) === 1, 'Missing/invalid request UUID is never retried');
        check($error->requestId === (in_array($operation, ['createJob', 'findJob'], true) ? REQUEST_ID : null),
            'Invalid response cannot replace the expected request identity');
    }
    [$client, $state] = fake([]);
    raises(static fn() => $client->downloadResult(array_replace($malformed, ['status' => 'success', 'result' => job()['result']])), 'invalid_response');
    check(!$state->requests, 'Missing/invalid request identity cannot reach storage');
}

// The initial identity stays pinned across every poll, including malformed replies.
foreach (array_merge($invalidIdentities, [job('success', ['requestId' => JOB_ID]), job('success', ['id' => REQUEST_ID])]) as $changed) {
    foreach (['generate', 'array', 'string'] as $entry) {
        $queue = $entry === 'array' ? [response(job('running')), response($changed)]
            : [response(job('queued')), response(job('running')), response($changed)];
        [$client, $state] = fake($queue);
        $error = raises(static fn() => match ($entry) {
            'generate' => $client->generateSpeech('Hello', [['voiceName' => 'Kore']], REQUEST_ID),
            'array' => $client->waitForJob(job('queued')),
            'string' => $client->waitForJob(JOB_ID),
        }, 'invalid_response');
        check($error->requestId === REQUEST_ID && $error->jobId === JOB_ID, 'Polling errors retain initial recovery IDs');
        check($client->getLastRequestId() === REQUEST_ID && $client->getLastJobId() === JOB_ID,
            'Malformed polling cannot poison client recovery state');
        check(count($state->requests) === ($entry === 'array' ? 2 : 3), 'Identity mismatch stops before download');
    }
}

// Response UUID case is normalized in returned jobs, state, request paths and errors.
$upper = ['id' => strtoupper(JOB_ID), 'requestId' => strtoupper(REQUEST_ID)];
[$client, $state] = fake([response(job('queued', $upper)), response(job('running', $upper)), response(job('success', $upper))]);
$created = $client->createJob(INPUT, strtoupper(REQUEST_ID));
check($created['id'] === JOB_ID && $created['requestId'] === REQUEST_ID, 'Creation returns normalized UUIDs');
$finished = $client->waitForJob($created);
check($finished['id'] === JOB_ID && $finished['requestId'] === REQUEST_ID, 'Polling returns normalized UUIDs');
check($client->getLastJobId() === JOB_ID && $client->getLastRequestId() === REQUEST_ID, 'Client state uses canonical lowercase UUIDs');
check($state->requests[0]['headers']['Idempotency-Key'] === REQUEST_ID, 'Uppercase caller UUID uses normalized idempotency header');
check($state->requests[1]['url'] === 'https://flowspeech.io/api/v1/tts/jobs/' . JOB_ID, 'Polling uses normalized job path');
[$client] = fake([response(job('failed', $upper))]);
$error = raises(static fn() => $client->waitForJob(strtoupper(JOB_ID)), 'failed');
check($error->jobId === JOB_ID && $error->requestId === REQUEST_ID, 'Terminal errors expose normalized response IDs');

// Independent reads/cancellation must not inherit a previous operation's request ID.
$otherJobId = 'af451078-92bd-4539-9e20-cbcf0b21e4f8';
$otherRequestId = 'e767d2eb-620c-4c1c-a4a2-202bdd16a783';
foreach (['getJob', 'cancelJob', 'waitForJob', 'getAudio'] as $operation) {
    $other = job('success', ['id' => strtoupper($otherJobId), 'requestId' => strtoupper($otherRequestId)]);
    $queue = [response(job('queued')), response($other)];
    if ($operation === 'getAudio') $queue[] = response(wav());
    [$client, $state] = fake($queue);
    $client->createJob(INPUT, REQUEST_ID);
    $returned = $client->$operation(strtoupper($otherJobId));
    if ($operation === 'getAudio') $returned = $returned['job'];
    check($returned['id'] === $otherJobId && $returned['requestId'] === $otherRequestId, 'Unrelated standalone operation accepts its own identity');
    check($client->getLastRequestId() === $otherRequestId && $client->getLastJobId() === $otherJobId, 'Standalone operation replaces recovery state');
    [$client] = fake([response(job('queued')), response(['error' => ['code' => 'job_not_found', 'retryable' => false]], 404)]);
    $client->createJob(INPUT, REQUEST_ID);
    $error = raises(static fn() => $client->$operation(strtoupper($otherJobId)), 'job_not_found');
    check($error->requestId === null && $error->jobId === $otherJobId, 'Standalone error clears stale request UUID');
}
[$client] = fake([response(job('queued')), response(job('queued', $upper))]);
$client->createJob(INPUT, REQUEST_ID);
$found = $client->findJob(strtoupper(REQUEST_ID));
check($found['requestId'] === REQUEST_ID && $found['id'] === JOB_ID, 'Recovery lookup normalizes UUIDs');
echo "PASS: $checks assertions; no network or credits used.\n";
