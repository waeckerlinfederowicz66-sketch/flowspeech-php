import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync, spawn } from 'node:child_process';
import { startFixture } from '../tests/https-fixture.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const certDir = path.join(root, '.tools/fixture-certificates');
fs.mkdirSync(certDir, { recursive: true, mode: 0o700 });
const openssl = process.env.FLOWSPEECH_TEST_OPENSSL || path.join(root, '.tools/openssl/bin/openssl');
const php = process.env.FLOWSPEECH_TEST_PHP || path.join(root, '.tools/php/bin/php');
const phpunit = process.env.FLOWSPEECH_TEST_PHPUNIT || path.join(root, '.tools/phpunit/vendor/bin/phpunit');
fs.mkdirSync(path.join(root, '.tools/tmp'), { recursive: true });
fs.mkdirSync(path.join(root, '.tools/logs'), { recursive: true });
function ssl(args) {
  execFileSync(openssl, args, { cwd: certDir, stdio: 'ignore',
    env: { ...process.env, OPENSSL_CONF: '/dev/null', TMPDIR: path.join(root, '.tools/tmp') } });
}
let certificatesCurrent = fs.existsSync(path.join(certDir, 'wrong-host.pem'));
if (certificatesCurrent) {
  try {
    execFileSync(openssl, ['x509', '-checkend', '3600', '-noout', '-in', path.join(certDir, 'server.pem')], { stdio: 'ignore' });
  } catch {
    certificatesCurrent = false;
  }
}
if (!certificatesCurrent) {
  ssl(['req', '-x509', '-newkey', 'rsa:2048', '-noenc', '-keyout', 'ca.key', '-out', 'ca.pem',
    '-days', '2', '-subj', '/CN=FlowSpeech local test CA', '-addext', 'basicConstraints=critical,CA:TRUE']);
  ssl(['req', '-new', '-newkey', 'rsa:2048', '-noenc', '-keyout', 'server.key', '-out', 'server.csr',
    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1']);
  ssl(['x509', '-req', '-in', 'server.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial',
    '-out', 'server.pem', '-days', '2', '-copy_extensions', 'copy']);
  ssl(['req', '-x509', '-new', '-key', 'server.key', '-out', 'untrusted.pem', '-days', '2',
    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1']);
  ssl(['req', '-new', '-key', 'server.key', '-out', 'wrong-host.csr',
    '-subj', '/CN=wrong.invalid', '-addext', 'subjectAltName=DNS:wrong.invalid']);
  ssl(['x509', '-req', '-in', 'wrong-host.csr', '-CA', 'ca.pem', '-CAkey', 'ca.key', '-CAcreateserial',
    '-out', 'wrong-host.pem', '-days', '2', '-copy_extensions', 'copy']);
  for (const file of ['ca.key', 'server.key']) fs.chmodSync(path.join(certDir, file), 0o600);
}
const fixture = await startFixture(certDir);
const env = { ...process.env, FLOWSPEECH_FIXTURE_ENDPOINTS: JSON.stringify(fixture.endpoints),
  TMPDIR: path.join(root, '.tools/tmp'), NO_PROXY: '127.0.0.1,localhost', no_proxy: '127.0.0.1,localhost' };
delete env.FLOWSPEECH_API_KEY;
const target = process.argv[2];
const args = ['-d', 'curl.cainfo=' + path.join(certDir, 'ca.pem'),
  '-d', 'openssl.cafile=' + path.join(certDir, 'ca.pem'),
  ...(target ? [path.resolve(target), ...process.argv.slice(3)] :
    [phpunit, '--configuration', path.join(root, 'phpunit.xml.dist'),
      '--log-junit', path.join(root, '.tools/logs/phpunit-transport.xml')])];
try {
  const code = await new Promise((resolve, reject) => {
    const child = spawn(php, args, { cwd: root, env, stdio: 'inherit' });
    child.once('error', reject);
    child.once('exit', resolve);
  });
  process.exitCode = code ?? 1;
} finally {
  await fixture.close();
}
