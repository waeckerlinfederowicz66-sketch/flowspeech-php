# FlowSpeech PHP

PHP client for the [FlowSpeech](https://flowspeech.io/) text-to-speech API.

FlowSpeech converts text into human-like audio with context-aware delivery, emotion control, pause control, and multi-speaker voice configuration. This package provides a small Composer-friendly client for the public FlowSpeech API surface.

## Installation

```bash
composer require flowspeech/flowspeech-php
```

## Authentication

Create a FlowSpeech API key in your account, then pass it as a Bearer token through the client:

```php
use FlowSpeech\FlowSpeechClient;

$client = new FlowSpeechClient(getenv('FLOWSPEECH_API_KEY'));
```

Do not commit real FlowSpeech API keys, provider keys, cookies, session tokens, or environment values.

## Check Quota

```php
$quota = $client->getQuota();
print_r($quota);
```

## Generate Speech

```php
$audio = $client->generateSpeech(
    'Welcome to FlowSpeech. Generate natural speech from context-aware text.',
    [
        ['voiceName' => 'Kore'],
    ],
);

print_r($audio);
```

## Multi-Speaker Speech

```php
$audio = $client->generateMultiSpeakerSpeech(
    "Speaker A: We can make this sound natural.\nSpeaker B: Great, let's use different voices.",
    [
        ['speaker' => 'Speaker A', 'voiceName' => 'Kore'],
        ['speaker' => 'Speaker B', 'voiceName' => 'Puck'],
    ],
);

print_r($audio);
```

## API Surface

This client targets the stable FlowSpeech API-key flow:

- `GET /api/ai/text-to-speech/quota`
- `POST /api/ai/text-to-speech`

It intentionally avoids internal web-session routes and provider-specific compatibility routes.

## License

MIT
