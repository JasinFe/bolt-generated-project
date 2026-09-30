<?php
/**
 * Affichage des paliers d'un artiste — v2 avec 5 paliers standardisés
 *
 * Variables : $artiste_id, $paliers, $message_accroche, $avantages_custom, $artiste_name
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$current_sub  = $is_logged_in ? KMFamily_Subscriptions::get_user_subscription( get_current_user_id(), $artiste_id ) : null;
// Page de soutien de CET artiste : c'est là que la personne doit revenir après s'être
// connectée / avoir créé son compte (paramètre "next" de la page de connexion). Sans lui,
// elle finissait dans son espace membre au lieu de reprendre son soutien à l'artiste.
$support_url  = KMFamily_Direct_Link::get_support_url( $artiste_id );
$login_url    = KMFamily_Direct_Link::get_login_url( $support_url );
$register_url = KMFamily_Direct_Link::get_login_url( $support_url, 'register' );

// Effectif soutenant cet artiste
$nb_members   = KMFamily_Subscriptions::count_members_for_artist( $artiste_id );

/**
 * PHOTO DE COUVERTURE (v3.3.1)
 * ----------------------------
 * La photo de profil d'un artiste est son image mise en avant (image à la une du CPT
 * 'nos-artistes') — c'est déjà elle qui sert partout ailleurs : espace membre, liste des
 * artistes, bandeau d'accueil, encart « Vous allez soutenir… » de la page de connexion.
 * On la réutilise donc telle quelle, sans nouveau champ à renseigner : dès qu'un artiste a
 * une image à la une, sa page de soutien a sa couverture.
 *
 * La même image sert deux fois : en fond large (flouté et assombri, pour rester lisible
 * derrière du texte blanc quelle que soit la photo) et en portrait rond au premier plan.
 *
 * Repli : sans image à la une, on retombe exactement sur l'en-tête d'origine — aucune
 * page ne se retrouve avec un bloc vide ou un cadre gris.
 */
$kmf_cover_id  = get_post_thumbnail_id( $artiste_id );
$kmf_cover_url = $kmf_cover_id ? wp_get_attachment_image_url( $kmf_cover_id, 'full' ) : '';
$kmf_has_cover = (bool) $kmf_cover_url;
?>

<section class="kmfamily-paliers<?php echo $kmf_has_cover ? ' kmfamily-paliers--has-cover' : ''; ?>" id="paliers">

    <?php if ( $kmf_has_cover ) : ?>
    <header class="kmfamily-artiste-cover">
        <div class="kmfamily-artiste-cover__bg" style="background-image:url('<?php echo esc_url( $kmf_cover_url ); ?>');" aria-hidden="true"></div>
        <div class="kmfamily-artiste-cover__veil" aria-hidden="true"></div>

        <div class="kmfamily-artiste-cover__content">
            <div class="kmfamily-artiste-cover__portrait">
                <?php
                echo get_the_post_thumbnail(
                    $artiste_id,
                    'medium',
                    array(
                        'class'    => 'kmfamily-artiste-cover__portrait-img',
                        'alt'      => esc_attr( $artiste_name ),
                        'loading'  => 'eager',
                        'decoding' => 'async',
                    )
                );
                ?>
            </div>

            <span class="kmfamily-paliers__eyebrow kmfamily-artiste-cover__eyebrow">KM FAMILY</span>

            <h2 class="kmfamily-paliers__title kmfamily-artiste-cover__title">
                Devenez un pilier de&nbsp;<span><?php echo esc_html( $artiste_name ); ?></span>
            </h2>

            <?php if ( $nb_members > 0 ) : ?>
                <div class="kmfamily-paliers__social-proof">
                    <span class="kmfamily-paliers__social-proof-dot"></span>
                    <?php echo esc_html( $nb_members ); ?> <?php echo $nb_members > 1 ? 'membres soutiennent' : 'membre soutient'; ?> déjà cet artiste
                </div>
            <?php endif; ?>
        </div>
    </header>
    <?php endif; ?>

    <div class="kmfamily-paliers__header">
        <?php if ( ! $kmf_has_cover ) : ?>
            <span class="kmfamily-paliers__eyebrow">KM FAMILY</span>
            <h2 class="kmfamily-paliers__title">
                Devenez un pilier de&nbsp;<span><?php echo esc_html( $artiste_name ); ?></span>
            </h2>

            <?php if ( $nb_members > 0 ) : ?>
                <div class="kmfamily-paliers__social-proof">
                    <span class="kmfamily-paliers__social-proof-dot"></span>
                    <?php echo esc_html( $nb_members ); ?> <?php echo $nb_members > 1 ? 'membres soutiennent' : 'membre soutient'; ?> déjà cet artiste
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( $message_accroche ) : ?>
            <div class="kmfamily-paliers__message">
                <?php echo wp_kses_post( $message_accroche ); ?>
            </div>
        <?php else : ?>
            <p class="kmfamily-paliers__emotional">
                <?php echo esc_html( KMFamily_Notifications::get_emotional_message() ); ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ( ! $is_logged_in ) : ?>
    <div class="kmfamily-paliers__auth-hint">
        <p>
            <?php
            printf(
                /* translators: %s: artist name */
                esc_html__( 'Connectez-vous ou créez votre compte gratuit pour soutenir %s : vous reviendrez ici automatiquement.', 'km-family' ),
                '<strong>' . esc_html( $artiste_name ) . '</strong>'
            );
            ?>
        </p>
        <div class="kmfamily-paliers__auth-hint-actions">
            <a href="<?php echo esc_url( $login_url ); ?>" class="kmfamily-btn kmfamily-btn-ghost kmfamily-btn--sm"><?php esc_html_e( 'Se connecter', 'km-family' ); ?></a>
            <a href="<?php echo esc_url( $register_url ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--sm"><?php esc_html_e( 'Créer un compte', 'km-family' ); ?></a>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::is_enabled() ) : ?>
    <div class="kmfamily-period-selector" role="tablist" aria-label="<?php esc_attr_e( 'Choisir une périodicité', 'km-family' ); ?>">
        <span class="kmfamily-period-selector__label"><?php esc_html_e( 'Choisissez la durée de votre engagement', 'km-family' ); ?></span>
        <div class="kmfamily-period-selector__tabs">
            <?php foreach ( KMFamily_Periodicity::get_all() as $pslug => $period ) : ?>
                <button type="button"
                        class="kmfamily-period-tab <?php echo $pslug === 'monthly' ? 'is-active' : ''; ?>"
                        data-period="<?php echo esc_attr( $pslug ); ?>"
                        role="tab"
                        aria-selected="<?php echo $pslug === 'monthly' ? 'true' : 'false'; ?>">
                    <span class="kmfamily-period-tab__icon"><?php echo esc_html( $period['icon'] ); ?></span>
                    <span class="kmfamily-period-tab__label"><?php echo esc_html( $period['label'] ); ?></span>
                    <?php if ( $period['discount'] > 0 ) : ?>
                        <span class="kmfamily-period-tab__badge">-<?php echo esc_html( $period['discount'] ); ?>%</span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="kmfamily-period-selector__hint">💡 <?php esc_html_e( 'Plus vous vous engagez longtemps, plus vous économisez.', 'km-family' ); ?></p>
    </div>
    <?php endif; ?>

    <div class="kmfamily-paliers__grid kmfamily-paliers__grid--count-<?php echo count( $paliers ); ?>">
        <?php
        $i = 0;
        foreach ( $paliers as $slug => $p ) :
            $i++;
            $is_current  = $current_sub && $current_sub['palier'] === $slug && ! empty( $current_sub['expire'] ) && $current_sub['expire'] > time();
            $is_featured = ( $slug === 'or' ); // Le palier "Or" est mis en avant
            $is_libre = ! empty( $p['is_free'] );

            // Pré-calcul des prix par périodicité (pour data-attributes JS)
            $prices_by_period = array();
            if ( ! $is_libre && KMFamily_Periodicity::is_enabled() ) {
                foreach ( KMFamily_Periodicity::get_all() as $pslug => $period ) {
                    $prices_by_period[ $pslug ] = KMFamily_Periodicity::calculate_price( $p['prix'], $pslug );
                }
            }
        ?>
            <div class="kmfamily-palier-card kmfamily-palier-card--<?php echo esc_attr( $slug ); ?> <?php echo $is_current ? 'is-current' : ''; ?> <?php echo $is_featured ? 'is-featured' : ''; ?>"
                 <?php if ( ! empty( $prices_by_period ) ) : ?>data-prices='<?php echo esc_attr( wp_json_encode( $prices_by_period ) ); ?>'<?php endif; ?>>

                <?php if ( $is_featured ) : ?>
                    <div class="kmfamily-palier-card__ribbon">LE PLUS POPULAIRE</div>
                <?php endif; ?>

                <?php if ( $is_current ) : ?>
                    <div class="kmfamily-palier-card__current-badge">✓ ACTIF</div>
                <?php endif; ?>

                <div class="kmfamily-palier-card__number"><?php echo esc_html( $p['numero'] ); ?></div>

                <div class="kmfamily-palier-card__icon" style="background: <?php echo esc_attr( $p['gradient'] ); ?>;">
                    <?php echo esc_html( $p['icon'] ); ?>
                </div>

                <h3 class="kmfamily-palier-card__name" style="color: <?php echo esc_attr( $p['color'] ); ?>;">
                    <?php echo esc_html( $p['nom'] ); ?>
                </h3>

                <div class="kmfamily-palier-card__statut">
                    <?php echo esc_html( $p['statut'] ); ?>
                </div>

                <?php $is_libre = ! empty( $p['is_free'] ); ?>

                <div class="kmfamily-palier-card__price">
                    <?php if ( $is_libre ) : ?>
                        <span class="kmfamily-palier-card__amount kmfamily-palier-card__amount--libre">✨ LIBRE ✨</span>
                        <span class="kmfamily-palier-card__period">Montant au choix<br><small>(min. <?php echo esc_html( number_format( $p['prix_min'] ?? 500, 0, ',', ' ' ) ); ?> FCFA)</small></span>
                    <?php else : ?>
                        <span class="kmfamily-palier-card__amount" data-price-mensuel="<?php echo esc_attr( $p['prix'] ); ?>"><?php echo esc_html( number_format( $p['prix'], 0, ',', ' ' ) ); ?></span>
                        <span class="kmfamily-palier-card__currency">FCFA</span>
                        <span class="kmfamily-palier-card__period">/ mois</span>

                        <?php if ( KMFamily_Periodicity::is_enabled() ) : ?>
                            <div class="kmfamily-palier-card__period-info" style="display:none;">
                                <div class="kmfamily-palier-card__total-row">
                                    <span class="kmfamily-palier-card__total-label">Total :</span>
                                    <span class="kmfamily-palier-card__total-amount">0</span>
                                    <span class="kmfamily-palier-card__total-currency">FCFA</span>
                                </div>
                                <div class="kmfamily-palier-card__savings" style="display:none;">
                                    <span class="kmfamily-palier-card__savings-icon">💰</span>
                                    Vous économisez <strong class="kmfamily-palier-card__savings-amount">0</strong> FCFA
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="kmfamily-palier-card__description">
                    <?php echo esc_html( $p['description'] ); ?>
                </div>

                <ul class="kmfamily-palier-card__benefits">
                    <?php foreach ( $p['benefits'] as $benefit ) : ?>
                        <li><span class="kmfamily-palier-card__check" style="color: <?php echo esc_attr( $p['color'] ); ?>;">✓</span> <?php echo esc_html( $benefit ); ?></li>
                    <?php endforeach; ?>
                </ul>

                <div class="kmfamily-palier-card__action">
                    <?php if ( $is_current ) : ?>
                        <button class="kmfamily-btn kmfamily-btn-disabled kmfamily-btn--full">
                            ✓ Votre palier actuel
                        </button>
                        <p class="kmfamily-palier-card__expiry">
                            <?php
                            printf(
                                esc_html__( 'Expire le %s', 'km-family' ),
                                esc_html( date_i18n( 'd/m/Y', $current_sub['expire'] ) )
                            );
                            ?>
                        </p>
                    <?php elseif ( $is_logged_in && $is_libre ) : ?>
                        <div class="kmfamily-libre-form">
                            <label for="libre-amount-<?php echo esc_attr( $artiste_id ); ?>">Votre don</label>
                            <div class="kmfamily-libre-form__input">
                                <input type="number"
                                       id="libre-amount-<?php echo esc_attr( $artiste_id ); ?>"
                                       class="kmfamily-libre-amount"
                                       min="<?php echo esc_attr( $p['prix_min'] ?? 500 ); ?>"
                                       step="100"
                                       placeholder="<?php echo esc_attr( $p['prix_min'] ?? 500 ); ?>"
                                       value="1000">
                                <span>FCFA</span>
                            </div>
                            <div class="kmfamily-libre-form__suggestions">
                                <button type="button" class="kmfamily-libre-suggest" data-amount="500">500</button>
                                <button type="button" class="kmfamily-libre-suggest" data-amount="1000">1 000</button>
                                <button type="button" class="kmfamily-libre-suggest" data-amount="3000">3 000</button>
                                <button type="button" class="kmfamily-libre-suggest" data-amount="10000">10 000</button>
                            </div>
                            <button type="button"
                                    class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--subscribe kmfamily-btn--libre"
                                    data-artiste-id="<?php echo esc_attr( $artiste_id ); ?>"
                                    data-palier="<?php echo esc_attr( $slug ); ?>"
                                    data-montant-min="<?php echo esc_attr( $p['prix_min'] ?? 500 ); ?>"
                                    data-target-input="libre-amount-<?php echo esc_attr( $artiste_id ); ?>">
                                <span class="kmfamily-btn__label">💝 Faire un don</span>
                                <span class="kmfamily-btn__spinner"></span>
                            </button>
                        </div>
                    <?php elseif ( $is_logged_in ) : ?>
                        <button type="button"
                                class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--subscribe"
                                data-artiste-id="<?php echo esc_attr( $artiste_id ); ?>"
                                data-palier="<?php echo esc_attr( $slug ); ?>"
                                data-montant="<?php echo esc_attr( $p['prix'] ); ?>"
                                data-periodicity="monthly">
                            <span class="kmfamily-btn__label">Devenir <?php echo esc_html( $p['nom'] ); ?></span>
                            <span class="kmfamily-btn__spinner"></span>
                        </button>
                    <?php else : ?>
                        <?php
                        // Retour sur la page de soutien avec le palier choisi mis en avant.
                        $join_url = KMFamily_Direct_Link::get_login_url(
                            KMFamily_Direct_Link::get_support_url( $artiste_id, array( 'palier' => $slug ) )
                        );
                        ?>
                        <a href="<?php echo esc_url( $join_url ); ?>"
                           class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--join"
                           data-palier="<?php echo esc_attr( $slug ); ?>">
                            Rejoindre
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ( $avantages_custom ) : ?>
        <div class="kmfamily-paliers__custom">
            <h3>Avantages spécifiques de <?php echo esc_html( $artiste_name ); ?></h3>
            <?php echo wp_kses_post( $avantages_custom ); ?>
        </div>
    <?php endif; ?>

    <div class="kmfamily-paliers__footer">
        <div class="kmfamily-paliers__payment-methods">
            <span>🇨🇮 Paiement Mobile Money sécurisé</span>
            <div class="kmfamily-paliers__payment-logos">
                <span>Orange Money</span>
                <span>MTN MoMo</span>
                <span>Moov</span>
                <span>Wave</span>
            </div>
        </div>
        <p><small>Renouvellement automatique selon la périodicité choisie. Annulable à tout moment depuis votre espace membre.</small></p>
    </div>
</section>
