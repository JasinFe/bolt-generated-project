// Crée une licence depuis la ligne de commande (ou un script d'automatisation),
// via l'API du gestionnaire de licences en cours d'exécution.
//
//   set OLM_JETON=olm_xxx            (Windows)   export OLM_JETON=olm_xxx   (macOS / Linux)
//   node scripts/creer-licence.mjs --offre createur --nom "Église Bethel" --email contact@bethel.org
//
// Options : --offre <code> --nom <texte> --email <e-mail> --organisation <texte> --pays <texte>
//           --jours <n> (0 = perpétuelle) --postes <n> --machine <code> --prix <montant>
//           --paiement paye|en-attente|offert --reference <texte> --quantite <n> --en-ligne
//           --serveur http://localhost:4444   --lic <dossier> (écrit les fichiers .lic)   --json
import {writeFileSync} from 'node:fs';
import {join} from 'node:path';

const args = process.argv.slice(2);
const opt = {};
for (let i = 0; i < args.length; i++) {
	if (!args[i].startsWith('--')) continue;
	const k = args[i].slice(2);
	opt[k] = args[i + 1] && !args[i + 1].startsWith('--') ? args[++i] : true;
}
const serveur = (opt.serveur || process.env.OLM_SERVEUR || 'http://localhost:4444').replace(/\/$/, '');
const jeton = opt.jeton || process.env.OLM_JETON;
if (!jeton || !opt.nom) {
	console.error('Usage : OLM_JETON=… node scripts/creer-licence.mjs --nom "Client" [--offre code] [--email …] [--jours 365] [--json]');
	console.error('Générez le jeton dans le tableau de bord : Paramètres > Automatisation.');
	process.exit(1);
}
const appel = async (chemin, corps) => {
	const r = await fetch(`${serveur}/api/${chemin}`, {method: corps ? 'POST' : 'GET', headers: {Authorization: `Bearer ${jeton}`, 'Content-Type': 'application/json'}, body: corps ? JSON.stringify(corps) : undefined});
	const d = await r.json().catch(() => ({}));
	if (!r.ok) throw new Error(d.erreur || `Erreur ${r.status}`);
	return d;
};
try {
	const corps = {
		offre: opt.offre,
		client: {nom: opt.nom, email: opt.email || '', organisation: opt.organisation || '', pays: opt.pays || ''},
		quantite: Number(opt.quantite) || 1,
		enLigne: !!opt['en-ligne'],
	};
	if (opt.jours !== undefined) corps.duree = Number(opt.jours);
	if (opt.postes !== undefined) corps.postes = Number(opt.postes);
	if (opt.machine) corps.machine = opt.machine;
	if (opt.prix !== undefined) corps.prix = Number(opt.prix);
	if (opt.paiement || opt.reference) corps.paiement = {statut: opt.paiement || 'paye', reference: opt.reference || ''};
	const {licences} = await appel('licences', corps);
	if (opt.lic) {
		for (const l of licences) {
			const r = await fetch(`${serveur}/api/licences/${l.id}/lic`, {headers: {Authorization: `Bearer ${jeton}`}});
			writeFileSync(join(opt.lic, `licence-${l.id}.lic`), await r.text());
		}
	}
	if (opt.json) console.log(JSON.stringify(licences.map(({id, cle, expire, offreNom}) => ({id, cle, expire, offre: offreNom})), null, 2));
	else for (const l of licences) console.log(`${l.id}  ${l.offreNom}  ${l.expire || 'perpétuelle'}\n${l.cle}\n`);
} catch (e) {
	console.error(`Échec : ${e.message}`);
	process.exit(1);
}
