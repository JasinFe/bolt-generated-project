<?php
/**
 * Page de connexion — et vitrine du produit.
 *
 * POURQUOI CETTE PAGE A CHANGÉ (1.546.0)
 * --------------------------------------
 * L'ancienne version annonçait « comptabilité, facturation, fiscalité, paie et
 * plus ». C'était exact il y a deux ans et ne l'est plus : le produit compte
 * aujourd'hui plus de soixante packs métier répartis en onze familles, un
 * moteur UX par métier et un centre de pilotage par profil. Une page d'accueil
 * qui sous-vend de cette ampleur coûte des ventes — c'est le seul écran que
 * voit un prospect au début d'une démonstration.
 *
 * POURQUOI ELLE A ENCORE CHANGÉ (1.872.0)
 * ---------------------------------------
 * La version 1.546.0 disait tout, et c'était devenu son défaut : familles,
 * quatorze modules, éditions et argumentaire s'empilaient sur quatre écrans
 * de défilement. Un prospect ne lit pas un inventaire ; il retient une idée
 * et une impression. La vitrine tient donc désormais sur UN écran.
 *
 * TEXTES REVUS EN 1.873.0, à la demande de l'éditeur :
 *   - la promesse devient « Pour votre Entreprise. Un seul espace de
 *     Gestion. », suivie des domaines couverts et de trois phrases ;
 *   - quatre atouts, formulés comme l'argumentaire commercial ;
 *   - une accroche et une signature ;
 *   - le formulaire accueille : « Bienvenue sur FinaKop ».
 * Deux éléments de la 1.872.0 ont été RETIRÉS : l'aperçu du cockpit (des
 * montants figés sur une page d'accueil étonnent plus qu'ils ne convainquent,
 * et il prenait trop de place) et le sélecteur animé de métiers (le ruban
 * des packs montre déjà l'étendue, sans doublon).
 *
 * DEUX PRINCIPES DE CONSTRUCTION
 * ------------------------------
 * 1. CE QUI BOUGE EST CALCULÉ. Le nombre de packs, la liste des métiers du
 *    ruban et le nombre de familles sont lus dans les manifests (FKC_Packs,
 *    FKC_PackFamilles) ; les libellés d'éditions viennent de FKC_Modules.
 *    Ajouter un pack demain met la vitrine à jour sans qu'on y touche.
 *
 * 2. RIEN NE PEUT EMPÊCHER DE SE CONNECTER. Toute lecture est enveloppée : si
 *    un manifest est illisible ou une classe absente, la vitrine se réduit et
 *    le formulaire reste servi. Le script de la page n'est qu'un décor : sans
 *    lui, le formulaire fonctionne à l'identique et les chiffres sont déjà
 *    affichés à leur valeur finale.
 *
 * ATTENTION : cette page est PUBLIQUE et ANONYME. Elle ne doit montrer que le
 * catalogue du produit — jamais rien qui tienne à la licence installée, à la
 * société ni au contenu de la base. Ce qui figure ici est ce qui figure dans
 * la documentation commerciale, et rien de plus.
 *
 * @var string|null $error
 * @package FinaKop_ERP_Core
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );

$logo  = fkc_asset( 'img/logo.png' );
$https = ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] );

/* ── Métiers et familles, lus dans les manifests ─────────────────────────
 * $vitPacks alimente le ruban. L'ordre est entrelacé par
 * famille (un commerce, puis un hôtel, puis une clinique…) : le ruban
 * montre ainsi l'étendue du produit dès les premières secondes, au lieu de
 * dérouler douze commerces d'affilée.
 */
$vitPacks    = array();
$vitNbFam    = 0;
try {
	if ( class_exists( 'FKC_Packs' ) && class_exists( 'FKC_PackFamilles' ) ) {
		$manifests = array();
		foreach ( FKC_Packs::all() as $code => $m ) {
			// « generique » n'est pas un métier : c'est le socle sans spécialisation.
			if ( 'generique' === $code || 'multi' === $code ) { continue; }
			$m['code']   = $code;
			$manifests[] = $m;
		}
		$parFamille = array();
		foreach ( FKC_PackFamilles::grouper( $manifests ) as $famCode => $g ) {
			if ( 'socle' === $famCode ) { continue; }
			$vitNbFam++;
			foreach ( $g['packs'] as $p ) {
				// « FinaKop Artist — Artiste, Producteur & Revenus » → « Artist » :
				// le ruban veut un nom, pas une fiche produit.
				$lab = trim( preg_replace( '/^FinaKop\s+/u', '', (string) ( $p['label'] ?? $p['code'] ) ) );
				if ( false !== strpos( $lab, ' — ' ) && strlen( $lab ) > 26 ) {
					$lab = trim( strstr( $lab, ' — ', true ) );
				}
				$parFamille[ $famCode ][] = array( 'i' => (string) ( $p['icone'] ?? '🧩' ), 'l' => $lab );
			}
		}
		// Entrelacement : une tête par famille, tour après tour.
		while ( $parFamille ) {
			foreach ( $parFamille as $k => $liste ) {
				$vitPacks[] = array_shift( $parFamille[ $k ] );
				if ( ! $parFamille[ $k ] ) { unset( $parFamille[ $k ] ); }
			}
		}
	}
} catch ( \Throwable $e ) {
	$vitPacks = array();
	$vitNbFam = 0;
}
$vitNbPacks = count( $vitPacks );

/* ── Modules : on compte les dossiers de app/Modules/ ─────────────────── */
$vitNbModules = 0;
try {
	foreach ( scandir( FKC_ROOT . 'Modules' ) as $d ) {
		if ( '.' !== $d[0] && is_dir( FKC_ROOT . 'Modules/' . $d ) ) { $vitNbModules++; }
	}
} catch ( \Throwable $e ) {
	$vitNbModules = 0;
}

/* ── Éditions présentées (une sélection du référentiel, pas son entier) ── */
$vitEditions = array();
foreach ( array( 'starter', 'business', 'pro', 'enterprise', 'creative' ) as $cle ) {
	$lab = ucfirst( $cle );
	try {
		if ( class_exists( 'FKC_Modules' ) ) { $lab = FKC_Modules::profileLabel( $cle ); }
	} catch ( \Throwable $e ) {}
	$vitEditions[] = $lab;
}

/* Chiffres clés : valeur affichée, valeur à animer (0 = pas d'animation). */
$vitChiffres = array(
	array( $vitNbPacks ?: '60+', $vitNbPacks, 'Packs métier' ),
	array( $vitNbFam ?: '10+', $vitNbFam, 'Familles d\'activité' ),
	array( $vitNbModules ?: '25+', $vitNbModules, 'Modules' ),
	array( count( $vitEditions ), count( $vitEditions ), 'Éditions' ),
);

/* Les quatre forces — argumentaire, pas inventaire. Icônes en SVG (traits). */
$vitForces = array(
	array( 'b', '<path d="M3 21h18M5 21V10m4 11V10m6 11V10m4 11V10M2 10l10-6 10 6"/>',
		'Comptabilité & Fiscalité', 'SYSCOHADA révisé, comptabilité structurée et outils fiscaux adaptés à la Côte d\'Ivoire.' ),
	array( 'o', '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M12 12l8-4.5M12 12v9M12 12L4 7.5"/>',
		'Votre métier', 'Des packs spécialisés qui adaptent FinaKop à votre activité, vos processus et vos indicateurs.' ),
	array( 'g', '<path d="M3 4h2l2.4 11.2a1 1 0 001 .8h9.2a1 1 0 001-.8L20 8H6.2"/><circle cx="9" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/>',
		'Gestion au quotidien', 'Ventes, achats, caisse, stocks, trésorerie et paiements réunis dans un même environnement.' ),
	array( 'p', '<path d="M4 20V10m6 10V4m6 16v-7m4 7H2"/>',
		'Pilotage', 'Un cockpit pour suivre votre activité, analyser vos résultats et prendre de meilleures décisions.' ),
);

/* Domaines couverts, en une ligne sous le titre. */
$vitDomaines = array( 'Comptabilité', 'Facturation', 'Fiscalité', 'Caisse', 'Stocks', 'RH', 'Trésorerie', 'Pilotage', 'Packs métiers' );

/* Le ruban défile en deux rangées de sens opposés. */
$vitRubans = $vitPacks ? array_chunk( $vitPacks, (int) ceil( $vitNbPacks / 2 ) ) : array();
?><!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Connexion · FinaKop ERP Core</title>
	<meta name="robots" content="noindex,nofollow">
	<meta name="theme-color" content="#0A0F1C">
	<link rel="icon" type="image/png" href="<?= e( fkc_asset( 'img/logo-256.png' ) ) ?>">
	<?php if ( ! defined( 'FKC_PLATEFORME' ) ) : // Plateforme : aucune ressource tierce (vie privée, CSP). ?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<?php endif; ?>
	<link rel="stylesheet" href="<?= e( fkc_asset( 'css/app.css' ) ) ?>?v=<?= e( FKC_VERSION ) ?>">
</head>
<body class="auth-body lg-body">
<div class="auth-top"></div>
<div class="auth lg">

	<!-- ══════════ Colonne gauche : la vitrine ══════════ -->
	<section class="auth-hero lg-hero" aria-label="FinaKop ERP Core en bref">
		<div class="lg-bg" aria-hidden="true"><i class="lg-orb a"></i><i class="lg-orb b"></i><i class="lg-orb c"></i><i class="lg-grid"></i></div>

		<div class="lg-inner">
			<div class="lg-top">
				<div class="hero-logos lg-logo"><img src="<?= e( $logo ) ?>" alt=""></div>
				<div class="lg-nom">FinaKop ERP Core<small>KOPHI'S GROUP SAS</small></div>
				<span class="lg-badge"><i></i>SYSCOHADA révisé · États DGI Côte d'Ivoire</span>
			</div>

			<div class="lg-pitch">
				<?php /* Aucun chiffre dans le titre : il serait faux le jour où un
				         pack est ajouté. Les chiffres sont en dessous, calculés. */ ?>
				<h1 class="hero-title lg-title">Pour votre <span class="accent">Entreprise</span>.<br>Un seul espace de <span class="accent">Gestion</span>.</h1>

				<ul class="lg-domaines" aria-label="Domaines couverts">
					<?php foreach ( $vitDomaines as $d ) : ?><li><?= e( $d ) ?></li><?php endforeach; ?>
				</ul>

				<div class="lg-textes">
					<p class="lg-sub"><strong>FinaKop</strong> réunit dans une même plateforme la gestion, la comptabilité et le pilotage de votre activité.</p>
					<p class="lg-sub"><strong>FinaKop s'adapte à votre métier</strong>, avec des outils, des écrans et des indicateurs conçus pour votre activité.</p>
					<p class="lg-sub lg-sub-s">Que vous soyez commerçant, restaurateur, hôtelier, professionnel de santé, cabinet comptable,
						entreprise de services, transporteur, acteur culturel ou créatif, association ou autre organisation,
						FinaKop vous permet de gérer votre activité depuis un espace unique.</p>
				</div>

				<div class="lg-stats">
					<?php foreach ( $vitChiffres as $c ) : ?>
						<div class="lg-stat"><b<?= $c[1] ? ' data-count="' . (int) $c[1] . '"' : '' ?>><?= e( $c[0] ) ?></b><span><?= e( $c[2] ) ?></span></div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="lg-atouts">
				<h2 class="lg-accroche">Une vision claire. Des données maîtrisées. <span>Des décisions mieux éclairées.</span></h2>
				<div class="lg-forces">
					<?php foreach ( $vitForces as $f ) : ?>
						<div class="lg-force">
							<span class="lg-force-ico <?= e( $f[0] ) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $f[1] /* SVG écrit ci-dessus, jamais une donnée */ ?></svg></span>
							<div><div class="lg-force-lab"><?= e( $f[2] ) ?></div><p class="lg-force-txt"><?= e( $f[3] ) ?></p></div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<p class="lg-signature"><b>FinaKop</b> — Pilotez votre activité. Maîtrisez vos chiffres. Développez votre entreprise.</p>
		</div>

		<?php if ( $vitRubans ) : ?>
		<!-- Ruban : chaque pack une fois ; la copie qui assure la boucle est masquée
		     aux lecteurs d'écran et marquée « is-copie ». -->
		<div class="lg-ruban" aria-label="Packs métier disponibles">
			<div class="lg-ruban-titre"><span>Packs métier</span><?= (int) $vitNbPacks ?> activités prêtes à l'emploi</div>
			<div class="lg-ruban-rangs">
			<?php foreach ( $vitRubans as $r => $rang ) : ?>
				<div class="lg-ruban-rang<?= $r ? ' inv' : '' ?>" style="--d:<?= (int) max( 40, count( $rang ) * 3 ) ?>s">
					<?php foreach ( array( '', ' is-copie' ) as $copie ) : ?>
						<div class="lg-ruban-piste<?= e( $copie ) ?>"<?= $copie ? ' aria-hidden="true"' : '' ?>>
							<?php foreach ( $rang as $p ) : ?><span class="lg-pk"><b><?= e( $p['i'] ) ?></b><?= e( $p['l'] ) ?></span><?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
	</section>

	<!-- ══════════ Colonne droite : le formulaire ══════════ -->
	<section class="auth-form lg-form">
		<div class="auth-form-inner lg-card">
			<div class="auth-brand">
				<img src="<?= e( $logo ) ?>" alt="">
				<div class="ab-name">FinaKop ERP Core<small>KOPHI'S GROUP SAS</small></div>
			</div>
			<h2 class="auth-h">Bienvenue sur FinaKop</h2>
			<p class="auth-desc">Connectez-vous à votre espace de gestion et retrouvez toutes les informations essentielles au pilotage de votre activité.</p>
			<?php if ( $https ) : ?><div class="auth-secure">🔒 Accès sécurisé · HTTPS</div><?php endif; ?>
			<?php if ( $error ) : ?><div class="alert alert-err"><?= e( $error ) ?></div><?php endif; ?>

			<form method="post" action="<?= e( url( 'login' ) ) ?>" id="lgForm">
				<?= FKC_Csrf::field() ?>
				<label class="fld"><span>Identifiant</span>
					<span class="auth-input"><span class="ico" aria-hidden="true">👤</span>
						<input type="text" name="login" placeholder="Votre identifiant" autocomplete="username" autofocus required>
					</span>
				</label>
				<label class="fld"><span>Mot de passe</span>
					<span class="auth-input"><span class="ico" aria-hidden="true">🔑</span>
						<input type="password" name="password" id="lgPwd" placeholder="••••••••" autocomplete="current-password" required>
						<button type="button" class="lg-eye" id="lgEye" aria-label="Afficher le mot de passe" aria-pressed="false" hidden>
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
						</button>
					</span>
				</label>
				<p class="lg-caps" id="lgCaps" hidden>⇪ Verrouillage des majuscules activé</p>
				<?php /* Changer de mot de passe exige d'être connecté (l'ancien est
				         redemandé sur l'écran dédié) : la case ne fait qu'y conduire
				         juste après la connexion. Aucun envoi par e-mail n'existe —
				         la réinitialisation passe par un administrateur. */ ?>
				<div class="lg-options">
					<label class="lg-check"><input type="checkbox" name="changer_mdp" value="1"><span>Modifier mon mot de passe après la connexion</span></label>
				</div>
				<button class="btn btn-primary btn-login" type="submit" id="lgBtn"><span>Se connecter</span><span class="lg-arrow" aria-hidden="true">→</span></button>
			</form>

			<details class="lg-oubli">
				<summary>Mot de passe oublié&nbsp;?</summary>
				<p>Pour votre sécurité, FinaKop n'envoie jamais de mot de passe par e-mail.</p>
				<?php /* Aucune mention de l'hébergement : cette page est publique. */ ?>
				<p>Demandez à votre administrateur de vous attribuer un nouveau mot de passe.</p>
				<p>Une fois connecté, cochez « Modifier mon mot de passe après la connexion » pour le remplacer par le vôtre.</p>
			</details>

			<div class="auth-divider"></div>
			<div class="auth-tags-label">Éditions disponibles</div>
			<div class="auth-tags">
				<?php foreach ( $vitEditions as $ed ) : ?><span class="auth-tag"><?= e( $ed ) ?></span><?php endforeach; ?>
			</div>

			<!-- Repli mobile : la vitrine est masquée sur petit écran, ce résumé la remplace. -->
			<div class="auth-mini">
				<span><?= e( $vitNbPacks ?: '60+' ) ?> packs métier</span>
				<span><?= e( $vitNbModules ?: '25+' ) ?> modules</span>
				<span>SYSCOHADA révisé</span>
				<span>États DGI CI</span>
			</div>

			<p class="auth-foot">FinaKop ERP Core<?= function_exists( 'fkc_version_publique' ) ? fkc_version_publique() : ' v' . e( FKC_VERSION ) ?> · <a href="https://kophisgroup.com" rel="noopener">kophisgroup.com</a> · Abidjan, CI</p>
		</div>
	</section>
</div>
<script>
/* Décor seulement : sans ce script, la page et le formulaire fonctionnent à
   l'identique (les chiffres sont déjà rendus à leur valeur finale). */
(function () {
	var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* Mot de passe : afficher / masquer, et avertir du verrouillage majuscules. */
	var pwd = document.getElementById('lgPwd'), eye = document.getElementById('lgEye'), caps = document.getElementById('lgCaps');
	if (pwd && eye) {
		eye.hidden = false;
		eye.addEventListener('click', function () {
			var voir = pwd.type === 'password';
			pwd.type = voir ? 'text' : 'password';
			eye.setAttribute('aria-pressed', voir ? 'true' : 'false');
			eye.setAttribute('aria-label', voir ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
			pwd.focus();
		});
	}
	if (pwd && caps) {
		var majs = function (ev) { if (ev.getModifierState) { caps.hidden = !ev.getModifierState('CapsLock'); } };
		pwd.addEventListener('keydown', majs); pwd.addEventListener('keyup', majs);
		pwd.addEventListener('blur', function () { caps.hidden = true; });
	}

	/* Envoi : un seul clic compte, et on le montre. */
	var form = document.getElementById('lgForm'), btn = document.getElementById('lgBtn');
	if (form && btn) {
		form.addEventListener('submit', function () {
			btn.classList.add('is-envoi');
			btn.firstChild.textContent = 'Connexion…';
			setTimeout(function () { btn.disabled = true; }, 0);
		});
	}

	if (calme) { return; }

	/* Chiffres : décompte de 0 à la valeur finale. */
	var nbs = document.querySelectorAll('.lg-stat b[data-count]');
	Array.prototype.forEach.call(nbs, function (el, i) {
		var fin = parseInt(el.getAttribute('data-count'), 10), t0 = null, duree = 1400 + i * 150;
		if (!fin) { return; }
		el.textContent = '0';
		function pas(t) {
			if (!t0) { t0 = t; }
			var k = Math.min(1, (t - t0) / duree);
			el.textContent = Math.round(fin * (1 - Math.pow(1 - k, 3)));
			if (k < 1) { requestAnimationFrame(pas); }
		}
		setTimeout(function () { requestAnimationFrame(pas); }, 350 + i * 90);
	});

})();
</script>
</body>
</html>
