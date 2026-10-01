<?php
/**
 * Activation / désactivation de la double authentification (1.876.2).
 * Hors layout, comme le changement de mot de passe : quand elle est imposée
 * (administrateur), aucune autre page n'est accessible avant.
 *
 * @var array $user @var bool $actif @var bool $impose @var string|null $error @var string|null $ok
 * @var array|null $codes @var int $restants @var string $secret @var string $qr
 * @var string|null $methode @var string $email (masqué) @var bool $email_envoye @var string|null $info
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
	.codes2fa span{background:rgba(127,127,127,.12);border-radius:6px;padding:6px 8px;text-align:center;user-select:all}
	@media(min-width:1081px){.auth-hero{padding:56px 60px;display:flex;flex-direction:column;justify-content:center;gap:24px}}</style>
</head>
<body>
<div class="auth-top"></div>
<div class="auth">
	<section class="auth-hero">
		<div class="hero-logos"><img src="<?= e( $logo ) ?>" alt="FinaKop ERP Core"></div>
		<div class="hero-eyebrow">Sécurité du compte</div>
		<h1 class="hero-title">Double<br><span class="accent">authentification</span></h1>
		<p class="hero-sub">Même si votre mot de passe était volé, personne ne pourrait se connecter sans le second code : celui de votre application, ou celui reçu par e-mail.</p>
		<div class="hero-cards">
			<div class="hero-card full"><span class="hc-ico">📱</span><div><div class="hc-label">Application (recommandé)</div><div class="hc-value">Google Authenticator, Microsoft Authenticator, Authy, 2FAS… Fonctionne même sans réseau.</div></div></div>
			<div class="hero-card full"><span class="hc-ico">✉️</span><div><div class="hc-label">Code par e-mail</div><div class="hc-value">Un code à 6 chiffres envoyé à chaque connexion à l'adresse de votre compte.</div></div></div>
			<div class="hero-card full"><span class="hc-ico">🛟</span><div><div class="hc-label">Codes de secours</div><div class="hc-value">10 codes à usage unique, à conserver en lieu sûr.</div></div></div>
		</div>
	</section>
	<section class="auth-form">
		<div class="auth-brand"><img src="<?= e( $logo ) ?>" alt=""><div class="ab-name">FinaKop ERP Core<small><?= e( $user['nom_complet'] ?? '' ) ?></small></div></div>
		<h2 class="auth-h"><?= $actif ? 'Double authentification active' : 'Activer la double authentification' ?></h2>
		<?php if ( $impose ) : ?><div class="alert alert-warn">Obligatoire pour les administrateurs : aucune autre page n'est accessible avant l'activation.</div><?php endif; ?>
		<?php if ( ! empty( $error ) ) : ?><div class="alert alert-err"><?= e( $error ) ?></div><?php endif; ?>
		<?php if ( ! empty( $ok ) ) : ?><div class="alert alert-ok"><?= e( $ok ) ?></div><?php endif; ?>
		<?php if ( ! empty( $info ) ) : ?><div class="alert alert-ok"><?= e( $info ) ?></div><?php endif; ?>

		<?php if ( ! empty( $codes ) ) : ?>
			<div class="alert alert-warn"><strong>Codes de secours — affichés une seule fois.</strong> Notez-les ou imprimez-les et rangez-les en lieu sûr : chacun permet UNE connexion si votre téléphone est perdu.</div>
			<div class="codes2fa"><?php foreach ( $codes as $c ) : ?><span><?= e( $c ) ?></span><?php endforeach; ?></div>
			<p><a class="btn btn-primary btn-login" href="<?= e( url( '' ) ) ?>">J'ai noté mes codes — continuer →</a></p>
		<?php elseif ( $actif ) : ?>
			<p class="auth-desc"><?= 'email' === $methode ? 'Chaque connexion demande le code envoyé par e-mail à <strong>' . e( $email ) . '</strong>.' : 'Chaque connexion demande le code de votre application.' ?> Codes de secours restants : <strong><?= (int) $restants ?></strong>.</p>
			<?php if ( 'admin' !== ( $user['role'] ?? '' ) || ! defined( 'FKC_2FA_ADMIN_OBLIGATOIRE' ) || ! FKC_2FA_ADMIN_OBLIGATOIRE ) : ?>
			<form method="post" action="<?= e( url( 'securite/deux-facteurs/desactiver' ) ) ?>" autocomplete="off">
				<?= FKC_Csrf::field() ?>
				<label class="fld"><span>Mot de passe</span><span class="auth-input"><span class="ico">🔑</span><input type="password" name="password" required></span></label>
				<label class="fld"><span><?= 'email' === $methode ? 'Code reçu par e-mail (ou code de secours)' : 'Code actuel (ou code de secours)' ?></span><span class="auth-input"><span class="ico">🔐</span><input type="text" name="code" inputmode="numeric" required></span></label>
				<button class="btn btn-ghost" type="submit">Désactiver la double authentification</button>
			</form>
			<?php if ( 'email' === $methode ) : ?>
			<form method="post" action="<?= e( url( 'securite/deux-facteurs/email/envoyer' ) ) ?>" style="margin-top:8px"><?= FKC_Csrf::field() ?><button class="btn btn-ghost" type="submit">✉️ Recevoir un code pour désactiver</button></form>
			<?php endif; ?>
			<?php endif; ?>
			<p><a class="btn btn-primary btn-login" href="<?= e( url( '' ) ) ?>">Retour à l'espace →</a></p>
		<?php endif; ?>
		<?php if ( empty( $codes ) && ( ! $actif || 'email' === $methode ) ) : ?>
			<?php if ( $actif ) : ?><div class="auth-divider"></div><h3 class="auth-h" style="font-size:17px">Passer à l'application (plus sûr)</h3><?php else : ?>
			<h3 class="auth-h" style="font-size:17px">📱 Option 1 — Application d'authentification <small style="font-weight:600;color:#16a34a">recommandé</small></h3><?php endif; ?>
			<p class="auth-desc">Scannez ce QR code avec votre application :</p>
			<div class="qr2fa"><?= $qr /* SVG généré localement */ ?></div>
			<p class="auth-desc">Ou saisissez cette clé : <span class="secret2fa"><?= e( trim( chunk_split( $secret, 4, ' ' ) ) ) ?></span></p>
			<form method="post" action="<?= e( url( 'securite/deux-facteurs/activer' ) ) ?>" autocomplete="off">
				<?= FKC_Csrf::field() ?>
				<label class="fld"><span>Code affiché par l'application</span>
					<span class="auth-input"><span class="ico">🔐</span><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required autofocus placeholder="123456"></span>
				</label>
				<button class="btn btn-primary btn-login" type="submit">Activer avec l'application →</button>
			</form>
			<?php if ( ! $actif ) : ?>
			<div class="auth-divider"></div>
			<h3 class="auth-h" style="font-size:17px">✉️ Option 2 — Code par e-mail</h3>
			<?php if ( '' === $email ) : ?>
				<p class="auth-desc">Aucune adresse e-mail n'est enregistrée sur votre compte. Demandez à votre administrateur de l'ajouter (Utilisateurs), ou utilisez l'application.</p>
			<?php elseif ( ! $email_envoye ) : ?>
				<p class="auth-desc">À chaque connexion, un code à 6 chiffres sera envoyé à <strong><?= e( $email ) ?></strong>. Plus simple, mais moins sûr que l'application : protégez bien votre boîte mail.</p>
				<form method="post" action="<?= e( url( 'securite/deux-facteurs/email/envoyer' ) ) ?>"><?= FKC_Csrf::field() ?><button class="btn btn-ghost" type="submit">Recevoir un code à <?= e( $email ) ?></button></form>
			<?php else : ?>
				<form method="post" action="<?= e( url( 'securite/deux-facteurs/email/activer' ) ) ?>" autocomplete="off">
					<?= FKC_Csrf::field() ?>
					<label class="fld"><span>Code reçu à <?= e( $email ) ?></span>
						<span class="auth-input"><span class="ico">✉️</span><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required placeholder="123456"></span>
					</label>
					<button class="btn btn-primary btn-login" type="submit">Activer par e-mail →</button>
				</form>
				<form method="post" action="<?= e( url( 'securite/deux-facteurs/email/envoyer' ) ) ?>" style="margin-top:8px"><?= FKC_Csrf::field() ?><button class="btn btn-ghost" type="submit">Renvoyer un code</button></form>
			<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>

		<div class="auth-divider"></div>
		<form method="post" action="<?= e( url( 'logout' ) ) ?>"><?= FKC_Csrf::field() ?><button class="btn btn-ghost" type="submit">Se déconnecter</button></form>
		<p class="auth-foot">FinaKop ERP Core · KOPHI'S GROUP SAS</p>
	</section>
</div>
</body>
</html>
