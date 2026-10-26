<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FlowSpeech\FlowSpeechClient;
use FlowSpeech\FlowSpeechException;

$key = getenv('FLOWSPEECH_API_KEY');
if (!$key) {
    fwrite(STDERR, "Set FLOWSPEECH_API_KEY server-side before running this paid-generation example.\n");
    exit(1);
}
$requestId = getenv('FLOWSPEECH_REQUEST_ID') ?: FlowSpeechClient::newRequestId();
fwrite(STDERR, "Request ID (save for recovery): $requestId\n");
$client = new FlowSpeechClient($key, 'https://flowspeech.io', 600);
try {
    // Preserve both this body and requestId when replaying an interrupted run.
    $result = $client->generateSpeech('Hello world.', [['voiceName' => 'Kore']], $requestId);
    file_put_contents('speech.wav', base64_decode($result['data']['audioBase64'], true));
    fwrite(STDERR, "Saved speech.wav; job ID: " . $result['id'] . "\n");
} catch (FlowSpeechException $error) {
    fwrite(STDERR, "FlowSpeech error: " . ($error->errorCode ?? 'unknown') .
        "; request ID: $requestId; job ID: " . ($error->jobId ?? 'not received') . "\n");
    // Once a job is successful, retry downloadResult()/getAudio(), not generation.
    exit(1);
}
