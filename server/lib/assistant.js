'use strict';
/**
 * Assistant d'étude biblique (facultatif) — Claude, via le SDK officiel @anthropic-ai/sdk.
 *
 * Activation : ANTHROPIC_API_KEY=<clé> dans le fichier .env (https://console.anthropic.com).
 * Le modèle reçoit le texte réel du passage (version choisie + Darby avec numéros Strong),
 * ses principales références croisées et la fiche des mots originaux : il s'appuie sur
 * l'Écriture fournie au lieu de citer de mémoire.
 */
const B = require('./bible');
const { parseRefs, formatRef } = require('./refs');
const { plain, strongTokens } = require('./text');

const MODEL = process.env.ANTHROPIC_MODEL || 'claude-opus-5-5';

let client = null;
function getClient() {
  if (client) return client;
  if (!process.env.ANTHROPIC_API_KEY) {
    const e = new Error('Assistant désactivé : ajoutez ANTHROPIC_API_KEY dans le fichier .env, puis relancez Mister Preacher.');
    e.status = 503;
    throw e;
  }
  let Anthropic;
  try {
    Anthropic = require('@anthropic-ai/sdk');
  } catch {
    const e = new Error('Composant manquant : lancez « npm install » dans le dossier de Mister Preacher, puis relancez.');
    e.status = 503;
    throw e;
  }
  client = new (Anthropic.default || Anthropic)();
  return client;
}

const enabled = () => !!process.env.ANTHROPIC_API_KEY;

const SYSTEM = `Tu es l'assistant d'étude biblique de « Mister Preacher », au service de prédicateurs, pasteurs, prophètes, lecteurs et artistes francophones.

Règles :
- Réponds en français, avec chaleur et rigueur, dans un style clair et utilisable tel quel (titres courts, listes).
- Appuie-toi d'abord sur les textes bibliques fournis dans <contexte>. Quand tu cites un verset, cite-le à partir de ces textes et donne toujours la référence (ex. Jean 3:16). Ne fabrique jamais de citation : si un verset n'est pas fourni, donne seulement sa référence.
- Pour les mots hébreux ou grecs, utilise les fiches Strong fournies (mot original, translittération, sens) sans inventer d'étymologie.
- Distingue ce que dit le texte, son interprétation et son application. Quand les traditions chrétiennes divergent (protestante, catholique, orthodoxe, pentecôtiste…), présente les lectures principales avec respect, sans trancher à la place de l'utilisateur.
- Pour le contexte historique, signale les incertitudes (dates, auteurs) au lieu de les présenter comme certaines.
- Reste dans le domaine biblique, théologique et pastoral ; pour une question médicale, juridique ou de crise personnelle, encourage aussi à consulter une personne qualifiée.`;

const MODES = {
  expliquer: 'Explique ce passage : contexte (historique, littéraire, place dans le livre), sens verset par verset ou par section, mots clés de la langue originale qui éclairent le texte, liens avec le reste de la Bible, puis 3 applications concrètes pour aujourd\'hui.',
  sermon: 'Propose un plan de prédication sur ce passage : titre accrocheur, idée principale en une phrase, introduction (accroche), 3 points avec pour chacun le verset d\'appui, l\'explication et une application, une illustration possible, et une conclusion avec un appel.',
  etude: 'Prépare une étude biblique de groupe sur ce passage : objectif, 3 questions d\'observation, 3 questions d\'interprétation, 3 questions d\'application personnelle, et un temps de prière suggéré.',
  illustrations: 'Propose 5 illustrations variées pour prêcher ce passage (histoire vécue plausible, image de la vie quotidienne, fait historique ou scientifique vérifiable, comparaison, mise en situation), chacune reliée clairement à un verset.',
  chant: 'Aide à écrire un chant à partir de ce passage : thème central, images bibliques fortes à exploiter, une proposition de refrain (4 vers) et d\'un couplet (4 à 8 vers) en français, fidèles au texte, et une liste de mots ou expressions bibliques utilisables.',
  evangelisation: 'Montre comment utiliser ce passage pour annoncer l\'Évangile : besoin humain abordé, bonne nouvelle révélée, objections possibles et réponses bibliques, et une manière simple d\'inviter quelqu\'un à répondre.',
  personnage: 'Présente le ou les personnages principaux de ce passage : qui ils sont, leur histoire (avec références), leurs forces, leurs failles, ce que Dieu fait à travers eux, et les leçons pour aujourd\'hui.',
  libre: 'Réponds à la question de l\'utilisateur.',
};

/** Prépare le contexte biblique : passage, Darby (Strong), références croisées, mots originaux. */
function buildContext(ref, version) {
  const refs = parseRefs(ref || '');
  if (!refs.length) return { label: '', text: '' };
  const label = refs.map(r => formatRef(r)).join(' ; ');
  const parts = [];
  const strongNums = new Map();
  for (const r of refs.slice(0, 3)) {
    const verses = B.rangeVerses(version, r, 60);
    parts.push(`<passage ref="${formatRef(r)}" version="${version}">\n${verses.map(v => `${v.c}:${v.v} ${plain(v.text)}`).join('\n')}\n</passage>`);
    if (version !== 'JND') {
      const darby = B.rangeVerses('JND', r, 60);
      if (darby.length) parts.push(`<passage ref="${formatRef(r)}" version="Darby (traduction littérale)">\n${darby.map(v => `${v.c}:${v.v} ${plain(v.text)}`).join('\n')}\n</passage>`);
    }
    for (const v of B.rangeVerses('JND', r, 30)) {
      for (const t of strongTokens(v.text)) for (const n of t.nums) if (!strongNums.has(n)) strongNums.set(n, t.word);
    }
    // Références croisées des premiers versets
    const xr = [];
    for (const v of B.rangeVerses(version, r, 3)) {
      for (const x of B.crossRefs(v.key, version, 4).refs) xr.push(`${x.ref} — ${x.text.slice(0, 220)}`);
    }
    if (xr.length) parts.push(`<references_croisees>\n${[...new Set(xr)].slice(0, 10).join('\n')}\n</references_croisees>`);
  }
  // Fiches Strong des mots porteurs de sens (on ignore les mots grammaticaux très fréquents)
  const fiches = [];
  for (const [num, word] of strongNums) {
    if (fiches.length >= 14) break;
    try {
      const e = B.strongEntry(num);
      const occ = Math.max(0, ...Object.values(e.usage).map(u => u.occurrences));
      if (occ > 2500) continue;
      fiches.push(`${num} « ${word} » : ${e.lemma} (${e.translit}) — ${e.def}`);
    } catch { /* numéro absent du lexique */ }
  }
  if (fiches.length) parts.push(`<mots_originaux>\n${fiches.join('\n')}\n</mots_originaux>`);
  return { label, text: parts.join('\n\n') };
}

/**
 * Lance une réponse en continu. onText(texte) reçoit les morceaux au fil de l'eau.
 * Renvoie { stopReason }.
 */
async function ask({ mode = 'expliquer', ref = '', question = '', version = B.DEFAULT_VERSION, history = [] }, onText) {
  const c = getClient();
  if (!MODES[mode]) mode = 'libre';
  if (!ref && !question.trim()) {
    const e = new Error('Indiquez un passage ou posez une question.');
    e.status = 400;
    throw e;
  }
  if (!B.versionMeta(version)) version = B.DEFAULT_VERSION;
  const ctx = buildContext(ref, version);
  const userText = [
    ctx.text ? `<contexte>\n${ctx.text}\n</contexte>` : '',
    ctx.label ? `Passage étudié : ${ctx.label}` : '',
    `Tâche : ${MODES[mode]}`,
    question.trim() ? `Question ou précision de l'utilisateur : ${question.trim().slice(0, 4000)}` : '',
  ].filter(Boolean).join('\n\n');

  // Historique court (suivi de conversation) : uniquement du texte, alterné user/assistant.
  const messages = [];
  for (const h of (Array.isArray(history) ? history : []).slice(-6)) {
    if ((h.role === 'user' || h.role === 'assistant') && typeof h.content === 'string' && h.content.trim()) {
      messages.push({ role: h.role, content: h.content.slice(0, 8000) });
    }
  }
  messages.push({ role: 'user', content: userText });

  const stream = c.beta.messages.stream({
    model: MODEL,
    max_tokens: 16000,
    system: SYSTEM,
    thinking: { type: 'adaptive' },
    output_config: { effort: 'medium' },
    // Si une réponse est refusée par les filtres de sécurité, l'API relance la demande sur un autre modèle.
    betas: ['server-side-fallback-2026-07-01'],
    fallbacks: 'default',
    messages,
  });
  for await (const event of stream) {
    if (event.type === 'content_block_delta' && event.delta.type === 'text_delta') onText(event.delta.text);
  }
  const final = await stream.finalMessage();
  if (final.stop_reason === 'refusal') {
    onText('\n\n_Cette demande n’a pas pu être traitée. Reformulez-la ou précisez le passage étudié._');
  } else if (final.stop_reason === 'max_tokens') {
    onText('\n\n_(Réponse tronquée : demandez la suite.)_');
  }
  return { stopReason: final.stop_reason, model: final.model };
}

/** Message d'erreur lisible à partir d'une erreur du SDK. */
function explainError(e) {
  let Anthropic = null;
  try { Anthropic = require('@anthropic-ai/sdk'); Anthropic = Anthropic.default || Anthropic; } catch { /* SDK absent */ }
  if (Anthropic) {
    if (e instanceof Anthropic.AuthenticationError) return 'Clé ANTHROPIC_API_KEY refusée : vérifiez-la dans le fichier .env.';
    if (e instanceof Anthropic.PermissionDeniedError) return 'Cette clé n’a pas accès au modèle demandé.';
    if (e instanceof Anthropic.RateLimitError) return 'Trop de demandes en même temps : réessayez dans un instant.';
    if (e instanceof Anthropic.BadRequestError) return `Requête refusée par l’API : ${e.message}`;
    if (e instanceof Anthropic.APIConnectionError) return 'Impossible de joindre le service de l’assistant (connexion Internet ?).';
    if (e instanceof Anthropic.APIError) return `Erreur du service de l’assistant (${e.status}).`;
  }
  return e.message || 'Erreur inconnue';
}

module.exports = { ask, enabled, buildContext, explainError, MODES, MODEL };
