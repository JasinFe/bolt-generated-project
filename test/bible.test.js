'use strict';
const test = require('node:test');
const assert = require('node:assert');
const B = require('../server/lib/bible');
const { compileQuery, matchQuery, normalize } = require('../server/lib/text');

test('normalisation : accents et apostrophes', () => {
  assert.strictEqual(normalize('Éternel'), 'eternel');
  assert.strictEqual(normalize('qu’il'), "qu'il");
  assert.strictEqual(normalize('cœur'), 'coeur');
});

test('requêtes : mots, phrase, préfixe, exclusion', () => {
  const t = 'car dieu a tant aime le monde';
  assert.ok(matchQuery(compileQuery('Dieu monde'), t));
  assert.ok(!matchQuery(compileQuery('Dieu ciel'), t));
  assert.ok(matchQuery(compileQuery('Dieu ciel', 'any'), t));
  assert.ok(matchQuery(compileQuery('"tant aime"'), t));
  assert.ok(!matchQuery(compileQuery('"aime tant"'), t));
  assert.ok(matchQuery(compileQuery('mond*'), t));
  assert.ok(!matchQuery(compileQuery('mond'), t));
  assert.ok(!matchQuery(compileQuery('dieu -monde'), t));
  assert.throws(() => compileQuery('(a+)+', 'regex'));
});

test('passage et chapitre', () => {
  const p = B.passage('JND', 'Jean 3:16');
  assert.strictEqual(p.passages[0].verses.length, 1);
  assert.match(p.passages[0].verses[0].text, /tant/);
  const c = B.chapter('MAR', 'Gen', 1);
  assert.strictEqual(c.verses.length, 31);
  assert.strictEqual(c.verses[0].title, 'Création du monde.');
});

test('recherche plein texte avec surlignage', () => {
  const r = B.search('JND', '"vie éternelle"');
  assert.ok(r.total > 30);
  const first = r.results[0];
  const [a, b] = first.hl[0];
  assert.strictEqual(normalize(first.text.slice(a, b)), 'vie eternelle');
});

test('recherche Strong et fiche lexicale', () => {
  const r = B.search('JND', 'G26');
  assert.ok(r.total > 100);
  const e = B.strongEntry('G26');
  assert.strictEqual(e.lemma, 'ἀγάπη');
  assert.strictEqual(e.usage.JND.words[0].word, 'amour');
  assert.ok(B.strongLookup('grâce').some(x => x.num === 'G5485'));
});

test('références croisées et comparaison', () => {
  const x = B.crossRefs('John.3.16');
  assert.ok(x.total > 10);
  assert.ok(x.refs[0].votes >= x.refs[1].votes);
  const c = B.compare('Jean 3:16');
  assert.ok(c.versions.length >= 6);
});

test('livres deutérocanoniques et apocryphes', () => {
  assert.ok(B.chapter('CRA', 'Tob', 1).verses.length > 10);
  assert.ok(B.chapter('LXX', '1En', 1).verses.length > 0);
  assert.ok(B.chapter('KJVA', 'Sir', 1).verses.length > 10);
});

test('rimes', () => {
  const words = B.rhymes('amour').map(r => r.word);
  assert.ok(words.includes('jour'));
});
