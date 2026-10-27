import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.FLOWSPEECH_TEST_PHP || path.join(root, '.tools/php/bin/php');
let checks = 0;
for (const [scenario, expected] of [
  ['http', 'FlowSpeech API returned HTTP 403.'],
  ['terminal', 'FlowSpeech job failed.'],
  ['download', 'FlowSpeech download returned HTTP 403.'],
]) {
  const result = spawnSync(php, [
    '-d', 'display_errors=stderr', '-d', 'log_errors=0',
    '-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=4096',
    path.join(root, 'tests/error-output.php'), scenario,
  ], { cwd: root, env: process.env, encoding: 'utf8', timeout: 10000 });
  assert.ifError(result.error);
  assert.equal(result.status, 255, 'An uncaught fixture failure must really execute');
  const output = result.stdout + result.stderr;
  assert.ok(output.includes(expected), 'CLI preserves the fixed diagnostic');
  assert.ok(output.includes('Stack trace:'), 'CLI preserves useful call frames');
  for (const privateValue of ['fixture-only', 'private.example.test', 'signature=private-fixture']) {
    assert.ok(!output.includes(privateValue), 'CLI must not print private fixture data');
  }
  checks += 7;
}
console.log(`PASS: ${checks} real PHP CLI output assertions (fake transport, no network).`);
