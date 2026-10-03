// Overlay OBS : reçoit l'état depuis le serveur local, place et anime les éléments.
// Paramètres d'URL :
//   ?elements=logo,bandeauNom   n'afficher que certains éléments (une source OBS par élément)
//   ?fond=vert                  fond vert (pour un logiciel qui ne gère pas la transparence)
//   ?apercu=1                   fond damier + repères (aperçu dans la régie)
(() => {
	const params = new URLSearchParams(location.search);
	const only = (params.get('elements') || '').split(',').map((s) => s.trim()).filter(Boolean);
	if (params.get('fond') === 'vert') document.body.classList.add('fond-vert');
	if (params.get('apercu')) document.body.classList.add('apercu');
	if (only.length) {
		document.querySelectorAll('#scene > .slot, #scene > #ecran').forEach((el) => {
			const id = el.dataset.slot || el.id;
			if (!only.includes(id)) el.classList.add('masque');
		});
	}

	const $ = (sel, root = document) => root.querySelector(sel);
	const slot = (id) => $(`.slot[data-slot="${id}"]`);
	const isHidden = (id) => (slot(id) || document.getElementById(id)).classList.contains('masque');
	const setText = (el, txt) => {
		if (el.textContent !== txt) el.textContent = txt;
	};
	const OUT_MS = 550; // durée des animations de sortie avant de réafficher un nouveau contenu

	let state = null;
	const shown = {};

	// Affiche / masque un élément. Si son contenu change pendant qu'il est affiché,
	// il sort puis rentre avec le nouveau contenu (comme à la télé).
	const present = (id, visible, signature, fill) => {
		const el = document.getElementById(id);
		const prev = shown[id] || {visible: false, signature: null};
		shown[id] = {visible, signature};
		clearTimeout(el._t);
		if (!visible) {
			el.classList.remove('on');
			return;
		}
		if (prev.visible && prev.signature !== signature) {
			el.classList.remove('on');
			el._t = setTimeout(() => {
				fill();
				void el.offsetWidth;
				el.classList.add('on');
			}, OUT_MS);
			return;
		}
		if (!prev.visible || prev.signature !== signature) fill();
		void el.offsetWidth;
		el.classList.add('on');
	};

	// --- Placement ------------------------------------------------------------------
	// ancre : haut|milieu|bas + gauche|centre|droite (« centre » seul = milieu-centre)
	// x, y : décalage en pixels (x positif = vers la droite, y positif = vers le bas)
	// echelle : taille en %
	const MARGE = {x: 64, haut: 48, bas: 80};
	const place = (id, pl = {}, reserve) => {
		const s = slot(id);
		if (!s) return;
		const ancre = pl.ancre === 'centre' ? 'milieu-centre' : pl.ancre || 'bas-gauche';
		const [v, h] = ancre.split('-');
		const x = Number(pl.x) || 0;
		const y = Number(pl.y) || 0;
		const k = (Number(pl.echelle) || 100) / 100;
		const st = s.style;
		st.top = st.bottom = st.left = st.right = '';
		let tx = '0px';
		let ty = '0px';
		if (v === 'haut') st.top = `${MARGE.haut + reserve.haut + y}px`;
		else if (v === 'bas') st.bottom = `${MARGE.bas + reserve.bas - y}px`;
		else {
			st.top = `calc(50% + ${y}px)`;
			ty = '-50%';
		}
		if (h === 'gauche') st.left = `${MARGE.x + x}px`;
		else if (h === 'droite') st.right = `${MARGE.x - x}px`;
		else {
			st.left = `calc(50% + ${x}px)`;
			tx = '-50%';
		}
		st.transform = `translate(${tx}, ${ty}) scale(${k})`;
		st.transformOrigin = `${h === 'gauche' ? 'left' : h === 'droite' ? 'right' : 'center'} ${v === 'haut' ? 'top' : v === 'bas' ? 'bottom' : 'center'}`;
		s.dataset.v = v;
		s.dataset.h = h;
	};

	const theme = () => {
		const em = state.emissions[state.emissionActive] || Object.values(state.emissions)[0] || {};
		const c = em.couleurs || {};
		const root = document.documentElement.style;
		root.setProperty('--c1', c.primaire || '#C40808');
		root.setProperty('--c2', c.secondaire || '#F28C1B');
		root.setProperty('--sombre', c.sombre || '#121216');
		root.setProperty('--clair', c.clair || '#FFFFFF');
		return em;
	};

	// --- Défilant : la séquence est répétée pour remplir la largeur, puis dupliquée pour boucler
	let tickerKey = '';
	const buildTicker = (d) => {
		const key = JSON.stringify([d.messages, d.vitesse]);
		if (key === tickerKey) return;
		tickerKey = key;
		const track = $('.tk-track');
		const win = $('.tk-window');
		const msgs = (d.messages || []).filter((m) => m && m.trim());
		track.innerHTML = '';
		if (!msgs.length) return;
		const makeSeq = (times) => {
			const seq = document.createElement('div');
			seq.className = 'tk-seq';
			for (let t = 0; t < times; t++) {
				for (const m of msgs) {
					const item = document.createElement('span');
					item.className = 'tk-item';
					item.textContent = m;
					const sep = document.createElement('span');
					sep.className = 'tk-sep';
					seq.append(item, sep);
				}
			}
			return seq;
		};
		let seq = makeSeq(1);
		track.append(seq);
		const w1 = seq.offsetWidth || 1;
		const times = Math.max(1, Math.ceil((win.offsetWidth || 1600) / w1));
		if (times > 1) {
			track.innerHTML = '';
			seq = makeSeq(times);
			track.append(seq);
		}
		track.append(seq.cloneNode(true));
		const w = seq.offsetWidth;
		track.style.setProperty('--tk-w', `${w}px`);
		track.style.animationDuration = `${w / Math.max(20, Number(d.vitesse) || 110)}s`;
	};

	const initials = (name) =>
		name
			.split(/\s+/)
			.filter(Boolean)
			.slice(0, 2)
			.map((w) => w[0].toUpperCase())
			.join('') || '?';

	// Versets avec numéros en exposant (numéros masqués pour un verset seul)
	const fillVerses = (el, versets) => {
		el.innerHTML = '';
		const multi = versets.length > 1;
		versets.forEach((v, i) => {
			if (multi) {
				const sup = document.createElement('sup');
				sup.textContent = v.n;
				el.append(sup);
			}
			el.append(document.createTextNode(v.texte + (i < versets.length - 1 ? ' ' : '')));
		});
	};
	// taille du texte adaptée à la longueur du passage
	const bibleSize = (versets, parallel) => {
		const len = versets.reduce((n, v) => n + v.texte.length, 0) * (parallel ? 1.5 : 1);
		return len < 170 ? 44 : len < 300 ? 38 : len < 480 ? 33 : len < 700 ? 28 : 24;
	};

	const lastScores = {a: null, b: null};

	// Contour SVG de la carte du logo : adapté à sa taille réelle (périmètre pour l'animation)
	let logoRatio = 2;
	const majContour = () => {
		const card = $('.lg-card');
		const w = card.offsetWidth;
		const h = card.offsetHeight;
		if (!w || !h) return;
		const cs = getComputedStyle($('#logo'));
		const bw = parseFloat(cs.getPropertyValue('--lg-bw')) || 0;
		const r = Math.min(parseFloat(cs.getPropertyValue('--lg-r')) || 0, w / 2, h / 2);
		const svg = $('.lg-border');
		svg.setAttribute('width', w);
		svg.setAttribute('height', h);
		for (const rect of svg.querySelectorAll('rect')) {
			rect.setAttribute('x', bw / 2);
			rect.setAttribute('y', bw / 2);
			rect.setAttribute('width', Math.max(0, w - bw));
			rect.setAttribute('height', Math.max(0, h - bw));
			rect.setAttribute('rx', r);
		}
		const per = 2 * (w - bw + h - bw) - 8 * r + 2 * Math.PI * r;
		$('#logo').style.setProperty('--lg-per', `${per.toFixed(1)}`);
		$('#logo').style.setProperty('--lg-seg', `${Math.max(60, per * 0.22).toFixed(1)}`);
	};
	new ResizeObserver(majContour).observe(document.querySelector('.lg-card'));

	const renderFiligrane = () => {
		const f = state.licence?.filigrane || '';
		const el = $('#filigrane');
		setText(el.querySelector('span'), f);
		el.classList.toggle('on', !!f);
		el.classList.toggle('fort', state.licence?.statut !== 'essai');
	};

	const render = () => {
		const em = theme();
		const s = state;
		renderFiligrane();

		// Défilant (et place réservée pour ne pas chevaucher les autres éléments)
		const tk = s.defilant;
		const tkOn = !!tk.visible && !isHidden('defilant');
		const tkPos = tk.position === 'haut' ? 'haut' : 'bas';
		const tkSlot = slot('defilant');
		tkSlot.style.top = tkPos === 'haut' ? '0px' : '';
		tkSlot.style.bottom = tkPos === 'bas' ? '0px' : '';
		tkSlot.dataset.v = tkPos;
		const reserve = {haut: tkOn && tkPos === 'haut' ? 96 : 0, bas: tkOn && tkPos === 'bas' ? 40 : 0};
		setText($('.tk-label span'), (tk.etiquette || '').toUpperCase());
		$('.tk-label').style.display = tk.etiquette ? '' : 'none';
		present('defilant', tk.visible, 'defilant', () => {});
		buildTicker(tk);

		for (const id of ['logo', 'sujet', 'score', 'message', 'bandeauNom', 'titreEnCours', 'citation', 'bible']) {
			place(id, s[id]?.placement, reserve);
		}

		// Logo de chaîne
		const lg = s.logo;
		const logoEl = $('#logo');
		logoEl.classList.toggle('sans-badge', !lg.afficherBadge);
		logoEl.classList.toggle('sans-heure', !lg.afficherHeure);
		const style = lg.style === 'simple' ? 'simple' : 'carte';
		const dispo = ['verticale', 'logo-pivote', 'horizontale'].includes(lg.disposition) ? lg.disposition : 'verticale';
		for (const c of [...logoEl.classList]) if (c.startsWith('st-') || c.startsWith('d-')) logoEl.classList.remove(c);
		logoEl.classList.add(`st-${style}`, `d-${style === 'simple' ? 'simple' : dispo}`);
		// bandeau « EN DIRECT » + heure : position, disposition, ordre, taille
		const slotV = slot('logo')?.dataset.v;
		const autoPos = style === 'carte' && dispo === 'horizontale' ? 'droite' : style === 'simple' && slotV === 'bas' ? 'dessus' : 'dessous';
		const infosPos = ['dessous', 'dessus', 'droite', 'gauche'].includes(lg.infosPosition) ? lg.infosPosition : autoPos;
		for (const c of [...logoEl.classList]) if (/^(ip|ia|io)-/.test(c)) logoEl.classList.remove(c);
		logoEl.classList.add(`ip-${infosPos}`, `ia-${lg.infosDisposition === 'cote-a-cote' ? 'cote' : 'empilees'}`);
		if (lg.infosOrdre === 'heure-bandeau') logoEl.classList.add('io-inverse');
		const ls = logoEl.style;
		ls.setProperty('--lg-info', String((Number(lg.infosTaille) || 100) / 100));
		ls.setProperty('--lg-court', `${lg.taille}px`);
		ls.setProperty('--lg-long', `${Math.round(lg.taille * logoRatio)}px`);
		ls.setProperty('--lg-fond', lg.couleurCarte || '#000000');
		ls.setProperty('--lg-fond-op', lg.opaciteCarte ?? 0.9);
		ls.setProperty('--lg-trait', lg.couleurContour || '#FFFFFF');
		ls.setProperty('--lg-trait-op', lg.opaciteContour ?? 1);
		ls.setProperty('--lg-bw', `${lg.epaisseurContour ?? 3}px`);
		ls.setProperty('--lg-r', `${lg.arrondi ?? 16}px`);
		logoEl.style.opacity = lg.opacite ?? 1;
		majContour();
		setText($('.lg-badge b', logoEl), (lg.badge || '').toUpperCase());
		present('logo', lg.visible && !!s.chaine.logo, s.chaine.logo, () => {
			const img = $('.lg-img img', logoEl);
			img.onload = () => {
				// proportions réelles du logo (largeur / hauteur)
				logoRatio = img.naturalWidth / img.naturalHeight || 2;
				render();
			};
			img.src = s.chaine.logo;
			logoEl.style.setProperty('--logo-url', `url("${s.chaine.logo}")`);
		});

		// Sujet
		present('sujet', s.sujet.visible, JSON.stringify([s.sujet.etiquette, s.sujet.texte]), () => {
			setText($('.sj-tag'), (s.sujet.etiquette || '').toUpperCase());
			setText($('.sj-txt span'), s.sujet.texte || '');
			$('.sj-tag').style.display = s.sujet.etiquette ? '' : 'none';
		});

		// Score (mise à jour sur place, les points « rebondissent »)
		const sc = s.score;
		present('score', sc.visible, JSON.stringify([sc.equipeA, sc.equipeB]), () => {
			setText($('.sc-a'), sc.equipeA);
			setText($('.sc-b'), sc.equipeB);
		});
		for (const [k, sel] of [['a', '.sc-pa'], ['b', '.sc-pb']]) {
			const v = String(k === 'a' ? sc.scoreA : sc.scoreB);
			const el = $(sel);
			if (lastScores[k] !== null && lastScores[k] !== v) {
				el.classList.remove('bump');
				void el.offsetWidth;
				el.classList.add('bump');
			}
			lastScores[k] = v;
			setText(el, v);
		}
		setText($('.sc-per'), (sc.periode || '').toUpperCase());

		// Message du public
		const ms = s.message;
		present('message', ms.visible, JSON.stringify([ms.auteur, ms.texte, ms.plateforme, ms.avatar, ms.montant]), () => {
			setText($('.ms-auteur'), ms.auteur || '');
			// photo de profil (chat YouTube / Facebook), sinon initiales
			const av = $('.ms-avatar');
			av.textContent = initials(ms.auteur || '');
			if (ms.avatar) {
				const img = document.createElement('img');
				img.onerror = () => img.remove();
				img.src = ms.avatar;
				av.append(img);
			}
			const mt = $('.ms-montant');
			setText(mt, ms.montant || '');
			mt.style.display = ms.montant ? '' : 'none';
			setText($('.ms-texte'), ms.texte || '');
			const pf = $('.ms-plateforme');
			setText(pf, ms.plateforme || '');
			pf.className = `ms-plateforme ${(ms.plateforme || '').toLowerCase()}`;
			pf.style.display = ms.plateforme ? '' : 'none';
		});

		// Bandeau nom
		const bn = s.bandeauNom;
		present('bandeauNom', bn.visible, JSON.stringify([bn.nom, bn.fonction]), () => {
			setText($('.bn-nom span'), bn.nom || '');
			setText($('.bn-fonction span'), bn.fonction || '');
			$('#bandeauNom').classList.toggle('sans-fonction', !bn.fonction);
		});

		// Titre en cours
		const te = s.titreEnCours;
		present('titreEnCours', te.visible, JSON.stringify([te.titre, te.artiste]), () => {
			setText($('.te-titre'), te.titre || '');
			setText($('.te-artiste'), te.artiste || '');
			$('#titreEnCours').classList.toggle('sans-artiste', !te.artiste);
		});

		// Citation
		const ct = s.citation;
		present('citation', ct.visible, JSON.stringify([ct.texte, ct.reference]), () => {
			setText($('.ct-texte'), ct.texte || '');
			setText($('.ct-ref'), ct.reference || '');
			$('.ct-ref').style.display = ct.reference ? '' : 'none';
		});

		// Bible
		const bb = s.bible || {};
		const versets = bb.versets || [];
		const versets2 = bb.versets2 || [];
		present(
			'bible',
			bb.visible && versets.length > 0,
			JSON.stringify([bb.reference, bb.nomVersion, bb.nomVersion2, bb.style, versets.length && versets[0].texte]),
			() => {
				const el = $('#bible');
				el.classList.toggle('plein', bb.style === 'plein-ecran');
				el.classList.toggle('avec-version2', versets2.length > 0);
				el.style.setProperty('--bb-size', `${bibleSize(versets, versets2.length > 0)}px`);
				setText($('.bb-ref'), bb.reference || '');
				setText($('.bb-version'), bb.nomVersion || '');
				setText($('.bb-version2'), bb.nomVersion2 || '');
				fillVerses($('.bb-texte'), versets);
				fillVerses($('.bb-texte2'), versets2);
			},
		);

		// Écran plein
		const ec = s.ecran;
		const mode = ec.mode;
		document.body.classList.toggle('ecran-on', mode !== 'aucun' && !isHidden('ecran'));
		const titles = {debut: ec.titreDebut, pause: ec.titrePause, fin: ec.titreFin};
		present('ecran', mode !== 'aucun' && !!titles[mode], mode, () => {
			const logo = em.logo || s.chaine.logo || '';
			const img = $('.ec-logo');
			if (logo) img.src = logo;
			else img.setAttribute('src', '');
			setText($('.ec-emission'), em.nom || '');
			setText($('.ec-reseaux'), s.chaine.reseaux || '');
		});
		if (mode !== 'aucun') {
			setText($('.ec-sous'), mode === 'fin' ? '' : ec.sousTitre || '');
			setText($('.ec-titre'), titles[mode] || '');
		}
		tick();
	};

	const pad = (n) => String(n).padStart(2, '0');
	const tick = () => {
		if (!state) return;
		const now = new Date();
		setText($('.lg-clock'), `${pad(now.getHours())}${now.getSeconds() % 2 ? ' ' : ':'}${pad(now.getMinutes())}`);

		// Compte à rebours de l'écran de début
		const ec = state.ecran;
		const remaining = Math.max(0, Math.round(((ec.cible || 0) - Date.now()) / 1000));
		const showCount = ec.mode === 'debut' && ec.cible > 0;
		// pendant le fondu de sortie (mode « aucun »), on garde l'écran tel quel
		if (ec.mode !== 'aucun') $('#ecran').classList.toggle('sans-compte', !showCount);
		setText($('.ec-compte-val'), `${pad(Math.floor(remaining / 60))}:${pad(remaining % 60)}`);
		setText($('.ec-compte-label'), remaining > 0 ? 'Début dans' : "C'est parti !");

		// Chrono du score
		const ch = state.score.chrono || {};
		const ms = (ch.cumul || 0) + (ch.enMarche ? Date.now() - (ch.depuis || Date.now()) : 0);
		const sec = Math.floor(ms / 1000);
		setText($('.sc-time'), `${pad(Math.floor(sec / 60))}:${pad(sec % 60)}`);
	};
	setInterval(tick, 250);

	// La régie signale l'élément en cours de réglage : il est entouré dans l'aperçu
	window.addEventListener('message', (e) => {
		if (!e.data || e.data.type !== 'repere') return;
		document.body.classList.toggle('reperes', !!e.data.id);
		document.querySelectorAll('.slot').forEach((s) => s.classList.toggle('actif', s.dataset.slot === e.data.id));
	});

	// --- Connexion au serveur (reconnexion automatique) ---------------------------
	const connect = () => {
		const es = new EventSource('/api/events');
		es.addEventListener('etat', (e) => {
			document.body.classList.remove('deconnecte');
			state = JSON.parse(e.data);
			document.fonts.ready.then(render);
		});
		es.onerror = () => document.body.classList.add('deconnecte');
	};
	connect();
})();
