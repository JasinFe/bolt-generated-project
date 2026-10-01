<?php /** @var string|null $error @var string|null $ok @var bool $impose @var array $user */
/**
 * Changement de mot de passe.
 *
 * Rendue hors layout : quand le changement est IMPOSÉ (compte d'installation,
 * ou compte détecté avec l'ancien mot de passe par défaut), l'utilisateur n'a
 * accès à aucune autre route — afficher la barre latérale et ses liens
 * inaccessibles serait trompeur.
 *
 * @var string|null $error
 * @var string|null $ok
 * @var bool        $impose
 * @var array       $user
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );
$logo = fkc_asset( 'img/logo.png' );
?><!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Mot de passe · FinaKop ERP Core</title>
	<link rel="icon" type="image/png" href="<?= e( fkc_asset( 'img/logo-256.png' ) ) ?>">
	<link rel="stylesheet" href="<?= e( fkc_asset( 'css/app.css' ) ) ?>?v=<?= e( FKC_VERSION ) ?>">
</head>
<body>
<div class="auth-top"></div>
<div class="auth">

	<section class="auth-hero">
		<div class="hero-logos"><img src="<?= e( $logo ) ?>" alt="FinaKop ERP Core"></div>
		<div class="hero-eyebrow">Sécurité du compte</div>
		<h1 class="hero-title">Choisissez votre<br><span class="accent">mot de passe</span></h1>
		<?php if ( $impose ) : ?>
		<p class="hero-sub">Le mot de passe fourni à l'installation est à usage unique. Remplacez-le pour ouvrir votre espace de gestion : il donne accès à la comptabilité et aux déclarations de toutes vos sociétés.</p>
		<?php else : ?>
		<p class="hero-sub">Changer régulièrement de mot de passe n'est pas nécessaire. En changer dès qu'un doute existe, si.</p>
		<?php endif; ?>
		<div class="hero-cards">
			<div class="hero-card full">
				<span class="hc-ico">📏</span>
				<div><div class="hc-label">Longueur</div><div class="hc-value">12 caractères minimum</div></div>
			</div>
			<div class="hero-card full">
				<span class="hc-ico">🔤</span>
				<div><div class="hc-label">Composition</div><div class="hc-value">3 catégories sur 4 : minuscules, majuscules, chiffres, spéciaux</div></div>
			</div>
			<div class="hero-card full">
				<span class="hc-ico">🚫</span>
				<div><div class="hc-label">À éviter</div><div class="hc-value">Votre identifiant, « finakop », « azerty », « 123456 »</div></div>
			</div>
		</div>
	</section>

	<section class="auth-form">
		<div class="auth-brand">
			<img src="<?= e( $logo ) ?>" alt="">
			<div class="ab-name">FinaKop ERP Core<small><?= e( $user['nom_complet'] ?? '' ) ?></small></div>
		</div>
		<h2 class="auth-h"><?= $impose ? 'Changement requis' : 'Changer de mot de passe' ?></h2>
		<p class="auth-desc">Compte <strong><?= e( $user['login'] ?? '' ) ?></strong>.</p>

		<?php if ( $impose ) : ?>
			<div class="alert alert-warn">Cette étape est obligatoire. Aucune autre page n'est accessible avant.</div>
		<?php endif; ?>
		<?php if ( ! empty( $error ) ) : ?><div class="alert alert-err"><?= e( $error ) ?></div><?php endif; ?>
		<?php if ( ! empty( $ok ) ) : ?><div class="alert alert-ok"><?= e( $ok ) ?></div><?php endif; ?>

		<form method="post" action="<?= e( url( 'mot-de-passe' ) ) ?>" autocomplete="off">
			<?= FKC_Csrf::field() ?>
			<label class="fld"><span>Mot de passe actuel</span>
				<span class="auth-input"><span class="ico">🔑</span>
					<input type="password" name="ancien" autocomplete="current-password" required autofocus>
				</span>
			</label>
			<label class="fld"><span>Nouveau mot de passe</span>
				<span class="auth-input"><span class="ico">🆕</span>
					<input type="password" name="nouveau" autocomplete="new-password" minlength="12" required>
				</span>
			</label>
			<label class="fld"><span>Confirmation</span>
				<span class="auth-input"><span class="ico">✅</span>
					<input type="password" name="confirmation" autocomplete="new-password" minlength="12" required>
				</span>
			</label>
			<button class="btn btn-primary btn-login" type="submit">Enregistrer le mot de passe →</button>
		</form>

		<?php if ( ! $impose ) : ?><p class="auth-desc">🔐 <a href="<?= e( url( 'securite/deux-facteurs' ) ) ?>">Double authentification (code sur téléphone)</a></p><?php endif; ?>
		<div class="auth-divider"></div>
		<form method="post" action="<?= e( url( 'logout' ) ) ?>">
			<?= FKC_Csrf::field() ?>
			<button class="btn btn-ghost" type="submit">Se déconnecter</button>
		</form>
		<p class="auth-foot">FinaKop ERP Core<?= fkc_version_publique() ?> · KOPHI'S GROUP SAS</p>
	</section>
</div>
</body>
</html>
