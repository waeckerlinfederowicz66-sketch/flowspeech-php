import http from 'node:http';
import https from 'node:https';
import fs from 'node:fs';

export async function startFixture(certDir) {
  let scenario = 'success';
  let records = [];
  let created = 0;
  let reads = 0;
  let downloaded = 0;
  let requestId;
  let endpoints;
  const id = 'b698824b-de05-486d-8833-05d5c4522a39';
  const wav = Buffer.alloc(46);
  wav.write('RIFF'); wav.writeUInt32LE(38, 4); wav.write('WAVEfmt ', 8);
  wav.writeUInt32LE(16, 16); wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
  wav.writeUInt32LE(24000, 24); wav.writeUInt32LE(48000, 28);
  wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
  wav.write('data', 36); wav.writeUInt32LE(2, 40);
  const sockets = new Set();
  const send = (res, data, status = 200, headers = {}) => {
    res.writeHead(status, { 'Content-Type': 'application/json', ...headers });
    res.end(JSON.stringify(data));
  };
  const handler = (role) => async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = Buffer.concat(chunks).toString();
    if (role === 'http' && req.url === '/control') {
      if (req.method === 'POST') {
        ({ scenario } = JSON.parse(body));
        records = []; created = 0; reads = 0; downloaded = 0;
      }
      send(res, { endpoints, records, created, reads, downloaded });
      return;
    }
    records.push({ role, method: req.method, path: req.url, headers: req.headers, body, at: Date.now() });
    if (role === 'api') {
      const interrupted = /^api-(truncated|slow-body)-(401|429|503)$/.exec(scenario);
      if (interrupted) {
        res.writeHead(Number(interrupted[2]), { 'Content-Type': 'application/json',
          'Content-Length': '100', 'Retry-After': '30', Connection: 'close' });
        res.write('{');
        if (interrupted[1] === 'truncated') res.end();
        else {
          const timer = setTimeout(() => res.end(), 2500);
          req.socket.once('close', () => clearTimeout(timer));
        }
        return;
      }
      if (scenario === 'privacy-http-key' || scenario === 'privacy-http-url') {
        const reflected = scenario === 'privacy-http-key' ? 'fixture-only' : 'https://private.example.test/?signature=private-fixture';
        send(res, { error: { code: reflected, message: reflected, retryable: false }, traceId: reflected }, 403); return;
      }
      if (scenario === 'api-redirect') {
        send(res, {}, 307, { Location: endpoints.storage + '/sink' }); return;
      }
      if (req.url === '/api/v1/quota') {
        send(res, { quota: { available: 20, remaining: 23, reserved: 3 },
          subscription: scenario === 'quota-active' ? { plan: 'basic', status: 'active' } : null, limits: {} }); return;
      }
      if (req.url === '/api/v1/voices') {
        send(res, { voices: [{ name: 'Kore', description: 'Fixture voice', gender: 'female' }] }); return;
      }
      if (req.method === 'POST' && req.url === '/api/v1/tts/jobs') {
        created++;
        requestId = req.headers['idempotency-key'];
        if (scenario === 'retry' && created === 1) {
          send(res, { error: { code: 'busy', message: 'Local fixture busy', retryable: true }, traceId: 'fixture-trace' }, 503, { 'Retry-After': '6' }); return;
        }
        if (scenario === 'lost-submit' && created === 1) { req.socket.destroy(); return; }
      } else {
        reads++;
      }
      let status = (scenario === 'queued' || scenario.startsWith('identity-poll-')) && created > 0 && reads === 0 ? 'queued' : 'success';
      if (['failed', 'cancelled'].includes(scenario)) status = scenario;
      if (scenario === 'privacy-terminal') status = 'failed';
      if (scenario === 'privacy-null-queued') status = 'queued';
      const result = { url: endpoints.storage + '/recording.wav', mimeType: 'audio/wav',
        sampleRate: 24000, channels: 1, bitsPerSample: 16 };
      if (scenario === 'http-result') result.url = endpoints.http + '/sink';
      if (scenario === 'privacy-download') result.url += '?signature=private-fixture';
      if (scenario === 'credential-url') result.url = endpoints.storage.replace('https://', 'https://user:fixture@') + '/recording.wav';
      const job = { id, requestId, status, result: status === 'success' ? result : null,
        usage: { billableCharacters: 5, reservedCredits: 0, chargedCredits: status === 'success' ? 5 : 0 } };
      if (scenario === 'identity-missing' || (scenario === 'identity-poll-missing' && reads > 0)) delete job.requestId;
      if (scenario === 'identity-poll-wrong' && reads > 0) job.requestId = id;
      if (scenario === 'identity-poll-uppercase') { job.id = id.toUpperCase(); job.requestId = requestId.toUpperCase(); }
      if (scenario === 'identity-other') {
        job.id = 'af451078-92bd-4539-9e20-cbcf0b21e4f8';
        job.requestId = 'e767d2eb-620c-4c1c-a4a2-202bdd16a783';
      }
      if (scenario === 'deleted') { job.result = null; job.resultDeleted = true; }
      if (scenario === 'malformed') job.result.sampleRate = 48000;
      if (status === 'failed' || status === 'cancelled') job.error = { code: 'FIXTURE_TERMINAL' };
      if (scenario.startsWith('privacy-null-')) job.error = null;
      if (scenario === 'privacy-terminal') {
        job.error = { code: 'fixture-only', message: 'https://private.example.test/?signature=private-fixture',
          retryable: true, details: { private: 'fixture-only' } };
        job.traceId = 'fixture-only';
      }
      send(res, job, status === 'queued' ? 202 : 200, { 'Retry-After': '5', 'Set-Cookie': 'fixture-session=not-a-secret; Path=/; Secure' });
      return;
    }
    if (role === 'storage') {
      downloaded++;
      if (scenario === 'privacy-download') {
        send(res, { error: { message: 'https://private.example.test/?signature=private-fixture' } }, 403); return;
      }
      if (scenario === 'storage-redirect') {
        res.writeHead(302, { Location: endpoints.http + '/sink' }); res.end(); return;
      }
      if (scenario === 'download-retry' && downloaded === 1) {
        res.writeHead(503, { 'Retry-After': '5' }); res.end(); return;
      }
      if (scenario === 'oversize') { res.end(Buffer.alloc(8192)); return; }
      if (scenario === 'truncated') {
        res.writeHead(200, { 'Content-Length': wav.length + 10, Connection: 'close' }); res.end(wav); return;
      }
      if (scenario === 'invalid-wav') { res.end('not a WAV'); return; }
      if (scenario === 'slow') {
        res.writeHead(200, { 'Content-Length': wav.length });
        res.write(wav.subarray(0, 10));
        const timer = setTimeout(() => res.end(wav.subarray(10)), 2500);
        req.socket.once('close', () => clearTimeout(timer));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'audio/wav' }); res.end(wav); return;
    }
    send(res, { unexpected: true });
  };
  const key = fs.readFileSync(certDir + '/server.key');
  const cert = fs.readFileSync(certDir + '/server.pem');
  const servers = {
    http: http.createServer(handler('http')),
    api: https.createServer({ key, cert }, handler('api')),
    storage: https.createServer({ key, cert }, handler('storage')),
    untrusted: https.createServer({ key, cert: fs.readFileSync(certDir + '/untrusted.pem') }, handler('untrusted')),
    wrongHost: https.createServer({ key, cert: fs.readFileSync(certDir + '/wrong-host.pem') }, handler('wrongHost')),
  };
  endpoints = {};
  for (const [name, server] of Object.entries(servers)) {
    server.on('connection', (socket) => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    endpoints[name] = (name === 'http' ? 'http' : 'https') + '://127.0.0.1:' + server.address().port;
  }
  return { endpoints, close: async () => {
    for (const socket of sockets) socket.destroy();
    await Promise.all(Object.values(servers).map((server) => new Promise((resolve) => server.close(resolve))));
  }};
}
