<?php
declare(strict_types=1);
if (extension_loaded('curl')) { fwrite(STDERR, "Run this fake cURL test with php -n without ext-curl.\n"); exit(1); }
require __DIR__ . '/contract.php';
$initial = $checks;
foreach (['CURLOPT_URL', 'CURLOPT_CUSTOMREQUEST', 'CURLOPT_HTTPHEADER', 'CURLOPT_FOLLOWLOCATION',
    'CURLOPT_PROTOCOLS', 'CURLPROTO_HTTPS', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST',
    'CURLOPT_TIMEOUT_MS', 'CURLOPT_CONNECTTIMEOUT_MS', 'CURLOPT_HEADERFUNCTION',
    'CURLOPT_WRITEFUNCTION', 'CURLOPT_POSTFIELDS', 'CURLINFO_RESPONSE_CODE'] as $i => $name) { define($name, $i + 1); }
$handles = [];
$chunks = [wav()];
$curlOk = true;
if (!extension_loaded('curl')) {
function curl_init() { global $handles; $handle = (object) ['options' => []]; $handles[] = $handle; return $handle; }
function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
function curl_setopt($handle, $key, $value) { $handle->options[$key] = $value; return true; }
function curl_getinfo($handle, $key) { return 200; }
function curl_close($handle) {}
function curl_exec($handle) {
    global $chunks, $curlOk;
    ($handle->options[CURLOPT_HEADERFUNCTION])($handle, "Retry-After: 7\r\n");
    foreach ($chunks as $chunk) {
        if (($handle->options[CURLOPT_WRITEFUNCTION])($handle, $chunk) !== strlen($chunk)) return false;
    }
    return $curlOk;
}
}
$request = ['method' => 'GET', 'url' => 'https://audio.example.test/a.wav',
    'headers' => ['Accept' => 'audio/wav'], 'body' => null, 'timeout' => 2.25, 'maxBytes' => 100];
$result = \FlowSpeech\FlowSpeechClient::curlTransport($request);
check($result['body'] === wav() && $result['headers']['retry-after'] === '7', 'cURL callbacks capture body/headers');
$options = $handles[0]->options;
check($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'cURL no redirects and HTTPS only');
check($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2, 'cURL TLS verification');
check($options[CURLOPT_TIMEOUT_MS] === 2250 && $options[CURLOPT_CONNECTTIMEOUT_MS] === 2250, 'cURL total and connect timeout');
check($options[CURLOPT_HTTPHEADER] === ['Accept: audio/wav'], 'cURL storage headers');
$chunks = ['abc', 'def'];
$request['maxBytes'] = 5;
raises(static fn() => \FlowSpeech\FlowSpeechClient::curlTransport($request), 'response_too_large');
$chunks = [];
$curlOk = false;
$error = raises(static fn() => \FlowSpeech\FlowSpeechClient::curlTransport($request), 'network_error');
check($error->retryable, 'cURL network failure retryable');
$curlOk = true;
$chunks = [json_encode(job())];
$client = new \FlowSpeech\FlowSpeechClient('fixture-only');
$created = $client->createJob(INPUT, REQUEST_ID);
$options = end($handles)->options;
check($options[CURLOPT_POSTFIELDS] === json_encode(['input' => INPUT]), 'Default cURL serializes v1 body');
check(in_array('Idempotency-Key: ' . REQUEST_ID, $options[CURLOPT_HTTPHEADER], true), 'Default cURL sends UUID');
check(in_array('Authorization: Bearer fixture-only', $options[CURLOPT_HTTPHEADER], true), 'Default cURL Bearer');
$chunks = [wav()];
check($client->downloadResult($created) === wav(), 'Default transport WAV download');
$options = end($handles)->options;
check($options[CURLOPT_HTTPHEADER] === ['Accept: audio/wav'] && !isset($options[CURLOPT_POSTFIELDS]), 'Fresh cURL handle does not retain API auth or body');
echo 'PASS: ' . ($checks - $initial) . " fake cURL transport assertions.\n";
