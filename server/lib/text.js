'use strict';
/** Outils de texte : normalisation (accents, apostrophes) et balisage compact. */

const COMBINING = /[̀-֑ͯ-ׇ҃-҉]/;

/** Normalise une chaîne pour la recherche : minuscules, sans accents ni points-voyelles. */
function normalize(s) {
  return String(s)
    .normalize('NFD')
    .replace(/[̀-֑ͯ-ׇ҃-҉]/g, '')
    .replace(/[’ʼ‘`´]/g, "'")
    .replace(/[œ]/g, 'oe').replace(/[Œ]/g, 'OE').replace(/[æ]/g, 'ae').replace(/[Æ]/g, 'AE')
    .replace(/ς/g, 'σ')
    .toLowerCase();
}

/**
 * Normalise en gardant une table de correspondance vers la chaîne d'origine,
 * pour pouvoir surligner exactement les mots trouvés.
 */
function normalizeWithMap(s) {
  let norm = '';
  const map = [];
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    const n = normalize(ch);
    for (const c of n) {
      if (COMBINING.test(c)) continue;
      norm += c;
      map.push(i);
    }
  }
  map.push(s.length);
  return { norm, map };
}

/** Retire le balisage compact {mot|G25} et [ajout]. */
function plain(t) {
  return t.replace(/\{([^|}]*)\|[^}]*\}/g, '$1').replace(/[[\]]/g, '');
}

/** Liste des mots balisés Strong d'un verset : [{ word, nums: ['G25'] }]. */
function strongTokens(t) {
  const out = [];
  for (const m of t.matchAll(/\{([^|}]*)\|([^}]*)\}/g)) out.push({ word: m[1], nums: m[2].split(',') });
  return out;
}

function escapeRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const L = '[\\p{L}\\p{N}]';

/**
 * Compile une requête de recherche en une liste de tests.
 * Syntaxe : mots (tous requis), "phrase exacte", -exclu, préfixe*, OU entre mots (mode any).
 */
function compileQuery(q, mode = 'all') {
  const terms = [];
  const exclude = [];
  if (mode === 'regex') {
    // Garde-fou contre les expressions catastrophiques (quantificateurs imbriqués)
    if (q.length > 80 || /\([^)]*[+*}][^)]*\)\s*[+*{]/.test(q)) throw new Error('expression trop complexe');
    return { terms: [new RegExp(q, 'giu')], exclude, mode };
  }
  const re = /(-?)"([^"]+)"|(-?)(\S+)/g;
  let m;
  while ((m = re.exec(q))) {
    const neg = m[1] || m[3];
    const raw = m[2] || m[4];
    if (!raw || raw.toUpperCase() === 'OU' || raw.toUpperCase() === 'OR') continue;
    const words = normalize(raw).split(/\s+/).filter(Boolean);
    const src = words.map(w => {
      const wild = w.endsWith('*');
      const core = escapeRegex(wild ? w.slice(0, -1) : w).replace(/'/g, "'");
      return core + (wild ? `${L}*` : '');
    }).join("[\\s,;:.!?'’«»\"-]+");
    if (!src) continue;
    const rx = mode === 'partial'
      ? new RegExp(src, 'gu')
      : new RegExp(`(?<!${L})${src}(?!${L})`, 'gu');
    (neg ? exclude : terms).push(rx);
  }
  if (mode === 'phrase' && terms.length > 1) {
    // Toute la requête comme une seule phrase
    return compileQuery(`"${q.replace(/"/g, '')}"`, 'all');
  }
  return { terms, exclude, mode };
}

/** Teste un texte normalisé ; renvoie les plages trouvées ou null. */
function matchQuery(cq, norm) {
  for (const rx of cq.exclude) { rx.lastIndex = 0; if (rx.test(norm)) return null; }
  const ranges = [];
  let any = false;
  for (const rx of cq.terms) {
    rx.lastIndex = 0;
    let found = false;
    let m;
    while ((m = rx.exec(norm))) {
      if (m[0].length === 0) { rx.lastIndex++; continue; }
      ranges.push([m.index, m.index + m[0].length]);
      found = true;
    }
    if (found) any = true;
    else if (cq.mode !== 'any') return null;
  }
  if (!any) return null;
  return ranges.sort((a, b) => a[0] - b[0]);
}

module.exports = { normalize, normalizeWithMap, plain, strongTokens, compileQuery, matchQuery, escapeRegex };
