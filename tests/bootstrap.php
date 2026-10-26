<?php
declare(strict_types=1);
$source = getenv('FLOWSPEECH_TEST_ROOT') ?: dirname(__DIR__);
require_once $source . '/src/FlowSpeechException.php';
require_once $source . '/src/FlowSpeechClient.php';
