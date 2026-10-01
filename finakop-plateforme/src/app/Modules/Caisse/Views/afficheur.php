<?php /** Écran client — afficheur secondaire plein écran. @var int $caisse_id @var array|null $caisse @var array $societe @var string $message */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );
$enseigne = $societe['raison_sociale'] ?? ( $societe['sigle'] ?? 'FinaKop' );
?>
<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e( $enseigne ) ?> — Écran client</title>
<style>
:root{--bg:#0b1020;--panel:#141a2e;--line:#26304d;--text:#eef2ff;--muted:#8b95b5;--accent:#4f7cff;--ok:#37d67a;--gold:#ffc34d}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:radial-gradient(1200px 800px at 70% -10%,#1a2banner,transparent),var(--bg);color:var(--text);overflow:hidden}
.screen{height:100vh;display:flex;flex-direction:column}
.top{display:flex;justify-content:space-between;align-items:center;padding:24px 40px;border-bottom:1px solid var(--line)}
.brand{font-size:30px;font-weight:800;letter-spacing:.5px}
.brand small{display:block;font-size:14px;font-weight:500;color:var(--muted)}
.ticketno{color:var(--muted);font-size:16px}
.body{flex:1;display:flex;min-height:0}
.items{flex:1;overflow-y:auto;padding:16px 40px}
.item{display:flex;justify-content:space-between;align-items:baseline;padding:14px 0;border-bottom:1px solid var(--line);font-size:22px;animation:pop .25s ease}
.item .q{color:var(--muted);font-size:16px;margin-right:14px}
.item .name{flex:1}
.item .amt{font-variant-numeric:tabular-nums;font-weight:700}
@keyframes pop{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.side{width:40%;max-width:560px;padding:28px 40px;border-left:1px solid var(--line);display:flex;flex-direction:column;justify-content:flex-end;background:linear-gradient(180deg,transparent,rgba(79,124,255,.06))}
.rowline{display:flex;justify-content:space-between;font-size:18px;color:var(--muted);padding:6px 0}
.rowline.disc{color:var(--gold)}
.total{display:flex;justify-content:space-between;align-items:baseline;margin-top:16px;padding-top:16px;border-top:2px solid var(--line)}
.total .lbl{font-size:24px;font-weight:700}
.total .val{font-size:56px;font-weight:800;font-variant-numeric:tabular-nums}
.cur{font-size:24px;color:var(--muted);margin-left:8px}
.idle{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:18px}
.idle .logo{font-size:64px;font-weight:800}
.idle .msg{font-size:26px;color:var(--muted)}
.pulse{width:10px;height:10px;border-radius:50%;background:var(--ok);display:inline-block;animation:blink 1.6s infinite}
@keyframes blink{50%{opacity:.3}}
.paid{position:fixed;inset:0;background:rgba(6,10,22,.96);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;z-index:10}
.paid .check{font-size:96px}
.paid .thanks{font-size:40px;font-weight:800}
.paid .rendu{font-size:28px;color:var(--gold)}
.hidden{display:none!important}
.foot{padding:14px 40px;text-align:center;color:var(--muted);font-size:14px;border-top:1px solid var(--line)}
</style>
</head><body>
<div class="screen">
	<div class="top">
		<div class="brand"><?= e( $enseigne ) ?><small><?= e( $societe['adresse'] ?? '' ) ?></small></div>
		<div class="ticketno" id="tno"></div>
	</div>

	<div class="body" id="body-active" style="display:none">
		<div class="items" id="items"></div>
		<div class="side">
			<div class="rowline" id="r-sous"><span>Sous-total</span><span id="v-sous">0</span></div>
			<div class="rowline disc hidden" id="r-remise"><span>Remise</span><span id="v-remise">0</span></div>
			<div class="rowline disc hidden" id="r-promo"><span>Promotions</span><span id="v-promo">0</span></div>
			<div class="rowline disc hidden" id="r-fid"><span>Fidélité</span><span id="v-fid">0</span></div>
			<div class="rowline hidden" id="r-tva"><span>dont TVA</span><span id="v-tva">0</span></div>
			<div class="total"><span class="lbl">À PAYER</span><span><span class="val" id="v-total">0</span><span class="cur">FCFA</span></span></div>
		</div>
	</div>

	<div class="idle" id="body-idle">
		<div class="logo"><?= e( $enseigne ) ?></div>
		<div class="msg"><?= e( $message ) ?></div>
		<div><span class="pulse"></span></div>
	</div>

	<div class="foot">Bienvenue — vos articles s'affichent ici en temps réel.</div>
</div>

<div class="paid hidden" id="paid">
	<div class="check">✅</div>
	<div class="thanks">Merci&nbsp;!</div>
	<div class="rendu hidden" id="paid-rendu"></div>
</div>

<script>
var CAISSE = <?= (int) $caisse_id ?>;
var STATE = <?= json_encode( url( 'caisse/afficheur/etat' ) ) ?>, STREAM = <?= json_encode( url( 'caisse/afficheur/stream' ) ) ?>;
var REV = '', paidTimer = null;
function fmt(n){ return (Math.round(n||0)).toLocaleString('fr-FR'); }
/* Guillemets inclus : cette fonction sert aussi à remplir des valeurs
   d'attribut, où un " ou un ' non échappé permet d'en sortir. */
function esc(s){ return (s==null?'':''+s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

function render(d){
	if(!d || d.rev===REV) return; REV = d.rev;
	document.getElementById('tno').textContent = d.numero ? ('Ticket '+d.numero) : '';

	if(d.paye){
		document.getElementById('paid-rendu').classList.toggle('hidden', !(d.rendu>0));
		if(d.rendu>0) document.getElementById('paid-rendu').textContent = 'Rendu : '+fmt(d.rendu)+' FCFA';
		document.getElementById('paid').classList.remove('hidden');
		clearTimeout(paidTimer); paidTimer=setTimeout(function(){ document.getElementById('paid').classList.add('hidden'); }, 12000);
		return;
	}
	document.getElementById('paid').classList.add('hidden');

	var actif = d.statut==='actif' && d.nb>0;
	document.getElementById('body-active').style.display = actif ? 'flex' : 'none';
	document.getElementById('body-idle').style.display = actif ? 'none' : 'flex';
	if(!actif) return;

	var box=document.getElementById('items'), h='';
	d.lignes.forEach(function(l){
		var q = l.quantite && l.quantite!=1 ? '<span class="q">'+ (l.quantite % 1 ? l.quantite : Math.round(l.quantite)) +'×</span>' : '';
		h += '<div class="item">'+q+'<span class="name">'+esc(l.libelle)+'</span><span class="amt">'+fmt(l.total)+'</span></div>';
	});
	box.innerHTML = h; box.scrollTop = box.scrollHeight;

	document.getElementById('v-sous').textContent = fmt(d.sous_total);
	function opt(id,row,val){ document.getElementById(row).classList.toggle('hidden', !(val>0)); if(val>0) document.getElementById(id).textContent = '-'+fmt(val); }
	opt('v-remise','r-remise',d.remise);
	opt('v-promo','r-promo',d.promo);
	opt('v-fid','r-fid',d.fidelite);
	document.getElementById('r-tva').classList.toggle('hidden', !(d.tva>0));
	if(d.tva>0) document.getElementById('v-tva').textContent = fmt(d.tva);
	document.getElementById('v-total').textContent = fmt(d.total);
}

/* Temps réel : SSE si disponible, repli polling 0,7 s. Aucune dépendance. */
var ES=null;
function poll(){ fetch(STATE+'?caisse='+CAISSE).then(function(r){return r.json();}).then(render).catch(function(){}); }
function start(){
	if('EventSource' in window && <?= fkc_sse_actif() ? 'true' : 'false' ?>){
		try{
			ES=new EventSource(STREAM+'?caisse='+CAISSE);
			ES.onmessage=function(ev){ try{ render(JSON.parse(ev.data)); }catch(e){} };
			ES.onerror=function(){ /* reconnexion auto du navigateur */ };
			return;
		}catch(e){ ES=null; }
	}
	poll(); setInterval(poll, 700);
}
start();
</script>
</body></html>
