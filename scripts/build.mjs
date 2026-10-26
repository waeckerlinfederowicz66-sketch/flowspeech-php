import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const version = fs.readFileSync(path.join(root, 'VERSION'), 'utf8').trim();
if (!/^\d+\.\d+\.\d+$/.test(version)) throw new Error('Invalid release version');
const slug = 'flowspeech-php';
const allow = ["src","composer.json","README.md","CHANGELOG.md","LICENSE","VERSION","examples"];
const dist = path.join(root, 'dist');
const archiveEnv = { ...process.env, LC_ALL: 'C', COPYFILE_DISABLE: '1' };
const stage = path.join(dist, 'stage', slug);
fs.mkdirSync(dist, { recursive: true });
// Only generated distribution state is replaced; source and untracked work stay intact.
fs.rmSync(path.join(dist, 'stage'), { recursive: true, force: true });
fs.mkdirSync(stage, { recursive: true });
for (const file of allow) fs.cpSync(path.join(root, file), path.join(stage, file), { recursive: true, dereference: false });

const manifest = [];
function inspect(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) throw new Error('No symlinks allowed: ' + absolute);
    if (/^(\.env|\.git|\.tools|vendor|node_modules|tests|\.DS_Store|RELEASE_REPORT\.md)$/.test(entry.name)) {
      throw new Error('Unintended package entry: ' + absolute);
    }
    if (entry.isDirectory()) { inspect(absolute); continue; }
    const bytes = fs.readFileSync(absolute);
    const content = bytes.toString('utf8');
    if (/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|(?:sk|fs)_[A-Za-z0-9_-]{24,}/.test(content)) {
      throw new Error('Possible secret in package: ' + absolute);
    }
    manifest.push({ path: path.relative(stage, absolute), bytes: bytes.length,
      sha256: crypto.createHash('sha256').update(bytes).digest('hex') });
  }
}
inspect(stage);
manifest.sort((a, b) => a.path.localeCompare(b.path));
const format = 'tar';
const filename = slug + '-' + version + (format === 'zip' ? '.zip' : '.tar.gz');
const archive = path.join(dist, filename);
fs.rmSync(archive, { force: true });
if (format === 'zip') {
  execFileSync('zip', ['-q', '-r', archive, slug], { cwd: path.dirname(stage) });
} else {
  execFileSync('tar', ['-czf', archive, '-C', path.dirname(stage), slug], { env: archiveEnv });
}
const checksum = crypto.createHash('sha256').update(fs.readFileSync(archive)).digest('hex');
fs.writeFileSync(path.join(dist, 'manifest.json'), JSON.stringify({ version, archive: filename, sha256: checksum, files: manifest }, null, 2) + '\n');
fs.writeFileSync(archive + '.sha256', checksum + '  ' + filename + '\n');
const verify = path.join(dist, 'verify');
fs.rmSync(verify, { recursive: true, force: true });
fs.mkdirSync(verify, { recursive: true });
if (format === 'zip') execFileSync('unzip', ['-q', archive, '-d', verify]);
else execFileSync('tar', ['-xzf', archive, '-C', verify], { env: archiveEnv });
for (const file of manifest) {
  const bytes = fs.readFileSync(path.join(verify, slug, file.path));
  if (crypto.createHash('sha256').update(bytes).digest('hex') !== file.sha256) throw new Error('Archive verification mismatch: ' + file.path);
}
const extracted = fs.readdirSync(path.join(verify, slug), { recursive: true, withFileTypes: true });
if (extracted.some((entry) => entry.isSymbolicLink()) ||
    extracted.filter((entry) => entry.isFile()).length !== manifest.length) {
  throw new Error('Unexpected files in extracted archive');
}
console.log(JSON.stringify({ archive, files: manifest.length, sha256: checksum, verifiedRoot: path.join(verify, slug) }, null, 2));
