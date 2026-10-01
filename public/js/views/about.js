import { esc, versions } from '../core.js';

export async function render(el) {
  const list = await versions();
  el.innerHTML = `<div class="page"><h1>Versions &amp; sources</h1>
    <div class="card"><table class="parallel"><thead><tr><th>Version</th><th>Langue</th><th>Contenu</th><th>Licence</th></tr></thead><tbody>
      ${list.map(v => `<tr><td><b>${esc(v.short)}</b> — ${esc(v.name)}<div class="muted small">${esc(v.description)}</div></td>
        <td>${esc(v.lang.toUpperCase())}</td><td class="small">${v.stats.books} livres<br>${v.stats.verses.toLocaleString('fr-FR')} versets${v.strong ? '<br><span class="tag">Strong</span>' : ''}</td>
        <td class="small">${esc(v.license)}<br><a href="${esc(v.source)}" target="_blank" rel="noopener">source</a></td></tr>`).join('')}
    </tbody></table></div>
    <div class="card"><h3>Autres ressources</h3><ul>
      <li><b>Références croisées</b> : OpenBible.info (CC BY) — plus de 340 000 liens classés par pertinence.</li>
      <li><b>Dictionnaires Strong</b> hébreu et grec : Open Scriptures (CC BY-SA), d’après James Strong (1890).</li>
      <li><b>Textes</b> : projet <a href="https://github.com/scrollmapper/bible_databases" target="_blank" rel="noopener">scrollmapper/bible_databases</a> (140 traductions).</li>
    </ul></div>
    <div class="card"><h3>Ajouter une version</h3>
      <p>Toute traduction du projet scrollmapper (Reina-Valera, Luther, Synodale…) s’ajoute en une ligne dans <code>scripts/build-data.js</code>, puis <code>npm run data</code>.</p>
      <p>Les versions modernes protégées (Louis Segond 21, NEG, Semeur, NBS, Parole de Vie, Colombe…) nécessitent l’accord de leur éditeur
      (Société Biblique de Genève, Biblica, Alliance Biblique…) ou un accès via l’API officielle <a href="https://scripture.api.bible" target="_blank" rel="noopener">API.Bible</a>.
      Voir <code>docs/ANALYSE.md</code>.</p></div>
    <div class="card"><h3>Numérotation</h3><p class="small">Les versions françaises suivent la numérotation hébraïque pour certains livres (Joël 3 = Joël 2:28-32 en anglais ; titres des Psaumes comptés comme verset 1).
      La Septante et la Vulgate numérotent les Psaumes à la grecque (Ps 22 hébreu = Ps 21 grec).</p></div>
  </div>`;
}
