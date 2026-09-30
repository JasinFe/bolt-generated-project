<?php
/**
 * Contrôle d'accès et paywall v2
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Access {

    public static function init() {
        add_filter( 'the_content',      array( __CLASS__, 'restrict_content' ), 20 );
        add_filter( 'the_excerpt',      array( __CLASS__, 'restrict_excerpt' ), 20 );
        add_filter( 'the_content_feed', array( __CLASS__, 'restrict_feed' ), 20 );
    }

    /**
     * POINT D'ENTRÉE UNIQUE — « ce membre peut-il voir CE contenu, maintenant ? »
     *
     * À préférer systématiquement à user_has_access() dans les gabarits et les
     * modules : cette méthode tient compte du moteur d'exclusivité (premières,
     * drops, archives), là où user_has_access() ne répond qu'à la question du
     * palier, hors du temps.
     *
     * Défaut corrigé : les gabarits appelaient directement user_has_access(),
     * ce qui produisait une URL de lecture signée pour une première dont la
     * fenêtre n'était pas encore ouverte. Le paywall bloquait bien le texte,
     * mais le média, lui, était accessible.
     */
    public static function can_view( $user_id, $post_id ) {
        if ( class_exists( 'KMFamily_Availability' ) ) {
            return KMFamily_Availability::user_can_access( $user_id, $post_id );
        }

        $palier      = get_field( 'palier_requis', $post_id );
        $artiste_obj = get_field( 'artiste_lie', $post_id );
        if ( ! $artiste_obj ) return false;

        $artiste_id = is_object( $artiste_obj ) ? $artiste_obj->ID : $artiste_obj;
        return self::user_has_access( $user_id, $artiste_id, $palier );
    }

    public static function user_has_access( $user_id, $artiste_id, $palier_requis ) {
        if ( $palier_requis === 'public' ) return true;
        if ( ! $user_id ) return false;
        if ( user_can( $user_id, 'manage_options' ) ) return true;

        $subscription = KMFamily_Subscriptions::get_user_subscription( $user_id, $artiste_id );
        if ( ! $subscription ) return false;
        if ( ! empty( $subscription['expire'] ) && $subscription['expire'] < time() ) return false;

        // LOT 0 — SÉPARATION CONTRIBUTION / ACCÈS.
        // Le palier acheté n'est plus systématiquement le niveau d'accès : pour
        // le palier Libre, l'accès peut être dérivé du montant réellement versé
        // (option désactivée par défaut, voir KMFamily_Memberships). Pour tous
        // les autres paliers, le résultat est rigoureusement identique à
        // l'ancien calcul.
        if ( class_exists( 'KMFamily_Memberships' ) ) {
            $acces = KMFamily_Memberships::resolve_access(
                $subscription['palier'],
                $subscription['montant'] ?? 0
            );
            return $acces['rank'] >= KMFamily_Memberships::required_rank( $palier_requis );
        }

        $hierarchy     = KMFamily_Paliers::get_hierarchy();
        $niveau_user   = $hierarchy[ $subscription['palier'] ] ?? 0;
        $niveau_requis = $hierarchy[ $palier_requis ] ?? 999;

        return $niveau_user >= $niveau_requis;
    }

    public static function restrict_content( $content ) {
        if ( ! is_singular( KMFamily_CPT::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $post_id       = get_the_ID();
        $palier_requis = get_field( 'palier_requis', $post_id );
        $artiste_obj   = get_field( 'artiste_lie', $post_id );

        if ( ! $artiste_obj ) return $content;

        $artiste_id = is_object( $artiste_obj ) ? $artiste_obj->ID : $artiste_obj;
        $user_id    = get_current_user_id();

        // LOT 3 — MOTEUR D'EXCLUSIVITÉ.
        // Le palier requis n'est plus figé : il peut descendre avec le temps
        // (Diamant J-7 → Public J). On demande donc au moteur le palier
        // applicable À CET INSTANT, et c'est lui qui est confronté aux droits.
        if ( class_exists( 'KMFamily_Availability' ) ) {
            $verdict = KMFamily_Availability::check( $user_id, $post_id );
            if ( $verdict['ok'] ) return $content;

            // Le palier affiché dans le paywall doit être celui du moment,
            // sans quoi on annoncerait « réservé Diamant » à un membre Argent
            // qui y aura droit dans deux jours.
            $palier_affiche = $verdict['dispo']['palier'] ?: $palier_requis;
            return self::render_paywall( $post_id, $artiste_id, $palier_affiche );
        }

        if ( self::user_has_access( $user_id, $artiste_id, $palier_requis ) ) {
            return $content;
        }

        return self::render_paywall( $post_id, $artiste_id, $palier_requis );
    }

    public static function restrict_excerpt( $excerpt ) {
        if ( get_post_type() !== KMFamily_CPT::POST_TYPE ) return $excerpt;

        $post_id     = get_the_ID();
        $palier      = get_field( 'palier_requis', $post_id );
        $artiste_obj = get_field( 'artiste_lie', $post_id );

        if ( $palier === 'public' ) return $excerpt;

        if ( $artiste_obj ) {
            $artiste_id = is_object( $artiste_obj ) ? $artiste_obj->ID : $artiste_obj;
            if ( self::can_view( get_current_user_id(), $post_id ) ) {
                return $excerpt;
            }
        }

        $teaser = get_field( 'teaser_public', $post_id );
        return $teaser ? wp_strip_all_tags( $teaser ) : $excerpt;
    }

    public static function restrict_feed( $content ) {
        if ( get_post_type() !== KMFamily_CPT::POST_TYPE ) return $content;

        $palier = get_field( 'palier_requis', get_the_ID() );
        if ( $palier === 'public' ) return $content;

        return sprintf(
            '<p>%s <a href="%s">%s</a></p>',
            esc_html__( 'Ce contenu est réservé aux membres KM Family.', 'km-family' ),
            esc_url( get_permalink() ),
            esc_html__( 'Voir sur le site', 'km-family' )
        );
    }

    public static function render_paywall( $post_id, $artiste_id, $palier_requis ) {
        $teaser       = get_field( 'teaser_public', $post_id );
        $artiste_name = get_the_title( $artiste_id );
        $artiste_url  = get_permalink( $artiste_id );
        $is_logged_in = is_user_logged_in();
        $label_palier = KMFamily_Paliers::get_label( $palier_requis );

        $template = self::locate_template( 'paywall.php' );

        ob_start();
        include $template;
        return ob_get_clean();
    }

    public static function locate_template( $template_name ) {
        $theme_template = locate_template( 'km-family/' . $template_name );
        if ( $theme_template ) return $theme_template;
        return KMFAMILY_PATH . 'templates/' . $template_name;
    }
}
