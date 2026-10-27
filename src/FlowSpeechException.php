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

    public function __toString(): string
    {
        return self::formatException($this);
    }

    public static function formatException(Throwable $error): string
    {
        $frames = [];
        foreach ($error->getTrace() as $index => $frame) {
            $location = isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')' : '[internal function]';
            $frames[] = '#' . $index . ' ' . $location . ': ' . ($frame['class'] ?? '') .
                ($frame['type'] ?? '') . ($frame['function'] ?? '') . '()';
        }
        $frames[] = '#' . count($frames) . ' {main}';
        $previous = $error->getPrevious();
        // PHP's default exception string can print signed URLs from call arguments.
        return ($previous === null ? '' : self::formatException($previous) . "\n\nNext ") .
            get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine() .
            "\nStack trace:\n" . implode("\n", $frames);
    }
}
