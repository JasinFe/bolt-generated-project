'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { createServer } = require('../server/index');

let server;
let base;
test.before(async () => {
  server = createServer();
  await new Promise(r => server.listen(0, r));
  base = `http://127.0.0.1:${server.address().port}`;
});
test.after(() => server.close());

const get = async path => { const r = await fetch(base + path); return { status: r.status, body: await r.json().catch(() => null) }; };

test('API : santé, versions, lookup', async () => {
  assert.strictEqual((await get('/api/health')).body.ok, true);
  assert.strictEqual((await get('/api/versions')).body.length, 8);
  const ref = await get('/api/lookup?q=' + encodeURIComponent('Ps 23'));
  assert.strictEqual(ref.body.type, 'passage');
  const words = await get('/api/lookup?q=berger');
  assert.strictEqual(words.body.type, 'search');
});

test('API : erreurs propres', async () => {
  assert.strictEqual((await get('/api/chapter?v=XXX&b=Gen&c=1')).status, 404);
  assert.strictEqual((await get('/api/passage?ref=nimportequoi')).status, 400);
  assert.strictEqual((await get('/api/inconnu')).status, 404);
});

test('statique : page, sécurité des chemins, URL invalide', async () => {
  const r = await fetch(base + '/');
  assert.match(await r.text(), /Mister Preacher/);
  assert.strictEqual((await fetch(base + '/%E0%A4%A')).status, 400);
  const t = await fetch(base + '/%2e%2e%2fpackage.json');
  assert.ok([403, 400].includes(t.status) || !(await t.text()).includes('"scripts"'));
});

test('API : thèmes, évangélisation, palette', async () => {
  const th = await get('/api/theme?id=salut');
  assert.ok(th.body.passages.every(p => p.verses.length));
  const ev = await get('/api/evangelisation');
  assert.ok(ev.body.plans.every(p => p.steps.every(s => s.verses.length)));
  const pal = await fetch(base + '/api/palette', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ refs: ['Psaumes 23'] }) });
  assert.ok((await pal.json()).some(w => w.word === 'berger'));
});
