<?php
/**
 * Page de connexion / inscription KM Family
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// CORRECTIF v3.4.1 — DOCUMENT HTML IMBRIQUÉ.
// Ce gabarit n'est inclus QUE par le shortcode [kmfamily_login] (donc au milieu du
// contenu de la page, alors que l'en-tête du thème est déjà envoyé). Il appelait
// pourtant get_header()/get_footer() : un second <html><head><body> complet était
// injecté dans la page — scripts et styles chargés deux fois, erreurs JavaScript,
// mise en page cassée sur mobile. On ne les appelle plus que si le gabarit est
// servi seul (ancienne surcharge de thème via template_include).
$kmfamily_login_standalone = empty( $kmfamily_login_embedded );
if ( $kmfamily_login_standalone ) get_header();

$reset_action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
$reset_key    = isset( $_GET['key'] )    ? sanitize_text_field( $_GET['key'] ) : '';
$reset_login  = isset( $_GET['login'] )  ? sanitize_text_field( $_GET['login'] ) : '';
$show_reset_form = ( $reset_action === 'rp' && $reset_key && $reset_login );

/**
 * Destination après connexion / inscription ("next").
 * 1. Paramètre ?next= (bouton "Rejoindre", lien direct de soutien...), validé côté serveur.
 * 2. À défaut, si la personne vient d'une fiche artiste ou d'une page de soutien (bouton
 *    "Se connecter" d'un thème / d'Elementor qui pointe ici sans paramètre), on la ramène
 *    sur le soutien de cet artiste plutôt que dans son espace membre.
 */
$kmfamily_next = KMFamily_Direct_Link::safe_next( isset( $_GET['next'] ) ? wp_unslash( $_GET['next'] ) : '', '' );

if ( ! $kmfamily_next ) {
    $kmfamily_ref    = wp_get_referer();
    $kmfamily_ref_id = $kmfamily_ref ? KMFamily_Direct_Link::artist_from_url( $kmfamily_ref ) : 0;
    if ( $kmfamily_ref_id && KMFamily_Direct_Link::is_supportable( $kmfamily_ref_id ) ) {
        $kmfamily_next = KMFamily_Direct_Link::get_support_url( $kmfamily_ref_id );
    }
}

// Artiste visé par le parcours en cours (pour l'encart "Vous allez soutenir…").
$kmfamily_support_id = $kmfamily_next ? KMFamily_Direct_Link::artist_from_url( $kmfamily_next ) : 0;
if ( $kmfamily_support_id && ! KMFamily_Direct_Link::is_supportable( $kmfamily_support_id ) ) {
    $kmfamily_support_id = 0;
}
?>

<div class="kmfamily-auth-page">
    <div class="kmfamily-auth-bg">
        <div class="kmfamily-auth-bg__orb kmfamily-auth-bg__orb--1"></div>
        <div class="kmfamily-auth-bg__orb kmfamily-auth-bg__orb--2"></div>
        <div class="kmfamily-auth-bg__orb kmfamily-auth-bg__orb--3"></div>
    </div>

    <div class="kmfamily-auth-container">
        <aside class="kmfamily-auth-brand">
            <div class="kmfamily-auth-brand__content">
                <div class="kmfamily-auth-brand__logo">
                    <span class="kmfamily-auth-brand__logo-main">KM FAMILY</span>
                    <span class="kmfamily-auth-brand__logo-sub">KOPHI'S MUSIC — Urban Gospel</span>
                </div>

                <h1 class="kmfamily-auth-brand__title">
                    Devenez un <span>pilier</span>.<br>
                    Entrez dans <span>l'intimité</span>.
                </h1>

                <p class="kmfamily-auth-brand__tagline">
                    <?php echo esc_html( KMFamily_Notifications::get_emotional_message() ); ?>
                </p>

                <ul class="kmfamily-auth-brand__perks">
                    <li>
                        <span class="kmfamily-auth-brand__perk-icon">🎬</span>
                        <div>
                            <strong>Coulisses & Making-of</strong>
                            <span>L'envers du décor, les clips de l'intérieur</span>
                        </div>
                    </li>
                    <li>
                        <span class="kmfamily-auth-brand__perk-icon">🎵</span>
                        <div>
                            <strong>Nouveautés en avant-première</strong>
                            <span>Les titres 48h avant tout le monde</span>
                        </div>
                    </li>
                    <li>
                        <span class="kmfamily-auth-brand__perk-icon">👑</span>
                        <div>
                            <strong>Votre nom au générique</strong>
                            <span>À partir du palier Platine</span>
                        </div>
                    </li>
                </ul>
            </div>
        </aside>

        <main class="kmfamily-auth-main">
            <?php if ( $show_reset_form ) : ?>
                <div class="kmfamily-auth-form is-active">
                    <h2 class="kmfamily-auth-form__title">Nouveau mot de passe</h2>
                    <p class="kmfamily-auth-form__subtitle">Choisissez un mot de passe sécurisé.</p>

                    <form class="kmfamily-auth-form__fields" id="kmfamily-reset-form" novalidate>
                        <input type="hidden" name="key" value="<?php echo esc_attr( $reset_key ); ?>">
                        <input type="hidden" name="login" value="<?php echo esc_attr( $reset_login ); ?>">

                        <div class="kmfamily-form-field">
                            <label for="pass1">Nouveau mot de passe</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">🔒</span>
                                <input type="password" name="pass1" id="pass1" required minlength="8" autocomplete="new-password">
                                <button type="button" class="kmfamily-form-field__toggle" aria-label="Afficher">👁</button>
                            </div>
                        </div>

                        <div class="kmfamily-form-field">
                            <label for="pass2">Confirmer</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">🔒</span>
                                <input type="password" name="pass2" id="pass2" required minlength="8" autocomplete="new-password">
                            </div>
                        </div>

                        <div class="kmfamily-form-message" id="reset-message"></div>

                        <button type="submit" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--lg">Définir mon mot de passe</button>
                    </form>
                </div>
            <?php else : ?>
                <?php if ( $kmfamily_support_id ) : ?>
                    <div class="kmfamily-auth-support">
                        <?php if ( has_post_thumbnail( $kmfamily_support_id ) ) : ?>
                            <span class="kmfamily-auth-support__img"><?php
                                /**
                                 * ROBUSTESSE v3.3.4 — la taille 'thumbnail' était servie sans attribut
                                 * width/height utile : tant que la feuille de styles n'était pas appliquée
                                 * (cache d'hébergeur servant une version périmée, minification ratée,
                                 * extension concurrente), l'image s'affichait à sa taille naturelle —
                                 * un pavé de 150 px qui poussait le texte en dessous et cassait
                                 * complètement l'encart, au lieu d'une pastille ronde de 56 px.
                                 * On demande désormais un carré recadré en 112 px (le double de la taille
                                 * d'affichage, pour rester net sur les écrans haute densité) et on fixe
                                 * width/height : même sans une ligne de CSS, l'encart reste présentable.
                                 */
                                echo get_the_post_thumbnail(
                                    $kmfamily_support_id,
                                    array( 112, 112 ),
                                    array(
                                        'width'    => 56,
                                        'height'   => 56,
                                        'alt'      => esc_attr( get_the_title( $kmfamily_support_id ) ),
                                        'loading'  => 'eager',
                                        'decoding' => 'async',
                                    )
                                );
                            ?></span>
                        <?php else : ?>
                            <span class="kmfamily-auth-support__img kmfamily-auth-support__img--icon">💛</span>
                        <?php endif; ?>
                        <span class="kmfamily-auth-support__text">
                            <small>Vous allez soutenir</small>
                            <strong><?php echo esc_html( get_the_title( $kmfamily_support_id ) ); ?></strong>
                            <em>Connectez-vous ou créez votre compte pour continuer.</em>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="kmfamily-auth-tabs">
                    <button type="button" class="kmfamily-auth-tab is-active" data-tab="login">Connexion</button>
                    <button type="button" class="kmfamily-auth-tab" data-tab="register">Inscription</button>
                </div>

                <!-- LOGIN FORM -->
                <div class="kmfamily-auth-form is-active" data-form="login">
                    <h2 class="kmfamily-auth-form__title">Content de vous revoir 👋</h2>
                    <p class="kmfamily-auth-form__subtitle">Connectez-vous à votre espace membre.</p>

                    <form class="kmfamily-auth-form__fields" id="kmfamily-login-form" novalidate>
                        <?php wp_nonce_field( 'kmfamily_auth_nonce', 'kmfamily_auth_nonce' ); ?>
                        <input type="hidden" name="next" class="kmfamily-auth-next" value="<?php echo esc_attr( $kmfamily_next ); ?>">

                        <div class="kmfamily-form-field">
                            <label for="login-email">Email ou nom d'utilisateur</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">✉</span>
                                <input type="text" name="login" id="login-email" required autocomplete="username" placeholder="votre@email.com">
                            </div>
                        </div>

                        <div class="kmfamily-form-field">
                            <label for="login-password">Mot de passe</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">🔒</span>
                                <input type="password" name="password" id="login-password" required autocomplete="current-password" placeholder="••••••••">
                                <button type="button" class="kmfamily-form-field__toggle" aria-label="Afficher">👁</button>
                            </div>
                        </div>

                        <div class="kmfamily-form-row">
                            <label class="kmfamily-form-checkbox">
                                <input type="checkbox" name="remember" value="1" checked>
                                <span>Se souvenir de moi</span>
                            </label>
                            <button type="button" class="kmfamily-auth-link" data-switch="forgot">Mot de passe oublié ?</button>
                        </div>

                        <div class="kmfamily-form-message" id="login-message"></div>

                        <button type="submit" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--lg">
                            <span class="kmfamily-btn__label">Se connecter</span>
                            <span class="kmfamily-btn__spinner"></span>
                        </button>

                        <p class="kmfamily-auth-form__footer">
                            Pas encore membre ?
                            <button type="button" class="kmfamily-auth-link" data-switch="register">Créer un compte</button>
                        </p>
                    </form>
                </div>

                <!-- REGISTER FORM -->
                <div class="kmfamily-auth-form" data-form="register">
                    <h2 class="kmfamily-auth-form__title">Rejoignez la famille 🎵</h2>
                    <p class="kmfamily-auth-form__subtitle">Créez votre compte en 30 secondes.</p>

                    <form class="kmfamily-auth-form__fields" id="kmfamily-register-form" novalidate>
                        <?php wp_nonce_field( 'kmfamily_auth_nonce', 'kmfamily_auth_nonce' ); ?>
                        <input type="hidden" name="next" class="kmfamily-auth-next" value="<?php echo esc_attr( $kmfamily_next ); ?>">

                        <div class="kmfamily-form-field">
                            <label for="reg-name">Votre nom</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">👤</span>
                                <input type="text" name="full_name" id="reg-name" required placeholder="Prénom Nom" autocomplete="name">
                            </div>
                        </div>

                        <div class="kmfamily-form-field">
                            <label for="reg-email">Email</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">✉</span>
                                <input type="email" name="email" id="reg-email" required placeholder="votre@email.com" autocomplete="email">
                            </div>
                        </div>

                        <div class="kmfamily-form-field">
                            <label for="reg-password">Mot de passe</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">🔒</span>
                                <input type="password" name="password" id="reg-password" required minlength="8" placeholder="Minimum 8 caractères" autocomplete="new-password">
                                <button type="button" class="kmfamily-form-field__toggle" aria-label="Afficher">👁</button>
                            </div>
                            <div class="kmfamily-password-strength">
                                <span class="kmfamily-password-strength__bar"></span>
                                <span class="kmfamily-password-strength__label">Force du mot de passe</span>
                            </div>
                        </div>

                        <label class="kmfamily-form-checkbox">
                            <input type="checkbox" required>
                            <span>J'accepte de rejoindre la KM Family et de recevoir les actualités</span>
                        </label>

                        <div class="kmfamily-form-message" id="register-message"></div>

                        <button type="submit" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--lg">
                            <span class="kmfamily-btn__label">Créer mon compte</span>
                            <span class="kmfamily-btn__spinner"></span>
                        </button>

                        <p class="kmfamily-auth-form__footer">
                            Déjà membre ?
                            <button type="button" class="kmfamily-auth-link" data-switch="login">Se connecter</button>
                        </p>
                    </form>
                </div>

                <!-- FORGOT FORM -->
                <div class="kmfamily-auth-form" data-form="forgot">
                    <h2 class="kmfamily-auth-form__title">Mot de passe oublié 🔐</h2>
                    <p class="kmfamily-auth-form__subtitle">Entrez votre email, nous vous envoyons un lien.</p>

                    <form class="kmfamily-auth-form__fields" id="kmfamily-forgot-form" novalidate>
                        <?php wp_nonce_field( 'kmfamily_auth_nonce', 'kmfamily_auth_nonce' ); ?>

                        <div class="kmfamily-form-field">
                            <label for="forgot-email">Votre email</label>
                            <div class="kmfamily-form-field__input">
                                <span class="kmfamily-form-field__icon">✉</span>
                                <input type="email" name="email" id="forgot-email" required placeholder="votre@email.com" autocomplete="email">
                            </div>
                        </div>

                        <div class="kmfamily-form-message" id="forgot-message"></div>

                        <button type="submit" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--lg">
                            <span class="kmfamily-btn__label">Envoyer le lien</span>
                            <span class="kmfamily-btn__spinner"></span>
                        </button>

                        <p class="kmfamily-auth-form__footer">
                            <button type="button" class="kmfamily-auth-link" data-switch="login">← Retour à la connexion</button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
(function() {
    // Reprise de parcours (ex. "S'abonner" sans compte) ou lien "mot de passe oublié" /
    // "créer un compte" venant d'ailleurs sur le site (voir filter_login_url/register_url/
    // lostpassword_url dans class-kmfamily-auth.php) : on transmet le lien de retour aux
    // formulaires, et on ouvre l'onglet demandé.
    var params = new URLSearchParams(window.location.search);
    var next = params.get('next');
    if (next) {
        // Le serveur a déjà validé et pré-rempli "next" ; on ne remplace que les champs vides.
        document.querySelectorAll('.kmfamily-auth-next').forEach(function(el) { if (!el.value) el.value = next; });
    }

    var tab = params.get('tab');
    if (tab === 'register' || tab === 'forgot') {
        var tabBtn = tab === 'register'
            ? document.querySelector('.kmfamily-auth-tab[data-tab="register"]')
            : document.querySelector('.kmfamily-auth-link[data-switch="forgot"]');
        if (tabBtn) tabBtn.click();
    }
})();
</script>

<?php if ( $kmfamily_login_standalone ) get_footer(); ?>
