<?php
/**
 * KM Family — Registre des moyens de paiement (Smart Payment Center)
 *
 * Chaque moyen de paiement déclare ses capacités d'affichage (QR / bouton /
 * copie du lien) et son mode de validation. Ce registre est volontairement
 * séparé de la logique de paiement pour rester facile à étendre : brancher
 * une vraie API (Wave Checkout, Orange Money API, etc.) plus tard ne
 * nécessite que de changer 'kind' => 'manual' en 'automatic' et d'ajouter
 * le webhook correspondant — l'UI du Smart Payment Center s'adapte seule.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Payment_Methods {

    /**
     * Définitions statiques — reflète le tableau de référence fourni :
     * Wave / Orange Money / MTN Money / Moov Money / CinetPay / Paystack.
     */
    public static function definitions() {
        return array(
            'wave' => array(
                'label'        => 'Wave',
                'icon'         => '🌊',
                'kind'         => 'manual',
                'capabilities' => array( 'qr', 'button', 'copy' ),
                'validation'   => __( 'Manuelle (puis automatisable via API Wave)', 'km-family' ),
                'settings_key' => 'wave_merchant_link',
                'instructions' => __( "Scanne le QR ou clique sur le bouton, entre le montant exact indiqué, puis confirme le paiement dans l'app Wave.", 'km-family' ),
            ),
            'orange_money' => array(
                'label'        => 'Orange Money',
                'icon'         => '🟠',
                'kind'         => 'manual',
                'capabilities' => array( 'qr', 'button', 'copy' ),
                'validation'   => __( "Selon l'intégration (automatique si CinetPay est configuré)", 'km-family' ),
                'settings_key' => 'orange_money_link',
                'instructions' => __( 'Compose le code ou utilise le lien pour payer via Orange Money, puis confirme.', 'km-family' ),
            ),
            'mtn_money' => array(
                'label'        => 'MTN Money',
                'icon'         => '🟡',
                'kind'         => 'manual',
                'capabilities' => array( 'qr', 'button', 'copy' ),
                'validation'   => __( "Selon l'intégration (automatique si CinetPay est configuré)", 'km-family' ),
                'settings_key' => 'mtn_money_link',
                'instructions' => __( 'Compose le code ou utilise le lien pour payer via MTN Money, puis confirme.', 'km-family' ),
            ),
            'moov_money' => array(
                'label'        => 'Moov Money',
                'icon'         => '🔵',
                'kind'         => 'manual',
                'capabilities' => array( 'qr', 'button', 'copy' ),
                'validation'   => __( "Selon l'intégration (automatique si CinetPay est configuré)", 'km-family' ),
                'settings_key' => 'moov_money_link',
                'instructions' => __( 'Compose le code ou utilise le lien pour payer via Moov Money, puis confirme.', 'km-family' ),
            ),
            'cinetpay' => array(
                'label'        => 'CinetPay',
                'icon'         => '💳',
                'kind'         => 'automatic',
                'capabilities' => array( 'button' ),
                'validation'   => __( 'Automatique (webhook + vérification anti-fraude)', 'km-family' ),
                'settings_key' => null, // configuration via cinetpay_apikey + cinetpay_siteid
                'instructions' => __( 'Paiement instantané par carte ou Mobile Money via CinetPay — abonnement activé automatiquement.', 'km-family' ),
            ),
            'paystack' => array(
                'label'        => 'Paystack',
                'icon'         => '💠',
                'kind'         => 'automatic',
                'capabilities' => array( 'button' ),
                'validation'   => __( 'Automatique (webhook signé HMAC)', 'km-family' ),
                'settings_key' => null, // configuration via paystack_seckey
                'instructions' => __( 'Paiement instantané par carte via Paystack — abonnement activé automatiquement.', 'km-family' ),
            ),
        );
    }

    public static function get( $method ) {
        $defs = self::definitions();
        return $defs[ $method ] ?? null;
    }

    /**
     * Un moyen de paiement est "configuré" si l'admin a renseigné le lien
     * marchand (moyens manuels) ou les identifiants API (moyens automatiques).
     */
    public static function is_configured( $method, $settings = null ) {
        if ( null === $settings ) $settings = get_option( 'kmfamily_settings', array() );

        switch ( $method ) {
            case 'wave':
                return ! empty( $settings['wave_merchant_link'] );
            case 'orange_money':
                return ! empty( $settings['orange_money_link'] );
            case 'mtn_money':
                return ! empty( $settings['mtn_money_link'] );
            case 'moov_money':
                return ! empty( $settings['moov_money_link'] );
            case 'cinetpay':
                // KMFamily_Payment_Gateway::init_payment() route sur UN SEUL gateway actif à la fois
                // (settings['payment_gateway']) : on n'affiche donc l'onglet que s'il est bien sélectionné,
                // sinon un clic sur "CinetPay" paierait en réalité via l'autre passerelle configurée.
                return ( $settings['payment_gateway'] ?? 'none' ) === 'cinetpay'
                    && ! empty( $settings['cinetpay_apikey'] ) && ! empty( $settings['cinetpay_siteid'] );
            case 'paystack':
                return ( $settings['payment_gateway'] ?? 'none' ) === 'paystack'
                    && ! empty( $settings['paystack_seckey'] );
            default:
                return false;
        }
    }

    /**
     * Lien marchand brut pour les moyens manuels (Wave, Orange Money, MTN, Moov).
     * NB : les liens marchands "statiques" (ex. Wave https://pay.wave.com/m/...)
     * ne supportent pas de paramètres pour préremplir le montant ou une référence —
     * l'utilisateur doit saisir le montant lui-même dans l'app. C'est justement
     * pour ça que le Smart Payment Center passe par une page intermédiaire :
     * elle affiche le montant exact à saisir et collecte la déclaration de
     * paiement après coup.
     */
    public static function get_merchant_link( $method, $settings = null ) {
        if ( null === $settings ) $settings = get_option( 'kmfamily_settings', array() );
        $def = self::get( $method );
        if ( ! $def || empty( $def['settings_key'] ) ) return '';
        return esc_url_raw( $settings[ $def['settings_key'] ] ?? '' );
    }

    /**
     * Moyens de paiement à afficher dans le Smart Payment Center, dans un ordre
     * de priorité : automatiques (validation instantanée) d'abord, puis manuels.
     * Seuls les moyens réellement configurés par l'admin sont retournés — c'est
     * ça, "les meilleures options disponibles".
     */
    public static function get_display_methods( $settings = null ) {
        if ( null === $settings ) $settings = get_option( 'kmfamily_settings', array() );

        $available = array();
        foreach ( self::definitions() as $key => $def ) {
            if ( self::is_configured( $key, $settings ) ) {
                $available[ $key ] = $def;
            }
        }

        // Tri : automatiques d'abord (meilleure expérience), puis manuels par ordre de définition.
        uksort( $available, function( $a, $b ) use ( $available ) {
            $ka = $available[ $a ]['kind'] === 'automatic' ? 0 : 1;
            $kb = $available[ $b ]['kind'] === 'automatic' ? 0 : 1;
            return $ka <=> $kb;
        } );

        return $available;
    }

    public static function has_any_configured( $settings = null ) {
        return count( self::get_display_methods( $settings ) ) > 0;
    }
}
