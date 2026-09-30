<?php
/**
 * Dashboard membre KM Family v2.1
 *
 * Variables : $user_id, $subs, $history, $user, $badge
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$has_active = false;
foreach ( $subs as $sub ) {
    if ( ! empty( $sub['expire'] ) && $sub['expire'] > time() ) {
        $has_active = true;
        break;
    }
}

$badge_data = $badge ? KMFamily_Paliers::get( $badge ) : null;
$paliers_page = get_option( 'kmfamily_page_paliers' );
$profile_page = get_option( 'kmfamily_page_profile' );

// Statistiques
$total_artistes_soutenus = 0;
$total_contribution = 0;
foreach ( $subs as $sub ) {
    if ( ! empty( $sub['expire'] ) && $sub['expire'] > time() ) {
        $total_artistes_soutenus++;
    }
}
foreach ( $history as $entry ) {
    $total_contribution += intval( $entry['montant'] ?? 0 );
}
?>

<div class="kmfamily-page-wrap kmfamily-dashboard">

    <!-- HERO -->
    <header class="kmfamily-dashboard__hero">
        <div class="kmfamily-dashboard__hero-bg"<?php if ( $badge_data ) echo ' style="background: linear-gradient(135deg, #0a0a0a 0%, ' . esc_attr( $badge_data['color'] ) . '22 100%);"'; ?>></div>

        <div class="kmfamily-dashboard__hero-content">
            <div class="kmfamily-dashboard__profile">
                <div class="kmfamily-dashboard__avatar">
                    <?php echo get_avatar( $user_id, 96 ); ?>
                    <?php if ( $badge_data ) : ?>
                        <span class="kmfamily-dashboard__avatar-badge" style="background: <?php echo esc_attr( $badge_data['gradient'] ); ?>;">
                            <?php echo esc_html( $badge_data['icon'] ); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="kmfamily-dashboard__profile-info">
                    <span class="kmfamily-dashboard__greeting">Bonjour,</span>
                    <h1 class="kmfamily-dashboard__username"><?php echo esc_html( $user->display_name ); ?></h1>

                    <?php if ( $badge_data ) : ?>
                        <div class="kmfamily-dashboard__user-badge" style="background: <?php echo esc_attr( $badge_data['gradient'] ); ?>; color: <?php echo in_array( $badge, array( 'argent', 'or', 'platine', 'diamant' ) ) ? '#0a0a0a' : '#fff'; ?>;">
                            <?php echo esc_html( $badge_data['icon'] ); ?> <?php echo esc_html( $badge_data['nom'] ); ?> — <?php echo esc_html( $badge_data['statut'] ); ?>
                        </div>
                    <?php else : ?>
                        <div class="kmfamily-dashboard__user-no-badge">👋 Pas encore membre actif</div>
                    <?php endif; ?>
                </div>

                <?php if ( $profile_page ) : ?>
                    <a href="<?php echo esc_url( get_permalink( $profile_page ) ); ?>" class="kmfamily-dashboard__edit-profile">
                        ⚙️ Modifier mon profil
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( $has_active ) : ?>
                <div class="kmfamily-dashboard__stats">
                    <div class="kmfamily-dashboard__stat">
                        <span class="kmfamily-dashboard__stat-value"><?php echo esc_html( $total_artistes_soutenus ); ?></span>
                        <span class="kmfamily-dashboard__stat-label">Artiste<?php echo $total_artistes_soutenus > 1 ? 's' : ''; ?> soutenu<?php echo $total_artistes_soutenus > 1 ? 's' : ''; ?></span>
                    </div>
                    <div class="kmfamily-dashboard__stat">
                        <span class="kmfamily-dashboard__stat-value"><?php echo esc_html( number_format( $total_contribution, 0, ',', ' ' ) ); ?></span>
                        <span class="kmfamily-dashboard__stat-label">FCFA contribués au total</span>
                    </div>
                    <div class="kmfamily-dashboard__stat">
                        <span class="kmfamily-dashboard__stat-value">💛</span>
                        <span class="kmfamily-dashboard__stat-label">Merci pour votre soutien !</span>
                    </div>
                </div>
            <?php else : ?>
                <p class="kmfamily-dashboard__hero-subtitle">
                    Vous n'avez pas encore d'abonnement actif.
                </p>
            <?php endif; ?>
        </div>
    </header>

    <!-- TABS -->
    <nav class="kmfamily-dashboard__tabs">
        <button type="button" class="kmfamily-dashboard__tab is-active" data-tab="subscriptions">🎵 Mes abonnements</button>
        <button type="button" class="kmfamily-dashboard__tab" data-tab="discover">✨ Découvrir</button>
        <button type="button" class="kmfamily-dashboard__tab" data-tab="content">🎬 Mes contenus</button>
        <button type="button" class="kmfamily-dashboard__tab" data-tab="history">📜 Historique</button>
    </nav>

    <!-- ============ TAB: Abonnements ============ -->
    <section class="kmfamily-dashboard__tab-content is-active" data-content="subscriptions">
        <div class="kmfamily-dashboard__section-header">
            <h2 class="kmfamily-dashboard__section-title">Mes abonnements actifs</h2>
            <?php if ( $paliers_page ) : ?>
                <a href="<?php echo esc_url( get_permalink( $paliers_page ) ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--sm">
                    + Soutenir un nouvel artiste
                </a>
            <?php endif; ?>
        </div>

        <?php if ( ! $has_active ) : ?>
            <div class="kmfamily-empty-state">
                <div class="kmfamily-empty-state__icon">🎵</div>
                <h3>Commencez votre aventure dans la KM Family</h3>
                <p><?php echo esc_html( KMFamily_Notifications::get_emotional_message() ); ?></p>
                <?php if ( $paliers_page ) : ?>
                    <a href="<?php echo esc_url( get_permalink( $paliers_page ) ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--lg">
                        Découvrir les artistes →
                    </a>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="kmfamily-dashboard__subs-grid">
                <?php foreach ( $subs as $artiste_id => $sub ) :
                    if ( empty( $sub['expire'] ) || $sub['expire'] < time() ) continue;
                    $artiste = get_post( $artiste_id );
                    if ( ! $artiste ) continue;
                    $sub_palier = KMFamily_Paliers::get( $sub['palier'] );
                    $days_left  = ceil( ( $sub['expire'] - time() ) / DAY_IN_SECONDS );
                    $artist_url = get_permalink( $artiste_id );

                    // Paliers actifs pour cet artiste
                    $paliers_actifs = get_field( 'paliers_actifs', $artiste_id );
                    if ( empty( $paliers_actifs ) || ! is_array( $paliers_actifs ) ) {
                        $paliers_actifs = KMFamily_Paliers::LEVELS;
                    }
                    $hierarchy = KMFamily_Paliers::get_hierarchy();
                    $current_level = $hierarchy[ $sub['palier'] ] ?? 0;
                ?>
                    <div class="kmfamily-sub-card kmfamily-sub-card--<?php echo esc_attr( $sub['palier'] ); ?>">
                        <div class="kmfamily-sub-card__header" style="background: <?php echo esc_attr( $sub_palier['gradient'] ); ?>;">
                            <?php if ( has_post_thumbnail( $artiste_id ) ) : ?>
                                <?php echo get_the_post_thumbnail( $artiste_id, 'thumbnail', array( 'class' => 'kmfamily-sub-card__avatar' ) ); ?>
                            <?php endif; ?>
                            <div class="kmfamily-sub-card__header-info">
                                <span class="kmfamily-sub-card__artist-name"><?php echo esc_html( $artiste->post_title ); ?></span>
                                <span class="kmfamily-sub-card__palier-name"><?php echo esc_html( $sub_palier['icon'] ); ?> <?php echo esc_html( $sub_palier['nom'] ); ?></span>
                            </div>
                        </div>

                        <div class="kmfamily-sub-card__body">
                            <p class="kmfamily-sub-card__statut"><?php echo esc_html( $sub_palier['statut'] ); ?></p>

                            <div class="kmfamily-sub-card__meta">
                                <div class="kmfamily-sub-card__meta-item">
                                    <span class="kmfamily-sub-card__meta-label">Expire le</span>
                                    <strong><?php echo esc_html( date_i18n( 'd/m/Y', $sub['expire'] ) ); ?></strong>
                                </div>
                                <div class="kmfamily-sub-card__meta-item">
                                    <span class="kmfamily-sub-card__meta-label">Reste</span>
                                    <strong><?php echo esc_html( $days_left ); ?> j</strong>
                                </div>
                                <div class="kmfamily-sub-card__meta-item">
                                    <span class="kmfamily-sub-card__meta-label">Contribution</span>
                                    <strong><?php echo esc_html( number_format( $sub_palier['prix'], 0, ',', ' ' ) ); ?> FCFA/mois</strong>
                                </div>
                            </div>

                            <?php if ( $days_left < 7 ) : ?>
                                <div class="kmfamily-sub-card__warning">
                                    ⚠ Votre soutien expire bientôt — Renouvelez pour ne rien manquer
                                </div>
                            <?php endif; ?>

                            <!-- Changer de palier -->
                            <div class="kmfamily-sub-card__change-palier">
                                <label class="kmfamily-sub-card__change-label">🎚 Changer de palier</label>
                                <div class="kmfamily-palier-switcher">
                                    <?php foreach ( $paliers_actifs as $slug ) :
                                        $p = KMFamily_Paliers::get( $slug );
                                        if ( ! $p ) continue;
                                        $p_level = $hierarchy[ $slug ] ?? 0;
                                        $is_current = ( $slug === $sub['palier'] );
                                        $is_upgrade = ( $p_level > $current_level );
                                    ?>
                                        <button type="button"
                                                class="kmfamily-palier-switcher__btn <?php echo $is_current ? 'is-current' : ''; ?>"
                                                data-artiste-id="<?php echo esc_attr( $artiste_id ); ?>"
                                                data-palier="<?php echo esc_attr( $slug ); ?>"
                                                data-montant="<?php echo esc_attr( $p['prix'] ); ?>"
                                                data-artist-name="<?php echo esc_attr( $artiste->post_title ); ?>"
                                                data-palier-name="<?php echo esc_attr( $p['nom'] ); ?>"
                                                <?php echo $is_current ? 'disabled' : ''; ?>
                                                title="<?php echo esc_attr( $p['nom'] . ' — ' . number_format( $p['prix'], 0, ',', ' ' ) . ' FCFA' ); ?>"
                                                style="<?php echo $is_current ? 'background:' . esc_attr( $p['gradient'] ) . ';color:' . esc_attr( in_array( $slug, array( 'argent', 'or', 'platine', 'diamant' ) ) ? '#0a0a0a' : '#fff' ) . ';' : ''; ?>">
                                            <span class="kmfamily-palier-switcher__icon"><?php echo esc_html( $p['icon'] ); ?></span>
                                            <span class="kmfamily-palier-switcher__name"><?php echo esc_html( $p['nom'] ); ?></span>
                                            <?php if ( $is_current ) : ?>
                                                <span class="kmfamily-palier-switcher__badge">ACTUEL</span>
                                            <?php elseif ( $is_upgrade ) : ?>
                                                <span class="kmfamily-palier-switcher__badge kmfamily-palier-switcher__badge--upgrade">↑</span>
                                            <?php endif; ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="kmfamily-sub-card__actions">
                                <a href="<?php echo esc_url( $artist_url ); ?>" class="kmfamily-btn kmfamily-btn-ghost kmfamily-btn--sm">Voir l'artiste</a>
                                <a href="<?php echo esc_url( $artist_url . '#paliers' ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--sm">Renouveler</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ============ TAB: Découvrir d'autres artistes ============ -->
    <section class="kmfamily-dashboard__tab-content" data-content="discover">
        <h2 class="kmfamily-dashboard__section-title">✨ Soutenez d'autres artistes du label</h2>
        <p class="kmfamily-dashboard__section-intro">
            Chaque artiste a sa propre KM Family. Devenez un pilier pour plusieurs d'entre eux — chaque palier est indépendant !
        </p>

        <?php
        $all_artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        if ( ! empty( $all_artistes ) ) : ?>
            <div class="kmfamily-discover-grid">
                <?php foreach ( $all_artistes as $artiste ) :
                    $active = get_field( 'kmfamily_active', $artiste->ID );
                    if ( $active === null ) $active = true;
                    if ( ! $active ) continue;

                    $current_sub = $subs[ $artiste->ID ] ?? null;
                    $is_member   = $current_sub && ! empty( $current_sub['expire'] ) && $current_sub['expire'] > time();
                    $current_p   = $is_member ? KMFamily_Paliers::get( $current_sub['palier'] ) : null;
                    $nb_fans     = KMFamily_Subscriptions::count_members_for_artist( $artiste->ID );
                ?>
                    <div class="kmfamily-discover-card <?php echo $is_member ? 'is-member' : ''; ?>">
                        <?php if ( has_post_thumbnail( $artiste->ID ) ) : ?>
                            <a href="<?php echo esc_url( get_permalink( $artiste->ID ) ); ?>" class="kmfamily-discover-card__img">
                                <?php echo get_the_post_thumbnail( $artiste->ID, 'medium_large' ); ?>
                            </a>
                        <?php endif; ?>

                        <div class="kmfamily-discover-card__body">
                            <h3><?php echo esc_html( $artiste->post_title ); ?></h3>

                            <?php if ( $nb_fans > 0 ) : ?>
                                <p class="kmfamily-discover-card__fans">
                                    <span class="kmfamily-discover-card__dot"></span>
                                    <?php echo esc_html( $nb_fans ); ?> <?php echo $nb_fans > 1 ? 'membres' : 'membre'; ?>
                                </p>
                            <?php endif; ?>

                            <?php if ( $is_member && $current_p ) : ?>
                                <div class="kmfamily-discover-card__member-status" style="background: <?php echo esc_attr( $current_p['gradient'] ); ?>;color:<?php echo in_array( $current_sub['palier'], array( 'argent', 'or', 'platine', 'diamant' ) ) ? '#0a0a0a' : '#fff'; ?>;">
                                    ✓ Membre <?php echo esc_html( $current_p['nom'] ); ?>
                                </div>
                                <a href="<?php echo esc_url( get_permalink( $artiste->ID ) . '#paliers' ); ?>" class="kmfamily-btn kmfamily-btn-ghost kmfamily-btn--full kmfamily-btn--sm">
                                    Gérer mon abonnement
                                </a>
                            <?php else : ?>
                                <a href="<?php echo esc_url( get_permalink( $artiste->ID ) . '#paliers' ); ?>" class="kmfamily-btn kmfamily-btn-primary kmfamily-btn--full kmfamily-btn--sm">
                                    💛 Devenir un pilier
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ============ TAB: Contenus disponibles ============ -->
    <section class="kmfamily-dashboard__tab-content" data-content="content">
        <h2 class="kmfamily-dashboard__section-title">Contenus exclusifs disponibles</h2>

        <?php
        $available_content = array();
        foreach ( $subs as $artiste_id => $sub ) {
            if ( empty( $sub['expire'] ) || $sub['expire'] < time() ) continue;

            $args = array(
                'post_type'      => KMFamily_CPT::POST_TYPE,
                'posts_per_page' => 6,
                'meta_query'     => array(
                    array(
                        'key'     => 'artiste_lie',
                        'value'   => '"' . $artiste_id . '"',
                        'compare' => 'LIKE',
                    ),
                ),
            );
            $q = new WP_Query( $args );
            while ( $q->have_posts() ) {
                $q->the_post();
                $available_content[] = get_the_ID();
            }
            wp_reset_postdata();
        }

        if ( ! empty( $available_content ) ) : ?>
            <div class="kmfamily-exclusive-grid">
                <?php foreach ( $available_content as $pid ) :
                    $GLOBALS['post'] = get_post( $pid );
                    setup_postdata( $GLOBALS['post'] );
                    include KMFamily_Access::locate_template( 'card-contenu-exclusif.php' );
                    wp_reset_postdata();
                endforeach; ?>
            </div>
        <?php else : ?>
            <div class="kmfamily-empty-state kmfamily-empty-state--small">
                <p>Aucun contenu exclusif disponible pour vos abonnements actuels.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- ============ TAB: Historique ============ -->
    <section class="kmfamily-dashboard__tab-content" data-content="history">
        <h2 class="kmfamily-dashboard__section-title">Historique des paiements</h2>

        <?php if ( ! empty( $history ) ) : ?>
            <div class="kmfamily-dashboard__history-wrap">
                <table class="kmfamily-dashboard__history">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Artiste</th>
                            <th>Palier</th>
                            <th>Montant</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_slice( $history, 0, 20 ) as $entry ) :
                            $p = KMFamily_Paliers::get( $entry['palier'] );
                        ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( 'd/m/Y', $entry['date'] ) ); ?></td>
                                <td><?php echo esc_html( get_the_title( $entry['artiste_id'] ) ); ?></td>
                                <td>
                                    <span class="kmfamily-badge kmfamily-badge--<?php echo esc_attr( $entry['palier'] ); ?>">
                                        <?php echo $p ? esc_html( $p['icon'] . ' ' . $p['nom'] ) : esc_html( $entry['palier'] ); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo esc_html( number_format( $entry['montant'], 0, ',', ' ' ) ); ?> FCFA</strong></td>
                                <td><small><?php echo esc_html( $entry['gateway'] ?? '-' ); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <div class="kmfamily-empty-state kmfamily-empty-state--small">
                <p>Aucun historique pour le moment.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- LOGOUT -->
    <div class="kmfamily-dashboard__logout">
        <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="kmfamily-btn kmfamily-btn-ghost">
            Se déconnecter →
        </a>
    </div>
</div>
