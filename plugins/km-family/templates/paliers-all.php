<?php
/**
 * Page "Rejoindre la KM Family" — liste tous les artistes
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="kmfamily-paliers-all">

    <div class="kmfamily-paliers-all__hero">
        <div class="kmfamily-paliers-all__hero-bg"></div>
        <div class="kmfamily-paliers-all__hero-content">
            <span class="kmfamily-paliers-all__eyebrow">KM FAMILY — URBAN GOSPEL</span>
            <h1>Devenez un&nbsp;<span>pilier</span>&nbsp;de l'Urban Gospel Francophone</h1>
            <div class="kmfamily-paliers-all__hero-message">
                <?php echo KMFamily_Notifications::get_emotional_message_rich(); ?>
            </div>
        </div>
    </div>

    <div class="kmfamily-paliers-all__standard">
        <div class="kmfamily-paliers-all__standard-header">
            <span class="kmfamily-paliers-all__eyebrow"><?php echo esc_html( sprintf( _n( 'LE %d PALIER DU LABEL', 'LES %d PALIERS DU LABEL', count( KMFamily_Paliers::get_all() ), 'km-family' ), count( KMFamily_Paliers::get_all() ) ) ); ?></span>
            <h2>Un système standardisé, un engagement fort</h2>
            <p>Tous les artistes du label KOPHI'S MUSIC proposent les mêmes paliers, pour une expérience unifiée.</p>
        </div>

        <?php if ( class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::is_enabled() ) : ?>
        <div class="kmfamily-period-selector kmfamily-period-selector--showcase" role="tablist" aria-label="<?php esc_attr_e( 'Choisir une périodicité', 'km-family' ); ?>">
            <span class="kmfamily-period-selector__label"><?php esc_html_e( 'Choisissez votre engagement :', 'km-family' ); ?></span>
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
            <p class="kmfamily-period-selector__hint">💡 <?php esc_html_e( 'Plus vous vous engagez longtemps, plus vous économisez. Les prix s\'adaptent automatiquement.', 'km-family' ); ?></p>
        </div>
        <?php endif; ?>

        <div class="kmfamily-paliers__grid kmfamily-paliers__grid--count-6 kmfamily-paliers__grid--showcase">
            <?php foreach ( KMFamily_Paliers::get_all() as $slug => $p ) :
                $is_featured = ( $slug === 'or' );
                $is_libre = ! empty( $p['is_free'] );

                // Pré-calcul des prix par période pour recalcul JS live
                $prices_by_period = array();
                if ( ! $is_libre && class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::is_enabled() ) {
                    foreach ( KMFamily_Periodicity::get_all() as $pslug => $period ) {
                        $prices_by_period[ $pslug ] = KMFamily_Periodicity::calculate_price( $p['prix'], $pslug );
                    }
                }
            ?>
                <div class="kmfamily-palier-card kmfamily-palier-card--<?php echo esc_attr( $slug ); ?> kmfamily-palier-card--showcase <?php echo $is_featured ? 'is-featured' : ''; ?>"
                     <?php if ( ! empty( $prices_by_period ) ) : ?>data-prices='<?php echo esc_attr( wp_json_encode( $prices_by_period ) ); ?>'<?php endif; ?>>
                    <?php if ( $is_featured ) : ?>
                        <div class="kmfamily-palier-card__ribbon">⭐ POPULAIRE</div>
                    <?php endif; ?>

                    <div class="kmfamily-palier-card__number"><?php echo esc_html( $p['numero'] ); ?></div>
                    <div class="kmfamily-palier-card__icon" style="background: <?php echo esc_attr( $p['gradient'] ); ?>;"><?php echo esc_html( $p['icon'] ); ?></div>

                    <h3 class="kmfamily-palier-card__name" style="color: <?php echo esc_attr( $p['color'] ); ?>;">
                        <?php echo esc_html( $p['nom'] ); ?>
                    </h3>
                    <div class="kmfamily-palier-card__statut"><?php echo esc_html( $p['statut'] ); ?></div>

                    <div class="kmfamily-palier-card__price">
                        <?php if ( $is_libre ) : ?>
                            <span class="kmfamily-palier-card__amount kmfamily-palier-card__amount--libre">✨ LIBRE ✨</span>
                            <span class="kmfamily-palier-card__period">Montant au choix</span>
                        <?php else : ?>
                            <span class="kmfamily-palier-card__amount" data-price-mensuel="<?php echo esc_attr( $p['prix'] ); ?>"><?php echo esc_html( number_format( $p['prix'], 0, ',', ' ' ) ); ?></span>
                            <span class="kmfamily-palier-card__currency">FCFA</span>
                            <span class="kmfamily-palier-card__period">/ mois</span>

                            <?php if ( class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::is_enabled() ) : ?>
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

                    <p class="kmfamily-palier-card__description"><?php echo esc_html( $p['description'] ); ?></p>

                    <button type="button" class="kmfamily-palier-card__cta" data-palier="<?php echo esc_attr( $slug ); ?>">
                        Choisir <?php echo esc_html( $p['nom'] ); ?>
                        <span class="kmfamily-palier-card__cta-arrow">→</span>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="kmfamily-paliers-all__artistes" id="artistes">
        <div class="kmfamily-paliers-all__artistes-header">
            <span class="kmfamily-paliers-all__eyebrow">CHOISISSEZ UN ARTISTE</span>
            <h2>Les artistes à soutenir</h2>
            <p class="kmfamily-paliers-all__artistes-subtitle">Cliquez sur un artiste pour choisir votre palier et finaliser votre contribution.</p>
        </div>

        <div class="kmfamily-artistes-grid">
            <?php
            $paliers_page_id = get_option( 'kmfamily_page_paliers' );
            $paliers_page_url = $paliers_page_id ? get_permalink( $paliers_page_id ) : '';
            foreach ( $artistes as $artiste ) :
                $active = get_field( 'kmfamily_active', $artiste->ID );
                if ( $active === null ) $active = true;
                if ( ! $active ) continue;
                $nb = KMFamily_Subscriptions::count_members_for_artist( $artiste->ID );
                // Page de soutien de l'artiste (paliers, via ?artiste_id=X)
                $soutenir_url = KMFamily_Direct_Link::get_support_url( $artiste->ID );
            ?>
                <article class="kmfamily-artiste-card">
                    <?php if ( has_post_thumbnail( $artiste->ID ) ) : ?>
                        <a href="<?php echo esc_url( $soutenir_url ); ?>" class="kmfamily-artiste-card__img">
                            <?php echo get_the_post_thumbnail( $artiste->ID, 'medium_large' ); ?>
                        </a>
                    <?php endif; ?>
                    <div class="kmfamily-artiste-card__body">
                        <h3><a href="<?php echo esc_url( $soutenir_url ); ?>"><?php echo esc_html( $artiste->post_title ); ?></a></h3>
                        <?php if ( $nb > 0 ) : ?>
                            <p class="kmfamily-artiste-card__stats">
                                <span class="kmfamily-artiste-card__dot"></span>
                                <?php echo esc_html( $nb ); ?> <?php echo $nb > 1 ? 'membres' : 'membre'; ?>
                            </p>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $soutenir_url ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full">
                            Soutenir →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</div>
