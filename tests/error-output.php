<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/contract.php';
ob_end_clean();

$privateUrl = 'https://private.example.test/audio.wav?signature=private-fixture';
$scenario = $argv[1] ?? 'http';
if ($scenario === 'http') {
    [$client] = fake([response(['error' => ['code' => 'fixture-only',
        'message' => $privateUrl, 'retryable' => false], 'traceId' => 'fixture-only'], 403)]);
    $client->createJob(INPUT, REQUEST_ID);
} elseif ($scenario === 'terminal') {
    [$client] = fake([]);
    $client->waitForJob(job('failed', ['error' => [
        'code' => 'fixture-only', 'message' => $privateUrl, 'retryable' => false,
    ]]));
} elseif ($scenario === 'download') {
    [$client] = fake([response(['error' => ['message' => $privateUrl]], 403)]);
    $client->downloadResult(job('success', [
        'result' => array_replace(job()['result'], ['url' => $privateUrl]),
    ]));
} else {
    throw new LogicException('Unknown fixture scenario.');
}
throw new LogicException('Expected the fixture to fail.');
