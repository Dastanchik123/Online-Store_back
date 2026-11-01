// Локальная замена Soketi для разработки. Soketi 1.6 не ставится без git
// (uWebSockets.js тянется с GitHub) и не поддерживает Node 22, поэтому здесь
// минимальная реализация Pusher-протокола — ровно то, что нужно проекту:
//   - WS  /app/{key}               — клиенты (laravel-echo / pusher-js)
//   - POST /apps/{id}/events       — Laravel (BROADCAST_DRIVER=pusher)
//   - POST /apps/{id}/batch_events
// Публичные и private-каналы (подпись auth проверяется). Presence не нужен.
// Приложение/ключи/порт берутся из ../soketi.config.json — как у Soketi.
// В проде используется настоящий Soketi (fly.io), этот файл туда не идёт.

const http = require('http');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { WebSocketServer } = require('ws');

const config = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'soketi.config.json'), 'utf8'));
const app = config.appManager.array.apps[0];
const port = Number(process.env.PORT || config.port || 6001);
const debug = !!config.debug;

const log = (...args) => debug && console.log(new Date().toISOString(), ...args);

// channel -> Set<ws>
const channels = new Map();

function send(ws, event, data, channel) {
  const msg = { event, data: typeof data === 'string' ? data : JSON.stringify(data) };
  if (channel) msg.channel = channel;
  if (ws.readyState === ws.OPEN) ws.send(JSON.stringify(msg));
}

function broadcast(channel, event, data, exceptSocketId) {
  const subs = channels.get(channel);
  if (!subs) return 0;
  let n = 0;
  for (const ws of subs) {
    if (ws.socketId === exceptSocketId) continue;
    send(ws, event, data, channel);
    n++;
  }
  return n;
}

function unsubscribe(ws, channel) {
  const subs = channels.get(channel);
  if (!subs) return;
  subs.delete(ws);
  if (subs.size === 0) channels.delete(channel);
}

function validPrivateAuth(socketId, channel, auth) {
  if (typeof auth !== 'string') return false;
  const [key, sig] = auth.split(':');
  if (key !== app.key || !sig) return false;
  const expected = crypto.createHmac('sha256', app.secret).update(`${socketId}:${channel}`).digest('hex');
  return sig.length === expected.length && crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}

// Pusher HTTP API: auth_signature = HMAC-SHA256(secret, "POST\n{path}\n{sorted query без auth_signature}")
function validApiSignature(method, pathname, query) {
  const params = [...query.entries()].filter(([k]) => k !== 'auth_signature').sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
  const qs = params.map(([k, v]) => `${k}=${v}`).join('&');
  const expected = crypto.createHmac('sha256', app.secret).update(`${method}\n${pathname}\n${qs}`).digest('hex');
  return query.get('auth_key') === app.key && query.get('auth_signature') === expected;
}

function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' });
  res.end(JSON.stringify(body));
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

  if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/ready')) {
    return json(res, 200, { ok: true, connections: wss.clients.size, channels: [...channels.keys()] });
  }

  const m = url.pathname.match(/^\/apps\/([^/]+)\/(events|batch_events)$/);
  if (req.method !== 'POST' || !m) return json(res, 404, { error: 'Not found' });
  if (m[1] !== String(app.id)) return json(res, 404, { error: 'Unknown app' });
  if (!validApiSignature('POST', url.pathname, url.searchParams)) return json(res, 401, { error: 'Invalid signature' });

  let raw = '';
  req.on('data', (c) => (raw += c));
  req.on('end', () => {
    let body;
    try {
      body = JSON.parse(raw || '{}');
    } catch {
      return json(res, 400, { error: 'Invalid JSON' });
    }
    const events = m[2] === 'batch_events'
      ? (body.batch || [])
      : (body.channels || [body.channel]).filter(Boolean).map((channel) => ({ ...body, channel }));
    for (const e of events) {
      const n = broadcast(e.channel, e.name, e.data, e.socket_id);
      log(`[event] ${e.channel} ${e.name} → ${n} client(s)`);
    }
    json(res, 200, {});
  });
});

const wss = new WebSocketServer({ noServer: true });

server.on('upgrade', (req, socket, head) => {
  const url = new URL(req.url, 'http://localhost');
  const m = url.pathname.match(/^\/app\/([^/]+)$/);
  if (!m) return socket.destroy();
  wss.handleUpgrade(req, socket, head, (ws) => {
    if (m[1] !== app.key) {
      send(ws, 'pusher:error', { code: 4001, message: `App key ${m[1]} not in this cluster.` });
      return ws.close(4001);
    }
    wss.emit('connection', ws);
  });
});

wss.on('connection', (ws) => {
  ws.socketId = `${crypto.randomInt(1e9)}.${crypto.randomInt(1e9)}`;
  ws.subscribed = new Set();
  ws.isAlive = true;
  log(`[connect] ${ws.socketId}`);
  send(ws, 'pusher:connection_established', { socket_id: ws.socketId, activity_timeout: 30 });

  ws.on('pong', () => (ws.isAlive = true));
  ws.on('message', (raw) => {
    ws.isAlive = true;
    let msg;
    try {
      msg = JSON.parse(raw.toString());
    } catch {
      return;
    }
    const data = typeof msg.data === 'string' ? (() => { try { return JSON.parse(msg.data); } catch { return {}; } })() : (msg.data || {});

    switch (msg.event) {
      case 'pusher:ping':
        return send(ws, 'pusher:pong', {});
      case 'pusher:subscribe': {
        const channel = data.channel;
        if (!channel) return;
        if (channel.startsWith('presence-')) {
          return send(ws, 'pusher:subscription_error', { type: 'NotSupported', error: 'Presence channels are not supported by dev server', status: 400 }, channel);
        }
        if (channel.startsWith('private-') && !validPrivateAuth(ws.socketId, channel, data.auth)) {
          log(`[subscribe denied] ${ws.socketId} ${channel}`);
          return send(ws, 'pusher:subscription_error', { type: 'AuthError', error: 'Invalid signature', status: 401 }, channel);
        }
        if (!channels.has(channel)) channels.set(channel, new Set());
        channels.get(channel).add(ws);
        ws.subscribed.add(channel);
        log(`[subscribe] ${ws.socketId} ${channel}`);
        return send(ws, 'pusher_internal:subscription_succeeded', {}, channel);
      }
      case 'pusher:unsubscribe':
        if (data.channel) {
          unsubscribe(ws, data.channel);
          ws.subscribed.delete(data.channel);
        }
        return;
    }
  });
  ws.on('close', () => {
    for (const ch of ws.subscribed) unsubscribe(ws, ch);
    log(`[disconnect] ${ws.socketId}`);
  });
});

// Чистим «мёртвые» соединения (ноутбук уснул, вкладку убили без close)
setInterval(() => {
  for (const ws of wss.clients) {
    if (!ws.isAlive) {
      ws.terminate();
      continue;
    }
    ws.isAlive = false;
    ws.ping();
  }
}, 60_000).unref();

server.listen(port, '0.0.0.0', () => {
  console.log(`Dev WebSocket (Pusher protocol) on :${port}, app id=${app.id} key=${app.key}`);
});
