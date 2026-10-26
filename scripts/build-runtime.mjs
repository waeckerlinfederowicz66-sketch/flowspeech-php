import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dir = path.join(root, '.tools');
for (const name of ['downloads', 'src', 'logs', 'tmp', 'composer-home', 'composer-cache']) fs.mkdirSync(path.join(dir, name), { recursive: true });
const env = { ...process.env, TMPDIR: path.join(dir, 'tmp'), LC_ALL: 'C', COPYFILE_DISABLE: '1',
  COMPOSER_HOME: path.join(dir, 'composer-home'), COMPOSER_CACHE_DIR: path.join(dir, 'composer-cache') };
function fetch(url, file) {
  if (!fs.existsSync(file)) execFileSync('/usr/bin/curl', ['--fail', '--location', '--silent', '--show-error', '--proto', '=https', '--tlsv1.2', '--max-time', '180', url, '-o', file], { env });
}
function verified(url, file, expected) {
  fetch(url, file);
  const actual = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
  if (actual !== expected) throw new Error('Checksum mismatch for ' + url);
  return { url, sha256: actual };
}
function run(command, args, cwd, logName, extraEnv = {}) {
  console.log(command + ' ' + args.join(' '));
  const fd = fs.openSync(path.join(dir, 'logs', logName), 'w');
  try { execFileSync(command, args, { cwd, env: { ...env, ...extraEnv }, stdio: ['ignore', fd, fd] }); }
  finally { fs.closeSync(fd); }
}
const provenance = [];
const phpArchive = path.join(dir, 'downloads/php-8.4.27.tar.xz');
provenance.push(verified('https://www.php.net/distributions/php-8.4.27.tar.xz', phpArchive,
  'dcf93923b0e38e0d5252b25b796520b5a47761a08b3488b6d0b5d1115764d3f0'));
const sslUrl = 'https://github.com/openssl/openssl/releases/download/openssl-3.5.9/openssl-3.5.9.tar.gz';
const sslSum = path.join(dir, 'downloads/openssl-3.5.9.tar.gz.sha256');
fetch(sslUrl + '.sha256', sslSum);
const expectedSSL = fs.readFileSync(sslSum, 'utf8').trim().split(/\s+/)[0];
if (expectedSSL !== '603f5602e2eef00d77fbd429d34dcd5822bb301757a1bc9cdb24c670f1eb859a') {
  throw new Error('Official OpenSSL checksum does not match the pinned release.');
}
const sslArchive = path.join(dir, 'downloads/openssl-3.5.9.tar.gz');
provenance.push(verified(sslUrl, sslArchive, expectedSSL));
const composer = path.join(dir, 'composer.phar');
provenance.push(verified('https://getcomposer.org/download/2.10.3/composer.phar', composer,
  '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'));
fs.writeFileSync(path.join(dir, 'provenance.json'), JSON.stringify(provenance, null, 2) + '\n');
const sslSrc = path.join(dir, 'src/openssl-3.5.9');
const phpSrc = path.join(dir, 'src/php-8.4.27');
const sslPrefix = path.join(dir, 'openssl');
const phpPrefix = path.join(dir, 'php');
if (!fs.existsSync(sslSrc)) run('tar', ['-xzf', sslArchive, '-C', path.join(dir, 'src')], root, 'openssl-extract.log');
if (!fs.existsSync(phpSrc)) run('tar', ['-xJf', phpArchive, '-C', path.join(dir, 'src')], root, 'php-extract.log');
if (!fs.existsSync(path.join(sslPrefix, 'lib/libssl.a'))) {
  run('./Configure', ['darwin64-arm64-cc', 'no-shared', 'no-tests', '--prefix=' + sslPrefix, '--libdir=lib', '-O1'], sslSrc, 'openssl-configure.log');
  run('make', ['-j4'], sslSrc, 'openssl-make.log');
  run('make', ['install_sw'], sslSrc, 'openssl-install.log');
}
if (!fs.existsSync(path.join(phpPrefix, 'bin/php'))) {
  const sdk = execFileSync('xcrun', ['--show-sdk-path'], { encoding: 'utf8' }).trim();
  const buildEnv = { CFLAGS: '-O1 -arch arm64', LDFLAGS: '-arch arm64',
    OPENSSL_CFLAGS: '-I' + sslPrefix + '/include',
    OPENSSL_LIBS: '-L' + sslPrefix + '/lib -lssl -lcrypto',
    CURL_CFLAGS: '-I' + sdk + '/usr/include', CURL_LIBS: '-lcurl',
    LIBXML_CFLAGS: '-I' + sdk + '/usr/include/libxml2', LIBXML_LIBS: '-lxml2',
    SQLITE_CFLAGS: '-I' + sdk + '/usr/include', SQLITE_LIBS: '-lsqlite3',
    ZLIB_CFLAGS: '-I' + sdk + '/usr/include', ZLIB_LIBS: '-lz' };
  run('./configure', ['--prefix=' + phpPrefix, '--with-config-file-path=' + phpPrefix + '/etc',
    '--disable-all', '--enable-cli', '--disable-cgi', '--disable-phpdbg', '--without-pear',
    '--with-openssl', '--with-curl', '--with-libxml', '--enable-dom', '--enable-filter',
    '--enable-mbstring', '--disable-mbregex', '--enable-phar', '--enable-tokenizer',
    '--enable-xml', '--enable-xmlwriter', '--enable-xmlreader', '--enable-simplexml',
    '--with-iconv=' + sdk + '/usr', '--enable-session', '--enable-pdo', '--with-pdo-sqlite', '--with-sqlite3',
    '--enable-fileinfo', '--enable-ctype', '--enable-posix', '--enable-pcntl', '--with-zlib',
    '--enable-bcmath', '--with-mysqli=mysqlnd'], phpSrc, 'php-configure.log', buildEnv);
  run('make', ['-j4'], phpSrc, 'php-make.log', buildEnv);
  run('make', ['install'], phpSrc, 'php-install.log', buildEnv);
}
console.log(execFileSync(path.join(phpPrefix, 'bin/php'), ['-v'], { encoding: 'utf8', env }));
console.log(execFileSync(path.join(phpPrefix, 'bin/php'), ['-m'], { encoding: 'utf8', env }));
