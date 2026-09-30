<?php
/**
 * Classe Badges — gestion et affichage des badges de membre
 *
 * Chaque utilisateur a un "badge principal" = son palier le plus élevé
 * tous artistes confondus. Ce badge est stocké en user_meta et affiché
 * dans :
 * - Son profil (dashboard KM Family)
 * - Les commentaires (avatar)
 * - La barre d'admin WordPress
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Badges {

    const USER_META_KEY = 'kmfamily_badge';

    public static function init() {
        // Afficher le badge dans la barre d'admin WP
        add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_badge' ), 999 );

        // Ajouter le badge à l'avatar
        add_filter( 'get_avatar', array( __CLASS__, 'filter_avatar' ), 10, 6 );

        // Styles pour le badge admin bar
        add_action( 'wp_head',       array( __CLASS__, 'badge_styles' ) );
        add_action( 'admin_head',    array( __CLASS__, 'badge_styles' ) );
    }

    /**
     * Recalculer et stocker le badge d'un utilisateur (à appeler après activate/cancel).
     */
    public static function update_user_badge( $user_id ) {
        $highest = KMFamily_Paliers::get_user_highest_palier( $user_id );
        if ( $highest ) {
            update_user_meta( $user_id, self::USER_META_KEY, $highest );
        } else {
            delete_user_meta( $user_id, self::USER_META_KEY );
        }
    }

    /**
     * Récupérer le badge d'un utilisateur.
     */
    public static function get_user_badge( $user_id ) {
        return get_user_meta( $user_id, self::USER_META_KEY, true );
    }

    /**
     * Générer le HTML d'un badge.
     */
    public static function render( $palier_slug, $size = 'medium', $show_label = true ) {
        if ( ! $palier_slug ) return '';

        $palier = KMFamily_Paliers::get( $palier_slug );
        if ( ! $palier ) return '';

        $sizes = array(
            'small'  => array( 'width' => 20, 'font' => 10 ),
            'medium' => array( 'width' => 32, 'font' => 14 ),
            'large'  => array( 'width' => 48, 'font' => 18 ),
            'hero'   => array( 'width' => 80, 'font' => 28 ),
        );
        $s = $sizes[ $size ] ?? $sizes['medium'];

        ob_start();
        ?>
        <span class="kmfamily-badge kmfamily-badge--<?php echo esc_attr( $palier_slug ); ?> kmfamily-badge--<?php echo esc_attr( $size ); ?>"
              title="<?php echo esc_attr( $palier['nom'] . ' — ' . $palier['statut'] ); ?>">
            <span class="kmfamily-badge__icon" style="background: <?php echo esc_attr( $palier['gradient'] ); ?>;">
                <?php echo esc_html( $palier['icon'] ); ?>
            </span>
            <?php if ( $show_label ) : ?>
                <span class="kmfamily-badge__label"><?php echo esc_html( $palier['nom'] ); ?></span>
            <?php endif; ?>
        </span>
        <?php
        return ob_get_clean();
    }

    /**
     * Ajouter le badge dans la barre d'admin WP.
     */
    public static function add_admin_bar_badge( $wp_admin_bar ) {
        if ( ! is_user_logged_in() ) return;

        $user_id = get_current_user_id();
        $badge_slug = self::get_user_badge( $user_id );
        if ( ! $badge_slug ) return;

        $palier = KMFamily_Paliers::get( $badge_slug );
        if ( ! $palier ) return;

        $wp_admin_bar->add_node( array(
            'id'    => 'kmfamily-badge',
            'title' => '<span class="ab-icon" style="font-size:16px;margin-right:4px;">' . $palier['icon'] . '</span>' . $palier['nom'],
            'href'  => home_url( '/mon-espace-km-family/' ),
            'meta'  => array(
                'class' => 'kmfamily-adminbar-badge kmfamily-adminbar-badge--' . $badge_slug,
                'title' => $palier['statut'] . ' — KM Family',
            ),
        ) );
    }

    /**
     * Ajouter un liseré coloré autour de l'avatar selon le palier.
     */
    public static function filter_avatar( $avatar, $id_or_email, $size, $default, $alt, $args ) {
        $user_id = 0;

        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) {
            $user_id = (int) $id_or_email->user_id;
        } elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) $user_id = $user->ID;
        }

        if ( ! $user_id ) return $avatar;

        $badge_slug = self::get_user_badge( $user_id );
        if ( ! $badge_slug ) return $avatar;

        $color = KMFamily_Paliers::get_color( $badge_slug );

        // Ajouter une classe et un style inline
        $avatar = str_replace(
            'class=\'avatar',
            'class=\'avatar kmfamily-avatar-badged kmfamily-avatar-badged--' . esc_attr( $badge_slug ),
            $avatar
        );
        // Si class="avatar" au lieu de class='avatar'
        $avatar = str_replace(
            'class="avatar',
            'class="avatar kmfamily-avatar-badged kmfamily-avatar-badged--' . esc_attr( $badge_slug ),
            $avatar
        );

        return $avatar;
    }

    /**
     * Styles du badge admin bar et avatar.
     */
    public static function badge_styles() {
        ?>
        <style id="kmfamily-badge-styles">
        #wpadminbar .kmfamily-adminbar-badge--bronze  > .ab-item { background: linear-gradient(135deg, #cd7f32 0%, #8b4513 100%) !important; color:#fff !important; font-weight:700; }
        #wpadminbar .kmfamily-adminbar-badge--argent  > .ab-item { background: linear-gradient(135deg, #c0c0c0 0%, #808080 100%) !important; color:#1a1a1a !important; font-weight:700; }
        #wpadminbar .kmfamily-adminbar-badge--or      > .ab-item { background: linear-gradient(135deg, #ffd700 0%, #d4af37 50%, #b8941f 100%) !important; color:#1a1a1a !important; font-weight:700; }
        #wpadminbar .kmfamily-adminbar-badge--platine > .ab-item { background: linear-gradient(135deg, #f0f0f0 0%, #b8b8b8 100%) !important; color:#1a1a1a !important; font-weight:700; }
        #wpadminbar .kmfamily-adminbar-badge--diamant > .ab-item { background: linear-gradient(135deg, #b9f2ff 0%, #4facfe 50%, #00f2fe 100%) !important; color:#1a1a1a !important; font-weight:700; }

        .kmfamily-avatar-badged { position:relative; border-radius:50%; }
        .kmfamily-avatar-badged--bronze  { box-shadow: 0 0 0 3px #cd7f32; }
        .kmfamily-avatar-badged--argent  { box-shadow: 0 0 0 3px #c0c0c0; }
        .kmfamily-avatar-badged--or      { box-shadow: 0 0 0 3px #d4af37; }
        .kmfamily-avatar-badged--platine { box-shadow: 0 0 0 3px #e5e4e2; }
        .kmfamily-avatar-badged--diamant { box-shadow: 0 0 0 3px #4facfe; }
        </style>
        <?php
    }
}
