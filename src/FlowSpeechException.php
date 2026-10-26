<?php

declare(strict_types=1);

namespace FlowSpeech;

use RuntimeException;
use Throwable;

final class FlowSpeechException extends RuntimeException
{
    public float $retryAfterSeconds = 5;

    public function __construct(
        string $message,
        int $statusCode = 0,
        public ?string $errorCode = null,
        public bool $retryable = false,
        public ?string $traceId = null,
        public ?string $requestId = null,
        public ?string $jobId = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
