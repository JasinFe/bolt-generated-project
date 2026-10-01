import { esc, versions, versionsByLang, langName, icon } from '../core.js';

export async function render(el) {
  const list = await versions();
  const groups = versionsByLang(list);
  const verses = list.reduce((n, v) => n + (v.stats.verses || 0), 0);
  el.innerHTML = `<div class="page">
    <div class="page-head"><div class="grow"><div class="eyebrow">Bibliothèque</div><h1>Versions &amp; sources</h1>
      <p>${list.length} versions en ${groups.length} langues · ${verses.toLocaleString('fr-FR')} versets. Uniquement des textes libres de droits ou sous licence libre.</p></div></div>
    ${groups.map(([lang, vs]) => `<div class="lang-group"><h3>${esc(langName(lang))} <span class="tag">${vs.length}</span></h3>
      <div class="grid">${vs.map(v => `<div class="card v-card">
        <div class="row"><span class="tag gold">${esc(v.short)}</span>${v.strong ? '<span class="tag">Strong</span>' : ''}${v.remote ? '<span class="tag">API.Bible</span>' : ''}${v.year ? `<span class="muted small">${v.year}</span>` : ''}</div>
        <h3>${esc(v.name)}</h3><p>${esc(v.description || '')}</p>
        <div class="meta"><span class="muted">${v.stats.books} livres${v.stats.verses ? ` · ${v.stats.verses.toLocaleString('fr-FR')} versets` : ''}</span></div>
        <div class="meta" style="margin-top:6px"><span>${esc(v.license)}</span> · <a href="${esc(v.source)}" target="_blank" rel="noopener">source</a></div>
      </div>`).join('')}</div></div>`).join('')}

    <div class="card"><h3>${icon('link')} D’où viennent les textes ?</h3><ul>
      <li><a href="https://github.com/scrollmapper/bible_databases" target="_blank" rel="noopener">scrollmapper/bible_databases</a> — Darby (avec Strong), Martin, Crampon, Septante, KJV, BSB, hébreu, grec…</li>
      <li><a href="https://github.com/BibleNLP/ebible" target="_blank" rel="noopener">Corpus eBible.org</a> (source du <i>Free Use Bible API</i>) — Lingala, Néo-Crampon Libre, Textus Receptus.</li>
      <li><a href="https://github.com/seven1m/open-bibles" target="_blank" rel="noopener">open-bibles</a> (source de <i>bible-api.com</i>) — Ostervald, Luther 1912, Riveduta.</li>
      <li><a href="https://github.com/Beblia/Holy-Bible-XML-Format" target="_blank" rel="noopener">Beblia</a> — Segond 1910, Vigouroux, éwé, twi, haoussa, igbo, créole, arabe (éditions libres vérifiées uniquement).</li>
      <li>Références croisées : OpenBible.info (CC BY) · Dictionnaires Strong : Open Scriptures (CC BY-SA).</li>
    </ul></div>
    <div class="card"><h3>Versions modernes protégées</h3>
      <p>Segond 21, NEG 1979, Semeur, NBS, Parole de Vie, Colombe, TOB, Jérusalem… appartiennent à leurs éditeurs. Mister Preacher peut les afficher
      <b>légalement</b> via <a href="https://scripture.api.bible" target="_blank" rel="noopener">API.Bible</a> : créez une clé gratuite, puis renseignez
      <code>API_BIBLE_KEY</code> et <code>API_BIBLE_VERSIONS</code> sur le serveur (voir <code>docs/ANALYSE.md</code>). Elles apparaissent alors avec le symbole ☁.</p></div>
    <div class="card"><h3>Numérotation des versets</h3><p class="small">Certaines versions suivent la numérotation hébraïque (Darby, Néo-Crampon, Lingala : Joël 3:1 = Joël 2:28 de la Segond ;
      Malachie 3:19-24 = Malachie 4). La Septante et la Vulgate numérotent les Psaumes à la grecque (Ps 22 hébreu = Ps 21 grec).</p></div>
  </div>`;
}
