<?php
/**
 * Page de profil membre KM Family
 *
 * Variables : $user_id, $user
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$avatar_url = KMFamily_Profile::get_avatar_url( $user_id );
$phone      = get_user_meta( $user_id, 'kmfamily_phone', true );
$city       = get_user_meta( $user_id, 'kmfamily_city',  true );
$badge_slug = KMFamily_Badges::get_user_badge( $user_id );
$badge_data = $badge_slug ? KMFamily_Paliers::get( $badge_slug ) : null;
?>

<div class="kmfamily-page-wrap kmfamily-profile-page">

    <header class="kmfamily-profile__hero">
        <div class="kmfamily-profile__hero-bg"<?php if ( $badge_data ) echo ' style="background: linear-gradient(135deg, #0a0a0a 0%, ' . esc_attr( $badge_data['color'] ) . '22 100%);"'; ?>></div>

        <div class="kmfamily-profile__hero-content">
            <a href="<?php echo esc_url( get_permalink( get_option( 'kmfamily_page_dashboard' ) ) ); ?>" class="kmfamily-profile__back">
                ← Retour à mon espace
            </a>
            <h1>Modifier mon profil</h1>
            <p>Gérez vos informations personnelles et votre photo de profil</p>
        </div>
    </header>

    <div class="kmfamily-profile__grid">

        <!-- ============ AVATAR ============ -->
        <section class="kmfamily-profile__card kmfamily-profile__card--avatar">
            <h2 class="kmfamily-profile__card-title">📸 Photo de profil</h2>

            <div class="kmfamily-avatar-uploader">
                <div class="kmfamily-avatar-uploader__preview">
                    <?php if ( $avatar_url ) : ?>
                        <img src="<?php echo esc_url( $avatar_url ); ?>" alt="Avatar" id="kmfamily-avatar-preview">
                    <?php else : ?>
                        <div class="kmfamily-avatar-uploader__placeholder" id="kmfamily-avatar-placeholder">
                            <span class="kmfamily-avatar-uploader__placeholder-icon">👤</span>
                            <span class="kmfamily-avatar-uploader__placeholder-text">Aucune photo</span>
                        </div>
                        <img src="" alt="Avatar" id="kmfamily-avatar-preview" style="display:none;">
                    <?php endif; ?>

                    <?php if ( $badge_data ) : ?>
                        <span class="kmfamily-avatar-uploader__badge" style="background: <?php echo esc_attr( $badge_data['gradient'] ); ?>;">
                            <?php echo esc_html( $badge_data['icon'] ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="kmfamily-avatar-uploader__actions">
                    <label for="kmfamily-avatar-input" class="kmfamily-btn kmfamily-btn-primary">
                        📷 Changer la photo
                    </label>
                    <input type="file" id="kmfamily-avatar-input" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">

                    <?php if ( $avatar_url ) : ?>
                        <button type="button" class="kmfamily-btn kmfamily-btn-ghost" id="kmfamily-avatar-delete">
                            🗑 Supprimer
                        </button>
                    <?php endif; ?>

                    <div class="kmfamily-form-message" id="avatar-message"></div>

                    <p class="kmfamily-avatar-uploader__hint">
                        JPG, PNG, WebP ou GIF • Max 2 Mo<br>
                        Une photo carrée donne le meilleur résultat
                    </p>
                </div>
            </div>
        </section>

        <!-- ============ INFORMATIONS PERSONNELLES ============ -->
        <section class="kmfamily-profile__card kmfamily-profile__card--info">
            <h2 class="kmfamily-profile__card-title">👤 Informations personnelles</h2>

            <form id="kmfamily-profile-form" class="kmfamily-profile-form">
                <?php wp_nonce_field( 'kmfamily_auth_nonce', 'kmfamily_auth_nonce' ); ?>

                <div class="kmfamily-form-grid">
                    <div class="kmfamily-form-field">
                        <label for="display_name">Nom d'affichage *</label>
                        <div class="kmfamily-form-field__input">
                            <span class="kmfamily-form-field__icon">👤</span>
                            <input type="text" name="display_name" id="display_name" required value="<?php echo esc_attr( $user->display_name ); ?>">
                        </div>
                    </div>

                    <div class="kmfamily-form-field">
                        <label for="email">Email</label>
                        <div class="kmfamily-form-field__input">
                            <span class="kmfamily-form-field__icon">✉</span>
                            <input type="email" name="email" id="email" value="<?php echo esc_attr( $user->user_email ); ?>">
                        </div>
                    </div>

                    <div class="kmfamily-form-field">
                        <label for="first_name">Prénom</label>
                        <div class="kmfamily-form-field__input">
                            <input type="text" name="first_name" id="first_name" value="<?php echo esc_attr( $user->first_name ); ?>">
                        </div>
                    </div>

                    <div class="kmfamily-form-field">
                        <label for="last_name">Nom</label>
                        <div class="kmfamily-form-field__input">
                            <input type="text" name="last_name" id="last_name" value="<?php echo esc_attr( $user->last_name ); ?>">
                        </div>
                    </div>

                    <div class="kmfamily-form-field">
                        <label for="phone">Téléphone</label>
                        <div class="kmfamily-form-field__input">
                            <span class="kmfamily-form-field__icon">📱</span>
                            <input type="tel" name="phone" id="phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="+225 01 02 03 04 05">
                        </div>
                    </div>

                    <div class="kmfamily-form-field">
                        <label for="city">Ville</label>
                        <div class="kmfamily-form-field__input">
                            <span class="kmfamily-form-field__icon">📍</span>
                            <input type="text" name="city" id="city" value="<?php echo esc_attr( $city ); ?>" placeholder="Abidjan">
                        </div>
                    </div>
                </div>

                <div class="kmfamily-form-field kmfamily-form-field--full">
                    <label for="description">À propos de moi</label>
                    <textarea name="description" id="description" rows="3" placeholder="Parlez-nous de votre amour pour l'Urban Gospel..."><?php echo esc_textarea( $user->description ); ?></textarea>
                </div>

                <div class="kmfamily-form-message" id="profile-message"></div>

                <button type="submit" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--lg">
                    <span class="kmfamily-btn__label">💾 Enregistrer les modifications</span>
                    <span class="kmfamily-btn__spinner"></span>
                </button>
            </form>
        </section>

        <!-- ============ FIDÉLITÉ ============ -->
        <?php if ( class_exists( 'KMFamily_Loyalty' ) ) :
            $points = KMFamily_Loyalty::get_balance( $user_id );
            $ledger = KMFamily_Loyalty::get_ledger( $user_id, 5 );
        ?>
        <section class="kmfamily-profile__card kmfamily-profile__card--loyalty">
            <h2 class="kmfamily-profile__card-title">⭐ Fidélité</h2>
            <p class="kmfamily-loyalty__balance"><strong><?php echo esc_html( number_format_i18n( $points ) ); ?></strong> points cumulés</p>
            <p class="kmfamily-loyalty__hint">Chaque billet et chaque renouvellement d'abonnement rapporte des points — ils s'additionnent automatiquement, rien à faire de ton côté.</p>
            <?php if ( ! empty( $ledger ) ) : ?>
            <ul class="kmfamily-loyalty__ledger">
                <?php foreach ( $ledger as $entry ) : ?>
                <li>
                    <span class="kmfamily-loyalty__ledger-points">+<?php echo (int) $entry['points']; ?></span>
                    <span class="kmfamily-loyalty__ledger-reason"><?php echo esc_html( $entry['reason'] === 'ticket_purchase' ? 'Billet' : 'Abonnement' ); ?></span>
                    <span class="kmfamily-loyalty__ledger-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $entry['created_at'] ) ) ); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ============ MOT DE PASSE ============ -->
        <section class="kmfamily-profile__card kmfamily-profile__card--password">
            <h2 class="kmfamily-profile__card-title">🔐 Mot de passe</h2>

            <form id="kmfamily-password-form" class="kmfamily-profile-form">
                <?php wp_nonce_field( 'kmfamily_auth_nonce', 'kmfamily_auth_nonce' ); ?>

                <div class="kmfamily-form-field">
                    <label for="current-password">Mot de passe actuel</label>
                    <div class="kmfamily-form-field__input">
                        <span class="kmfamily-form-field__icon">🔒</span>
                        <input type="password" name="current_password" id="current-password" required autocomplete="current-password">
                        <button type="button" class="kmfamily-form-field__toggle">👁</button>
                    </div>
                </div>

                <div class="kmfamily-form-field">
                    <label for="new-password">Nouveau mot de passe</label>
                    <div class="kmfamily-form-field__input">
                        <span class="kmfamily-form-field__icon">🔑</span>
                        <input type="password" name="new_password" id="new-password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="kmfamily-form-field__toggle">👁</button>
                    </div>
                    <div class="kmfamily-password-strength">
                        <span class="kmfamily-password-strength__bar"></span>
                        <span class="kmfamily-password-strength__label">Au moins 8 caractères</span>
                    </div>
                </div>

                <div class="kmfamily-form-message" id="password-message"></div>

                <button type="submit" class="kmfamily-btn kmfamily-btn-primary">
                    <span class="kmfamily-btn__label">Modifier le mot de passe</span>
                    <span class="kmfamily-btn__spinner"></span>
                </button>
            </form>
        </section>
    </div>
</div>
