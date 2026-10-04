// Régie : modifie l'état partagé ; les overlays se mettent à jour instantanément.
(() => {
	const $ = (sel, root = document) => root.querySelector(sel);
	const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
	let state = null;

	const ELEMENTS = ['bandeauNom', 'sujet', 'defilant', 'titreEnCours', 'citation', 'bible', 'message', 'score', 'logo'];
	const LABELS = {
		logo: 'Logo de chaîne',
		bandeauNom: 'Bandeau nom',
		sujet: 'Sujet en cours',
		defilant: 'Bandeau défilant',
		titreEnCours: 'Titre en cours',
		citation: 'Citation',
		bible: 'Bible',
		message: 'Message du public',
		score: 'Score',
		ecran: 'Écran plein',
	};

	// Petit message en bas de l'écran (refus de licence, erreurs…)
	let toastTimer;
	const toast = (txt, duree = 5000) => {
		const t = $('#toast');
		t.textContent = txt;
		t.classList.add('on');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => t.classList.remove('on'), duree);
	};
	const send = (patch) =>
		fetch('/api/state', {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify(patch),
		})
			.then((r) => r.json())
			.then((d) => {
				if (d.refus?.length) toast(`🔒 ${d.refus.join(' ')}`);
				return d;
			})
			.catch(() => {});

	// "a.b.c" -> {a: {b: {c: value}}} ; "@" = émission active
	const resolve = (path) => path.replace('@', state.emissionActive);
	const patchFor = (path, value) => {
		const keys = resolve(path).split('.');
		const patch = {};
		let o = patch;
		keys.forEach((k, i) => {
			o[k] = i === keys.length - 1 ? value : {};
			o = o[k];
		});
		return patch;
	};
	const getPath = (path) => resolve(path).split('.').reduce((o, k) => (o == null ? o : o[k]), state);

	// --- Réglages de position (ajoutés à chaque carte d'élément) --------------------
	const ANCRES = [
		['haut-gauche', '↖'], ['haut-centre', '↑'], ['haut-droite', '↗'],
		['milieu-gauche', '←'], ['milieu-centre', '•'], ['milieu-droite', '→'],
		['bas-gauche', '↙'], ['bas-centre', '↓'], ['bas-droite', '↘'],
	];
	$$('.card[data-el]').forEach((card) => {
		const key = card.dataset.el;
		if (key === 'defilant') return; // pleine largeur : seulement haut / bas
		const box = document.createElement('details');
		box.className = 'placement';
		box.innerHTML = `
			<summary>Position et taille <span class="pl-resume"></span></summary>
			<div class="pl-body">
				<div class="pl-grid">${ANCRES.map(([a, g]) => `<button type="button" class="pl-cell" data-ancre="${a}" title="${a.replace('-', ' ')}">${g}</button>`).join('')}</div>
				<div class="fields">
					<label>Décalage horizontal <span class="val"></span><input type="range" min="-900" max="900" step="2" data-path="${key}.placement.x"></label>
					<label>Décalage vertical <span class="val"></span><input type="range" min="-520" max="520" step="2" data-path="${key}.placement.y"></label>
					<label>Taille (%) <span class="val"></span><input type="range" min="40" max="200" step="5" data-path="${key}.placement.echelle"></label>
					<button type="button" class="small ghost pl-reset">Remettre à zéro décalages et taille</button>
				</div>
			</div>`;
		box.querySelectorAll('[data-ancre]').forEach((b) =>
			b.addEventListener('click', () => send({[key]: {placement: {ancre: b.dataset.ancre}}})),
		);
		box.querySelector('.pl-reset').addEventListener('click', () => send({[key]: {placement: {x: 0, y: 0, echelle: 100}}}));
		card.append(box);
	});
	const renderPlacements = () => {
		$$('.card[data-el]').forEach((card) => {
			const pl = state[card.dataset.el]?.placement;
			if (!pl) return;
			card.querySelectorAll('[data-ancre]').forEach((b) => b.classList.toggle('active', b.dataset.ancre === pl.ancre));
			const r = card.querySelector('.pl-resume');
			if (r) r.textContent = `${pl.ancre.replace('-', ' ')}${pl.x || pl.y ? ` · ${pl.x > 0 ? '+' : ''}${pl.x}, ${pl.y > 0 ? '+' : ''}${pl.y}` : ''}${pl.echelle !== 100 ? ` · ${pl.echelle} %` : ''}`;
		});
	};
	const showVal = (el) => {
		const v = el.parentElement.querySelector('.val');
		if (v) v.textContent = el.value;
	};

	// --- Champs liés à l'état (data-path) ------------------------------------------
	const readInput = (el) => {
		if (el.type === 'checkbox') return el.checked;
		if (el.type === 'number' || el.type === 'range') return Number(el.value);
		if (el.dataset.type === 'lignes') return el.value.split('\n').map((l) => l.trim()).filter(Boolean);
		if (el.dataset.type === 'virgules') return el.value.split(',').map((l) => l.trim()).filter(Boolean);
		return el.value;
	};
	const timers = new Map();
	$$('[data-path]').forEach((el) => {
		const handler = () => {
			showVal(el);
			clearTimeout(timers.get(el));
			timers.set(
				el,
				setTimeout(() => send(patchFor(el.dataset.path, readInput(el))), el.type === 'range' || el.type === 'color' ? 60 : 250),
			);
		};
		el.addEventListener('input', handler);
		el.addEventListener('change', handler);
	});

	const fillInputs = () => {
		$$('[data-path]').forEach((el) => {
			if (document.activeElement === el) return;
			const v = getPath(el.dataset.path);
			if (el.type === 'checkbox') el.checked = !!v;
			else if (el.dataset.type === 'lignes') el.value = (v || []).join('\n');
			else if (el.dataset.type === 'virgules') el.value = (v || []).join(', ');
			else el.value = v ?? '';
			showVal(el);
		});
	};

	// --- Afficher / masquer ---------------------------------------------------------
	$$('[data-toggle]').forEach((btn) =>
		btn.addEventListener('click', () => {
			const key = btn.dataset.toggle;
			// on envoie aussi les champs en cours de saisie pour ne rien perdre
			flushPending();
			send({[key]: {visible: !state[key].visible}});
		}),
	);
	const flushPending = () => {
		for (const [el, t] of timers) {
			clearTimeout(t);
			send(patchFor(el.dataset.path, readInput(el)));
		}
		timers.clear();
	};

	$('#btn-tout-masquer').addEventListener('click', () => {
		const patch = {ecran: {mode: 'aucun'}};
		for (const k of ELEMENTS) if (k !== 'logo') patch[k] = {visible: false};
		send(patch);
	});

	// --- Écrans pleins ----------------------------------------------------------------
	$$('[data-ecran]').forEach((btn) =>
		btn.addEventListener('click', () => {
			const mode = btn.dataset.ecran;
			const patch = {ecran: {mode}};
			if (mode === 'debut') patch.ecran.cible = state.ecran.minutes > 0 ? Date.now() + state.ecran.minutes * 60000 : 0;
			send(patch);
		}),
	);

	// --- Invités enregistrés ----------------------------------------------------------
	$('#btn-ajout-invite').addEventListener('click', () => {
		const nom = $('[data-path="bandeauNom.nom"]').value.trim();
		const fonction = $('[data-path="bandeauNom.fonction"]').value.trim();
		if (!nom) return;
		const liste = (state.bandeauNom.liste || []).filter((i) => i.nom !== nom);
		send({bandeauNom: {liste: [...liste, {nom, fonction}]}});
	});
	const renderGuests = () => {
		const ul = $('#liste-invites');
		ul.innerHTML = '';
		(state.bandeauNom.liste || []).forEach((g, i) => {
			const li = document.createElement('li');
			const who = document.createElement('div');
			who.className = 'who';
			const b = document.createElement('b');
			b.textContent = g.nom;
			const s = document.createElement('span');
			s.textContent = g.fonction;
			who.append(b, s);
			const show = document.createElement('button');
			show.className = 'small';
			show.textContent = '▶ Afficher';
			show.onclick = () => send({bandeauNom: {nom: g.nom, fonction: g.fonction, visible: true}});
			const del = document.createElement('button');
			del.className = 'small ghost';
			del.textContent = '✕';
			del.title = 'Retirer de la liste';
			del.onclick = () => send({bandeauNom: {liste: state.bandeauNom.liste.filter((_, j) => j !== i)}});
			li.append(who, show, del);
			ul.append(li);
		});
	};

	// --- Score ----------------------------------------------------------------------
	$$('[data-score]').forEach((btn) =>
		btn.addEventListener('click', () => {
			const [key, delta] = btn.dataset.score.split(':');
			send({score: {[key]: Math.max(0, Number(state.score[key]) + Number(delta))}});
		}),
	);
	$$('[data-chrono]').forEach((btn) =>
		btn.addEventListener('click', () => {
			const ch = state.score.chrono || {};
			const now = Date.now();
			const action = btn.dataset.chrono;
			if (action === 'start' && !ch.enMarche) send({score: {chrono: {enMarche: true, depuis: now}}});
			if (action === 'pause' && ch.enMarche) send({score: {chrono: {enMarche: false, cumul: (ch.cumul || 0) + now - ch.depuis}}});
			if (action === 'reset') send({score: {chrono: {enMarche: false, cumul: 0, depuis: 0}}});
		}),
	);

	// --- Émissions ----------------------------------------------------------------------
	const renderEmissions = () => {
		const box = $('#emissions');
		box.innerHTML = '';
		for (const [key, em] of Object.entries(state.emissions)) {
			const b = document.createElement('button');
			b.textContent = em.nom || key;
			b.title = em.type || '';
			b.style.setProperty('--c', em.couleurs?.primaire || '#3d7bff');
			b.classList.toggle('active', key === state.emissionActive);
			b.onclick = () => send({emissionActive: key});
			box.append(b);
		}
		const cur = state.emissions[state.emissionActive] || {};
		$('#em-type').textContent = cur.type || '';
	};
	$('#btn-nouvelle-emission').addEventListener('click', () => {
		const nom = prompt('Nom de la nouvelle émission :');
		if (!nom) return;
		let key = nom.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'emission';
		while (state.emissions[key]) key += '-2';
		const base = state.emissions[state.emissionActive] || {};
		send({emissions: {[key]: {...structuredClone(base), nom}}, emissionActive: key});
	});
	$('#btn-suppr-emission').addEventListener('click', () => {
		const keys = Object.keys(state.emissions);
		if (keys.length <= 1) return alert('Il faut garder au moins une émission.');
		const cur = state.emissionActive;
		if (!confirm(`Supprimer l'émission « ${state.emissions[cur].nom} » ?`)) return;
		send({emissions: {[cur]: null}, emissionActive: keys.find((k) => k !== cur)});
	});

	// --- Import de logos ------------------------------------------------------------
	$$('[data-upload]').forEach((input) =>
		input.addEventListener('change', async () => {
			const file = input.files[0];
			if (!file) return;
			const res = await fetch(`/api/upload?nom=${encodeURIComponent(file.name)}`, {method: 'PUT', body: file});
			const data = await res.json();
			if (!res.ok) return alert(data.erreur || 'Échec de l’import');
			send(patchFor(input.dataset.upload, data.chemin));
			input.value = '';
		}),
	);

	// --- Divers ---------------------------------------------------------------------
	$('#btn-reset').addEventListener('click', () => {
		if (confirm('Revenir aux réglages d’origine ? Toutes vos modifications seront perdues.')) {
			fetch('/api/reset', {method: 'POST'});
		}
	});

	const preview = $('#preview');
	try {
		if (localStorage.getItem('apercu') === 'cache') preview.classList.add('cache');
	} catch {}
	$('#btn-apercu').addEventListener('click', () => {
		preview.classList.toggle('cache');
		try {
			localStorage.setItem('apercu', preview.classList.contains('cache') ? 'cache' : 'visible');
		} catch {}
		scalePreview();
	});
	const scalePreview = () => {
		const frame = $('.preview-frame');
		$('#apercu').style.transform = `scale(${frame.clientWidth / 1920})`;
	};
	new ResizeObserver(scalePreview).observe($('.preview-frame'));

	const renderLinks = () => {
		const ul = $('#liens');
		if (ul.childElementCount) return;
		const base = `${location.origin}/overlay.html`;
		const rows = [['Tout (recommandé)', base], ...ELEMENTS.map((k) => [LABELS[k], `${base}?elements=${k}`]), [LABELS.ecran, `${base}?elements=ecran`]];
		rows.push(['Régie dans OBS (dock)', `${location.origin}/controle.html`]);
		for (const [label, url] of rows) {
			const li = document.createElement('li');
			const span = document.createElement('span');
			span.textContent = label;
			const code = document.createElement('code');
			code.textContent = url;
			const btn = document.createElement('button');
			btn.className = 'small';
			btn.textContent = 'Copier';
			btn.onclick = async () => {
				try {
					await navigator.clipboard.writeText(url);
					btn.textContent = 'Copié ✓';
				} catch {
					const r = document.createRange();
					r.selectNodeContents(code);
					getSelection().removeAllRanges();
					getSelection().addRange(r);
					btn.textContent = 'Sélectionné';
				}
				setTimeout(() => (btn.textContent = 'Copier'), 1500);
			};
			li.append(span, code, btn);
			ul.append(li);
		}
	};

	// --- Bible ------------------------------------------------------------------------
	let versions = [];
	const loadVersions = async () => {
		versions = await (await fetch('/api/bible/versions')).json();
		$$('.bb-versions').forEach((sel) => {
			sel.innerHTML = '';
			if (sel.dataset.vide) sel.append(new Option(sel.dataset.vide, ''));
			for (const v of versions) sel.append(new Option(`${v.nom} (${v.abrev})`, v.id));
		});
		if (state) fillInputs();
	};
	loadVersions();

	const choisir = (sel, visible) => send({bible: {...sel, ...(visible ? {visible: true} : {})}});
	const makeBtn = (label, cls, onClick) => {
		const b = document.createElement('button');
		b.type = 'button';
		b.className = `small ${cls || ''}`;
		b.textContent = label;
		b.onclick = onClick;
		return b;
	};
	const resultats = $('#bb-resultats');
	$('#bb-form').addEventListener('submit', async (e) => {
		e.preventDefault();
		const q = $('#bb-q').value.trim();
		if (!q) return;
		resultats.textContent = 'Recherche…';
		const data = await (await fetch(`/api/bible/chercher?v=${encodeURIComponent(state.bible.version)}&q=${encodeURIComponent(q)}`)).json();
		resultats.innerHTML = '';
		if (data.erreur) return (resultats.textContent = `🔒 ${data.erreur}`);
		if (data.type === 'reference') {
			const p = data.passage;
			const head = document.createElement('div');
			head.className = 'bb-res-head';
			const t = document.createElement('b');
			t.textContent = p.reference;
			head.append(
				t,
				makeBtn('Préparer', 'ghost', () => choisir({livre: p.livre, chapitre: p.chapitre, debut: p.debut, fin: p.fin})),
				makeBtn('▶ Afficher', '', () => choisir({livre: p.livre, chapitre: p.chapitre, debut: p.debut, fin: p.fin}, true)),
			);
			resultats.append(head);
			const hint = document.createElement('div');
			hint.className = 'muted bb-hint';
			hint.textContent = 'Cliquez sur un verset pour n’afficher que celui-ci.';
			if (p.versets.length > 1) resultats.append(hint);
			for (const v of p.versets) {
				const row = document.createElement('div');
				row.className = 'bb-row';
				const n = document.createElement('span');
				n.className = 'bb-n';
				n.textContent = v.n;
				const tx = document.createElement('span');
				tx.textContent = v.texte;
				row.append(n, tx, makeBtn('▶', '', (ev) => {
					ev.stopPropagation();
					choisir({livre: p.livre, chapitre: p.chapitre, debut: v.n, fin: v.n}, true);
				}));
				row.onclick = () => choisir({livre: p.livre, chapitre: p.chapitre, debut: v.n, fin: v.n});
				resultats.append(row);
			}
		} else if (data.type === 'mots') {
			const info = document.createElement('div');
			info.className = 'muted bb-hint';
			info.textContent = data.total ? `${data.total} verset(s) trouvé(s)${data.total > data.resultats.length ? ` — ${data.resultats.length} premiers affichés` : ''}` : 'Aucun verset trouvé. Essayez une référence (ex. « Jean 3:16 ») ou d’autres mots.';
			resultats.append(info);
			const mots = q.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').split(/\s+/).filter((m) => m.length > 1);
			for (const r of data.resultats) {
				const row = document.createElement('div');
				row.className = 'bb-row';
				const ref = document.createElement('span');
				ref.className = 'bb-n';
				ref.textContent = r.reference;
				const tx = document.createElement('span');
				// surligne les mots cherchés (sans tenir compte des accents)
				const plain = r.texte.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
				let last = 0;
				const marks = [];
				for (const m of mots) {
					let i = plain.indexOf(m);
					while (i >= 0) {
						marks.push([i, i + m.length]);
						i = plain.indexOf(m, i + m.length);
					}
				}
				marks.sort((a, b) => a[0] - b[0]);
				for (const [a, b] of marks) {
					if (a < last) continue;
					tx.append(r.texte.slice(last, a));
					const mk = document.createElement('mark');
					mk.textContent = r.texte.slice(a, b);
					tx.append(mk);
					last = b;
				}
				tx.append(r.texte.slice(last));
				const sel = {livre: r.livre, chapitre: r.chapitre, debut: r.verset, fin: r.verset};
				row.append(ref, tx, makeBtn('▶', '', (ev) => {
					ev.stopPropagation();
					choisir(sel, true);
				}));
				row.onclick = () => choisir(sel);
				resultats.append(row);
			}
		} else {
			resultats.textContent = 'Passage introuvable dans cette version.';
		}
	});
	$$('[data-bible-nav]').forEach((b) =>
		b.addEventListener('click', async () => {
			const d = await (await fetch(`/api/bible/${b.dataset.bibleNav}`, {method: 'POST'})).json();
			if (d.erreur) toast(`🔒 ${d.erreur}`);
		}),
	);

	const renderBible = () => {
		const b = state.bible || {};
		$('#bb-courant').textContent = b.reference ? `${b.reference} · ${b.nomVersion}${b.nomVersion2 ? ` + ${b.nomVersion2}` : ''}` : '';
		const box = $('#bb-apercu');
		box.innerHTML = '';
		if (!b.versets?.length) return;
		const t = document.createElement('div');
		t.className = 'bb-prep';
		t.textContent = `${b.visible ? '● À l’antenne' : 'Prêt'} : ${b.reference} (${b.nomVersion})`;
		const tx = document.createElement('div');
		tx.className = 'bb-prep-txt';
		tx.textContent = b.versets.map((v) => (b.versets.length > 1 ? `${v.n} ` : '') + v.texte).join(' ');
		box.append(t, tx);
		box.classList.toggle('live', !!b.visible);
	};

	// --- Repères dans l'aperçu : l'élément survolé dans la régie est entouré -----
	const apercu = $('#apercu');
	const repere = (id) => apercu.contentWindow?.postMessage({type: 'repere', id}, '*');
	$$('.card[data-el]').forEach((card) => {
		card.addEventListener('mouseenter', () => repere(card.dataset.el));
		card.addEventListener('focusin', () => repere(card.dataset.el));
		card.addEventListener('mouseleave', () => repere(null));
	});

	const render = () => {
		fillInputs();
		renderPlacements();
		renderBible();
		renderEmissions();
		renderGuests();
		renderLinks();
		for (const k of ELEMENTS) {
			const on = !!state[k]?.visible;
			$$(`[data-toggle="${k}"]`).forEach((b) => {
				b.classList.toggle('is-on', on);
				b.textContent = on ? 'Affiché' : 'Afficher';
			});
			const card = $(`[data-el="${k}"]`);
			if (card) card.classList.toggle('is-on', on);
		}
		$$('[data-ecran]').forEach((b) => b.classList.toggle('toggle', b.dataset.ecran === state.ecran.mode && state.ecran.mode !== 'aucun'));
		$$('[data-ecran]').forEach((b) => b.classList.toggle('is-on', b.dataset.ecran === state.ecran.mode && state.ecran.mode !== 'aucun'));
		// compteur « à l'antenne » (écran plein compris)
		const nbAntenne = ELEMENTS.filter((k) => k !== 'logo' && state[k]?.visible).length + (state.ecran.mode !== 'aucun' ? 1 : 0);
		$('#antenne-nb').textContent = nbAntenne;
		$('#antenne').classList.toggle('on', nbAntenne > 0);
		$('#sa').textContent = state.score.scoreA;
		$('#sb').textContent = state.score.scoreB;
		renderLicence();
		if (state.assistant && !state.assistant.termine && !assistantVu) ouvrirAssistant();
	};

	// --- Licence ----------------------------------------------------------------------
	const STATUTS = {
		active: ['ok', 'Licence active'],
		essai: ['essai', 'Essai'],
		gratuit: ['ko', 'Non activée'],
		expiree: ['ko', 'Licence expirée'],
		revoquee: ['ko', 'Licence révoquée'],
		refusee: ['ko', 'Licence refusée'],
		invalide: ['ko', 'Licence invalide'],
		'hors-ligne': ['ko', 'Vérification requise'],
	};
	const dlgLic = $('#dlg-licence');
	const renderLicence = () => {
		const l = state.licence;
		if (!l) return;
		const [cls, lib] = STATUTS[l.statut] || ['ko', l.statut];
		const btn = $('#btn-licence');
		btn.dataset.etat = cls;
		$('#lic-resume').textContent = l.statut === 'active' ? l.plan || lib : l.statut === 'essai' ? `Essai · ${l.joursRestants} j` : lib;
		// cartes verrouillées
		$$('[data-fonction]').forEach((el) => {
			const off = !l.fonctions.includes(el.dataset.fonction);
			el.classList.toggle('verrou', off);
			if (el.classList.contains('card')) {
				let v = el.querySelector(':scope > .verrou-bandeau');
				if (off && !v) {
					v = document.createElement('div');
					v.className = 'verrou-bandeau';
					const t = document.createElement('span');
					t.textContent = `🔒 ${l.toutesFonctions[el.dataset.fonction]} : fonction incluse dans les licences`;
					v.append(t, makeBtn('Activer une licence', '', () => ouvrirLicence()));
					el.prepend(v);
				} else if (!off && v) v.remove();
			}
		});
		if (dlgLic.open) remplirLicence();
	};
	const remplirLicence = () => {
		const l = state.licence;
		const [cls, lib] = STATUTS[l.statut] || ['ko', l.statut];
		const box = $('#lic-etat');
		box.className = `lic-etat ${cls}`;
		box.innerHTML = '';
		const titre = document.createElement('div');
		titre.className = 'lic-titre';
		titre.textContent = l.statut === 'active' ? `${lib} — ${l.plan}` : lib;
		const msg = document.createElement('div');
		msg.textContent = l.message;
		box.append(titre, msg);
		if (l.id) {
			const det = document.createElement('dl');
			const lignes = [
				['N° de licence', l.id],
				['Titulaire', `${l.client || ''}${l.organisation ? ` (${l.organisation})` : ''}`],
				['Expiration', l.expire || 'Aucune (perpétuelle)'],
				['Postes', l.postes ? `${l.postes} maximum` : 'selon la licence'],
			];
			if (l.serveur) lignes.push(['Activation en ligne', l.enLigne?.derniere ? `dernière vérification le ${new Date(l.enLigne.derniere).toLocaleString()}` : 'en attente de connexion']);
			for (const [k, v] of lignes) {
				const dt = document.createElement('dt');
				dt.textContent = k;
				const dd = document.createElement('dd');
				dd.textContent = v;
				det.append(dt, dd);
			}
			box.append(det);
		}
		const ul = $('#lic-fonctions');
		ul.innerHTML = '';
		for (const [id, nom] of Object.entries(l.toutesFonctions)) {
			const li = document.createElement('li');
			const on = l.fonctions.includes(id);
			li.className = on ? 'on' : '';
			li.textContent = `${on ? '✓' : '🔒'} ${nom}`;
			ul.append(li);
		}
		const base = document.createElement('li');
		base.className = 'on';
		base.textContent = '✓ Habillages de base (logo, bandeaux, défilant, citation, écrans…)';
		ul.prepend(base);
		$('#lic-machine').textContent = l.machine;
		$('#lic-desactiver').hidden = !l.id;
		$('#lic-verifier').hidden = !l.serveur;
		const v = $('#lic-vendeur');
		v.innerHTML = '';
		if (l.vendeur?.nom) {
			const h = document.createElement('h3');
			h.textContent = 'Obtenir une licence';
			const p = document.createElement('p');
			p.textContent = l.vendeur.nom;
			v.append(h, p);
			const liens = document.createElement('div');
			liens.className = 'lic-liens';
			const lien = (href, txt) => {
				const a = document.createElement('a');
				a.href = href;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = txt;
				liens.append(a);
			};
			if (l.vendeur.email) lien(`mailto:${l.vendeur.email}`, `✉ ${l.vendeur.email}`);
			if (l.vendeur.telephone) lien(`tel:${l.vendeur.telephone.replace(/\s+/g, '')}`, `☎ ${l.vendeur.telephone}`);
			if (l.vendeur.whatsapp) lien(`https://wa.me/${l.vendeur.whatsapp.replace(/\D+/g, '')}`, '💬 WhatsApp');
			v.append(liens);
			if (l.vendeur.site) {
				const a = document.createElement('a');
				a.href = l.vendeur.site;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = 'Voir les offres ↗';
				v.append(a);
			}
		}
	};
	const ouvrirLicence = () => {
		$('#lic-msg').textContent = '';
		remplirLicence();
		if (!dlgLic.open) dlgLic.showModal();
	};
	$('#btn-licence').addEventListener('click', ouvrirLicence);
	$$('dialog [data-fermer]').forEach((b) => b.addEventListener('click', () => b.closest('dialog').close()));
	$$('dialog.dlg-large').forEach((d) => d.addEventListener('click', (e) => e.target === d && d.close()));
	$('#lic-copier-machine').addEventListener('click', () => navigator.clipboard?.writeText(state.licence.machine).then(() => toast('Code du poste copié.')));
	const licAction = async (url, body) => {
		const msg = $('#lic-msg');
		msg.className = 'lic-msg';
		msg.textContent = 'Un instant…';
		const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body || {})});
		const d = await r.json().catch(() => ({}));
		msg.classList.add(r.ok ? 'ok' : 'ko');
		msg.textContent = r.ok ? (url.endsWith('activer') && !url.endsWith('desactiver') ? '✓ Licence activée. Merci !' : '✓ Fait.') : d.erreur || 'Échec.';
		if (r.ok && url.endsWith('/activer')) $('#lic-cle').value = '';
	};
	$('#lic-activer').addEventListener('click', () => {
		const cle = $('#lic-cle').value.trim();
		if (!cle) return ($('#lic-msg').textContent = 'Collez d’abord votre clé.');
		licAction('/api/licence/activer', {cle});
	});
	$('#lic-fichier').addEventListener('change', async (e) => {
		const f = e.target.files[0];
		if (!f) return;
		$('#lic-cle').value = await f.text();
		e.target.value = '';
		licAction('/api/licence/activer', {cle: $('#lic-cle').value});
	});
	$('#lic-verifier').addEventListener('click', () => licAction('/api/licence/verifier'));
	$('#lic-desactiver').addEventListener('click', () => {
		if (confirm('Désactiver la licence sur ce PC ? Vous pourrez la réactiver ici ou sur un autre poste avec la même clé.')) licAction('/api/licence/desactiver');
	});

	// --- Assistant de premier démarrage --------------------------------------------
	let assistantVu = false;
	const dlgAs = $('#dlg-assistant');
	const ouvrirAssistant = () => {
		assistantVu = true;
		$('#as-nom').value = state.chaine.nom === 'Ma Chaîne' ? '' : state.chaine.nom;
		$('#as-reseaux').value = state.chaine.reseaux || '';
		const em = state.emissions[state.emissionActive];
		$('#as-c1').value = em?.couleurs?.primaire || '#3d7bff';
		$('#as-c2').value = em?.couleurs?.secondaire || '#00b8f0';
		$('#as-logo-img').src = state.chaine.logo || '';
		dlgAs.showModal();
	};
	let asLogo = null;
	$('#as-logo').addEventListener('change', async (e) => {
		const file = e.target.files[0];
		if (!file) return;
		const res = await fetch(`/api/upload?nom=${encodeURIComponent(file.name)}`, {method: 'PUT', body: file});
		const data = await res.json();
		if (!res.ok) return toast(data.erreur || 'Échec de l’import');
		asLogo = data.chemin;
		$('#as-logo-img').src = asLogo;
	});
	const finAssistant = (appliquer) => {
		const patch = {assistant: {termine: true}};
		if (appliquer) {
			const nom = $('#as-nom').value.trim();
			if (nom) patch.chaine = {nom};
			patch.chaine = {...patch.chaine, reseaux: $('#as-reseaux').value.trim()};
			if (asLogo) patch.chaine.logo = asLogo;
			patch.emissions = {[state.emissionActive]: {couleurs: {primaire: $('#as-c1').value, secondaire: $('#as-c2').value}}};
			if (nom) patch.citation = {reference: nom};
		}
		send(patch);
		dlgAs.close();
	};
	$('#as-terminer').addEventListener('click', () => finAssistant(true));
	$('#as-plus-tard').addEventListener('click', () => finAssistant(false));
	$('#as-licence').addEventListener('click', () => {
		finAssistant(true);
		ouvrirLicence();
	});

	// --- Téléphone : QR code de l'adresse de la régie ----------------------------------
	const dlg = $('#dlg-telephone');
	$('#dlg-fermer').addEventListener('click', () => dlg.close());
	dlg.addEventListener('click', (e) => {
		if (e.target === dlg) dlg.close();
	});
	$('#btn-telephone').addEventListener('click', async () => {
		const box = $('#tel-contenu');
		box.textContent = 'Chargement…';
		dlg.showModal();
		const info = await (await fetch('/api/reseau')).json();
		box.innerHTML = '';
		const p = (html) => {
			const el = document.createElement('div');
			el.innerHTML = html;
			box.append(el);
			return el;
		};
		if (!info.lan) {
			p(`<p>Le serveur est lancé en mode « ce PC uniquement ».</p>
				<ol>
					<li>Fermez la fenêtre noire du serveur.</li>
					<li>Double-cliquez sur <b>DEMARRER-avec-telephone.bat</b>.</li>
					<li>Revenez ici : le QR code s'affichera.</li>
				</ol>`);
			return;
		}
		if (!info.adresses.length) {
			p('<p>Aucune connexion réseau détectée sur ce PC. Vérifiez que le PC est connecté au Wi-Fi ou à la box.</p>');
			return;
		}
		const qr = document.createElement('img');
		qr.className = 'tel-qr';
		qr.alt = 'QR code de la régie';
		const url = document.createElement('code');
		url.className = 'tel-url';
		const show = (a) => {
			qr.src = `/api/qr.svg?t=${encodeURIComponent(a.url)}`;
			url.textContent = a.url;
		};
		show(info.adresses[0]);
		const wrap = p('');
		wrap.className = 'tel-main';
		const txt = document.createElement('div');
		txt.innerHTML = `<p><b>Scannez ce QR code</b> avec l'appareil photo du téléphone, puis touchez le lien.</p>
			<p class="muted">Le téléphone doit être connecté au <b>même Wi-Fi</b> que ce PC (pas en 4G/5G, pas sur un Wi-Fi « invités »).</p>`;
		txt.append(url);
		wrap.append(qr, txt);
		if (info.adresses.length > 1) {
			const other = p('<p class="muted">Ça ne marche pas ? Essayez une autre adresse de ce PC :</p>');
			const row = document.createElement('div');
			row.className = 'row';
			for (const a of info.adresses) {
				const b = makeBtn(a.ip, 'ghost', () => show(a));
				b.title = a.interface;
				row.append(b);
			}
			other.append(row);
		}
		p(`<details class="tel-aide"><summary>La page ne s'ouvre pas sur le téléphone ?</summary>
			<ol>
				<li><b>Pare-feu Windows</b> (cause la plus fréquente) : double-cliquez sur <b>AUTORISER-TELEPHONE.bat</b> dans le dossier du pack, acceptez la demande d'autorisation, puis réessayez.</li>
				<li>Vérifiez que le téléphone est sur le <b>même Wi-Fi</b> que le PC, et désactivez les données mobiles le temps du test.</li>
				<li>Certaines box isolent les appareils du Wi-Fi « invités » : utilisez le Wi-Fi principal.</li>
				<li>Un VPN actif sur le PC ou le téléphone peut bloquer : désactivez-le.</li>
			</ol></details>`);
	});

	// --- Chat en direct -------------------------------------------------------------
	const post = (url, body) =>
		fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: body ? JSON.stringify(body) : undefined}).then(async (r) => {
			if (r.status === 402) toast(`🔒 ${(await r.clone().json()).erreur}`);
			return r;
		});
	$$('.cx').forEach((cx) => {
		const pf = cx.dataset.pf;
		const secretKey = pf === 'youtube' ? 'cle' : 'jeton';
		cx.querySelector('.cx-connecter').addEventListener('click', () => {
			const body = {video: cx.querySelector('.cx-video').value.trim()};
			const secret = cx.querySelector('.cx-secret').value.trim();
			if (secret) body[secretKey] = secret;
			cx.querySelector('.cx-secret').value = '';
			post(`/api/chat/${pf}/connecter`, body).then(() => loadChat());
		});
		cx.querySelector('.cx-deconnecter').addEventListener('click', () => post(`/api/chat/${pf}/deconnecter`));
		cx.querySelector('.cx-oublier').addEventListener('click', async () => {
			await post(`/api/chat/${pf}/oublier`);
			loadChat();
		});
	});
	const LIBELLES = {arrete: 'Déconnecté', connexion: 'Connexion…', connecte: 'Connecté', erreur: 'Erreur'};
	const renderStatuts = (statuts) => {
		for (const [pf, st] of Object.entries(statuts)) {
			const cx = $(`.cx[data-pf="${pf}"]`);
			if (!cx) continue;
			cx.dataset.etat = st.etat;
			cx.querySelector('.cx-statut').textContent = st.etat === 'erreur' || st.etat === 'arrete' ? st.detail || LIBELLES[st.etat] : LIBELLES[st.etat];
			cx.querySelector('.cx-titre').textContent = st.etat === 'connecte' && st.titre ? `« ${st.titre} »` : '';
		}
	};
	const liste = $('#chat-liste');
	let nbMessages = 0;
	const compteur = () =>
		($('#chat-compteur').textContent = nbMessages ? `${nbMessages} commentaire(s) reçu(s) — les plus récents en haut` : 'Aucun commentaire pour l’instant');
	const ajouterMessage = (m) => {
		nbMessages++;
		const li = document.createElement('li');
		li.className = `chat-msg${m.filtre ? ' filtre' : ''}`;
		li.dataset.id = m.id;
		const av = document.createElement('div');
		av.className = 'chat-av';
		av.textContent = (m.auteur || '?').trim().charAt(0).toUpperCase();
		if (m.avatar) {
			const img = document.createElement('img');
			img.src = m.avatar;
			img.onerror = () => img.remove();
			av.append(img);
		}
		const body = document.createElement('div');
		body.className = 'chat-body';
		const head = document.createElement('div');
		head.className = 'chat-head';
		const pfTag = document.createElement('span');
		pfTag.className = `chat-pf ${m.plateforme.toLowerCase()}`;
		pfTag.textContent = m.plateforme;
		const auteur = document.createElement('b');
		auteur.textContent = m.auteur;
		head.append(pfTag, auteur);
		if (m.montant) {
			const mt = document.createElement('span');
			mt.className = 'chat-montant';
			mt.textContent = m.montant;
			head.append(mt);
		}
		if (m.filtre) {
			const f = document.createElement('span');
			f.className = 'chat-filtre';
			f.textContent = `ignoré en auto (${m.filtre})`;
			head.append(f);
		}
		const tx = document.createElement('div');
		tx.textContent = m.texte;
		body.append(head, tx);
		li.append(av, body, makeBtn('▶ Afficher', '', () => post(`/api/chat/afficher/${m.id}`)));
		liste.prepend(li);
		while (liste.childElementCount > 200) liste.lastElementChild.remove();
		compteur();
	};
	const loadChat = async () => {
		const data = await (await fetch('/api/chat')).json();
		renderStatuts(data.statuts);
		liste.innerHTML = '';
		nbMessages = 0;
		data.messages.forEach(ajouterMessage);
		compteur();
		for (const [pf, cfg] of Object.entries(data.config)) {
			const cx = $(`.cx[data-pf="${pf}"]`);
			const v = cx.querySelector('.cx-video');
			if (document.activeElement !== v) v.value = cfg.video || '';
			const saved = cfg.cleEnregistree || cfg.jetonEnregistre;
			cx.querySelector('.cx-secret').placeholder = saved ? '•••••••• enregistré(e) sur ce PC' : pf === 'youtube' ? 'AIza…' : 'EAA…';
		}
	};
	$('#btn-chat-vider').addEventListener('click', () => post('/api/chat/vider'));

	// Horloge de la régie
	const horloge = () => ($('#horloge').textContent = new Date().toLocaleTimeString('fr-FR'));
	horloge();
	setInterval(horloge, 1000);

	const connect = () => {
		const es = new EventSource('/api/events?chat=1');
		es.addEventListener('chat', (e) => ajouterMessage(JSON.parse(e.data)));
		es.addEventListener('chat-statut', (e) => renderStatuts(JSON.parse(e.data)));
		es.addEventListener('chat-vide', () => {
			liste.innerHTML = '';
			nbMessages = 0;
			compteur();
		});
		es.addEventListener('open', () => loadChat().catch(() => {}));
		es.addEventListener('etat', (e) => {
			state = JSON.parse(e.data);
			$('#statut').classList.add('ok');
			$('#statut-txt').textContent = 'connectée ·';
			render();
		});
		es.onerror = () => {
			$('#statut').classList.remove('ok');
			$('#statut-txt').textContent = 'serveur non joignable — lancez DEMARRER.bat';
		};
	};
	connect();
})();
