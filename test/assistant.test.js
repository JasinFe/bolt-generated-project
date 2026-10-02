'use strict';
// Assistant IA testé contre une fausse API Anthropic locale (aucun appel réseau, aucun coût).
const test = require('node:test');
const assert = require('node:assert');
const http = require('http');

let fake;
let lastBody;
test.before(async () => {
  fake = http.createServer((req, res) => {
    let data = '';
    req.on('data', c => { data += c; });
    req.on('end', () => {
      lastBody = JSON.parse(data);
      res.writeHead(200, { 'Content-Type': 'text/event-stream' });
      const ev = (type, obj) => res.write(`event: ${type}\ndata: ${JSON.stringify({ type, ...obj })}\n\n`);
      ev('message_start', { message: { id: 'msg_1', type: 'message', role: 'assistant', model: lastBody.model, content: [], stop_reason: null, stop_sequence: null, usage: { input_tokens: 10, output_tokens: 0 } } });
      ev('content_block_start', { index: 0, content_block: { type: 'text', text: '' } });
      ev('content_block_delta', { index: 0, delta: { type: 'text_delta', text: '## Contexte\n' } });
      ev('content_block_delta', { index: 0, delta: { type: 'text_delta', text: 'Jean 3:16 révèle l’amour de Dieu.' } });
      ev('content_block_stop', { index: 0 });
      ev('message_delta', { delta: { stop_reason: 'end_turn', stop_sequence: null }, usage: { output_tokens: 12 } });
      ev('message_stop', {});
      res.end();
    });
  });
  await new Promise(r => fake.listen(0, r));
  process.env.ANTHROPIC_API_KEY = 'test';
  process.env.ANTHROPIC_BASE_URL = `http://127.0.0.1:${fake.address().port}`;
});
test.after(() => fake.close());

test('contexte biblique fourni au modèle', () => {
  const { buildContext } = require('../server/lib/assistant');
  const ctx = buildContext('Jean 3:16', 'LSG');
  assert.strictEqual(ctx.label, 'Jean 3:16');
  assert.match(ctx.text, /Car Dieu a tant aimé le monde/);
  assert.match(ctx.text, /<mots_originaux>[\s\S]*G25/);
  assert.match(ctx.text, /<references_croisees>/);
});

test('réponse en continu, modèle et paramètres attendus', async () => {
  const A = require('../server/lib/assistant');
  let text = '';
  const out = await A.ask({ mode: 'expliquer', ref: 'Jean 3:16', version: 'LSG' }, t => { text += t; });
  assert.strictEqual(text, '## Contexte\nJean 3:16 révèle l’amour de Dieu.');
  assert.strictEqual(out.stopReason, 'end_turn');
  assert.strictEqual(lastBody.model, 'claude-opus-5-5');
  assert.deepStrictEqual(lastBody.thinking, { type: 'adaptive' });
  assert.strictEqual(lastBody.fallbacks, 'default');
  assert.match(lastBody.messages.at(-1).content, /Passage étudié : Jean 3:16/);
});

test('sans passage ni question : erreur claire', async () => {
  const A = require('../server/lib/assistant');
  await assert.rejects(A.ask({ mode: 'libre' }, () => {}), /passage ou posez une question/);
});
