'use strict';
// Découverte automatique des Bibles d'une langue (fausse API, aucun appel réseau).
process.env.API_BIBLE_KEY = 'test';
delete process.env.API_BIBLE_VERSIONS;
process.env.API_BIBLE_URL = 'https://rest.api.bible';
const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const AB = require('../server/lib/apibible');
const { loadEnv } = require('../server/lib/env');

const calls = [];
const fake = async url => {
  calls.push(url);
  const json = data => ({ ok: true, json: async () => ({ data }) });
  if (url.endsWith('/v1/bibles?language=fra')) {
    return json([
      { id: 'b1', abbreviationLocal: 'LSG', nameLocal: 'Louis Segond (API)', language: { id: 'fra' }, copyright: 'Domaine public' },
      { id: 'b2', abbreviationLocal: 'S21', nameLocal: 'Segond 21', language: { id: 'fra' }, copyright: '© SBG' },
    ]);
  }
  if (url.includes('/books?')) return json([{ id: 'JHN', chapters: [{ number: '1' }, { number: '2' }, { number: '3' }] }]);
  return { ok: false, status: 401, text: async () => 'Unauthorized', json: async () => ({}) };
};

test('point de terminaison rest.api.bible', () => {
  assert.strictEqual(AB.base(), 'https://rest.api.bible/v1');
});

test('découverte automatique, sans conflit avec les versions locales', async () => {
  const list = await AB.init(fake, ['LSG', 'JND']);
  assert.deepStrictEqual(list.map(v => v.id), ['LSG-AB', 'S21']);
  assert.strictEqual(AB.meta('S21').name, 'Segond 21');
  assert.strictEqual(AB.meta('S21').lang, 'fr');
  assert.strictEqual(AB.meta('S21').license, '© SBG');
  assert.ok(calls[0].startsWith('https://rest.api.bible/v1/bibles?language=fra'));
});

test('clé refusée : message clair', async () => {
  await assert.rejects(AB.chapter('S21', 'John', 3, fake), /clé refusée/);
});

test('fichier .env', () => {
  const f = path.join(os.tmpdir(), `mp-env-${process.pid}`);
  fs.writeFileSync(f, '# commentaire\nMP_TEST_A=bonjour\nMP_TEST_B="avec espaces"\n');
  loadEnv(f);
  assert.strictEqual(process.env.MP_TEST_A, 'bonjour');
  assert.strictEqual(process.env.MP_TEST_B, 'avec espaces');
  fs.unlinkSync(f);
});
