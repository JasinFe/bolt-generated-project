// Petits graphiques SVG sans dépendance : colonnes (empilées), courbe + aire, anneau,
// barres horizontales, carte de chaleur. Chaque graphique a une info-bulle au survol
// (et au clavier) et peut basculer en vue tableau.
// Les couleurs viennent des variables CSS --s1…--s8 (catégories), --seq-* (intensité), --st-* (statuts).
const NS = 'http://www.w3.org/2000/svg';
const el = (tag, attrs = {}, parent) => {
	const e = document.createElementNS(NS, tag);
	for (const [k, v] of Object.entries(attrs)) e.setAttribute(k, v);
	if (parent) parent.append(e);
	return e;
};
const h = (tag, cls, txt) => {
	const e = document.createElement(tag);
	if (cls) e.className = cls;
	if (txt !== undefined) e.textContent = txt;
	return e;
};

export const nombre = (n) => new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0}).format(n);
export const compact = (n) => (Math.abs(n) >= 10000 ? new Intl.NumberFormat('fr-FR', {notation: 'compact', maximumFractionDigits: 1}).format(n) : nombre(n));

// Graduations « rondes » : 0, 5, 10… / 0, 200, 400…
const graduations = (max, n = 4) => {
	if (max <= 0) return [0, 1];
	const brut = max / n;
	const p = 10 ** Math.floor(Math.log10(brut));
	const pas = [1, 2, 2.5, 5, 10].map((m) => m * p).find((s) => s >= brut);
	const t = [];
	for (let v = 0; v <= max + pas * 0.001; v += pas) t.push(Math.round(v * 1000) / 1000);
	if (t[t.length - 1] < max) t.push(t[t.length - 1] + pas);
	return t;
};

// Info-bulle partagée
let bulle;
const infobulle = () => {
	if (!bulle) {
		bulle = h('div', 'g-bulle');
		bulle.setAttribute('role', 'tooltip');
		document.body.append(bulle);
	}
	return bulle;
};
const montrerBulle = (x, y, titre, lignes) => {
	const b = infobulle();
	b.innerHTML = '';
	b.append(h('div', 'g-bulle-titre', titre));
	for (const l of lignes) {
		const r = h('div', 'g-bulle-ligne');
		const k = h('i', `g-cle ${l.forme || 'trait'}`);
		k.style.background = l.couleur;
		r.append(h('b', '', l.valeur), k, h('span', '', l.nom));
		b.append(r);
	}
	b.classList.add('on');
	const w = b.offsetWidth;
	const hh = b.offsetHeight;
	b.style.left = `${Math.min(window.innerWidth - w - 8, Math.max(8, x + 14))}px`;
	b.style.top = `${Math.max(8, y - hh - 12)}px`;
};
export const cacherBulle = () => bulle?.classList.remove('on');

const taille = (box) => ({w: Math.max(260, box.clientWidth || 600), h: Number(box.dataset.hauteur) || 240});

// Légende HTML (forme du repère = forme de la marque)
const legende = (box, series, forme) => {
	if (series.length < 2) return;
	const lg = h('div', 'g-legende');
	for (const s of series) {
		const it = h('span', 'g-leg');
		const k = h('i', `g-cle ${forme}`);
		k.style.background = s.couleur;
		it.append(k, document.createTextNode(s.nom));
		lg.append(it);
	}
	box.append(lg);
};

// Vue tableau (accessibilité, valeurs exactes)
const tableau = (box, entetes, lignes) => {
	const t = h('table', 'g-table');
	const tr = h('tr');
	for (const e of entetes) tr.append(h('th', '', e));
	t.append(tr);
	for (const l of lignes) {
		const r = h('tr');
		l.forEach((v, i) => r.append(h(i ? 'td' : 'th', '', v)));
		t.append(r);
	}
	box.append(t);
};

// Pas de données : silhouette de graphique qui « respire », avec un message
const vide = (box, msg = 'Pas encore de données sur cette période') => {
	const v = h('div', 'g-vide');
	const f = h('div', 'g-fantome');
	[38, 62, 45, 80, 55, 70, 30, 58].forEach((pc, i) => {
		const b = h('i');
		b.style.height = `${pc}%`;
		b.style.setProperty('--i', i);
		f.append(b);
	});
	const m = h('div', 'g-vide-msg');
	m.append(h('b', '', 'En attente de données'), h('span', '', msg));
	v.append(f, m);
	box.append(v);
};
// Les graphiques se dessinent à l'affichage (désactivé si l'utilisateur réduit les animations)
const animer = (box) => box.classList.add('g-anim');

// --- Colonnes (simples ou empilées) -------------------------------------------------------
// donnees : {categories: [...], series: [{nom, couleur, valeurs: [...]}], format, vueTableau}
export const colonnes = (box, d) => {
	box.innerHTML = '';
	const fmt = d.format || nombre;
	if (d.vueTableau) return tableau(box, ['', ...d.series.map((s) => s.nom), ...(d.series.length > 1 ? ['Total'] : [])], d.categories.map((c, i) => [c, ...d.series.map((s) => fmt(s.valeurs[i])), ...(d.series.length > 1 ? [fmt(d.series.reduce((a, s) => a + s.valeurs[i], 0))] : [])]));
	const totaux = d.categories.map((_, i) => d.series.reduce((a, s) => a + (s.valeurs[i] || 0), 0));
	if (!totaux.some((v) => v > 0)) return vide(box);
	animer(box);
	const {w, h: H} = taille(box);
	const m = {g: 44, d: 8, h: 12, b: 28};
	const ticks = graduations(Math.max(...totaux));
	const max = ticks[ticks.length - 1];
	const svg = el('svg', {viewBox: `0 0 ${w} ${H}`, class: 'g-svg', role: 'img'});
	const y = (v) => m.h + (H - m.h - m.b) * (1 - v / max);
	for (const t of ticks) {
		el('line', {x1: m.g, x2: w - m.d, y1: y(t), y2: y(t), class: t ? 'g-grille' : 'g-base'}, svg);
		el('text', {x: m.g - 8, y: y(t) + 4, class: 'g-axe', 'text-anchor': 'end'}, svg).textContent = compact(t);
	}
	const n = d.categories.length;
	const bande = (w - m.g - m.d) / n;
	const larg = Math.min(24, bande * 0.66);
	const pasEtiquette = Math.ceil(n / Math.floor((w - m.g) / 54));
	d.categories.forEach((c, i) => {
		const cx = m.g + bande * (i + 0.5);
		if (i % pasEtiquette === 0) el('text', {x: cx, y: H - 8, class: 'g-axe', 'text-anchor': 'middle'}, svg).textContent = c;
		let cumul = 0;
		const segments = d.series.map((s) => ({s, v: s.valeurs[i] || 0})).filter((x) => x.v > 0);
		segments.forEach(({s, v}, k) => {
			const y0 = y(cumul);
			const y1 = y(cumul + v);
			cumul += v;
			const haut = k === segments.length - 1;
			const hauteur = Math.max(0, y0 - y1 - (k > 0 ? 2 : 0)); // espace de 2px entre segments
			const x0 = cx - larg / 2;
			const r = haut ? Math.min(4, hauteur, larg / 2) : 0;
			// coin arrondi en haut seulement, carré sur la ligne de base
			const yb = y0 - (k > 0 ? 2 : 0);
			el('path', {d: `M${x0},${yb} V${yb - hauteur + r} Q${x0},${yb - hauteur} ${x0 + r},${yb - hauteur} H${x0 + larg - r} Q${x0 + larg},${yb - hauteur} ${x0 + larg},${yb - hauteur + r} V${yb} Z`, fill: s.couleur, class: 'g-marque g-barre-col', style: `--i:${i}`}, svg);
		});
		// zone de survol : toute la bande
		const zone = el('rect', {x: m.g + bande * i, y: m.h, width: bande, height: H - m.h - m.b, class: 'g-zone', tabindex: 0}, svg);
		const lignes = () => [...d.series.map((s) => ({nom: s.nom, valeur: fmt(s.valeurs[i] || 0), couleur: s.couleur, forme: 'carre'})), ...(d.series.length > 1 ? [{nom: 'Total', valeur: fmt(totaux[i]), couleur: 'transparent'}] : [])];
		const voir = (e) => {
			svg.querySelectorAll('.g-zone.actif').forEach((z) => z.classList.remove('actif'));
			zone.classList.add('actif');
			const r = zone.getBoundingClientRect();
			montrerBulle(e.clientX ?? r.left + r.width / 2, e.clientY ?? r.top + 20, d.titres?.[i] || c, lignes());
		};
		zone.addEventListener('pointermove', voir);
		zone.addEventListener('focus', voir);
		zone.addEventListener('pointerleave', () => (zone.classList.remove('actif'), cacherBulle()));
		zone.addEventListener('blur', () => (zone.classList.remove('actif'), cacherBulle()));
	});
	box.append(svg);
	legende(box, d.series, 'carre');
};

// --- Courbe + aire (une ou plusieurs séries) -----------------------------------------------
export const courbe = (box, d) => {
	box.innerHTML = '';
	const fmt = d.format || nombre;
	if (d.vueTableau) return tableau(box, ['', ...d.series.map((s) => s.nom)], d.categories.map((c, i) => [c, ...d.series.map((s) => fmt(s.valeurs[i]))]));
	const tous = d.series.flatMap((s) => s.valeurs);
	if (!tous.some((v) => v > 0)) return vide(box);
	animer(box);
	const {w, h: H} = taille(box);
	const m = {g: 52, d: 16, h: 14, b: 28};
	const ticks = graduations(Math.max(...tous));
	const max = ticks[ticks.length - 1];
	const n = d.categories.length;
	const x = (i) => m.g + (n === 1 ? (w - m.g - m.d) / 2 : ((w - m.g - m.d) * i) / (n - 1));
	const y = (v) => m.h + (H - m.h - m.b) * (1 - v / max);
	const svg = el('svg', {viewBox: `0 0 ${w} ${H}`, class: 'g-svg', role: 'img'});
	for (const t of ticks) {
		el('line', {x1: m.g, x2: w - m.d, y1: y(t), y2: y(t), class: t ? 'g-grille' : 'g-base'}, svg);
		el('text', {x: m.g - 8, y: y(t) + 4, class: 'g-axe', 'text-anchor': 'end'}, svg).textContent = compact(t);
	}
	const pasEtiquette = Math.ceil(n / Math.floor((w - m.g) / 60));
	d.categories.forEach((c, i) => {
		if (i % pasEtiquette === 0) el('text', {x: x(i), y: H - 8, class: 'g-axe', 'text-anchor': 'middle'}, svg).textContent = c;
	});
	for (const s of d.series) {
		const pts = s.valeurs.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`);
		if (d.aire !== false) el('path', {d: `M${x(0)},${y(0)} L${pts.join(' L')} L${x(n - 1)},${y(0)} Z`, fill: s.couleur, class: 'g-aire'}, svg);
		el('path', {d: `M${pts.join(' L')}`, stroke: s.couleur, class: 'g-ligne', pathLength: 1}, svg);
		el('circle', {cx: x(n - 1), cy: y(s.valeurs[n - 1]), r: 4, fill: s.couleur, class: 'g-point'}, svg);
	}
	// réticule : suit le pointeur et s'aligne sur la date la plus proche
	const reticule = el('line', {y1: m.h, y2: H - m.b, class: 'g-reticule'}, svg);
	const points = d.series.map((s) => el('circle', {r: 4, fill: s.couleur, class: 'g-point g-survol'}, svg));
	const zone = el('rect', {x: m.g, y: m.h, width: w - m.g - m.d, height: H - m.h - m.b, class: 'g-zone', tabindex: 0}, svg);
	let courant = n - 1;
	const voir = (i, cx, cy) => {
		courant = i;
		reticule.setAttribute('x1', x(i));
		reticule.setAttribute('x2', x(i));
		svg.classList.add('survol');
		d.series.forEach((s, k) => {
			points[k].setAttribute('cx', x(i));
			points[k].setAttribute('cy', y(s.valeurs[i]));
		});
		montrerBulle(cx, cy, d.titres?.[i] || d.categories[i], d.series.map((s) => ({nom: s.nom, valeur: fmt(s.valeurs[i]), couleur: s.couleur})));
	};
	zone.addEventListener('pointermove', (e) => {
		const r = svg.getBoundingClientRect();
		const px = ((e.clientX - r.left) / r.width) * w;
		const i = Math.max(0, Math.min(n - 1, Math.round(((px - m.g) / (w - m.g - m.d)) * (n - 1))));
		voir(i, e.clientX, e.clientY);
	});
	const quitter = () => (svg.classList.remove('survol'), cacherBulle());
	zone.addEventListener('pointerleave', quitter);
	zone.addEventListener('blur', quitter);
	zone.addEventListener('keydown', (e) => {
		if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
		e.preventDefault();
		const i = Math.max(0, Math.min(n - 1, courant + (e.key === 'ArrowRight' ? 1 : -1)));
		const r = svg.getBoundingClientRect();
		voir(i, r.left + (x(i) / w) * r.width, r.top + 30);
	});
	zone.addEventListener('focus', () => {
		const r = svg.getBoundingClientRect();
		voir(courant, r.left + (x(courant) / w) * r.width, r.top + 30);
	});
	box.append(svg);
	legende(box, d.series, 'trait');
};

// --- Anneau (part d'un tout, 2 à 6 parts) -----------------------------------------------------
export const anneau = (box, d) => {
	box.innerHTML = '';
	const fmt = d.format || nombre;
	const parts = d.parts.filter((p) => p.valeur > 0);
	const total = parts.reduce((a, p) => a + p.valeur, 0);
	if (d.vueTableau) return tableau(box, ['', 'Valeur', 'Part'], d.parts.map((p) => [p.nom, fmt(p.valeur), total ? `${Math.round((p.valeur / total) * 100)} %` : '—']));
	if (!total) return vide(box);
	animer(box);
	const wrap = h('div', 'g-anneau');
	const R = 80;
	const r = 54;
	const svg = el('svg', {viewBox: '-90 -90 180 180', class: 'g-svg-anneau', role: 'img'});
	let a0 = -Math.PI / 2;
	const ecart = parts.length > 1 ? 2 / R : 0; // 2px d'espace entre les parts
	for (const p of parts) {
		const a1 = a0 + (p.valeur / total) * Math.PI * 2;
		const s = a0 + ecart / 2;
		const e = Math.max(s + 0.001, a1 - ecart / 2);
		const grand = e - s > Math.PI ? 1 : 0;
		const pt = (rr, a) => `${(rr * Math.cos(a)).toFixed(2)},${(rr * Math.sin(a)).toFixed(2)}`;
		const chemin = parts.length === 1 ? `M0,${-R} A${R},${R} 0 1 1 -0.01,${-R} L-0.01,${-r} A${r},${r} 0 1 0 0,${-r} Z` : `M${pt(R, s)} A${R},${R} 0 ${grand} 1 ${pt(R, e)} L${pt(r, e)} A${r},${r} 0 ${grand} 0 ${pt(r, s)} Z`;
		const arc = el('path', {d: chemin, fill: p.couleur, class: 'g-marque g-part', tabindex: 0, style: `--i:${parts.indexOf(p)}`}, svg);
		const voir = (ev) => {
			const b = arc.getBoundingClientRect();
			montrerBulle(ev.clientX ?? b.left + b.width / 2, ev.clientY ?? b.top, p.nom, [{nom: `${Math.round((p.valeur / total) * 100)} %`, valeur: fmt(p.valeur), couleur: p.couleur, forme: 'carre'}]);
		};
		arc.addEventListener('pointermove', voir);
		arc.addEventListener('focus', voir);
		arc.addEventListener('pointerleave', cacherBulle);
		arc.addEventListener('blur', cacherBulle);
		a0 = a1;
	}
	el('text', {x: 0, y: 4, class: 'g-centre-val', 'text-anchor': 'middle'}, svg).textContent = d.centre?.valeur ?? fmt(total);
	el('text', {x: 0, y: 22, class: 'g-centre-lib', 'text-anchor': 'middle'}, svg).textContent = d.centre?.libelle ?? 'total';
	wrap.append(svg);
	const lg = h('div', 'g-legende vert');
	for (const p of d.parts) {
		const it = h('span', 'g-leg');
		const k = h('i', 'g-cle carre');
		k.style.background = p.couleur;
		it.append(k, h('span', 'g-leg-nom', p.nom), h('b', '', fmt(p.valeur)), h('span', 'g-leg-pc', total ? `${Math.round((p.valeur / total) * 100)} %` : ''));
		lg.append(it);
	}
	wrap.append(lg);
	box.append(wrap);
};

// --- Barres horizontales (classements, statuts) ------------------------------------------------
// items : [{nom, valeur, couleur, icone?}]
export const barres = (box, d) => {
	box.innerHTML = '';
	const fmt = d.format || nombre;
	if (d.vueTableau) return tableau(box, ['', d.unite || 'Valeur'], d.items.map((it) => [it.nom, fmt(it.valeur)]));
	if (!d.items.length || !d.items.some((i) => i.valeur > 0)) return vide(box, d.messageVide);
	animer(box);
	const max = Math.max(...d.items.map((i) => i.valeur));
	const liste = h('div', 'g-barres');
	for (const it of d.items) {
		const ligne = h('div', 'g-barre');
		ligne.tabIndex = 0;
		const nom = h('span', 'g-barre-nom');
		nom.title = it.nom;
		if (it.icone) nom.append(h('span', 'g-icone', it.icone));
		nom.append(document.createTextNode(it.nom));
		const piste = h('span', 'g-piste');
		const rempli = h('span', 'g-rempli');
		rempli.style.width = `${max ? Math.max(it.valeur > 0 ? 1.5 : 0, (it.valeur / max) * 100) : 0}%`;
		rempli.style.background = it.couleur;
		rempli.style.setProperty('--i', d.items.indexOf(it));
		piste.append(rempli);
		ligne.append(nom, piste, h('b', 'g-barre-val', fmt(it.valeur)));
		if (it.onClick) {
			ligne.classList.add('cliquable');
			ligne.addEventListener('click', it.onClick);
		}
		liste.append(ligne);
	}
	box.append(liste);
};

// --- Carte de chaleur calendrier (activité par jour) ----------------------------------------
// jours : Map 'AAAA-MM-JJ' -> nombre ; semaines : nombre de colonnes
export const chaleur = (box, d) => {
	box.innerHTML = '';
	const semaines = d.semaines || 26;
	const fin = new Date();
	fin.setUTCHours(12, 0, 0, 0);
	const vals = [...d.jours.values()];
	const max = Math.max(1, ...vals);
	if (d.vueTableau) {
		const lignes = [...d.jours.entries()].sort().reverse().slice(0, 60).map(([j, n]) => [j.split('-').reverse().join('/'), nombre(n)]);
		return tableau(box, ['Jour', d.unite || 'Événements'], lignes);
	}
	const c = 13;
	const g = 3;
	const W = 30 + (semaines + 1) * (c + g);
	const H = 20 + 7 * (c + g);
	const svg = el('svg', {viewBox: `0 0 ${W} ${H}`, class: 'g-svg g-chaleur', role: 'img'});
	animer(box);
	['lun', '', 'mer', '', 'ven', '', ''].forEach((j, i) => j && (el('text', {x: 0, y: 20 + i * (c + g) + 10, class: 'g-axe'}, svg).textContent = j));
	const niveaux = ['var(--seq-0)', 'var(--seq-1)', 'var(--seq-2)', 'var(--seq-3)', 'var(--seq-4)'];
	// lundi de la première semaine affichée
	const lundi = new Date(fin);
	lundi.setUTCDate(fin.getUTCDate() - semaines * 7 - ((fin.getUTCDay() + 6) % 7));
	let moisPrec = -1;
	let derniereEtiquette = -9;
	// la 1re colonne n'est étiquetée que si elle commence le mois
	if (lundi.getUTCDate() > 7) moisPrec = lundi.getUTCMonth();
	for (let s = 0; s <= semaines; s++) {
		for (let j = 0; j < 7; j++) {
			const dt = new Date(lundi);
			dt.setUTCDate(lundi.getUTCDate() + s * 7 + j);
			if (dt > fin) continue;
			const iso = dt.toISOString().slice(0, 10);
			if (j === 0 && dt.getUTCMonth() !== moisPrec && s - derniereEtiquette >= 3) {
				moisPrec = dt.getUTCMonth();
				derniereEtiquette = s;
				el('text', {x: 30 + s * (c + g), y: 11, class: 'g-axe'}, svg).textContent = dt.toLocaleDateString('fr-FR', {month: 'short', timeZone: 'UTC'});
			}
			const n = d.jours.get(iso) || 0;
			const niv = n === 0 ? 0 : Math.min(4, 1 + Math.floor((n / max) * 3.999));
			const r = el('rect', {x: 30 + s * (c + g), y: 20 + j * (c + g), width: c, height: c, rx: 3, fill: niveaux[niv], class: 'g-case', tabindex: -1, style: `--i:${s}`}, svg);
			const voir = (e) => montrerBulle(e.clientX, e.clientY, dt.toLocaleDateString('fr-FR', {weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC'}), [{nom: d.unite || 'événements', valeur: nombre(n), couleur: niveaux[Math.max(1, niv)], forme: 'carre'}]);
			r.addEventListener('pointermove', voir);
			r.addEventListener('pointerleave', cacherBulle);
		}
	}
	box.append(svg);
	const lg = h('div', 'g-legende g-echelle');
	lg.append(h('span', '', 'Moins'));
	for (const n of niveaux) {
		const k = h('i', 'g-cle carre');
		k.style.background = n;
		lg.append(k);
	}
	lg.append(h('span', '', 'Plus'));
	box.append(lg);
};

// Mini-courbe pour les tuiles de chiffres clés
export const sparkline = (box, valeurs) => {
	box.innerHTML = '';
	// une ligne plate à zéro n'apporte rien : on n'affiche la tendance que s'il y a des données
	if (valeurs.length < 2 || !valeurs.some((v) => v > 0)) return;
	const w = 120;
	const H = 34;
	const max = Math.max(1, ...valeurs);
	const svg = el('svg', {viewBox: `0 0 ${w} ${H}`, class: 'g-spark', 'aria-hidden': 'true', preserveAspectRatio: 'none'});
	const defs = el('defs', {}, svg);
	const g = el('linearGradient', {id: 'spark-degrade', x1: 0, y1: 0, x2: 0, y2: 1}, defs);
	el('stop', {offset: 0, 'stop-color': 'var(--accent2)', 'stop-opacity': 0.55}, g);
	el('stop', {offset: 1, 'stop-color': 'var(--accent2)', 'stop-opacity': 0}, g);
	const pts = valeurs.map((v, i) => `${((i / (valeurs.length - 1)) * (w - 4) + 2).toFixed(1)},${(H - 3 - (v / max) * (H - 8)).toFixed(1)}`);
	el('path', {d: `M2,${H} L${pts.join(' L')} L${w - 2},${H} Z`, class: 'g-spark-aire'}, svg);
	el('path', {d: `M${pts.join(' L')}`, class: 'g-spark-ligne', pathLength: 1, 'vector-effect': 'non-scaling-stroke'}, svg);
	box.append(svg);
};
