<?php
/**
 * KM Family — Jetons de redirection signés (Smart Payment Center)
 * =================================================================
 * PROBLÈME RÉSOLU : jusqu'ici, "marquer une commande comme initiée" reposait sur un
 * simple appel AJAX (order_ref + gateway) protégé par le nonce WordPress générique
 * de la page (kmfamily_nonce). Ce nonce n'est PAS lié à une commande ni à un moyen
 * de paiement précis : n'importe qui l'ayant récupéré une fois (ou le rejouant depuis
 * la console du navigateur) pouvait déclarer "j'ai cliqué sur Payer avec Wave" pour
 * n'importe quelle commande dont il connaît la référence, sans jamais avoir vu le
 * bouton ni le QR. C'est exactement la faille qui permet une fausse déclaration de
 * paiement plausible (le seul garde-fou existant, declare_payment(), refusait
 * seulement les déclarations sans AUCUNE initiation — pas les initiations fabriquées).
 *
 * SOLUTION : un jeton HMAC signé côté serveur est généré au chargement de la page de
 * paiement, UNIQUEMENT pour la commande et le moyen de paiement affichés. Il est :
 *  - Temporaire (courte durée de vie, alignée sur le temps réaliste d'un paiement) ;
 *  - Signé (HMAC-SHA256 avec une clé secrète propre au site, jamais exposée) ;
 *  - Lié à la commande ET au moyen de paiement précis (impossible à réutiliser pour
 *    une autre commande ou un autre gateway) ;
 *  - À usage unique (consommé après vérification, un rejeu est rejeté).
 *
 * Ce n'est toujours pas une preuve absolue de paiement réel (seule la vérification
 * manuelle de la référence transaction dans l'app du gateway l'est) — mais ça ferme
 * la porte aux déclarations fabriquées sans même avoir interagi avec le bouton/QR,
 * et alimente le moteur de risque avec un signal fiable ("redirection réellement
 * confirmée par un jeton valide" vs "legacy / absent").
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Security_Tokens {

    const SECRET_OPTION      = 'kmfamily_token_secret';
    const DEFAULT_TTL        = 15 * MINUTE_IN_SECONDS; // aligné sur la durée d'une session de paiement active
    const DB_VERSION_OPTION  = 'kmfamily_used_tokens_db_version';
    const DB_VERSION         = '1.0';
    // Marge de rétention des nonces consommés, largement supérieure à leur TTL réel — sert
    // uniquement à garder une trace pour le nettoyage périodique, pas à la validité du jeton.
    const RETENTION          = 2 * DAY_IN_SECONDS;

    public static function init() {
        self::maybe_upgrade_table();
        // Nettoyage périodique (voir cleanup_used_tokens()) — réutilise le cron quotidien
        // déjà existant du plugin plutôt que d'en créer un nouveau.
        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'cleanup_used_tokens' ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_used_tokens';
    }

    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;
        self::create_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Table volontairement minimaliste (une seule responsabilité : empêcher le rejeu
        // d'un nonce de jeton de façon ATOMIQUE — voir verify_and_consume()). `nonce` en
        // PRIMARY KEY : c'est cette contrainte d'unicité, appliquée par le moteur de
        // stockage lui-même au moment de l'INSERT, qui garantit qu'une seule requête
        // concurrente peut consommer un nonce donné — pas une vérification en PHP.
        $sql = "CREATE TABLE {$table} (
            nonce VARCHAR(32) NOT NULL,
            used_at DATETIME NOT NULL,
            PRIMARY KEY  (nonce),
            KEY used_at (used_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Purge les nonces consommés depuis plus de RETENTION (largement plus longtemps que
     * leur durée de vie réelle de 15 min) — évite que cette table ne grossisse indéfiniment.
     * Appelée par le cron quotidien du plugin (voir init()).
     */
    public static function cleanup_used_tokens() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::table_name() . " WHERE used_at < %s",
            KMFamily_Orders::local_datetime( - self::RETENTION )
        ) );
    }

    /**
     * Clé secrète HMAC propre au site, générée une seule fois et stockée (jamais
     * exposée côté client). Séparée des sels WordPress standards pour pouvoir être
     * régénérée indépendamment (ex. en cas de doute sur une fuite) sans invalider
     * les sessions de connexion du site.
     */
    private static function secret(): string {
        $secret = get_option( self::SECRET_OPTION );
        if ( ! $secret || ! is_string( $secret ) || strlen( $secret ) < 32 ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( self::SECRET_OPTION, $secret, false );
        }
        return $secret;
    }

    private static function b64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    private static function b64url_decode( string $data ): string {
        $data = strtr( $data, '-_', '+/' );
        $pad  = strlen( $data ) % 4;
        if ( $pad ) $data .= str_repeat( '=', 4 - $pad );
        return base64_decode( $data );
    }

    /**
     * Émettre un jeton pour une commande + un moyen de paiement précis.
     */
    public static function issue( string $order_ref, string $method, int $ttl = self::DEFAULT_TTL ): string {
        $payload = array(
            'r' => $order_ref,
            'm' => $method,
            'e' => time() + max( 60, $ttl ),
            'n' => bin2hex( random_bytes( 8 ) ), // nonce anti-rejeu
        );

        $encoded = self::b64url_encode( wp_json_encode( $payload ) );
        $sig     = hash_hmac( 'sha256', $encoded, self::secret() );

        return $encoded . '.' . $sig;
    }

    /**
     * Vérifier un jeton pour une commande + moyen de paiement donnés, et le
     * consommer (empêche tout rejeu). Retourne true si valide, WP_Error sinon.
     */
    public static function verify_and_consume( string $token, string $order_ref, string $method ) {
        if ( ! $token || strpos( $token, '.' ) === false ) {
            return new WP_Error( 'token_missing', __( 'Jeton de redirection manquant.', 'km-family' ) );
        }

        list( $encoded, $sig ) = array_pad( explode( '.', $token, 2 ), 2, '' );

        $expected_sig = hash_hmac( 'sha256', $encoded, self::secret() );
        if ( ! hash_equals( $expected_sig, $sig ) ) {
            return new WP_Error( 'token_invalid', __( 'Jeton de redirection invalide.', 'km-family' ) );
        }

        $payload = json_decode( self::b64url_decode( $encoded ), true );
        if ( ! is_array( $payload ) || empty( $payload['r'] ) || empty( $payload['m'] ) || empty( $payload['e'] ) || empty( $payload['n'] ) ) {
            return new WP_Error( 'token_malformed', __( 'Jeton de redirection malformé.', 'km-family' ) );
        }

        if ( ! hash_equals( (string) $payload['r'], $order_ref ) || ! hash_equals( (string) $payload['m'], $method ) ) {
            return new WP_Error( 'token_mismatch', __( "Ce jeton ne correspond pas à cette commande/moyen de paiement.", 'km-family' ) );
        }

        if ( time() > (int) $payload['e'] ) {
            return new WP_Error( 'token_expired', __( 'Jeton de redirection expiré — recharge la page de paiement.', 'km-family' ) );
        }

        // AUDIT QUALITÉ — PROTECTION ANTI-REJEU RÉELLEMENT ATOMIQUE : l'ancienne version
        // utilisait get_transient()/set_transient(), un "lire-puis-écrire" en DEUX étapes
        // séparées — donc pas atomique. Deux requêtes envoyant le MÊME jeton à quelques
        // millisecondes d'écart (double-clic, requête réseau rejouée automatiquement par le
        // navigateur, ou tentative de rejeu délibérée) pouvaient toutes les deux lire "pas
        // encore utilisé" avant qu'aucune n'ait eu le temps d'écrire le marqueur — vérifié
        // empiriquement : deux UPDATE lancés en parallèle réel sur une même ligne ne laissent
        // qu'une seule requête gagner, alors qu'un get+set séparé n'offre aucune garantie de
        // ce genre. On remplace donc par un INSERT dans une table avec le nonce en clé
        // primaire : c'est le moteur de stockage lui-même (verrouillage de ligne au moment
        // de l'INSERT) qui garantit qu'une seule requête concurrente peut réussir cet
        // insert pour un nonce donné — pas une vérification séquentielle en PHP.
        global $wpdb;
        $inserted = $wpdb->insert( self::table_name(), array(
            'nonce'   => $payload['n'],
            'used_at' => current_time( 'mysql' ),
        ), array( '%s', '%s' ) );

        if ( ! $inserted ) {
            // Échec d'insertion = nonce déjà présent (rejeu) OU vraie erreur DB. Dans les deux
            // cas, on refuse : mieux vaut bloquer un cas limite d'erreur DB que laisser passer
            // un rejeu potentiel — la logique appelante retombe de toute façon sur un mécanisme
            // non signé plutôt que de bloquer le paiement (voir confirm_redirect_core()).
            return new WP_Error( 'token_replayed', __( 'Ce jeton a déjà été utilisé (tentative de rejeu détectée).', 'km-family' ) );
        }

        return true;
    }
}
