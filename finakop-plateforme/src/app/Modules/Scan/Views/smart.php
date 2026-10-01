<?php /** FinaKop Smart Scan — écran récepteur PC. @var array $session @var string $join_url @var string $qr_svg @var array $contextes @var array|null $flash */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );
?>
<?php FKC_View::partial( 'scan::_subnav', array( 'tab' => 'smart' ) ); ?>
<?php if ( $flash ) : ?><div class="alert alert-ok"><?= e( is_array( $flash ) ? ( $flash['msg'] ?? '' ) : $flash ) ?></div><?php endif; ?>

<div class="card-head" style="margin-bottom:12px"><h2>📡 FinaKop Smart Scan</h2>
	<span class="muted">Reliez vos téléphones, PDA et webcams — les scans arrivent ici en temps réel.</span>
</div>

<div class="form-grid" style="grid-template-columns:minmax(280px,320px) 1fr;gap:16px">
	<div>
		<div class="card card-flush" style="margin-bottom:14px">
			<div class="card-head"><h2>📱 Appairer un appareil</h2></div>
			<div class="card-body" style="text-align:center">
				<div style="background:#fff;padding:10px;border-radius:10px;display:inline-block"><?= $qr_svg ?></div>
				<p class="muted" style="font-size:12px;margin:10px 0 4px">Scannez ce QR avec le téléphone,<br>ou ouvrez <strong><?= e( preg_replace( '/\?.*$/', '', $join_url ) ) ?></strong> et saisissez le code :</p>
				<div style="font-size:26px;font-weight:800;letter-spacing:3px"><?= e( $code_qualifie ?? $session['code_court'] ) ?></div>
			</div>
		</div>
		<div class="card card-flush">
			<div class="card-head"><h2>🔌 Appareils connectés</h2><span id="dev-count" class="muted">0</span></div>
			<div class="card-body"><div id="devices"><p class="muted" style="text-align:center;padding:16px">En attente d'un appareil…</p></div></div>
		</div>
		<form method="post" action="<?= e( url( 'scan/smart/fermer' ) ) ?>" style="margin-top:10px">
			<input type="hidden" name="session" value="<?= (int) $session['id'] ?>">
			<input type="hidden" name="societe_id" value="<?= (int) ( $societe_id ?? 0 ) ?>">
			<button class="btn btn-ghost btn-sm" type="submit" style="width:100%">Fermer la session</button>
		</form>
	</div>

	<div>
		<div class="form-grid" style="margin-bottom:12px">
			<div class="card"><div class="card-body"><span class="muted">Scans</span><div id="k-scans" style="font-size:1.6em;font-weight:800">0</div></div></div>
			<div class="card"><div class="card-body"><span class="muted">Résolus</span><div id="k-resolus" style="font-size:1.6em;font-weight:800;color:var(--ok)">0</div></div></div>
			<div class="card"><div class="card-body"><span class="muted">Alertes</span><div id="k-alertes" style="font-size:1.6em;font-weight:800;color:var(--warn,#c80)">0</div></div></div>
			<div class="card"><div class="card-body"><span class="muted">Appareils</span><div id="k-devices" style="font-size:1.6em;font-weight:800">0</div></div></div>
		</div>

		<div style="display:flex;gap:8px;margin-bottom:8px">
			<button class="btn btn-ghost btn-sm on" id="tab-flux" onclick="vue('flux')">⚡ Flux temps réel</button>
			<button class="btn btn-ghost btn-sm" id="tab-agg" onclick="vue('agg')">📊 Inventaire fusionné</button>
		</div>

		<div class="card card-flush" id="vue-flux">
			<div class="card-head"><h2>Flux des scans</h2><span class="muted" id="live">● en écoute</span></div>
			<div class="card-body"><div id="feed" style="max-height:520px;overflow-y:auto"><p class="muted" style="text-align:center;padding:24px">Les scans apparaîtront ici dès qu'un appareil scanne.</p></div></div>
		</div>

		<div class="card card-flush" id="vue-agg" style="display:none">
			<div class="card-head"><h2>Inventaire fusionné (multi-appareils)</h2>
				<span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<select id="apply-action" style="padding:6px 10px;border-radius:8px">
						<option value="inventaire"<?= 0 === strpos( (string) $session['contexte'], 'inventaire' ) ? ' selected' : '' ?>>Ajustement d'inventaire</option>
						<option value="reception"<?= 0 === strpos( (string) $session['contexte'], 'reception' ) ? ' selected' : '' ?>>Bon de réception</option>
					</select>
					<button class="btn btn-primary btn-sm" id="apply-btn" onclick="appliquer()">✓ Valider dans l'ERP</button>
				</span>
			</div>
			<div class="card-body"><div id="agg"><p class="muted" style="text-align:center;padding:24px">Aucun article scanné.</p></div>
				<div id="ecarts-zone" style="margin-top:12px">
					<button class="btn btn-sm" type="button" onclick="chargerEcarts()">📊 Calculer les écarts</button>
					<div id="ecarts"></div>
				</div>
				<div id="trace"></div>
				<p class="muted" style="font-size:12px" id="apply-hint">Choisissez l'action puis validez : « Ajustement d'inventaire » corrige le stock au comptage réel ; « Bon de réception » crée une entrée en stock.</p>
			</div>
		</div>
	</div>
</div>

<script>
var SESSION = <?= (int) $session['id'] ?>, LAST = 0, CONTEXTE = <?= json_encode( $session['contexte'] ) ?>, SOC = <?= (int) ( $societe_id ?? 0 ) ?>;
var PULL = <?= json_encode( url( 'scan/smart/pull' ) ) ?>, STREAM = <?= json_encode( url( 'scan/smart/stream' ) ) ?>;
var AGG = <?= json_encode( url( 'scan/smart/agregat' ) ) ?>, DEVDEL = <?= json_encode( url( 'scan/smart/device' ) ) ?>, APPLY = <?= json_encode( url( 'scan/smart/appliquer' ) ) ?>;
/* Guillemets inclus : cette fonction sert aussi à remplir des valeurs
   d'attribut, où un " ou un ' non échappé permet d'en sortir. */
function esc(s){ return (s==null?'':''+s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
var NIV = {info:'#39c', warn:'#c80', danger:'#c33'};
var NIVEAUX_CB = {unite:'', pack:'pack', carton:'carton', palette:'palette'};
function cbNiveau(e){
	var f = parseFloat(e.facteur)||1, u = e.unite||'unite';
	if(u!=='unite' && f>1){ return ' · <span style="color:#6aa8ff">'+esc(NIVEAUX_CB[u]||u)+' → '+esc(e.quantite)+' unités</span>'; }
	if(e.quantite && e.quantite!=1){ return ' · ×'+esc(e.quantite); }
	return '';
}

function vue(v){
	document.getElementById('vue-flux').style.display = v==='flux'?'':'none';
	document.getElementById('vue-agg').style.display = v==='agg'?'':'none';
	document.getElementById('tab-flux').classList.toggle('on', v==='flux');
	document.getElementById('tab-agg').classList.toggle('on', v==='agg');
	if(v==='agg') chargerAgg();
}

function ligneScan(e){
	var al = e.alertes || [], niveau='ok';
	al.forEach(function(a){ if(a.niveau==='danger')niveau='danger'; else if(a.niveau==='warn'&&niveau!=='danger')niveau='warn'; });
	var bord = e.statut==='ok' ? (niveau==='ok'?'var(--ok)':NIV[niveau]) : '#c33';
	var h = '<div style="border-left:4px solid '+bord+';padding:8px 10px;margin-bottom:6px;background:var(--panel,#fff);border-radius:6px">';
	h += '<div style="display:flex;justify-content:space-between"><strong>'+esc(e.libelle||e.code)+'</strong><span class="muted" style="font-size:11px">'+esc((e.created_at||'').substr(11,8))+'</span></div>';
	h += '<div class="muted" style="font-size:12px">'+esc(e.code)+' · '+esc(e.device_nom||'')+(e.user_nom?' · '+esc(e.user_nom):'')+cbNiveau(e)+'</div>';
	al.forEach(function(a){ h += '<div style="font-size:12px;color:'+(NIV[a.niveau]||'#666')+';margin-top:3px">⚠ '+esc(a.message)+(a.reco?' <span class="muted">→ '+esc(a.reco)+'</span>':'')+'</div>'; });
	h += '</div>';
	return h;
}

function appliquerData(d){
	if(d.events && d.events.length){
		var feed = document.getElementById('feed');
		if(LAST===0) feed.innerHTML='';
		d.events.forEach(function(e){ feed.insertAdjacentHTML('afterbegin', ligneScan(e)); });
		LAST = d.last_id;
	}
	if(d.stats){
		document.getElementById('k-scans').textContent = d.stats.scans;
		document.getElementById('k-resolus').textContent = d.stats.resolus;
		document.getElementById('k-alertes').textContent = d.stats.alertes;
		document.getElementById('k-devices').textContent = d.stats.appareils;
	}
	if(d.devices) renderDevices(d.devices);
}
function renderDevices(list){
	document.getElementById('dev-count').textContent = list.length;
	var box = document.getElementById('devices');
	if(!list.length){ box.innerHTML='<p class="muted" style="text-align:center;padding:16px">En attente d\'un appareil…</p>'; return; }
	box.innerHTML = list.map(function(d){
		return '<div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--line)">'
		+'<div><strong>'+esc(d.nom)+'</strong><br><span class="muted" style="font-size:11px">'+esc(d.plateforme)+(d.user_nom?' · '+esc(d.user_nom):'')+' · '+esc(d.nb_scans)+' scan(s)</span></div>'
		+'<button class="btn btn-ghost btn-sm" onclick="delDevice('+d.id+')">✕</button></div>';
	}).join('');
}
function delDevice(id){ var f=new FormData(); f.append('device',id); f.append('societe_id',SOC); fetch(DEVDEL,{method:'POST',body:f}).then(function(){ if(!ES) poll(); }); }

function chargerAgg(){
	fetch(AGG+'?session='+SESSION+'&societe_id='+SOC).then(function(r){return r.json();}).then(function(d){
		var box=document.getElementById('agg');
		if(!d.agregat||!d.agregat.length){ box.innerHTML='<p class="muted" style="text-align:center;padding:24px">Aucun article scanné.</p>'; return; }
		var h='<table class="table"><thead><tr><th>Article</th><th>Scans</th><th>Quantité</th><th>Dernier</th></tr></thead><tbody>';
		d.agregat.forEach(function(a){ h+='<tr><td>'+esc(a.libelle||('#'+a.entite_id))+'</td><td>'+esc(a.nb_scans)+'</td><td><strong>'+esc(a.qte)+'</strong></td><td class="muted" style="font-size:11px">'+esc((a.dernier||'').substr(11,8))+'</td></tr>'; });
		box.innerHTML=h+'</tbody></table>';
	});
}
/* ── 1.818.0 : écarts à valider avant l'écriture, puis traçabilité ── */
var ECARTS=<?= json_encode( url( 'scan/smart/ecarts' ) ) ?>, VALIDER=<?= json_encode( url( 'scan/smart/valider' ) ) ?>, TRACE=<?= json_encode( url( 'scan/smart/trace' ) ) ?>;
function fmt(n){ n=parseFloat(n)||0; return (Math.round(n*1000)/1000).toLocaleString('fr-FR'); }
function chargerEcarts(){
	fetch(ECARTS+'?session='+SESSION+'&societe_id='+SOC).then(function(r){return r.json();}).then(function(d){
		var box=document.getElementById('ecarts');
		if(!d.lignes||!d.lignes.length){ box.innerHTML='<p class="muted">Aucun article compté.</p>'; return; }
		var h='<div style="overflow-x:auto"><table class="table"><thead><tr><th>Article</th><th class="num">Théorique</th><th class="num">Compté</th><th class="num">Écart</th><th class="num">Valeur</th><th>Emplacements</th></tr></thead><tbody>';
		d.lignes.forEach(function(l){
			var c=Math.abs(l.ecart)>0.0001?'var(--warn)':'var(--ok)';
			h+='<tr><td>'+esc(l.libelle)+'</td><td class="num">'+fmt(l.theorique)+'</td><td class="num">'+fmt(l.compte)+'</td><td class="num" style="color:'+c+'"><strong>'+(l.ecart>0?'+':'')+fmt(l.ecart)+'</strong></td><td class="num">'+fmt(l.valeur)+'</td><td style="font-size:11px">'+(l.emplacements||[]).map(function(e){return esc(e.emplacement)+' : '+fmt(e.quantite);}).join('<br>')+'</td></tr>';
		});
		h+='</tbody></table></div><p><strong>'+d.nb_ecarts+' écart(s)</strong> — valeur nette '+fmt(d.total_valeur)+' F</p>';
		h+='<textarea id="ecarts-com" rows="2" style="width:100%" placeholder="Cause des écarts (obligatoire s\'il y en a) : casse, vol, erreur de réception…"></textarea>';
		h+='<button class="btn btn-sm btn-primary" type="button" onclick="validerEcarts()">✓ Valider les écarts</button> <span id="ecarts-msg"></span>';
		box.innerHTML=h;
	});
}
function validerEcarts(){
	var f=new FormData(); f.append('session',SESSION); f.append('societe_id',SOC); f.append('commentaire',document.getElementById('ecarts-com').value);
	fetch(VALIDER,{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
		document.getElementById('ecarts-msg').innerHTML='<strong style="color:'+(d.ok?'var(--ok)':'#c33')+'">'+esc(d.message)+'</strong>';
	});
}
function chargerTrace(){
	fetch(TRACE+'?session='+SESSION+'&societe_id='+SOC).then(function(r){return r.json();}).then(function(d){
		var h='<div style="border:1px solid var(--line);border-radius:10px;padding:10px;margin-top:10px"><strong>Traçabilité</strong> — '+d.scans+' scan(s), '+d.acceptes+' accepté(s), '+d.refuses+' refusé(s), '+d.emplacements+' emplacement(s)';
		if(d.commentaire){ h+='<br><span class="muted">Écarts validés : '+esc(d.commentaire)+'</span>'; }
		if(d.mouvements&&d.mouvements.length){ h+='<br>'+d.mouvements.length+' mouvement(s) de stock'; }
		if(d.ecritures&&d.ecritures.length){ h+='<br>Écriture(s) : '+d.ecritures.map(function(e){ return esc(e.numero||('#'+e.id))+(e.brouillard?' (brouillard)':''); }).join(', '); }
		if(d.reception){ h+='<br>Bon de réception '+esc(d.reception.numero); }
		document.getElementById('trace').innerHTML=h+'</div>';
	});
}
function appliquer(){
	var action = document.getElementById('apply-action').value;
	var libelle = action==='reception' ? 'créer un bon de réception (entrées en stock)' : 'ajuster le stock au comptage réel';
	if(!confirm('Valider dans l\'ERP : '+libelle+' ?')) return;
	var f=new FormData(); f.append('session',SESSION); f.append('contexte',action); f.append('societe_id',SOC);
	document.getElementById('apply-btn').disabled=true;
	fetch(APPLY,{method:'POST',body:f}).then(function(r){return r.json();}).then(function(d){
		document.getElementById('apply-hint').innerHTML='<strong style="color:'+(d.ok?'var(--ok)':'#c33')+'">'+esc(d.message)+'</strong>';
		document.getElementById('apply-btn').disabled=false;
		if(d.ok){ chargerTrace(); }
	}).catch(function(){ document.getElementById('apply-btn').disabled=false; });
}

/* Transport temps réel : SSE si disponible, sinon polling court. */
var ES=null, pollTimer=null;
function poll(){
	fetch(PULL+'?session='+SESSION+'&after='+LAST+'&societe_id='+SOC)
	.then(function(r){return r.json();}).then(function(d){
		if(!d.ok) return; appliquerData(d);
		document.getElementById('live').textContent = d.valide ? '● en écoute (polling)' : '○ session fermée';
	}).catch(function(){});
}
function demarrer(){
	if('EventSource' in window && <?= fkc_sse_actif() ? 'true' : 'false' ?>){
		try{
			ES = new EventSource(STREAM+'?session='+SESSION+'&after='+LAST+'&societe_id='+SOC);
			ES.onmessage = function(ev){ try{ appliquerData(JSON.parse(ev.data)); }catch(e){} document.getElementById('live').textContent='● en écoute (temps réel)'; };
			ES.addEventListener('closed', function(){ document.getElementById('live').textContent='○ session fermée'; ES.close(); });
			ES.onerror = function(){ /* le navigateur relance EventSource automatiquement */ };
			return;
		}catch(e){ ES=null; }
	}
	poll(); pollTimer=setInterval(poll, 1000); // repli : polling < 1 s
}
demarrer();
</script>
