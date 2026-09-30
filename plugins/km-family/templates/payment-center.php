<?php
/**
 * Smart Payment Center — page intermédiaire de commande.
 *
 * Cette page NE redirige jamais aveuglément vers un lien marchand : elle
 * crée/affiche la commande, la sécurise (ownership vérifiée en amont dans
 * KMFamily_Payment_Center::guard_payment_center_access), puis propose les
 * moyens de paiement configurés (QR / bouton / copie du lien selon le
 * moyen), avant de renvoyer l'utilisateur vers le marchand.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$order_ref = sanitize_text_field( get_query_var( 'kmfamily_order_ref' ) );
$order     = KMFamily_Orders::get( $order_ref );

// Défense en profondeur, cohérente avec guard_payment_center_access() : on ne bloque que si
// un AUTRE membre est connecté sur cet appareil. Un appareil invité (ex. le téléphone qui scanne
// le QR, sans session WordPress) reste autorisé — la order_ref fait office de jeton d'accès.
// BUGFIX : la version précédente vidait $order pour TOUT visiteur non connecté (get_current_user_id()
// vaut 0 pour un invité, jamais égal au user_id propriétaire), ce qui cassait justement le scan du
// QR depuis un téléphone non connecté au compte — la page affichait "Commande introuvable" au lieu
// de mener au paiement marchand.
if ( $order && is_user_logged_in() && ! KMFamily_Orders::user_owns_order( $order, get_current_user_id() ) ) {
    $order = null;
}

get_header();
?>

<div class="kmfamily-spc">

<?php if ( ! $order ) : ?>

    <div class="kmfamily-spc__header">
        <span class="kmfamily-spc__eyebrow">KM Family</span>
        <h1 class="kmfamily-spc__title"><?php esc_html_e( 'Commande introuvable', 'km-family' ); ?></h1>
        <p class="kmfamily-spc__subtitle"><?php esc_html_e( "Ce lien de paiement n'existe plus ou a expiré. Retourne sur la page des paliers pour recommencer.", 'km-family' ); ?></p>
    </div>

<?php else :
    $settings    = get_option( 'kmfamily_settings', array() );
    $is_ticket   = class_exists( 'KMFamily_Orders' ) && KMFamily_Orders::is_ticket_order( $order );
    $order_meta  = is_array( $order['meta'] ) ? $order['meta'] : array();
    $artiste_nom = get_the_title( $order['artiste_id'] ); // pour un billet : titre de l'évènement
    $palier_nom  = $is_ticket ? ( $order_meta['ticket_type_nom'] ?? $order['palier'] ) : KMFamily_CPT::get_palier_label( $order['palier'] );
    $period_nom  = ( ! $is_ticket && class_exists( 'KMFamily_Periodicity' ) ) ? KMFamily_Periodicity::get_label( $order['periodicity'] ) : __( 'Billet', 'km-family' );
    $stage       = KMFamily_Orders::get_display_stage( $order['status'] );

    $display_methods = KMFamily_Payment_Methods::get_display_methods( $settings );

    // Construire les données par moyen de paiement pour le JS (QR / bouton / copie).
    $methods_payload = array();
    foreach ( $display_methods as $key => $def ) {
        $is_automatic = ( $def['kind'] === 'automatic' );

        $entry = array(
            'label'        => $def['label'],
            'icon'         => $def['icon'],
            'capabilities' => $def['capabilities'],
            'instructions' => $def['instructions'],
            'dynamic'      => $is_automatic, // true = URL de paiement récupérée en direct via AJAX (CinetPay/Paystack)
        );

        if ( ! $is_automatic ) {
            $entry['merchant_link'] = KMFamily_Payment_Methods::get_merchant_link( $key, $settings );

            // TRACKING & QR DYNAMIQUE : chaque chargement de la page génère un nouveau
            // tracking_token (KMFamily_Payment_Tracking) et un nouveau jeton signé
            // (KMFamily_Security_Tokens) pour CE moyen de paiement précis.
            $tokens = class_exists( 'KMFamily_Payment_Center' )
                ? KMFamily_Payment_Center::build_payment_tokens( $order['order_ref'], $key )
                : array( 'hmac_token' => '', 'tracking_token' => '' );

            $entry['redirect_token']  = $tokens['hmac_token'];
            $entry['tracking_token']  = $tokens['tracking_token'];

            // Le QR encode désormais notre propre lien de tracking dynamique
            // (/km-pay/{tracking_token}/) plutôt que le lien marchand brut : chaque scan
            // est comptabilisé et journalisé, le lien expire après un temps limité, et un
            // nouveau chargement de page produit un QR entièrement différent.
            // COMPROMIS ASSUMÉ : l'app Wave/Orange ne reconnaît plus le lien nativement dès
            // le scan (elle passe par notre redirection 302, quasi instantanée) — on échange
            // ce confort contre une traçabilité réelle du scan, comme demandé.
            $entry['qr_url'] = $tokens['tracking_token']
                ? home_url( '/km-pay/' . $tokens['tracking_token'] . '/' )
                : $entry['merchant_link']; // filet de sécurité si le tracking n'a pas pu être initialisé
        }

        $methods_payload[ $key ] = $entry;
    }

    $order_payload = array(
        'order_ref'   => $order['order_ref'],
        'artiste_id'  => (int) $order['artiste_id'],
        'palier'      => $order['palier'],
        'periodicity' => $order['periodicity'],
        'montant'     => (int) $order['montant'],
        'status'      => $order['status'],
        'stage'       => $stage,
    );
    ?>

    <div class="kmfamily-spc__header">
        <span class="kmfamily-spc__eyebrow">🔒 <?php esc_html_e( 'Paiement sécurisé', 'km-family' ); ?></span>
        <?php if ( $is_ticket ) : ?>
            <h1 class="kmfamily-spc__title"><?php esc_html_e( 'Finalise ta réservation', 'km-family' ); ?></h1>
            <p class="kmfamily-spc__subtitle">
                <?php
                printf(
                    esc_html__( 'Ton billet pour%1$s — %2$s', 'km-family' ),
                    '&nbsp;<strong>' . esc_html( $artiste_nom ) . '</strong>&nbsp;',
                    '&nbsp;<strong>' . esc_html( $palier_nom ) . '</strong>'
                );
                ?>
            </p>
        <?php else : ?>
            <h1 class="kmfamily-spc__title"><?php esc_html_e( 'Finalise ton adhésion', 'km-family' ); ?></h1>
            <p class="kmfamily-spc__subtitle">
                <?php
                // FIX ANTI-COLLAGE : les espaces qui entourent %s dans une chaîne traduisible sont
                // fragiles — un minifieur HTML de cache (WP Rocket/LiteSpeed/Autoptimize) ou une
                // traduction éditée à la main peut les avaler, collant "de" à "Mr Potego" ("deMr
                // Potego"). On déplace donc les espaces à l'intérieur même du fragment HTML inséré,
                // sous forme d'espaces insécables (&nbsp;), jamais supprimés par un minifieur.
                printf(
                    esc_html__( 'Rejoins la KM Family de%1$s au palier%2$s', 'km-family' ),
                    '&nbsp;<strong>' . esc_html( $artiste_nom ) . '</strong>&nbsp;',
                    '&nbsp;<strong>' . esc_html( $palier_nom ) . '</strong>'
                );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="kmfamily-spc__timeline"></div>

    <div class="kmfamily-spc__banner-slot"></div>

    <div class="kmfamily-spc__summary">
        <div class="kmfamily-spc__summary-item">
            <span class="kmfamily-spc__summary-label"><?php echo $is_ticket ? esc_html__( 'Type de billet', 'km-family' ) : esc_html__( 'Palier', 'km-family' ); ?></span>
            <span class="kmfamily-spc__summary-value"><?php echo esc_html( $palier_nom ); ?><?php echo $is_ticket ? '' : ' · ' . esc_html( $period_nom ); ?></span>
        </div>
        <div class="kmfamily-spc__summary-item">
            <span class="kmfamily-spc__summary-label"><?php esc_html_e( 'Référence commande', 'km-family' ); ?></span>
            <span class="kmfamily-spc__summary-value"><?php echo esc_html( $order['order_ref'] ); ?></span>
        </div>
        <div class="kmfamily-spc__summary-item">
            <span class="kmfamily-spc__summary-label"><?php esc_html_e( 'Montant exact à régler', 'km-family' ); ?></span>
            <span class="kmfamily-spc__amount"><?php echo esc_html( number_format_i18n( $order['montant'] ) ); ?> <?php echo esc_html( $order['currency'] ); ?></span>
        </div>
    </div>

    <?php if ( empty( $methods_payload ) ) : ?>

        <div class="kmfamily-spc__panel">
            <p class="kmfamily-spc__panel-instructions">
                <?php esc_html_e( "Aucun moyen de paiement n'est encore configuré. Contacte un administrateur pour finaliser ton adhésion.", 'km-family' ); ?>
            </p>
        </div>

    <?php else : ?>

        <div class="kmfamily-spc__methods-tabs"></div>
        <div class="kmfamily-spc__panel"></div>

        <script type="application/json" id="kmfamily-order-data">
            <?php echo wp_json_encode( array( 'order' => $order_payload, 'methods' => $methods_payload ) ); ?>
        </script>

    <?php endif; ?>

<?php endif; ?>

</div>

<?php get_footer(); ?>
