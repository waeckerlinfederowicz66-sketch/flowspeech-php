<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FlowSpeech\FlowSpeechClient;

$apiKey = getenv('FLOWSPEECH_API_KEY');

if (!$apiKey) {
    fwrite(STDERR, "Set FLOWSPEECH_API_KEY before running this example.\n");
    exit(1);
}

$client = new FlowSpeechClient($apiKey);

$result = $client->generateSpeech('Welcome to FlowSpeech. Generate natural speech from context-aware text.', [
    ['voiceName' => 'Kore'],
]);

print_r($result);
