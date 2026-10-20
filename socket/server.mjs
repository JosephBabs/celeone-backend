import http from 'node:http';
import express from 'express';
import { Server } from 'socket.io';
import Redis from 'ioredis';
import jwt from 'jsonwebtoken';

const env = process.env;
const port = Number(env.SOCKET_PORT || 4100);
const path = env.SOCKET_PATH || '/socket.io';
const api = (env.API_INTERNAL_URL || 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '');
if (!env.SOCKET_JWT_SECRET || !env.SOCKET_INTERNAL_SECRET) {
  throw new Error('SOCKET_JWT_SECRET and SOCKET_INTERNAL_SECRET are required');
}

const app = express();
app.get('/health', (_q, r) => r.json({ ok: true, service: 'celeone-socket', time: new Date().toISOString() }));
const server = http.createServer(app);
const io = new Server(server, {
  path,
  cors: {
    origin: (env.SOCKET_ALLOWED_ORIGINS || 'https://app.celeonetv.com').split(','),
    methods: ['GET', 'POST'],
  },
  pingInterval: 25000,
  pingTimeout: 15000,
  maxHttpBufferSize: 2e6,
});

io.use((socket, next) => {
  try {
    const token = socket.handshake.auth?.token || String(socket.handshake.headers.authorization || '').replace(/^Bearer\s+/i, '');
    const user = jwt.verify(token, env.SOCKET_JWT_SECRET, {
      issuer: env.SOCKET_JWT_ISSUER || 'celeone-api',
      audience: env.SOCKET_JWT_AUDIENCE || 'celeone-socket',
    });
    socket.data.user = user;
    next();
  } catch {
    next(new Error('UNAUTHENTICATED'));
  }
});

async function authorize(userId, channel) {
  const r = await fetch(`${api}/internal/socket/authorize`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-socket-internal-secret': env.SOCKET_INTERNAL_SECRET },
    body: JSON.stringify({ user_id: Number(userId), channel }),
  });
  if (!r.ok) return false;
  return Boolean((await r.json()).data?.authorized);
}

async function persistMessage(userId, payload) {
  const r = await fetch(`${api}/internal/socket/message`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'x-socket-internal-secret': env.SOCKET_INTERNAL_SECRET },
    body: JSON.stringify({
      user_id: Number(userId),
      conversation_id: String(payload?.chatId || ''),
      client_id: String(payload?.clientId || payload?.requestId || ''),
      type: payload?.payload?.type || 'text',
      text: payload?.payload?.text || payload?.payload?.body || null,
      metadata: payload?.payload || {},
    }),
  });
  if (!r.ok) throw new Error(`MESSAGE_${r.status}`);
  return (await r.json()).data || {};
}

function normalizedEnvelope(conversation, message = {}) {
  const messageId = String(message.firebase_id || message.id || message.client_id || '');
  const metadata = typeof message.metadata === 'string'
    ? (() => { try { return JSON.parse(message.metadata); } catch { return {}; } })()
    : (message.metadata || {});
  const from = metadata.from || {
    uid: String(metadata.fromUid || message.user_id || ''),
    name: metadata.fromName || 'User',
    avatar: metadata.fromAvatar || '',
  };
  return {
    chatId: String(conversation),
    messageId,
    msg: {
      ...metadata,
      clientId: message.client_id || metadata.clientId || messageId,
      from,
      type: message.message_type || metadata.type || 'text',
      text: message.body ?? metadata.text ?? null,
      createdAtMs: Number(metadata.createdAtMs || (message.created_at ? Date.parse(message.created_at) : 0) || Date.now()),
    },
  };
}

// Socket-send and Redis outbox may see the same persisted message. Keep a tiny
// process-local dedupe window so clients get instant delivery without duplicate
// notifications when the outbox arrives milliseconds later.
const emittedMessages = new Map();
function emissionKey(conversation, message) {
  return `${conversation}:${message?.firebase_id || message?.id || message?.client_id || ''}`;
}
function rememberEmission(key) {
  if (!key || key.endsWith(':')) return;
  const now = Date.now();
  emittedMessages.set(key, now);
  if (emittedMessages.size > 2000) {
    for (const [k, at] of emittedMessages) {
      if (now - at > 60_000 || emittedMessages.size > 1500) emittedMessages.delete(k);
    }
  }
}
function recentlyEmitted(key) {
  const at = emittedMessages.get(key);
  return !!at && Date.now() - at < 15_000;
}

function emitMessage(conversation, message, recipientIds = []) {
  const envelope = normalizedEnvelope(conversation, message);
  const key = emissionKey(conversation, message);
  if (recentlyEmitted(key)) return envelope;
  rememberEmission(key);
  io.to(`private-conversation.${conversation}`).emit('msg:new', envelope);
  for (const recipient of recipientIds || []) {
    io.to(`private-user.${recipient}`).emit('msg:new', envelope);
  }
  return envelope;
}

io.on('connection', socket => {
  const uid = String(socket.data.user.sub);
  socket.join(`private-user.${uid}`);

  const join = async ({ channel, chatId } = {}, ack = () => {}) => {
    const target = channel || `private-conversation.${chatId || ''}`;
    try {
      if (!target || !(await authorize(uid, String(target)))) return ack({ ok: false, error: 'FORBIDDEN' });
      socket.join(String(target));
      ack({ ok: true });
    } catch {
      ack({ ok: false, error: 'UNAVAILABLE' });
    }
  };
  socket.on('channel:join', join);
  socket.on('chat:join', join);
  socket.on('channel:leave', ({ channel, chatId } = {}) => {
    const target = channel || `private-conversation.${chatId || ''}`;
    if (target) socket.leave(String(target));
  });

  socket.on('msg:send', async (payload = {}, ack = () => {}) => {
    try {
      const saved = await persistMessage(uid, payload);
      const conversation = String(saved.conversation_id || payload.chatId);
      const message = saved.message || {};
      const envelope = emitMessage(conversation, message, saved.recipient_ids || []);
      ack({ ok: true, messageId: envelope.messageId || payload.clientId });
    } catch (error) {
      ack({ ok: false, error: error?.message || 'MESSAGE_FAILED' });
    }
  });

  socket.on('typing:start', p => p?.conversation_id && socket.to(`private-conversation.${p.conversation_id}`).emit('typing.started', {
    event_id: `typing-${Date.now()}-${uid}`, event: 'typing.started', occurred_at: new Date().toISOString(), version: 1,
    data: { conversation_id: p.conversation_id, user_id: uid },
  }));
  socket.on('typing:stop', p => p?.conversation_id && socket.to(`private-conversation.${p.conversation_id}`).emit('typing.stopped', {
    event_id: `typing-${Date.now()}-${uid}`, event: 'typing.stopped', occurred_at: new Date().toISOString(), version: 1,
    data: { conversation_id: p.conversation_id, user_id: uid },
  }));
  for (const event of ['call.offer', 'call.answer', 'call.ice', 'call.join', 'call.leave', 'call.end']) {
    socket.on(event, p => p?.call_id && socket.to(`private-call.${p.call_id}`).emit(event, {
      event_id: `call-${Date.now()}-${uid}`, event, occurred_at: new Date().toISOString(), version: 1,
      data: { ...p, user_id: uid },
    }));
  }
});

const redis = new Redis(env.REDIS_URL || 'redis://127.0.0.1:6379');
await redis.subscribe('celeone:outbox');
redis.on('message', (_channel, raw) => {
  try {
    const e = JSON.parse(raw);
    const type = e.aggregate_type;
    const payload = e.payload || {};
    const id = String(payload.conversation_id || e.aggregate_id || '');

    if (e.event === 'message.created' && type === 'conversation' && payload.message) {
      emitMessage(id, payload.message, payload.recipient_ids || []);
      return;
    }

    const rooms = [];
    if (type === 'conversation') rooms.push(`private-conversation.${id}`);
    if (type === 'post') rooms.push(`public-post.${id}`);
    if (type === 'live') rooms.push(`public-live.${id}`);
    if (type === 'user') rooms.push(`private-user.${id}`);
    for (const recipient of payload.recipient_ids || []) rooms.push(`private-user.${recipient}`);
    for (const room of new Set(rooms)) io.to(room).emit(e.event, e);
  } catch (error) {
    console.error('outbox event rejected', error.message);
  }
});

server.listen(port, '127.0.0.1', () => console.log(`celeone socket listening on 127.0.0.1:${port}${path}`));
