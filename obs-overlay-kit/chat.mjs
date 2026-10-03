// Lecture des commentaires en direct : YouTube (API Data v3) et Facebook (API Graph).
// Les identifiants (clé API, jeton) restent sur ce PC, dans data/connexions.json :
// ils ne sont jamais envoyés à l'overlay ni aux autres pages.

// Adresses des API (remplaçables pour les tests)
const YT_API = process.env.CHAT_YT_API || 'https://www.googleapis.com/youtube/v3';
const FB_API = process.env.CHAT_FB_API || 'https://graph.facebook.com/v23.0';

const wait = (ms, signal) =>
	new Promise((resolve) => {
		const t = setTimeout(resolve, ms);
		signal?.addEventListener('abort', () => {
			clearTimeout(t);
			resolve();
		});
	});

// --- Identifiants de vidéo --------------------------------------------------------
// YouTube : https://www.youtube.com/watch?v=ID, https://youtu.be/ID, /live/ID, ou l'ID seul
export const idYouTube = (s) => {
	const t = (s || '').trim();
	const m =
		/[?&]v=([\w-]{11})/.exec(t) ||
		/youtu\.be\/([\w-]{11})/.exec(t) ||
		/youtube\.com\/(?:live|shorts|embed)\/([\w-]{11})/.exec(t) ||
		/^([\w-]{11})$/.exec(t);
	return m ? m[1] : null;
};
// Facebook : https://www.facebook.com/<page>/videos/123456789/ , ?v=123 , ou l'ID seul
export const idFacebook = (s) => {
	const t = (s || '').trim();
	const m = /videos\/(?:[^/]+\/)?(\d{6,})/.exec(t) || /[?&]v=(\d{6,})/.exec(t) || /^(\d{6,})$/.exec(t);
	return m ? m[1] : null;
};

// Traduit les erreurs des API en français compréhensible
const errYouTube = (data, status) => {
	const reason = data?.error?.errors?.[0]?.reason || '';
	const msg = data?.error?.message || `HTTP ${status}`;
	if (/keyInvalid|API key not valid/i.test(reason + msg)) return 'Clé API invalide.';
	if (/accessNotConfigured|has not been used|disabled/i.test(reason + msg)) return 'L’API « YouTube Data API v3 » n’est pas activée pour cette clé (voir le guide).';
	if (/quotaExceeded|dailyLimitExceeded/i.test(reason)) return 'Quota YouTube du jour épuisé. Il sera remis à zéro demain (heure du Pacifique).';
	if (/liveChatEnded/i.test(reason)) return 'Le direct est terminé.';
	if (/liveChatDisabled/i.test(reason)) return 'Le chat est désactivé sur ce direct.';
	if (/forbidden|insufficientPermissions/i.test(reason)) return 'Accès refusé par YouTube.';
	return `YouTube : ${msg}`;
};
const errFacebook = (data, status) => {
	const e = data?.error || {};
	if (e.code === 190) return 'Jeton Facebook invalide ou expiré : générez-en un nouveau (voir le guide).';
	if (e.code === 100) return 'Vidéo introuvable, ou le jeton n’a pas accès à cette vidéo (il faut un jeton de la Page qui diffuse).';
	if (e.code === 10 || e.code === 200) return 'Permission manquante : le jeton doit avoir « pages_read_engagement » et « pages_read_user_content ».';
	if (e.code === 4 || e.code === 17 || e.code === 32) return 'Trop de requêtes : Facebook limite temporairement l’accès.';
	return `Facebook : ${e.message || `HTTP ${status}`}`;
};

const getJson = async (url, signal) => {
	const res = await fetch(url, {signal, headers: {Accept: 'application/json'}});
	const data = await res.json().catch(() => ({}));
	return {ok: res.ok, status: res.status, data};
};

// --- Connecteur générique -----------------------------------------------------------
// onMessage(msg) reçoit {id, plateforme, auteur, avatar, texte, montant, role, date}
// onStatut(statut) reçoit {etat: 'connexion'|'connecte'|'erreur'|'arrete', detail, titre}
class Connecteur {
	constructor(plateforme, onMessage, onStatut) {
		this.plateforme = plateforme;
		this.onMessage = onMessage;
		this.onStatut = onStatut;
		this.ctrl = null;
		this.statut = {etat: 'arrete', detail: '', titre: ''};
	}
	setStatut(s) {
		this.statut = {...this.statut, ...s};
		this.onStatut(this.plateforme, this.statut);
	}
	arreter(detail = '') {
		this.ctrl?.abort();
		this.ctrl = null;
		this.setStatut({etat: 'arrete', detail, titre: ''});
	}
	demarrer(params) {
		this.ctrl?.abort();
		const ctrl = new AbortController();
		this.ctrl = ctrl;
		this.setStatut({etat: 'connexion', detail: 'Connexion…', titre: ''});
		this.boucle(params, ctrl.signal).catch((e) => {
			if (ctrl.signal.aborted) return;
			this.ctrl = null;
			this.setStatut({etat: 'erreur', detail: e.message || String(e)});
		});
	}
}

export class YouTubeChat extends Connecteur {
	constructor(onMessage, onStatut) {
		super('youtube', onMessage, onStatut);
	}
	async boucle({cle, video, intervalleMin = 5}, signal) {
		const id = idYouTube(video);
		if (!cle) throw new Error('Clé API YouTube manquante.');
		if (!id) throw new Error('Adresse ou identifiant de vidéo YouTube non reconnu.');
		const k = encodeURIComponent(cle);
		const v = await getJson(`${YT_API}/videos?part=liveStreamingDetails,snippet&id=${id}&key=${k}`, signal);
		if (!v.ok) throw new Error(errYouTube(v.data, v.status));
		const item = v.data.items?.[0];
		if (!item) throw new Error('Vidéo introuvable (vérifiez l’adresse, et que la vidéo est publique ou non répertoriée).');
		const chatId = item.liveStreamingDetails?.activeLiveChatId;
		if (!chatId) throw new Error('Pas de chat en direct actif sur cette vidéo : le direct n’a pas commencé, est terminé, ou le chat est désactivé.');
		this.setStatut({etat: 'connecte', detail: 'Connecté', titre: item.snippet?.title || ''});

		let pageToken = '';
		let premier = true;
		while (!signal.aborted) {
			const r = await getJson(
				`${YT_API}/liveChat/messages?liveChatId=${encodeURIComponent(chatId)}&part=snippet,authorDetails&maxResults=200${pageToken ? `&pageToken=${encodeURIComponent(pageToken)}` : ''}&key=${k}`,
				signal,
			);
			if (!r.ok) throw new Error(errYouTube(r.data, r.status));
			// au premier appel, on ne garde que les 20 derniers messages (pas tout l'historique)
			const items = r.data.items || [];
			for (const m of premier ? items.slice(-20) : items) {
				const s = m.snippet || {};
				const a = m.authorDetails || {};
				const texte = s.displayMessage || s.textMessageDetails?.messageText || '';
				if (!texte && !s.superChatDetails) continue;
				this.onMessage({
					id: `yt-${m.id}`,
					plateforme: 'YouTube',
					auteur: a.displayName || 'Anonyme',
					avatar: a.profileImageUrl || '',
					texte: s.superChatDetails?.userComment || texte,
					montant: s.superChatDetails?.amountDisplayString || s.superStickerDetails?.amountDisplayString || '',
					role: a.isChatOwner ? 'proprietaire' : a.isChatModerator ? 'moderateur' : a.isChatSponsor ? 'membre' : '',
					date: s.publishedAt || new Date().toISOString(),
				});
			}
			premier = false;
			pageToken = r.data.nextPageToken || pageToken;
			// YouTube indique le délai à respecter ; on attend au moins intervalleMin secondes (quota)
			await wait(Math.max(Number(r.data.pollingIntervalMillis) || 0, intervalleMin * 1000), signal);
		}
	}
}

export class FacebookChat extends Connecteur {
	constructor(onMessage, onStatut) {
		super('facebook', onMessage, onStatut);
	}
	async boucle({jeton, video, intervalleMin = 4}, signal) {
		const id = idFacebook(video);
		if (!jeton) throw new Error('Jeton d’accès Facebook manquant.');
		if (!id) throw new Error('Adresse ou identifiant de vidéo Facebook non reconnu.');
		const t = encodeURIComponent(jeton);
		const v = await getJson(`${FB_API}/${id}?fields=title,description&access_token=${t}`, signal);
		if (!v.ok) throw new Error(errFacebook(v.data, v.status));
		this.setStatut({etat: 'connecte', detail: 'Connecté', titre: v.data.title || v.data.description || ''});

		const vus = new Set();
		let premier = true;
		while (!signal.aborted) {
			const r = await getJson(
				`${FB_API}/${id}/comments?order=reverse_chronological&filter=stream&limit=50&fields=id,message,created_time,from{name,picture}&access_token=${t}`,
				signal,
			);
			if (!r.ok) throw new Error(errFacebook(r.data, r.status));
			// les plus récents arrivent en premier : on les remet dans l'ordre chronologique
			const items = (r.data.data || []).slice().reverse();
			for (const c of premier ? items.slice(-20) : items) {
				if (vus.has(c.id)) continue;
				vus.add(c.id);
				if (!c.message) continue;
				this.onMessage({
					id: `fb-${c.id}`,
					plateforme: 'Facebook',
					auteur: c.from?.name || 'Utilisateur Facebook',
					avatar: c.from?.picture?.data?.url || '',
					texte: c.message,
					montant: '',
					role: '',
					date: c.created_time || new Date().toISOString(),
				});
			}
			if (premier) for (const c of items) vus.add(c.id);
			premier = false;
			if (vus.size > 5000) vus.clear();
			await wait(intervalleMin * 1000, signal);
		}
	}
}
