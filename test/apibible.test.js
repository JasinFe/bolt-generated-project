'use strict';
// Connecteur API.Bible testé avec une fausse API (aucun appel réseau).
process.env.API_BIBLE_KEY = 'test';
process.env.API_BIBLE_VERSIONS = 'S21=abc123|Segond 21|Bible Segond 21|fr';
const test = require('node:test');
const assert = require('node:assert');
const AB = require('../server/lib/apibible');

const fake = async url => {
  const json = data => ({ ok: true, json: async () => ({ data }) });
  if (url.endsWith('/bibles/abc123')) return json({ name: 'Segond 21', copyright: '© Société Biblique de Genève' });
  if (url.includes('/books?')) return json([{ id: 'JHN', chapters: [{ number: 'intro' }, { number: '1' }, { number: '2' }, { number: '3' }] }]);
  if (url.includes('/chapters/JHN.3?')) {
    return json({ copyright: '© SBG', content: [{ type: 'tag', name: 'para', items: [
      { type: 'text', text: 'Dieu a tant aimé ', attrs: { verseId: 'JHN.3.16' } },
      { type: 'text', text: 'le monde…', attrs: { verseId: 'JHN.3.16' } },
      { type: 'text', text: 'Dieu n’a pas envoyé…', attrs: { verseId: 'JHN.3.17' } },
    ] }] });
  }
  if (url.includes('/search?')) return json({ total: 1, verses: [{ id: 'JHN.3.16', text: '<b>Dieu</b> a tant aimé' }] });
  return { ok: false, status: 404, json: async () => ({}) };
};

test('configuration', () => {
  assert.deepStrictEqual(AB.parseConfig('A=x|Court|Long|fr; B=y'), [
    { id: 'A', bibleId: 'x', short: 'Court', name: 'Long', lang: 'fr' },
    { id: 'B', bibleId: 'y', short: 'B', name: 'B', lang: 'fr' },
  ]);
});

test('initialisation, chapitre, plage, recherche', async () => {
  const list = await AB.init(fake);
  assert.strictEqual(list.length, 1);
  assert.ok(AB.isRemote('S21'));
  assert.strictEqual(AB.meta('S21').books.John, 3);
  assert.match(AB.meta('S21').license, /Genève/);
  const ch = await AB.chapter('S21', 'John', 3, fake);
  assert.strictEqual(ch.verses[0].text, 'Dieu a tant aimé le monde…');
  const r = await AB.rangeVerses('S21', { book: 'John', chapter: 3, verse: 17, endChapter: 3, endVerse: 17 }, 10, fake);
  assert.deepStrictEqual(r.map(x => x.v), [17]);
  const s = await AB.search('S21', 'Dieu', {}, fake);
  assert.strictEqual(s.results[0].ref, 'Jean 3:16');
  assert.strictEqual(s.results[0].text, 'Dieu a tant aimé');
});
