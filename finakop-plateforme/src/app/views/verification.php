<?php /** @var string|null $error */
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
</head>
<body>
<div class="auth-top"></div>
<div class="auth">
	<section class="auth-hero">
		<div class="hero-logos"><img src="<?= e( $logo ) ?>" alt="FinaKop ERP Core"></div>
		<div class="hero-eyebrow">Double authentification</div>
		<h1 class="hero-title">Encore une<br><span class="accent">vérification</span></h1>
		<p class="hero-sub">Votre mot de passe est correct. Ouvrez votre application d'authentification et saisissez le code à 6 chiffres affiché pour FinaKop.</p>
		<div class="hero-cards">
			<div class="hero-card full"><span class="hc-ico">📱</span><div><div class="hc-label">Code de l'application</div><div class="hc-value">6 chiffres, renouvelé toutes les 30 secondes</div></div></div>
			<div class="hero-card full"><span class="hc-ico">🛟</span><div><div class="hc-label">Téléphone indisponible ?</div><div class="hc-value">Saisissez l'un de vos codes de secours (XXXX-XXXX)</div></div></div>
		</div>
	</section>
	<section class="auth-form">
		<div class="auth-brand"><img src="<?= e( $logo ) ?>" alt=""><div class="ab-name">FinaKop ERP Core<small>Vérification en deux étapes</small></div></div>
		<h2 class="auth-h">Code de vérification</h2>
		<?php if ( ! empty( $error ) ) : ?><div class="alert alert-err"><?= e( $error ) ?></div><?php endif; ?>
		<form method="post" action="<?= e( url( 'verification' ) ) ?>" autocomplete="off">
			<?= FKC_Csrf::field() ?>
			<label class="fld"><span>Code</span>
				<span class="auth-input"><span class="ico">🔐</span>
					<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus placeholder="123456">
				</span>
			</label>
			<button class="btn btn-primary btn-login" type="submit">Vérifier →</button>
		</form>
		<div class="auth-divider"></div>
		<p class="auth-desc"><a href="<?= e( url( 'login' ) ) ?>">← Revenir à la connexion</a></p>
		<p class="auth-foot">FinaKop ERP Core · KOPHI'S GROUP SAS</p>
	</section>
</div>
</body>
</html>
