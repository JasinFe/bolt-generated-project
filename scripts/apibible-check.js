#!/usr/bin/env node
/**
 * Diagnostic API.Bible : vérifie la clé et liste les Bibles accessibles.
 * Usage : npm run apibible            (langue par défaut : fra)
 *         npm run apibible -- eng     (autre langue, code ISO 639-3)
 */
'use strict';
require('../server/lib/env').loadEnv();
const AB = require('../server/lib/apibible');

async function get(path) {
  const res = await fetch(AB.base() + path, { headers: { 'api-key': process.env.API_BIBLE_KEY, accept: 'application/json' } });
  const text = await res.text();
  let body = {};
  try { body = JSON.parse(text); } catch { body = { raw: text }; }
  return { status: res.status, body };
}

async function main() {
  if (!process.env.API_BIBLE_KEY) {
    console.log('Aucune clé : créez un fichier .env à la racine du projet avec la ligne  API_BIBLE_KEY=votre-cle');
    process.exit(1);
  }
  const lang = process.argv[2] || (process.env.API_BIBLE_LANGS || 'fra').split(/[,\s]+/)[0];
  console.log(`Point de terminaison : ${AB.base()}`);
  console.log(`Langue : ${lang}\n`);
  const r = await get(`/bibles?language=${encodeURIComponent(lang)}`);
  if (r.status !== 200) {
    console.log(`Échec (HTTP ${r.status}) : ${r.body.message || r.body.error || r.body.raw || JSON.stringify(r.body)}`.slice(0, 300));
    if (/allowlist|proxy|egress/i.test(r.body.raw || '')) console.log('→ Blocage réseau local (pare-feu / proxy), pas un problème de clé.');
    else if (r.status === 401 || r.status === 403) console.log('→ La clé est refusée : vérifiez-la (et son statut) dans votre tableau de bord API.Bible.');
    process.exit(1);
  }
  const bibles = r.body.data || [];
  console.log(`${bibles.length} Bible(s) accessible(s) :\n`);
  for (const b of bibles) {
    console.log(`  ${String(b.abbreviationLocal || b.abbreviation).padEnd(10)} ${String(b.id).padEnd(20)} ${b.nameLocal || b.name}`);
  }
  if (bibles.length) {
    const t = await get(`/bibles/${bibles[0].id}/verses/JHN.3.16?content-type=text&include-verse-numbers=false`);
    console.log(`\nTest de lecture (Jean 3:16, ${bibles[0].abbreviationLocal || bibles[0].abbreviation}) : HTTP ${t.status}`);
    if (t.body.data) console.log('  ' + String(t.body.data.content || '').replace(/\s+/g, ' ').trim());
  }
  console.log('\nCes Bibles seront ajoutées automatiquement au démarrage (npm start), avec le symbole ☁.');
}

main().catch(e => { console.error('Erreur réseau :', e.message); process.exit(1); });
