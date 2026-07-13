<?php

declare(strict_types=1);

namespace FlowSpeech\Tests;

use FlowSpeech\FlowSpeechClient;
use FlowSpeech\FlowSpeechException;
use PHPUnit\Framework\TestCase;

final class FlowSpeechClientTest extends TestCase
{
    public function testApiKeyIsRequired(): void
    {
        $this->expectException(FlowSpeechException::class);

        new FlowSpeechClient('');
    }
}
