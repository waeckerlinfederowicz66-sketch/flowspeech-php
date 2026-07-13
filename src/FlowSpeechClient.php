<?php

declare(strict_types=1);

namespace FlowSpeech;

final class FlowSpeechClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(string $apiKey, string $baseUrl = 'https://flowspeech.io', int $timeoutSeconds = 60)
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new FlowSpeechException('A FlowSpeech API key is required.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuota(): array
    {
        return $this->request('GET', '/api/ai/text-to-speech/quota');
    }

    /**
     * @param array<int, array<string, string>> $speakers
     * @return array<string, mixed>
     */
    public function generateSpeech(string $text, array $speakers = [['voiceName' => 'Kore']]): array
    {
        if (trim($text) === '') {
            throw new FlowSpeechException('Text cannot be empty.');
        }

        return $this->request('POST', '/api/ai/text-to-speech', [
            'text' => $text,
            'originalText' => $text,
            'speakers' => $speakers,
        ]);
    }

    /**
     * @param array<int, array<string, string>> $speakers
     * @return array<string, mixed>
     */
    public function generateMultiSpeakerSpeech(string $dialogue, array $speakers): array
    {
        if (trim($dialogue) === '') {
            throw new FlowSpeechException('Dialogue cannot be empty.');
        }

        if ($speakers === []) {
            throw new FlowSpeechException('At least one speaker is required.');
        }

        return $this->request('POST', '/api/ai/text-to-speech', [
            'text' => $dialogue,
            'originalText' => $dialogue,
            'speakers' => $speakers,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = $this->baseUrl . $path;
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($payload !== null) {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new FlowSpeechException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw new FlowSpeechException($error !== '' ? $error : 'FlowSpeech request failed.');
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            throw new FlowSpeechException('FlowSpeech returned a non-JSON response.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : 'FlowSpeech request failed with HTTP ' . $statusCode . '.';

            throw new FlowSpeechException($message, $statusCode);
        }

        return $decoded;
    }
}
