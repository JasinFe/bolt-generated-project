<?php /** @var string|null $error @var string|null $info @var string $methode */
/**
 * Second facteur à la connexion (1.876.2) : code à 6 chiffres de l'application
 * d'authentification, ou code de secours. Hors layout : l'utilisateur n'est pas
 * encore connecté.
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );
$logo = fkc_asset( 'img/logo.png' );
?><!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title>Vérification · FinaKop ERP Core</title>
	<link rel="icon" type="image/png" href="<?= e( fkc_asset( 'img/logo-256.png' ) ) ?>">
	<link rel="stylesheet" href="<?= e( fkc_asset( 'css/app.css' ) ) ?>?v=<?= e( FKC_VERSION ) ?>">
	<style>@media(min-width:1081px){.auth-hero{padding:56px 60px;display:flex;flex-direction:column;justify-content:center;gap:24px}}</style>
</head>
<body>
<div class="auth-top"></div>
<div class="auth">
	<section class="auth-hero">
		<div class="hero-logos"><img src="<?= e( $logo ) ?>" alt="FinaKop ERP Core"></div>
		<div class="hero-eyebrow">Double authentification</div>
		<h1 class="hero-title">Encore une<br><span class="accent">vérification</span></h1>
		<?php $parMail = 'email' === ( $methode ?? 'totp' ); ?>
		<p class="hero-sub">Votre mot de passe est correct. <?= $parMail ? 'Un code à 6 chiffres vient d\'être envoyé à l\'adresse e-mail de votre compte : saisissez-le pour terminer la connexion.' : 'Ouvrez votre application d\'authentification et saisissez le code à 6 chiffres affiché pour FinaKop.' ?></p>
		<div class="hero-cards">
			<?php if ( $parMail ) : ?>
			<div class="hero-card full"><span class="hc-ico">✉️</span><div><div class="hc-label">Code reçu par e-mail</div><div class="hc-value">6 chiffres, valable 10 minutes, usage unique. Pensez aux courriers indésirables.</div></div></div>
			<?php else : ?>
			<div class="hero-card full"><span class="hc-ico">📱</span><div><div class="hc-label">Code de l'application</div><div class="hc-value">6 chiffres, renouvelé toutes les 30 secondes</div></div></div>
			<?php endif; ?>
			<div class="hero-card full"><span class="hc-ico">🛟</span><div><div class="hc-label"><?= $parMail ? 'E-mail non reçu ?' : 'Téléphone indisponible ?' ?></div><div class="hc-value"><?= $parMail ? 'Renvoyez un code, ou saisissez l\'un de vos codes de secours (XXXX-XXXX)' : 'Saisissez l\'un de vos codes de secours (XXXX-XXXX)' ?></div></div></div>
		</div>
	</section>
	<section class="auth-form">
		<div class="auth-brand"><img src="<?= e( $logo ) ?>" alt=""><div class="ab-name">FinaKop ERP Core<small>Vérification en deux étapes</small></div></div>
		<h2 class="auth-h">Code de vérification</h2>
		<?php if ( ! empty( $error ) ) : ?><div class="alert alert-err"><?= e( $error ) ?></div><?php endif; ?>
		<?php if ( ! empty( $info ) ) : ?><div class="alert alert-ok"><?= e( $info ) ?></div><?php endif; ?>
		<form method="post" action="<?= e( url( 'verification' ) ) ?>" autocomplete="off">
			<?= FKC_Csrf::field() ?>
			<label class="fld"><span>Code</span>
				<span class="auth-input"><span class="ico">🔐</span>
					<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus placeholder="123456">
				</span>
			</label>
			<button class="btn btn-primary btn-login" type="submit">Vérifier →</button>
		</form>
		<?php if ( $parMail ) : ?>
		<form method="post" action="<?= e( url( 'verification/renvoyer' ) ) ?>" style="margin-top:10px">
			<?= FKC_Csrf::field() ?>
			<button class="btn btn-ghost" type="submit">✉️ Renvoyer un nouveau code</button>
		</form>
		<?php endif; ?>
		<div class="auth-divider"></div>
		<p class="auth-desc"><a href="<?= e( url( 'login' ) ) ?>">← Revenir à la connexion</a></p>
		<p class="auth-foot">FinaKop ERP Core · KOPHI'S GROUP SAS</p>
	</section>
</div>
</body>
</html>
