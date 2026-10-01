<?php
/**
 * Activation / désactivation de la double authentification (1.876.2).
 * Hors layout, comme le changement de mot de passe : quand elle est imposée
 * (administrateur), aucune autre page n'est accessible avant.
 *
 * @var array $user @var bool $actif @var bool $impose @var string|null $error @var string|null $ok
 * @var array|null $codes @var int $restants @var string $secret @var string $qr
 */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );
$logo = fkc_asset( 'img/logo.png' );
?><!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<title>Double authentification · FinaKop ERP Core</title>
	<link rel="icon" type="image/png" href="<?= e( fkc_asset( 'img/logo-256.png' ) ) ?>">
	<link rel="stylesheet" href="<?= e( fkc_asset( 'css/app.css' ) ) ?>?v=<?= e( FKC_VERSION ) ?>">
	<style>.qr2fa{background:#fff;border-radius:12px;padding:10px;display:inline-block;margin:6px 0 10px}.qr2fa svg{display:block;width:200px;height:200px}
	.secret2fa{font-family:ui-monospace,Consolas,monospace;letter-spacing:.08em;word-break:break-all;user-select:all}
	.codes2fa{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;font-family:ui-monospace,Consolas,monospace;font-size:15px;margin:8px 0}
	.codes2fa span{background:rgba(127,127,127,.12);border-radius:6px;padding:6px 8px;text-align:center;user-select:all}</style>
</head>
<body>
<div class="auth-top"></div>
<div class="auth">
	<section class="auth-hero">
		<div class="hero-logos"><img src="<?= e( $logo ) ?>" alt="FinaKop ERP Core"></div>
		<div class="hero-eyebrow">Sécurité du compte</div>
		<h1 class="hero-title">Double<br><span class="accent">authentification</span></h1>
		<p class="hero-sub">Même si votre mot de passe était volé, personne ne pourrait se connecter sans votre téléphone.</p>
		<div class="hero-cards">
			<div class="hero-card full"><span class="hc-ico">1️⃣</span><div><div class="hc-label">Installez une application</div><div class="hc-value">Google Authenticator, Microsoft Authenticator, Authy, 2FAS…</div></div></div>
			<div class="hero-card full"><span class="hc-ico">2️⃣</span><div><div class="hc-label">Scannez le QR code</div><div class="hc-value">ou saisissez la clé affichée</div></div></div>
			<div class="hero-card full"><span class="hc-ico">3️⃣</span><div><div class="hc-label">Confirmez avec un code</div><div class="hc-value">puis conservez vos codes de secours</div></div></div>
		</div>
	</section>
	<section class="auth-form">
		<div class="auth-brand"><img src="<?= e( $logo ) ?>" alt=""><div class="ab-name">FinaKop ERP Core<small><?= e( $user['nom_complet'] ?? '' ) ?></small></div></div>
		<h2 class="auth-h"><?= $actif ? 'Double authentification active' : 'Activer la double authentification' ?></h2>
		<?php if ( $impose ) : ?><div class="alert alert-warn">Obligatoire pour les administrateurs : aucune autre page n'est accessible avant l'activation.</div><?php endif; ?>
		<?php if ( ! empty( $error ) ) : ?><div class="alert alert-err"><?= e( $error ) ?></div><?php endif; ?>
		<?php if ( ! empty( $ok ) ) : ?><div class="alert alert-ok"><?= e( $ok ) ?></div><?php endif; ?>

		<?php if ( ! empty( $codes ) ) : ?>
			<div class="alert alert-warn"><strong>Codes de secours — affichés une seule fois.</strong> Notez-les ou imprimez-les et rangez-les en lieu sûr : chacun permet UNE connexion si votre téléphone est perdu.</div>
			<div class="codes2fa"><?php foreach ( $codes as $c ) : ?><span><?= e( $c ) ?></span><?php endforeach; ?></div>
			<p><a class="btn btn-primary btn-login" href="<?= e( url( '' ) ) ?>">J'ai noté mes codes — continuer →</a></p>
		<?php elseif ( $actif ) : ?>
			<p class="auth-desc">Chaque connexion demande le code de votre application. Codes de secours restants : <strong><?= (int) $restants ?></strong>.</p>
			<?php if ( 'admin' !== ( $user['role'] ?? '' ) || ! defined( 'FKC_2FA_ADMIN_OBLIGATOIRE' ) || ! FKC_2FA_ADMIN_OBLIGATOIRE ) : ?>
			<form method="post" action="<?= e( url( 'securite/deux-facteurs/desactiver' ) ) ?>" autocomplete="off">
				<?= FKC_Csrf::field() ?>
				<label class="fld"><span>Mot de passe</span><span class="auth-input"><span class="ico">🔑</span><input type="password" name="password" required></span></label>
				<label class="fld"><span>Code actuel (ou code de secours)</span><span class="auth-input"><span class="ico">🔐</span><input type="text" name="code" inputmode="numeric" required></span></label>
				<button class="btn btn-ghost" type="submit">Désactiver la double authentification</button>
			</form>
			<?php endif; ?>
			<p><a class="btn btn-primary btn-login" href="<?= e( url( '' ) ) ?>">Retour à l'espace →</a></p>
		<?php else : ?>
			<p class="auth-desc">Scannez ce QR code avec votre application :</p>
			<div class="qr2fa"><?= $qr /* SVG généré localement */ ?></div>
			<p class="auth-desc">Ou saisissez cette clé : <span class="secret2fa"><?= e( trim( chunk_split( $secret, 4, ' ' ) ) ) ?></span></p>
			<form method="post" action="<?= e( url( 'securite/deux-facteurs/activer' ) ) ?>" autocomplete="off">
				<?= FKC_Csrf::field() ?>
				<label class="fld"><span>Code affiché par l'application</span>
					<span class="auth-input"><span class="ico">🔐</span><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required autofocus placeholder="123456"></span>
				</label>
				<button class="btn btn-primary btn-login" type="submit">Activer →</button>
			</form>
		<?php endif; ?>

		<div class="auth-divider"></div>
		<form method="post" action="<?= e( url( 'logout' ) ) ?>"><?= FKC_Csrf::field() ?><button class="btn btn-ghost" type="submit">Se déconnecter</button></form>
		<p class="auth-foot">FinaKop ERP Core · KOPHI'S GROUP SAS</p>
	</section>
</div>
</body>
</html>
