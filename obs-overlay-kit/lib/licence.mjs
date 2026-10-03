// Format des clés de licence OBS Overlay Kit — fichier partagé entre le gestionnaire de
// licences (qui signe) et le kit (qui vérifie). Aucune dépendance : node:crypto suffit.
//
// Une clé ressemble à :  OOK1-<contenu en base64url>.<signature Ed25519 en base64url>
// Le contenu est un petit JSON lisible par tous ; la signature, elle, ne peut être produite
// qu'avec la clé privée du vendeur. Toute modification du contenu (plan, date…) invalide la clé.
import {createHash, createPrivateKey, createPublicKey, generateKeyPairSync, randomBytes, sign, verify} from 'node:crypto';

// Propriétaire du logiciel OBS Overlay Kit et de son gestionnaire de licences
export const PROPRIETAIRE = {
	nom: "KOPHI'S GROUP SAS",
	email: 'contact@kophisgroup.com',
	telephone: '+225 05 03 40 43 89',
	whatsapp: '+2250503404389',
	site: 'https://www.kophisgroup.com',
};
export const MENTION = `OBS Overlay Kit est la propriété de ${PROPRIETAIRE.nom}. Tous droits réservés.`;

export const PREFIXE = 'OOK1';
export const VERSION_FORMAT = 1;

// Fonctions activables par une licence (identifiant -> libellé)
export const FONCTIONS = {
	chat: 'Chat YouTube / Facebook en direct',
	bible: 'Bible intégrée (recherche, versions parallèles)',
	telephone: 'Pilotage depuis un téléphone / une tablette',
	api: 'Raccourcis Stream Deck / Touch Portal (API)',
	emissions: 'Émissions multiples (changement d’habillage)',
	score: 'Score et chronomètre',
};

const b64u = (buf) => Buffer.from(buf).toString('base64url');
const unb64u = (s) => Buffer.from(s, 'base64url');

// Identifiant lisible : OOK-7F3K-9QZ2-M4TD (alphabet sans 0/O/1/I pour éviter les confusions)
const ALPHA = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
export const nouvelId = () => {
	const b = randomBytes(12);
	let s = '';
	for (let i = 0; i < 12; i++) s += ALPHA[b[i] % ALPHA.length];
	return `OOK-${s.slice(0, 4)}-${s.slice(4, 8)}-${s.slice(8, 12)}`;
};

export const genererPaire = () => {
	const {privateKey, publicKey} = generateKeyPairSync('ed25519');
	return {
		privee: privateKey.export({type: 'pkcs8', format: 'pem'}),
		publique: publicKey.export({type: 'spki', format: 'pem'}),
	};
};

export const empreinteCle = (pemPublique) =>
	createHash('sha256').update(createPublicKey(pemPublique).export({type: 'spki', format: 'der'})).digest('hex').slice(0, 16).toUpperCase().match(/.{4}/g).join('-');

// Champs courts pour garder la clé compacte
//   v  version du format      id identifiant          p  plan (code)      pn nom du plan
//   n  client                 e  e-mail               o  organisation
//   f  fonctions []           m  code machine ('' = tout poste)
//   a  postes max (0 = illimité)                       d  date d'émission (AAAA-MM-JJ)
//   x  expiration (AAAA-MM-JJ, '' = perpétuelle)       s  serveur d'activation ('' = hors ligne)
export const signerLicence = (contenu, pemPrivee) => {
	const charge = {v: VERSION_FORMAT, ...contenu};
	const data = Buffer.from(JSON.stringify(charge), 'utf8');
	const sig = sign(null, data, createPrivateKey(pemPrivee));
	return `${PREFIXE}-${b64u(data)}.${b64u(sig)}`;
};

// Extrait la clé d'un texte collé (espaces, retours à la ligne, fichier .lic…)
export const extraireCle = (texte) => {
	const brut = String(texte || '').replace(/-----[^-]+-----/g, ' ');
	const m = brut.replace(/\s+/g, '').match(new RegExp(`${PREFIXE}-[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+`));
	return m ? m[0] : '';
};

// Vérifie la signature. Ne regarde PAS les dates : voir statutDates().
export const lireLicence = (texte, pemPublique) => {
	const cle = extraireCle(texte);
	if (!cle) return {valide: false, erreur: 'Ce texte ne contient pas de clé de licence (elle commence par « OOK1- »).'};
	const [corps, sig] = cle.slice(PREFIXE.length + 1).split('.');
	let contenu;
	try {
		contenu = JSON.parse(unb64u(corps).toString('utf8'));
	} catch {
		return {valide: false, cle, erreur: 'Clé illisible : vérifiez qu’elle a été copiée en entier.'};
	}
	if (!pemPublique) return {valide: false, cle, contenu, erreur: 'Clé publique du vendeur absente : impossible de vérifier la licence.'};
	let ok = false;
	try {
		ok = verify(null, unb64u(corps), createPublicKey(pemPublique), unb64u(sig));
	} catch {}
	if (!ok) return {valide: false, cle, contenu, erreur: 'Signature invalide : cette clé a été modifiée ou n’a pas été émise pour ce logiciel.'};
	if (contenu.v !== VERSION_FORMAT) return {valide: false, cle, contenu, erreur: 'Format de clé non pris en charge par cette version du logiciel.'};
	return {valide: true, cle, contenu};
};

export const aujourdhui = (t = Date.now()) => new Date(t).toISOString().slice(0, 10);
const JOUR = 86400000;
// Jours restants avant expiration (fin de journée UTC), null = perpétuelle
export const joursRestants = (x, t = Date.now()) => (x ? Math.ceil((Date.parse(`${x}T23:59:59Z`) - t) / JOUR) : null);

export const ajouterDuree = (dateIso, jours) => (jours > 0 ? aujourdhui(Date.parse(`${dateIso}T12:00:00Z`) + jours * JOUR) : '');

// Rend un fichier .lic lisible par un humain (la clé reste la seule partie qui compte)
export const fichierLic = (cle, contenu, produit = 'OBS Overlay Kit') =>
	[
		`-----BEGIN ${produit.toUpperCase()} LICENCE-----`,
		...(cle.match(/.{1,64}/g) || []),
		`-----END ${produit.toUpperCase()} LICENCE-----`,
		'',
		`N° de licence : ${contenu.id}`,
		`Titulaire     : ${contenu.n}${contenu.o ? ` (${contenu.o})` : ''}`,
		`Offre         : ${contenu.pn || contenu.p}`,
		`Valable       : ${contenu.x ? `jusqu'au ${contenu.x}` : 'sans limite de durée'}`,
		`Postes        : ${contenu.m ? `ce poste uniquement (${contenu.m})` : contenu.a ? `${contenu.a} maximum` : 'illimité'}`,
		'',
		'Pour activer : ouvrez la régie > bouton « Licence » > collez la clé ou importez ce fichier.',
		'',
		MENTION,
		`${PROPRIETAIRE.email} · Tél. / WhatsApp ${PROPRIETAIRE.telephone} · ${PROPRIETAIRE.site.replace('https://', '')}`,
		'',
	].join('\n');
