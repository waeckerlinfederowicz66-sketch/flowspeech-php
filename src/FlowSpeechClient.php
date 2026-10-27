<?php

declare(strict_types=1);

namespace FlowSpeech;

/**
 * FlowSpeech public API v1 client. Transport receives one credential-isolated
 * request and must enforce timeout/maxBytes, disable redirects and return
 * {status: int, headers: array, body: string}.
 */
final class FlowSpeechClient
{
    public const VERSION = '0.2.0';
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;
    private $transport;
    private $clock;
    private $sleep;
    private int $requestTimeout;
    private int $maxRetries;
    private int $maxDownloadBytes;
    private float $pollInterval;
    private float $retryAfter = 5;
    private ?string $requestId = null;
    private ?string $jobId = null;

    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://flowspeech.io',
        int $timeoutSeconds = 120,
        ?callable $transport = null,
        array $options = []
    ) {
        if ($apiKey === '' || preg_match('/[\x00-\x20\x7f]/', $apiKey)) {
            throw new FlowSpeechException('A nonempty API key without whitespace is required.');
        }
        self::validateUrl($baseUrl);
        $url = parse_url($baseUrl);
        if (!empty($url['query']) || !empty($url['path']) && $url['path'] !== '/') {
            throw new FlowSpeechException('Base URL must be an HTTPS origin.');
        }
        $allowed = ['request_timeout', 'max_retries', 'max_download_bytes', 'poll_interval', 'clock', 'sleep'];
        if (array_diff(array_keys($options), $allowed)) {
            throw new FlowSpeechException('Unsupported client option.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
        $this->requestTimeout = $options['request_timeout'] ?? 30;
        $this->maxRetries = $options['max_retries'] ?? 2;
        $this->maxDownloadBytes = $options['max_download_bytes'] ?? 33554432;
        $this->pollInterval = $options['poll_interval'] ?? 5;
        if ($timeoutSeconds < 1 || $this->requestTimeout < 1 || $this->maxRetries < 0 ||
            $this->maxRetries > 10 || $this->maxDownloadBytes < 44 ||
            !is_finite($this->pollInterval) || $this->pollInterval < 5) {
            throw new FlowSpeechException('Invalid timeout, retry, polling or download configuration.');
        }
        $this->transport = $transport ?? [self::class, 'curlTransport'];
        $this->clock = $options['clock'] ?? static fn(): float => hrtime(true) / 1e9;
        $this->sleep = $options['sleep'] ?? static fn(float $seconds) => usleep((int) ceil($seconds * 1e6));
        if (!is_callable($this->clock) || !is_callable($this->sleep)) {
            throw new FlowSpeechException('Clock and sleep must be callable.');
        }
    }

    public static function newRequestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function getLastRequestId(): ?string { return $this->requestId; }
    public function getLastJobId(): ?string { return $this->jobId; }
    public function getRetryAfterSeconds(): float { return max($this->pollInterval, $this->retryAfter); }

    public function getQuota(): array
    {
        $data = $this->api('GET', '/api/v1/quota', null, $this->deadline());
        if (!isset($data['quota'], $data['limits']) || !array_key_exists('subscription', $data) ||
            !is_array($data['quota']) || !is_array($data['limits']) ||
            !is_numeric($data['quota']['available'] ?? null)) {
            throw $this->failure('Malformed quota response.', 'invalid_response');
        }
        return $data;
    }

    public function getVoices(): array
    {
        $data = $this->api('GET', '/api/v1/voices', null, $this->deadline());
        if (!isset($data['voices']) || !is_array($data['voices']) || !array_is_list($data['voices'])) {
            throw $this->failure('Malformed voices response.', 'invalid_response');
        }
        foreach ($data['voices'] as $voice) {
            if (!is_array($voice) || !is_string($voice['name'] ?? null) || $voice['name'] === '') {
                throw $this->failure('Malformed voice.', 'invalid_response');
            }
        }
        return $data;
    }

    public function createJob(array $input, ?string $requestId = null): array
    {
        return $this->submit($input, $requestId, $this->deadline());
    }

    public function getJob(string $jobId): array
    {
        return $this->readJob($jobId, $this->deadline());
    }

    public function findJob(string $requestId): array
    {
        self::validateId($requestId);
        $this->requestId = strtolower($requestId);
        $this->jobId = null;
        return $this->validateJob($this->api('GET', '/api/v1/tts/jobs?requestId=' .
            rawurlencode($this->requestId), null, $this->deadline()), null, $this->requestId);
    }

    public function cancelJob(string $jobId): array
    {
        self::validateId($jobId);
        $this->requestId = null;
        $this->jobId = strtolower($jobId);
        return $this->validateJob($this->api('POST', '/api/v1/tts/jobs/' .
            $this->jobId . '/cancel', null, $this->deadline()), $this->jobId);
    }

    public function waitForJob(array|string $job): array
    {
        $deadline = $this->deadline();
        $this->requestId = null;
        $this->jobId = null;
        return $this->wait(is_string($job) ? $this->readJob($job, $deadline) : $job, $deadline);
    }

    public function downloadResult(array $job): string
    {
        $this->requestId = null;
        $this->jobId = null;
        return $this->download($job, $this->deadline());
    }

    /** Read and download an existing job within one shared total deadline. */
    public function getAudio(string $jobId): array
    {
        $deadline = $this->deadline();
        $job = $this->readJob($jobId, $deadline);
        return ['binary' => $this->download($job, $deadline), 'job' => $job];
    }

    /**
     * Compatibility helper: returns the completed v1 job plus locally encoded
     * data.audioBase64. The API itself does not return this legacy data field.
     */
    public function generateSpeech(string $text, array $speakers = [['voiceName' => 'Kore']], ?string $requestId = null): array
    {
        if (count($speakers) !== 1 || !array_is_list($speakers) ||
            !is_array($speakers[0]) || array_diff(array_keys($speakers[0]), ['voiceName'])) {
            throw $this->failure('Single speech requires exactly one voiceName; use multi-speaker speech for dialogue.', 'invalid_input');
        }
        return $this->generate(['mode' => 'single', 'text' => $text,
            'voiceName' => $speakers[0]['voiceName'] ?? ''], $requestId);
    }

    /**
     * Each nonempty dialogue line must be "Speaker: text". No silent parsing
     * of unlabeled text. Use generateSegments() for arbitrary multiline turns.
     */
    public function generateMultiSpeakerSpeech(string $dialogue, array $speakers, ?string $requestId = null): array
    {
        $voices = [];
        foreach ($speakers as $speaker) {
            if (!is_array($speaker) || array_diff(array_keys($speaker), ['speaker', 'voiceName']) ||
                !is_string($speaker['speaker'] ?? null) || trim($speaker['speaker']) === '' ||
                !is_string($speaker['voiceName'] ?? null) || trim($speaker['voiceName']) === '') {
                throw $this->failure('Each speaker requires speaker and voiceName only.', 'invalid_input');
            }
            if (isset($voices[$speaker['speaker']])) {
                throw $this->failure('Duplicate speaker label.', 'invalid_input');
            }
            $voices[$speaker['speaker']] = $speaker['voiceName'];
        }
        $segments = [];
        foreach (preg_split('/\r\n|\r|\n/', $dialogue) as $line) {
            if (trim($line) === '') { continue; }
            $parts = explode(':', $line, 2);
            $speaker = trim($parts[0]);
            if (count($parts) !== 2 || !isset($voices[$speaker])) {
                throw $this->failure('Each dialogue line must start with a configured speaker label.', 'invalid_input');
            }
            $segments[] = ['speaker' => $speaker, 'text' => trim($parts[1]), 'voiceName' => $voices[$speaker]];
        }
        return $this->generateSegments($segments, $requestId);
    }

    public function generateSegments(array $segments, ?string $requestId = null): array
    {
        return $this->generate(['mode' => 'multi', 'segments' => $segments], $requestId);
    }

    private function generate(array $input, ?string $requestId): array
    {
        $deadline = $this->deadline();
        $job = $this->wait($this->submit($input, $requestId, $deadline), $deadline);
        $audio = $this->download($job, $deadline);
        $job['data'] = ['audioBase64' => base64_encode($audio), 'mimeType' => 'audio/wav',
            'sampleRate' => 24000, 'numChannels' => 1, 'bitsPerSample' => 16];
        return $job;
    }

    private function submit(array $input, ?string $requestId, float $deadline): array
    {
        $this->validateInput($input);
        $requestId = $requestId ?? self::newRequestId();
        self::validateId($requestId);
        $this->requestId = strtolower($requestId);
        $this->jobId = null;
        return $this->validateJob($this->api('POST', '/api/v1/tts/jobs', ['input' => $input],
            $deadline, ['Idempotency-Key' => $this->requestId]), null, $this->requestId);
    }

    private function validateInput(array $input): void
    {
        $mode = $input['mode'] ?? null;
        $allowed = $mode === 'single' ? ['mode', 'text', 'voiceName'] : ['mode', 'segments'];
        if (!in_array($mode, ['single', 'multi'], true) || array_diff(array_keys($input), $allowed)) {
            throw $this->failure('Unsupported input fields or mode.', 'invalid_input');
        }
        $segments = $mode === 'single' ? [['text' => $input['text'] ?? null, 'voiceName' => $input['voiceName'] ?? null]] : ($input['segments'] ?? null);
        if (!is_array($segments) || !array_is_list($segments) || count($segments) < 1 || count($segments) > 1000) {
            throw $this->failure('Input requires 1 to 1000 segments.', 'invalid_input');
        }
        $voices = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || array_diff(array_keys($segment), ['text', 'voiceName', 'speaker'])) {
                throw $this->failure('Unsupported segment fields.', 'invalid_input');
            }
            foreach ($mode === 'single' ? ['text', 'voiceName'] : ['text', 'voiceName', 'speaker'] as $key) {
                if (!is_string($segment[$key] ?? null) || trim($segment[$key]) === '') {
                    throw $this->failure('Text, voiceName and speaker labels must be nonempty strings.', 'invalid_input');
                }
            }
            if ($mode === 'multi') {
                $label = trim($segment['speaker']);
                if (isset($voices[$label]) && $voices[$label] !== trim($segment['voiceName'])) {
                    throw $this->failure('A speaker must use a consistent voice.', 'invalid_input');
                }
                $voices[$label] = trim($segment['voiceName']);
            }
        }
    }

    private function readJob(string $jobId, float $deadline, ?string $expectedRequestId = null): array
    {
        self::validateId($jobId);
        $this->requestId = $expectedRequestId === null ? null : strtolower($expectedRequestId);
        $this->jobId = strtolower($jobId);
        return $this->validateJob($this->api('GET', '/api/v1/tts/jobs/' . $this->jobId, null, $deadline),
            $this->jobId, $this->requestId);
    }

    private function validateJob(array $job, ?string $expectedId = null, ?string $expectedRequestId = null): array
    {
        if (!is_string($job['id'] ?? null)) {
            throw $this->failure('Job ID is missing.', 'invalid_response');
        }
        try {
            self::validateId($job['id']);
        } catch (FlowSpeechException $error) {
            throw $this->failure('Malformed response job ID.', 'invalid_response');
        }
        if ($expectedId !== null && strtolower($job['id']) !== strtolower($expectedId)) {
            throw $this->failure('Response job ID does not match.', 'invalid_response');
        }
        if (!is_string($job['requestId'] ?? null)) {
            throw $this->failure('Response request ID is missing or invalid.', 'invalid_response');
        }
        try {
            self::validateId($job['requestId']);
        } catch (FlowSpeechException $error) {
            throw $this->failure('Malformed response request ID.', 'invalid_response');
        }
        if ($expectedRequestId !== null && strtolower($job['requestId']) !== strtolower($expectedRequestId)) {
            throw $this->failure('Response request ID does not match.', 'invalid_response');
        }
        $job['id'] = strtolower($job['id']);
        $job['requestId'] = strtolower($job['requestId']);
        $this->jobId = $job['id'];
        $this->requestId = $job['requestId'];
        if (!in_array($job['status'] ?? null, ['queued', 'running', 'merging', 'settling', 'success', 'failed', 'cancelled'], true)) {
            throw $this->failure('Unknown job status.', 'invalid_response');
        }
        if (isset($job['error'])) {
            if (!is_array($job['error'])) {
                throw $this->failure('Malformed job error.', 'invalid_response');
            }
            $details = $job['error'];
            $terminal = in_array($job['status'], ['failed', 'cancelled'], true);
            $job['error'] = [
                'code' => $this->errorToken($details['code'] ?? null) ?? ($terminal ? $job['status'] : 'job_error'),
                'message' => $terminal ? 'FlowSpeech job ' . $job['status'] . '.' : 'FlowSpeech job reported an error.',
                'retryable' => ($details['retryable'] ?? false) === true,
            ];
            if (array_key_exists('traceId', $details)) {
                $job['error']['traceId'] = $this->errorToken($details['traceId']);
            }
        }
        if (array_key_exists('traceId', $job)) {
            $job['traceId'] = $this->errorToken($job['traceId']);
        }
        if ($job['status'] === 'success') {
            if (($job['resultDeleted'] ?? false) === true && array_key_exists('result', $job) && $job['result'] === null) {
                return $job;
            }
            $result = $job['result'] ?? null;
            if (!is_array($result) || !is_string($result['url'] ?? null) ||
                ($result['mimeType'] ?? null) !== 'audio/wav' || ($result['sampleRate'] ?? null) !== 24000 ||
                ($result['channels'] ?? null) !== 1 || ($result['bitsPerSample'] ?? null) !== 16) {
                throw $this->failure('Successful job has missing or malformed WAV result.', 'invalid_result');
            }
            try {
                self::validateUrl($result['url']);
            } catch (FlowSpeechException $error) {
                $error->requestId = $this->requestId;
                $error->jobId = $this->jobId;
                throw $error;
            }
        }
        return $job;
    }

    private function wait(array $job, float $deadline): array
    {
        $job = $this->validateJob($job);
        $jobId = $job['id'];
        $requestId = $job['requestId'];
        while (in_array($job['status'], ['queued', 'running', 'merging', 'settling'], true)) {
            $this->pause($this->getRetryAfterSeconds(), $deadline);
            $job = $this->readJob($jobId, $deadline, $requestId);
        }
        if ($job['status'] !== 'success') {
            throw $this->failure('FlowSpeech job ' . $job['status'] . '.',
                is_string($job['error']['code'] ?? null) ? $job['error']['code'] : $job['status']);
        }
        return $job;
    }

    private function download(array $job, float $deadline): string
    {
        $job = $this->validateJob($job);
        if ($job['status'] !== 'success') {
            throw $this->failure('Only a successful job can be downloaded.', 'job_not_successful');
        }
        if (($job['resultDeleted'] ?? false) === true) {
            throw $this->failure('The recording has been deleted (resultDeleted: true).', 'result_deleted');
        }
        // This fresh header set is intentionally unrelated to the API request.
        $response = $this->send('GET', $job['result']['url'], ['Accept' => 'audio/wav'],
            null, $deadline, $this->maxDownloadBytes, false);
        $audio = $response['body'];
        if (!$this->isValidWav($audio)) {
            throw $this->failure('Download is not a WAV recording.', 'invalid_audio');
        }
        return $audio;
    }

    private function isValidWav(string $audio): bool
    {
        $length = strlen($audio);
        if ($length < 44 || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE' ||
            unpack('Vsize', substr($audio, 4, 4))['size'] !== $length - 8) {
            return false;
        }
        $formatSeen = false;
        $dataSeen = false;
        $offset = 12;
        while ($offset + 8 <= $length) {
            $name = substr($audio, $offset, 4);
            $size = unpack('Vsize', substr($audio, $offset + 4, 4))['size'];
            $offset += 8;
            if ($size > $length - $offset) { return false; }
            if ($name === 'fmt ') {
                if ($size < 16) { return false; }
                $format = unpack('vencoding/vchannels/Vrate/VbyteRate/valign/vbits', substr($audio, $offset, 16));
                if ($format !== ['encoding' => 1, 'channels' => 1, 'rate' => 24000,
                    'byteRate' => 48000, 'align' => 2, 'bits' => 16]) { return false; }
                $formatSeen = true;
            } elseif ($name === 'data') {
                if ($size === 0 || $size % 2 !== 0) { return false; }
                $dataSeen = true;
            }
            $offset += $size + ($size % 2);
        }
        return $formatSeen && $dataSeen && $offset === $length;
    }

    private function api(string $method, string $path, ?array $payload, float $deadline, array $headers = []): array
    {
        $headers += ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'];
        try {
            $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw $this->failure('Input must be valid UTF-8 JSON.', 'invalid_input');
        }
        if ($body !== null && strlen($body) > 1048576) {
            throw $this->failure('Request JSON exceeds the 1 MiB API limit.', 'invalid_input');
        }
        if ($body !== null) { $headers['Content-Type'] = 'application/json'; }
        $response = $this->send($method, $this->baseUrl . $path, $headers, $body, $deadline, 2097152, true);
        $this->retryAfter = $this->retryDelay($response['headers']);
        $data = json_decode($response['body'], true);
        if (!is_array($data) || array_is_list($data)) {
            throw $this->failure('Malformed API JSON response.', 'invalid_response');
        }
        return $data;
    }

    private function send(string $method, string $url, array $headers, ?string $body, float $deadline, int $maxBytes, bool $api): array
    {
        for ($attempt = 0; ; $attempt++) {
            $remaining = $this->remaining($deadline);
            $delay = 5;
            try {
                $response = ($this->transport)([
                    'method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body,
                    'timeout' => min($remaining, $this->requestTimeout), 'maxBytes' => $maxBytes,
                ]);
                $this->remaining($deadline);
                if (!is_array($response) || !is_int($response['status'] ?? null) ||
                    !is_array($response['headers'] ?? null) || !is_string($response['body'] ?? null)) {
                    throw $this->failure('Invalid transport response.', 'invalid_response');
                }
                if (strlen($response['body']) > $maxBytes) {
                    throw $this->failure('Response exceeded the configured size limit.', 'response_too_large');
                }
                $status = $response['status'];
                if ($status >= 300 && $status < 400) {
                    throw $this->failure('Unexpected redirect refused.', 'redirect_refused', $status);
                }
                if ($status >= 200 && $status < 300) { return $response; }
                $data = $api ? json_decode($response['body'], true) : null;
                $error = is_array($data['error'] ?? null) ? $data['error'] : [];
                $retryable = $status === 429 || ($status >= 500 && (!$api || ($error['retryable'] ?? false) === true));
                $delay = $this->retryDelay($response['headers']);
                $failure = $this->failure('FlowSpeech ' . ($api ? 'API' : 'download') . ' returned HTTP ' . $status . '.',
                    $this->errorToken($error['code'] ?? null) ?? 'http_error', $status, $retryable);
                $failure->traceId = $this->errorToken($data['traceId'] ?? null);
                $failure->retryAfterSeconds = $delay;
                throw $failure;
            } catch (FlowSpeechException $error) {
                $error->requestId = $this->requestId;
                $error->jobId = $this->jobId;
                $delay = max($delay, $error->retryAfterSeconds);
                $this->retryAfter = $delay;
                if (!$error->retryable || $attempt >= $this->maxRetries) { throw $error; }
                $this->pause(max($delay, 5 * (2 ** $attempt)), $deadline);
            }
        }
    }

    private function errorToken(mixed $value): ?string
    {
        return is_string($value) && !str_contains($value, $this->apiKey) &&
            preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1 ? $value : null;
    }

    private static function retryDelay(array $headers): float
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== 'retry-after') { continue; }
            $value = is_array($value) ? end($value) : $value;
            if (!is_string($value) && !is_int($value)) { continue; }
            if (is_numeric($value)) { return max(5, (float) $value); }
            $date = strtotime($value);
            if ($date !== false) { return max(5, $date - time()); }
        }
        return 5;
    }

    private function deadline(): float { return ($this->clock)() + $this->timeoutSeconds; }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - ($this->clock)();
        if ($remaining <= 0) { throw $this->failure('FlowSpeech total deadline exceeded; recover using the request/job ID.', 'deadline_exceeded'); }
        return $remaining;
    }

    private function pause(float $seconds, float $deadline): void
    {
        if ($seconds >= $this->remaining($deadline)) {
            throw $this->failure('FlowSpeech total deadline would be exceeded; recover using the request/job ID.', 'deadline_exceeded');
        }
        ($this->sleep)($seconds);
        $this->remaining($deadline);
    }

    private function failure(string $message, string $code, int $status = 0, bool $retryable = false): FlowSpeechException
    {
        return new FlowSpeechException($message, $status, $code, $retryable, null, $this->requestId, $this->jobId);
    }

    private static function validateId(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) {
            throw new FlowSpeechException('A UUID request/job ID is required.', 0, 'invalid_id');
        }
    }

    private static function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || $parts === false ||
            ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new FlowSpeechException('Only HTTPS URLs without credentials or fragments are allowed.', 0, 'invalid_url');
        }
    }

    public static function curlTransport(array $request): array
    {
        if (!function_exists('curl_init')) {
            throw new FlowSpeechException('The cURL extension is required for the default transport.', 0, 'missing_curl');
        }
        $handle = curl_init();
        if ($handle === false) { throw new FlowSpeechException('Unable to initialize cURL.'); }
        $headers = [];
        foreach ($request['headers'] as $key => $value) { $headers[] = $key . ': ' . $value; }
        $body = '';
        $responseHeaders = [];
        $oversized = false;
        curl_setopt_array($handle, [
            CURLOPT_URL => $request['url'], CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => max(1, (int) floor($request['timeout'] * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) floor(min(10, $request['timeout']) * 1000)),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (preg_match('/^HTTP\/\S+\s+\d{3}/i', $line)) { $responseHeaders = []; }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) { $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversized, $request): int {
                if (strlen($body) + strlen($chunk) > $request['maxBytes']) { $oversized = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($request['body'] !== null) { curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']); }
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($oversized) { throw new FlowSpeechException('Response exceeded the configured size limit.', 0, 'response_too_large'); }
        if ($ok === false) {
            // Do not expose cURL messages which can contain signed result URLs.
            throw self::transportFailure($status, $responseHeaders);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
    }

    /** Preserve received headers when a transport cannot finish the body. */
    public static function transportFailure(int $status, array $headers): FlowSpeechException
    {
        $retryable = $status === 0 || ($status >= 200 && $status < 300) || $status === 429 ||
            ($status >= 500 && $status <= 599);
        $error = new FlowSpeechException('FlowSpeech response could not be read.', $status, 'network_error', $retryable);
        $error->retryAfterSeconds = self::retryDelay($headers);
        return $error;
    }
}
