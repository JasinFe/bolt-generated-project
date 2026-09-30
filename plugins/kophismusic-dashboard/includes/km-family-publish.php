<?php
/**
 * ============================================================
 * KOPHI'S MUSIC — Publication artiste (LOT 1)
 * ============================================================
 *
 * Interface, dans l'espace artiste, permettant de publier directement un
 * message, une photo, un vocal ou une vidéo courte pour les membres KM Family.
 *
 * Ce fichier ne contient QUE de l'interface et la réception du formulaire.
 * Toute la logique métier — droits, liste blanche de formats, limite
 * quotidienne, relecture, mise en dossier protégé — vit dans
 * KMFamily_Artist_Publishing, côté extension KM Family. Si KM Family est
 * absent ou désactivé, rien ne s'affiche et le tableau de bord fonctionne
 * exactement comme avant.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'kmfp_available' ) ) {
    function kmfp_available() {
        return class_exists( 'KMFamily_Artist_Publishing' )
            && method_exists( 'KMFamily_Artist_Publishing', 'publish' );
    }
}

// ══════════════════════════════════════════════════════════════
// ASSETS
// ══════════════════════════════════════════════════════════════
add_action( 'wp_enqueue_scripts', 'kmfp_enqueue_assets', 21 );
function kmfp_enqueue_assets() {
    if ( ! function_exists( 'km_is_dashboard_page' ) || ! km_is_dashboard_page() ) return;
    if ( ! kmfp_available() ) return;

    wp_enqueue_style( 'km-family-publish', KM_PLUGIN_URL . 'assets/km-family-publish.css', array( 'km-dashboard' ), KM_VERSION );
}

// ══════════════════════════════════════════════════════════════
// RÉCEPTION DU FORMULAIRE
// ══════════════════════════════════════════════════════════════
add_action( 'admin_post_kmfp_publish', 'kmfp_handle_publish' );
function kmfp_handle_publish() {
    $retour = function_exists( 'km_dashboard_url' ) ? km_dashboard_url( 'mon-tableau-de-bord' ) : home_url( '/' );

    if ( ! is_user_logged_in() || ! kmfp_available() ) {
        wp_safe_redirect( $retour );
        exit;
    }

    if ( ! isset( $_POST['kmfp_nonce'] ) || ! wp_verify_nonce( $_POST['kmfp_nonce'], 'kmfp_publish' ) ) {
        wp_safe_redirect( add_query_arg( 'kmfp_err', rawurlencode( __( 'Session expirée, merci de réessayer.', 'kophismusic' ) ), $retour ) );
        exit;
    }

    $res = KMFamily_Artist_Publishing::publish( get_current_user_id(), array(
        'format'  => sanitize_key( $_POST['kmfp_format'] ?? 'message' ),
        'titre'   => wp_unslash( $_POST['kmfp_titre'] ?? '' ),
        'message' => wp_unslash( $_POST['kmfp_message'] ?? '' ),
        'palier'  => sanitize_key( $_POST['kmfp_palier'] ?? 'bronze' ),
    ) );

    if ( is_wp_error( $res ) ) {
        wp_safe_redirect( add_query_arg( 'kmfp_err', rawurlencode( $res->get_error_message() ), $retour ) . '#kmfp' );
        exit;
    }

    wp_safe_redirect( add_query_arg( 'kmfp_ok', $res['status'] === 'pending' ? 'relecture' : 'publie', $retour ) . '#kmfp' );
    exit;
}

// ══════════════════════════════════════════════════════════════
// RENDU — branché sur le hook du tableau de bord artiste
// ══════════════════════════════════════════════════════════════
add_action( 'km_artist_dashboard_after_kpi', 'kmfp_render_section', 5, 1 );
function kmfp_render_section( $user_id ) {
    echo kmfp_get_section( $user_id );
}

add_shortcode( 'km_family_publication', 'kmfp_shortcode' );
function kmfp_shortcode() {
    if ( ! is_user_logged_in() ) return '';
    return kmfp_get_section( get_current_user_id() );
}

if ( ! function_exists( 'kmfp_statut_label' ) ) {
    function kmfp_statut_label( $statut ) {
        $map = array(
            'publish' => array( 'Visible', '#00D67F' ),
            'pending' => array( 'En relecture', '#F5A623' ),
            'draft'   => array( 'Brouillon', '#8a8a8a' ),
        );
        return $map[ $statut ] ?? array( ucfirst( $statut ), '#8a8a8a' );
    }
}

if ( ! function_exists( 'kmfp_get_section' ) ) {
    function kmfp_get_section( $user_id ) {
        if ( ! kmfp_available() ) return '';
        if ( ! KMFamily_Artist_Publishing::can_publish( $user_id ) ) return '';

        $artiste_id = KMFamily_Artist_Publishing::get_artiste_id( $user_id );
        $formats    = KMFamily_Artist_Publishing::post_types();
        $moderation = KMFamily_Artist_Publishing::moderation_enabled();
        $restants   = max( 0, KMFamily_Artist_Publishing::daily_max() - KMFamily_Artist_Publishing::count_today( $artiste_id ) );
        $recents    = KMFamily_Artist_Publishing::get_artist_posts( $artiste_id, 6 );

        $paliers = array(
            'public'  => 'Tout le monde',
            'bronze'  => 'Bronze et plus',
            'argent'  => 'Argent et plus',
            'or'      => 'Or et plus',
            'platine' => 'Platine et plus',
            'diamant' => 'Diamant uniquement',
        );

        $err = isset( $_GET['kmfp_err'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['kmfp_err'] ) ) ) : '';
        $ok  = isset( $_GET['kmfp_ok'] )  ? sanitize_key( $_GET['kmfp_ok'] ) : '';

        ob_start(); ?>
<div class="km-section kmfp-section" id="kmfp">

    <div class="km-section-header">
        <div>
            <h3 class="km-section-title"><span class="kmfp-dot"></span> PARLER À MA FAMILLE</h3>
            <span class="km-section-sub">
                Publiez directement pour vos membres — sans passer par le label
            </span>
        </div>
        <div class="kmfp-quota" title="Limite de publication sur 24 heures">
            <strong><?php echo esc_html( $restants ); ?></strong>
            <small>publication(s)<br />restante(s) aujourd'hui</small>
        </div>
    </div>

    <?php if ( $err ) : ?>
        <div class="kmfp-alert kmfp-alert--err"><?php echo esc_html( $err ); ?></div>
    <?php elseif ( $ok === 'relecture' ) : ?>
        <div class="kmfp-alert kmfp-alert--ok">
            Publication envoyée. Elle sera visible par vos membres dès validation par le label.
        </div>
    <?php elseif ( $ok === 'publie' ) : ?>
        <div class="kmfp-alert kmfp-alert--ok">
            C'est en ligne. Vos membres peuvent déjà le voir.
        </div>
    <?php endif; ?>

    <?php if ( $restants <= 0 ) : ?>
        <div class="kmfp-alert kmfp-alert--info">
            Vous avez atteint la limite de publications pour aujourd'hui. À demain.
        </div>
    <?php else : ?>

    <form class="kmfp-form" method="post" enctype="multipart/form-data"
          action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="kmfp_publish" />
        <?php wp_nonce_field( 'kmfp_publish', 'kmfp_nonce' ); ?>

        <div class="kmfp-formats">
            <?php $first = true; foreach ( $formats as $slug => $f ) : ?>
                <label class="kmfp-format">
                    <input type="radio" name="kmfp_format" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $first ); ?> />
                    <span class="kmfp-format-box">
                        <span class="kmfp-format-icon"><?php echo esc_html( $f['icon'] ); ?></span>
                        <span class="kmfp-format-label"><?php echo esc_html( $f['label'] ); ?></span>
                    </span>
                </label>
            <?php $first = false; endforeach; ?>
        </div>

        <div class="kmfp-row">
            <label class="kmfp-field">
                <span class="kmfp-label">Titre <em>(facultatif)</em></span>
                <input type="text" name="kmfp_titre" maxlength="120" placeholder="Ex. : Session studio de ce matin" />
            </label>
            <label class="kmfp-field kmfp-field--narrow">
                <span class="kmfp-label">Qui peut voir</span>
                <select name="kmfp_palier">
                    <?php foreach ( $paliers as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $v, 'bronze' ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <label class="kmfp-field">
            <span class="kmfp-label">Votre message</span>
            <textarea name="kmfp_message" rows="4" placeholder="Famille, je viens de terminer le refrain du prochain titre…"></textarea>
        </label>

        <label class="kmfp-field">
            <span class="kmfp-label">
                Fichier <em>(photo, audio ou vidéo — 60 Mo maximum)</em>
            </span>
            <input type="file" name="kmfp_file"
                   accept=".jpg,.jpeg,.png,.webp,.mp3,.m4a,.wav,.ogg,.mp4,.mov,.webm" />
        </label>

        <div class="kmfp-actions">
            <p class="kmfp-note">
                <?php if ( $moderation ) : ?>
                    Vos publications sont relues par le label avant d'être visibles.
                <?php else : ?>
                    Vos publications sont visibles immédiatement par vos membres.
                <?php endif; ?>
                Les fichiers sont stockés de façon protégée et ne sont jamais accessibles en dehors de KM Family.
            </p>
            <button type="submit" class="kmfp-submit">Publier</button>
        </div>
    </form>

    <?php endif; ?>

    <?php if ( $recents ) : ?>
    <div class="kmfp-recent">
        <div class="kmfp-recent-title">Mes dernières publications</div>
        <ul class="kmfp-list">
            <?php foreach ( $recents as $p ) :
                list( $st_label, $st_color ) = kmfp_statut_label( $p->post_status );
                $palier = function_exists( 'get_field' ) ? get_field( 'palier_requis', $p->ID ) : '';
            ?>
                <li class="kmfp-item">
                    <span class="kmfp-item-title"><?php echo esc_html( $p->post_title ); ?></span>
                    <span class="kmfp-item-meta">
                        <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $p->post_date ) ) ); ?>
                        <?php if ( $palier ) : ?>
                            · <?php echo esc_html( $paliers[ $palier ] ?? ucfirst( $palier ) ); ?>
                        <?php endif; ?>
                    </span>
                    <span class="kmfp-badge" style="color:<?php echo esc_attr( $st_color ); ?>;border-color:<?php echo esc_attr( $st_color ); ?>">
                        <?php echo esc_html( $st_label ); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

</div>
        <?php
        return ob_get_clean();
    }
}
