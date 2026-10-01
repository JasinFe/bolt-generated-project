<?php /** @var array $categories @var array $comptes @var array $tutoriels @var array $tours @var array $essentiels @var array $parcours @var string $q @var ?array $resultats @var array $videos @var array $nouveautes @var array $support */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' ); ?>
<?php $videos = $videos ?? array(); $nouveautes = $nouveautes ?? array(); $support = $support ?? array(); $v0 = $videos[0] ?? null; ?>
<section class="ah-hero">
	<div class="ah-hero-txt">
		<span class="ah-kicker">Centre d'aide FinaKop</span>
		<h1>Comment pouvons-nous <span class="ah-grad">vous aider ?</span></h1>
		<p><?= count( FKC_Aide::articles() ) ?> articles, <?= count( $videos ) ?> vidéos, <?= count( $tutoriels ) ?> tutoriels, un glossaire et un guide de prise en main.</p>
		<form method="get" action="<?= e( url( 'aide' ) ) ?>" class="aide-search ah-search">
			<span class="aide-search-ico">🔎</span>
			<input name="q" value="<?= e( $q ) ?>" placeholder="Rechercher : facture, double authentification, TVA, CUMP, licence…" autofocus autocomplete="off">
			<button class="btn btn-primary" type="submit">Rechercher</button>
		</form>
		<div class="aide-quick ah-quick">
			<a class="aide-chip aide-chip-hot" href="<?= e( url( 'aide/guide' ) ) ?>">🎯 Guide de prise en main</a>
			<a class="aide-chip ah-chip-video" href="<?= e( url( 'aide/videos' ) ) ?>">🎬 Vidéos</a>
			<?php foreach ( $tours as $tid => $t ) : ?>
				<button class="aide-chip" data-fkc-tour="<?= e( $tid ) ?>" type="button">✨ <?= e( $t['label'] ) ?></button>
			<?php endforeach; ?>
			<a class="aide-chip" href="<?= e( url( 'aide/tutoriels' ) ) ?>">🎓 Tutoriels</a>
			<a class="aide-chip" href="<?= e( url( 'aide/packs' ) ) ?>">🧩 Packs métier</a>
			<a class="aide-chip" href="<?= e( url( 'aide/depannage' ) ) ?>">🩺 Dépannage</a>
			<a class="aide-chip" href="<?= e( url( 'aide/glossaire' ) ) ?>">📖 Glossaire</a>
			<a class="aide-chip" href="<?= e( url( 'api/docs' ) ) ?>" target="_blank">🔌 API ↗</a>
		</div>
	</div>
	<?php if ( $v0 ) : ?>
	<a class="ah-hero-video" href="<?= e( url( 'aide/videos' ) ) ?>#<?= e( $v0['id'] ) ?>">
		<img src="<?= e( fkc_asset( $v0['affiche'] ) ) ?>" alt="" loading="lazy">
		<span class="ah-play" aria-hidden="true"></span>
		<span class="ah-hero-video-l"><strong>▶ <?= e( $v0['titre'] ) ?></strong><small><?= e( $v0['duree'] ) ?> · commencez par ici</small></span>
	</a>
	<?php endif; ?>
</section>

<?php if ( null !== $resultats ) : ?>
	<div class="card"><div class="card-head"><h2><?= (int) $resultats['total'] ?> résultat(s) pour « <?= e( $q ) ?> »</h2></div>
		<div class="card-body">
		<?php if ( ! $resultats['total'] ) : ?>
			<p class="muted">Aucun résultat. Essayez un terme plus court, ou parcourez les thèmes ci-dessous. Le glossaire aide souvent quand on ne connaît pas encore le bon mot.</p>
		<?php else : ?>
			<?php if ( $resultats['articles'] ) : ?>
				<div class="aide-res-t">📄 Articles</div>
				<?php foreach ( $resultats['articles'] as $a ) : ?>
					<a class="aide-result" href="<?= e( url( 'aide/article/' . $a['id'] ) ) ?>">
						<span class="aide-result-ico"><?= e( $categories[ $a['cat'] ]['icon'] ?? '📄' ) ?></span>
						<span><strong><?= e( $a['titre'] ) ?></strong><br><span class="muted"><?= e( $categories[ $a['cat'] ]['titre'] ?? '' ) ?></span></span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( $resultats['depannage'] ) : ?>
				<div class="aide-res-t">🩺 Dépannage</div>
				<?php foreach ( $resultats['depannage'] as $d ) : ?>
					<div class="aide-qa"><strong><?= e( $d['q'] ) ?></strong><p class="muted"><?= e( $d['r'] ) ?></p></div>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php if ( $resultats['glossaire'] ) : ?>
				<div class="aide-res-t">📖 Glossaire</div>
				<?php foreach ( $resultats['glossaire'] as $g ) : ?>
					<div class="aide-qa"><strong><?= e( $g['terme'] ) ?></strong><p class="muted"><?= e( $g['def'] ) ?></p></div>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php endif; ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( ! $q ) : ?>
<?php if ( $nouveautes ) : ?>
<div class="ah-new">
	<?php foreach ( $nouveautes as $n ) : ?>
		<a class="ah-new-item" href="<?= e( url( isset( $n['route'] ) ? $n['route'] : 'aide/article/' . $n['aide'] ) ) ?>">
			<span class="ah-new-ico"><?= e( $n['icon'] ) ?></span>
			<span><span class="ah-badge">Nouveau</span><strong><?= e( $n['titre'] ) ?></strong><br><span class="muted"><?= e( $n['texte'] ) ?></span></span>
		</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ( $videos ) : ?>
<div class="ah-sec-head"><h2 class="aide-h2">🎬 Vidéos de prise en main</h2><a class="btn btn-ghost btn-sm" href="<?= e( url( 'aide/videos' ) ) ?>">Toutes les vidéos →</a></div>
<div class="ah-videos">
	<?php foreach ( $videos as $v ) : ?>
		<a class="ah-video" href="<?= e( url( 'aide/videos' ) ) ?>#<?= e( $v['id'] ) ?>">
			<span class="ah-video-img"><img src="<?= e( fkc_asset( $v['affiche'] ) ) ?>" alt="" loading="lazy"><span class="ah-play sm" aria-hidden="true"></span><span class="ah-duree"><?= e( $v['duree'] ) ?></span></span>
			<span class="ah-video-t"><?= e( $v['icon'] . ' ' . $v['titre'] ) ?></span>
			<span class="ah-video-r"><?= e( $v['resume'] ) ?></span>
		</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card aide-start"><div class="card-body">
	<div class="aide-start-head">
		<div><h2>🎯 Vous démarrez ?</h2>
		<p class="muted">Le guide déroule les étapes dans l'ordre où elles doivent être faites — sauter la troisième oblige à ressaisir la cinquième.</p></div>
		<a class="btn btn-primary" href="<?= e( url( 'aide/guide' ) ) ?>">Ouvrir le guide →</a>
	</div>
	<div class="aide-start-steps">
		<?php foreach ( $parcours as $i => $p ) : ?>
			<a class="aide-start-step" href="<?= e( url( 'aide/guide' ) ) ?>#<?= e( $p['id'] ) ?>">
				<span class="aide-start-n"><?= $i + 1 ?></span>
				<span><strong><?= e( $p['icon'] . ' ' . $p['titre'] ) ?></strong><br><span class="muted"><?= e( $p['duree'] ) ?></span></span>
			</a>
		<?php endforeach; ?>
	</div>
</div></div>

<?php if ( $essentiels ) : ?>
<h2 class="aide-h2">À lire en premier</h2>
<div class="aide-ess">
	<?php foreach ( $essentiels as $a ) : ?>
		<a class="aide-ess-item" href="<?= e( url( 'aide/article/' . $a['id'] ) ) ?>">
			<span class="aide-ess-ico"><?= e( $categories[ $a['cat'] ]['icon'] ?? '📄' ) ?></span>
			<span><strong><?= e( $a['titre'] ) ?></strong><br><span class="muted"><?= e( $categories[ $a['cat'] ]['titre'] ?? '' ) ?></span></span>
		</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="aide-h2">Parcourir par thème</h2>
<div class="aide-cats">
	<?php foreach ( $categories as $cid => $c ) : ?>
		<a class="aide-cat" href="<?= e( url( 'aide/categorie/' . $cid ) ) ?>">
			<span class="aide-cat-ico"><?= e( $c['icon'] ) ?></span>
			<span class="aide-cat-t"><?= e( $c['titre'] ) ?> <span class="aide-cat-n"><?= (int) ( $comptes[ $cid ] ?? 0 ) ?></span></span>
			<span class="aide-cat-i"><?= e( $c['intro'] ) ?></span>
		</a>
	<?php endforeach; ?>
</div>

<div class="an-grid-2" style="margin-top:18px">
	<div class="card"><div class="card-head"><h2>🎓 Tutoriels</h2><a class="btn btn-ghost btn-sm" href="<?= e( url( 'aide/tutoriels' ) ) ?>">Tout voir</a></div><div class="card-body" style="padding-top:8px">
		<?php foreach ( array_slice( $tutoriels, 0, 6 ) as $t ) : ?>
			<a class="aide-tuto-row" href="<?= e( url( 'aide/tutoriels' ) ) ?>#<?= e( $t['id'] ) ?>">
				<span class="aide-tuto-ico"><?= e( $t['icon'] ) ?></span>
				<span><strong><?= e( $t['titre'] ) ?></strong><br><span class="muted"><?= count( $t['etapes'] ) ?> étapes · <?= e( $t['objectif'] ) ?></span></span>
			</a>
		<?php endforeach; ?>
	</div></div>
	<div class="card"><div class="card-head"><h2>🩺 Problèmes fréquents</h2><a class="btn btn-ghost btn-sm" href="<?= e( url( 'aide/depannage' ) ) ?>">Tout voir</a></div><div class="card-body" style="padding-top:8px">
		<?php foreach ( array_slice( FKC_Aide::depannage(), 0, 6 ) as $d ) : ?>
			<a class="aide-tuto-row" href="<?= e( url( 'aide/depannage' ) ) ?>">
				<span class="aide-tuto-ico">🩺</span>
				<span><strong><?= e( $d['q'] ) ?></strong><br><span class="muted"><?= e( $d['grp'] ) ?></span></span>
			</a>
		<?php endforeach; ?>
	</div></div>
</div>
<?php endif; ?>

<?php if ( ! $q && $support ) : ?>
<div class="ah-support">
	<div><h2>💬 Besoin d'un coup de main ?</h2><p class="muted">Notre équipe vous répond par WhatsApp, téléphone ou e-mail. Ne communiquez jamais votre mot de passe ni vos codes : personne n'en a besoin.</p></div>
	<div class="ah-support-btns">
		<a class="btn ah-btn-wa" href="https://wa.me/<?= e( $support['wa'] ) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
		<a class="btn btn-ghost" href="tel:+<?= e( $support['wa'] ) ?>">📞 <?= e( $support['tel'] ) ?></a>
		<a class="btn btn-ghost" href="mailto:<?= e( $support['mail'] ) ?>">✉️ <?= e( $support['mail'] ) ?></a>
	</div>
</div>
<?php endif; ?>

<script>window.FKC_TOURS = <?= json_encode( $tours, JSON_UNESCAPED_UNICODE ) ?>;</script>
<?php FKC_View::partial( 'aide::_tour' ); ?>
