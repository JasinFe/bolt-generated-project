<?php
/**
 * Gestion des périodicités d'abonnement
 *
 * Mensuel / Trimestriel / Semestriel / Annuel avec réductions configurables
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Periodicity {

    public static function init() {
        // Filtre pour inclure la période dans l'historique
    }

    /**
     * Retourne toutes les périodicités disponibles avec leurs réductions actuelles.
     */
    public static function get_all() {
        $settings = get_option( 'kmfamily_settings', array() );

        $periods = array(
            'monthly' => array(
                'slug'     => 'monthly',
                'label'    => __( 'Mensuel', 'km-family' ),
                'short'    => __( '1 mois', 'km-family' ),
                'months'   => 1,
                'days'     => 30,
                'discount' => 0,
                'icon'     => '🗓',
            ),
            'quarterly' => array(
                'slug'     => 'quarterly',
                'label'    => __( 'Trimestriel', 'km-family' ),
                'short'    => __( '3 mois', 'km-family' ),
                'months'   => 3,
                'days'     => 90,
                'discount' => absint( $settings['discount_quarterly'] ?? 10 ),
                'icon'     => '🌱',
            ),
            'biannual' => array(
                'slug'     => 'biannual',
                'label'    => __( 'Semestriel', 'km-family' ),
                'short'    => __( '6 mois', 'km-family' ),
                'months'   => 6,
                'days'     => 180,
                'discount' => absint( $settings['discount_biannual'] ?? 15 ),
                'icon'     => '🌿',
            ),
            'annual' => array(
                'slug'     => 'annual',
                'label'    => __( 'Annuel', 'km-family' ),
                'short'    => __( '12 mois', 'km-family' ),
                'months'   => 12,
                'days'     => 365,
                'discount' => absint( $settings['discount_annual'] ?? 20 ),
                'icon'     => '🌳',
            ),
        );

        return apply_filters( 'kmfamily_periodicities', $periods );
    }

    /**
     * Récupérer une périodicité spécifique.
     */
    public static function get( $slug ) {
        $periods = self::get_all();
        return $periods[ $slug ] ?? null;
    }

    /**
     * Vérifier qu'un slug est valide.
     */
    public static function exists( $slug ) {
        return (bool) self::get( $slug );
    }

    /**
     * La périodicité est-elle activée globalement ?
     */
    public static function is_enabled() {
        // Force refresh complet pour éviter tout cache d'options résiduel
        wp_cache_delete( 'kmfamily_settings', 'options' );
        wp_cache_delete( 'alloptions', 'options' );

        $settings = get_option( 'kmfamily_settings', array() );

        // Valeur par défaut : activé si la réduction trimestrielle > 0 (rétro-compatibilité)
        if ( ! isset( $settings['enable_periodicity'] ) ) {
            return true; // Par défaut activée pour les nouvelles installations
        }

        return ! empty( $settings['enable_periodicity'] );
    }

    /**
     * Calculer le prix final d'un palier pour une périodicité donnée.
     *
     * @param int    $monthly_price Prix mensuel du palier
     * @param string $period_slug   Slug de la périodicité
     * @return array [ 'total' => int, 'monthly_equivalent' => float, 'discount_amount' => int, 'original' => int ]
     */
    public static function calculate_price( $monthly_price, $period_slug ) {
        $period = self::get( $period_slug );
        if ( ! $period ) {
            return array(
                'total'              => $monthly_price,
                'monthly_equivalent' => $monthly_price,
                'discount_amount'    => 0,
                'original'           => $monthly_price,
                'months'             => 1,
                'discount_percent'   => 0,
            );
        }

        $original = $monthly_price * $period['months'];
        $total    = round( $original * ( 100 - $period['discount'] ) / 100 );
        $monthly_equivalent = $period['months'] > 0 ? round( $total / $period['months'] ) : $total;

        return array(
            'total'              => (int) $total,
            'monthly_equivalent' => (int) $monthly_equivalent,
            'discount_amount'    => (int) ( $original - $total ),
            'original'           => (int) $original,
            'months'             => $period['months'],
            'discount_percent'   => $period['discount'],
        );
    }

    /**
     * Retourner le nombre de jours d'une périodicité (pour calculer la date d'expiration).
     */
    public static function get_days( $period_slug ) {
        $period = self::get( $period_slug );
        return $period ? $period['days'] : 30;
    }

    /**
     * Label lisible d'une périodicité.
     */
    public static function get_label( $period_slug ) {
        $period = self::get( $period_slug );
        return $period ? $period['label'] : __( 'Mensuel', 'km-family' );
    }
}
