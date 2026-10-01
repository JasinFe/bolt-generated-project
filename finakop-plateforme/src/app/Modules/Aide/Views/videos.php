<?php /** @var array $videos @var array $support */
/**
 * Vidéos d'aide (1.876.4) : écrans réels de FinaKop, données d'un espace de
 * démonstration. Fichiers servis par la plateforme (aucun service extérieur).
 * 1.876.5 : bouton de lecture masqué pendant la lecture, bouton plein écran.
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' ); ?>
<div class="bar"><a class="btn btn-ghost btn-sm" href="<?= e( url( 'aide' ) ) ?>"><span aria-hidden="true">←</span> Centre d'aide</a></div>
<section class="ah-hero ah-hero-sm">
	<div class="ah-hero-txt">
		<span class="ah-kicker">Vidéos d'aide</span>
		<h1>Apprenez FinaKop <span class="ah-grad">en une minute</span></h1>
		<p>Chaque vidéo montre les vrais écrans, pas à pas. Cliquez sur un chapitre pour aller directement au passage qui vous intéresse.</p>
	</div>
</section>
<div class="ah-vlist">
<?php foreach ( $videos as $i => $v ) : ?>
	<article class="ah-vcard" id="<?= e( $v['id'] ) ?>">
		<div class="ah-vplayer">
			<video id="vid-<?= e( $v['id'] ) ?>" controls playsinline preload="none" poster="<?= e( fkc_asset( $v['affiche'] ) ) ?>">
				<source src="<?= e( fkc_asset( $v['fichier'] ) ) ?>" type="video/mp4">
				Votre navigateur ne lit pas cette vidéo.
			</video>
			<button type="button" class="ah-vlire" data-video="vid-<?= e( $v['id'] ) ?>" aria-label="Lire la vidéo « <?= e( $v['titre'] ) ?> »"><span class="ah-play" aria-hidden="true"></span></button>
			<button type="button" class="ah-vplein" data-video="vid-<?= e( $v['id'] ) ?>" aria-label="Afficher la vidéo en plein écran">⛶ <b>Plein écran</b></button>
		</div>
		<div class="ah-vinfo">
			<span class="ah-vnum"><?= $i + 1 ?></span>
			<h2><?= e( $v['icon'] . ' ' . $v['titre'] ) ?> <small><?= e( $v['duree'] ) ?></small></h2>
			<p class="muted"><?= e( $v['resume'] ) ?></p>
			<div class="ah-chap" data-video="vid-<?= e( $v['id'] ) ?>">
				<?php foreach ( $v['chapitres'] as $c ) : ?>
					<button type="button" data-t="<?= (int) $c[0] ?>"><span><?= sprintf( '%d:%02d', intdiv( (int) $c[0], 60 ), (int) $c[0] % 60 ) ?></span> <?= e( $c[1] ) ?></button>
				<?php endforeach; ?>
			</div>
		</div>
	</article>
<?php endforeach; ?>
</div>
<p class="muted" style="text-align:center;margin:8px 0 22px">Les vidéos montrent un espace de démonstration (données fictives). Votre écran peut afficher d'autres modules selon votre licence et votre métier.</p>
<div class="ah-support">
	<div><h2>💬 Une question après la vidéo ?</h2><p class="muted">Écrivez-nous : nous vous répondons par WhatsApp, téléphone ou e-mail.</p></div>
	<div class="ah-support-btns">
		<a class="btn ah-btn-wa" href="https://wa.me/<?= e( $support['wa'] ) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
		<a class="btn btn-ghost" href="tel:+<?= e( $support['wa'] ) ?>">📞 <?= e( $support['tel'] ) ?></a>
		<a class="btn btn-ghost" href="mailto:<?= e( $support['mail'] ) ?>">✉️ <?= e( $support['mail'] ) ?></a>
	</div>
</div>
<script>
function ahPlein(v) {
	var c = v.parentNode;
	if (document.fullscreenElement || document.webkitFullscreenElement) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
	if (c.requestFullscreen) { c.requestFullscreen(); } else if (c.webkitRequestFullscreen) { c.webkitRequestFullscreen(); } else if (v.webkitEnterFullscreen) { v.webkitEnterFullscreen(); }
}
document.querySelectorAll('.ah-vplayer video').forEach(function (v) {
	var lire = v.parentNode.querySelector('.ah-vlire'), plein = v.parentNode.querySelector('.ah-vplein');
	lire.addEventListener('click', function () { lire.hidden = true; v.play(); });
	plein.addEventListener('click', function () { if (v.paused) { v.play(); } ahPlein(v); });
	v.addEventListener('dblclick', function () { ahPlein(v); });
	v.addEventListener('play', function () { lire.hidden = true; });
	v.addEventListener('ended', function () { lire.hidden = false; });
});
document.querySelectorAll('.ah-chap').forEach(function (g) {
	var v = document.getElementById(g.dataset.video);
	g.querySelectorAll('button').forEach(function (b) {
		b.addEventListener('click', function () {
			var go = function () { v.currentTime = +b.dataset.t; v.play(); };
			if (v.readyState < 1) { v.preload = 'auto'; v.addEventListener('loadedmetadata', go, { once: true }); v.load(); } else { go(); }
			v.scrollIntoView({ behavior: 'smooth', block: 'center' });
		});
	});
	v.addEventListener('play', function () { document.querySelectorAll('.ah-vplayer video').forEach(function (o) { if (o !== v) { o.pause(); } }); });
});
if (location.hash) { var c = document.querySelector(location.hash); if (c) { c.classList.add('ah-cible'); } }
</script>
