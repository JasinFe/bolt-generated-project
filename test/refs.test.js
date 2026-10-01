'use strict';
const test = require('node:test');
const assert = require('node:assert');
const { parseRefs, formatRef, findBook } = require('../server/lib/refs');

const fmt = q => parseRefs(q).map(r => formatRef(r));

test('références françaises usuelles', () => {
  assert.deepStrictEqual(fmt('Jean 3:16'), ['Jean 3:16']);
  assert.deepStrictEqual(fmt('Jn 3.16-18'), ['Jean 3:16-18']);
  assert.deepStrictEqual(fmt('1 Co 13'), ['1 Corinthiens 13']);
  assert.deepStrictEqual(fmt('Ps 23,1'), ['Psaumes 23:1']);
  assert.deepStrictEqual(fmt('Ésaïe 53'), ['Ésaïe 53']);
  assert.deepStrictEqual(fmt('Esaie 53'), ['Ésaïe 53']);
  assert.deepStrictEqual(fmt('premier Pierre 2:9'), ['1 Pierre 2:9']);
  assert.deepStrictEqual(fmt('Hé 11:1'), ['Hébreux 11:1']);
});

test('références multiples et plages', () => {
  assert.deepStrictEqual(fmt('Rom 8:28; 12:1-2'), ['Romains 8:28', 'Romains 12:1-2']);
  assert.deepStrictEqual(fmt('Éphésiens 2:8-10, 14'), ['Éphésiens 2:8-10', 'Éphésiens 2:14']);
  assert.deepStrictEqual(fmt('Apocalypse 21:1-22:5'), ['Apocalypse 21:1-22:5']);
  assert.deepStrictEqual(fmt('Mt 5-7'), ['Matthieu 5-7']);
});

test('anglais, OSIS, deutérocanoniques et apocryphes', () => {
  assert.deepStrictEqual(fmt('John 3:16'), ['Jean 3:16']);
  assert.deepStrictEqual(fmt('II Kings 2'), ['2 Rois 2']);
  assert.deepStrictEqual(fmt('Gen.1.1'), ['Genèse 1:1']);
  assert.deepStrictEqual(fmt('Prov.8.22-Prov.8.30'), ['Proverbes 8:22-30']);
  assert.strictEqual(findBook('Tobie'), 'Tob');
  assert.strictEqual(findBook('Ecclésiastique'), 'Sir');
  assert.strictEqual(findBook('Hénoch'), '1En');
  assert.strictEqual(findBook('1 Maccabées'), '1Macc');
});

test('textes qui ne sont pas des références', () => {
  assert.deepStrictEqual(parseRefs('amour'), []);
  assert.strictEqual(findBook('le'), null);
});
