/* Images de la galerie : chaque écran de la démonstration du site, mis en scène en 1600×1000.
 *   node galerie.js <index.html> <dossier de sortie>
 */
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const NOMS = ['tableau-de-bord', 'facturation', 'caisse', 'stocks', 'paie', 'pilotage'];
const TITRES = [['Tableau de bord', 'Vos chiffres clés, en direct'], ['Facturation', 'Une facture conforme et certifiée'], ['Caisse', 'Encaissez vite, même par Mobile Money'],
  ['Stocks', 'Alertes avant la rupture'], ['RH & paie', 'Bulletins au barème ivoirien'], ['Pilotage Maestro', 'Les bonnes questions, les bonnes réponses']];
(async () => {
  const [index, sortie] = process.argv.slice(2);
  const nav = await chromium.launch();
  const page = await nav.newPage({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1 });
  await page.goto('file://' + path.resolve(index));
  await page.addStyleTag({ content: `#plateau{position:fixed;inset:0;z-index:999;display:grid;place-items:center;padding:50px;
    background:radial-gradient(900px 600px at 85% 0%,rgba(76,125,255,.28),transparent),radial-gradient(700px 500px at 0% 100%,rgba(247,147,30,.2),transparent),#060A15}
    #plateau .ui{width:1060px;zoom:1.36}#plateau .ui-corps{min-height:0}#plateau .mentionv{position:absolute;right:28px;bottom:18px;color:#6F7C9C;font-size:15px}#plateau .marque{margin-top:40px}` });
  for (let i = 0; i < NOMS.length; i++) {
    await page.evaluate(([i, t]) => {
      document.getElementById('plateau')?.remove();
      const p = document.createElement('div'); p.id = 'plateau';
      p.innerHTML = '<span class="mentionv">FinaKop ERP · illustration, données fictives</span>';
      const ui = document.querySelectorAll('.vue .ui')[i].cloneNode(true); ui.classList.remove('anime'); p.appendChild(ui);
      document.body.appendChild(p); void ui.offsetWidth; ui.classList.add('anime');
    }, [i, TITRES[i]]);
    await page.waitForTimeout(2600);
    await page.screenshot({ path: path.join(sortie, `ecran-${NOMS[i]}.jpg`), type: 'jpeg', quality: 86 });
  }
  await nav.close();
})().catch((e) => { console.error(e); process.exit(1); });
