<?php
/**
 * KM FAMILY — Anti-abus / limitation de débit
 * =============================================
 * Les endpoints publics de paiement (création de commande, déclaration de paiement)
 * et d'authentification (connexion, inscription) n'avaient aucune limite de fréquence
 * — n'importe qui pouvait les appeler en boucle (spam de fausses commandes, saturation
 * des notifications avec de fausses déclarations de paiement, ou tentatives de
 * connexion à répétition). On limite désormais chaque action par adresse IP, via des
 * transients (pas besoin de table dédiée, auto-nettoyage par expiration).
 */
if (!defined('ABSPATH')) exit;

class KMFamily_Security {

    const OPTION_PROXY_SEEN = 'kmfamily_proxy_header_detecte';

    public static function init() {
        add_action( 'init',          array( __CLASS__, 'detect_proxy' ), 5 );
        add_action( 'admin_notices', array( __CLASS__, 'proxy_notice' ) );
    }

    /**
     * FILET DE SÉCURITÉ v3.3.5 — BASCULE SILENCIEUSE DERRIÈRE UN PROXY.
     *
     * Tant que le site est servi en direct, REMOTE_ADDR est l'adresse réelle du
     * visiteur et tout fonctionne : chaque personne a son propre compteur anti-abus.
     *
     * Le jour où un intermédiaire est placé devant le site — Cloudflare, un
     * répartiteur de charge, un cache en frontal, un changement d'offre chez
     * l'hébergeur — REMOTE_ADDR devient l'adresse de CET INTERMÉDIAIRE, identique
     * pour tout le monde. Tous les visiteurs partagent alors le même compteur : au
     * huitième essai de connexion cumulé, TOUT LE MONDE est bloqué pendant dix
     * minutes, et le moteur de risque voit toutes les commandes arriver de la même
     * adresse. Rien ne planterait — le site deviendrait simplement inutilisable, sans
     * message d'erreur ni entrée dans les journaux pour expliquer pourquoi.
     *
     * On ne fait pas confiance à l'en-tête pour autant (ce serait rouvrir la faille
     * corrigée en 3.3.1) : on se contente de le repérer et de prévenir, puisque la
     * décision de faire confiance à un proxy est une décision d'administration, qui
     * doit être prise explicitement.
     *
     * POURQUOI SEULEMENT SUR LES REQUÊTES D'UN ADMINISTRATEUR CONNECTÉ :
     * n'importe quel visiteur peut envoyer un faux `X-Forwarded-For`. Si cela suffisait
     * à déclencher l'avertissement, un attaquant pourrait le provoquer volontairement
     * pour vous pousser à ajouter `KMFAMILY_TRUSTED_PROXY` sur un site qui n'est
     * pourtant derrière aucun proxy — et rouvrir ainsi, de votre propre main, la faille
     * de contournement de la limitation de débit corrigée en 3.3.1. L'avertissement
     * serait devenu un vecteur d'attaque.
     * Un vrai intermédiaire, lui, ajoute l'en-tête à TOUTES les requêtes, y compris
     * celles de votre propre navigateur d'administration — que personne d'autre ne peut
     * forger. C'est donc ce signal-là, et lui seul, qui déclenche l'avertissement.
     */
    public static function detect_proxy() {
        if ( defined( 'KMFAMILY_TRUSTED_PROXY' ) ) {
            delete_option( self::OPTION_PROXY_SEEN );
            return;
        }

        // Voir le commentaire ci-dessus : seule une requête authentifiée d'administrateur
        // constitue une preuve non falsifiable de la présence d'un intermédiaire.
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;

        $entetes = array(
            'HTTP_CF_CONNECTING_IP' => 'cloudflare',
            'HTTP_TRUE_CLIENT_IP'   => 'true-client-ip',
            'HTTP_X_FORWARDED_FOR'  => 'x-forwarded-for',
        );

        foreach ( $entetes as $cle => $nom ) {
            if ( empty( $_SERVER[ $cle ] ) ) continue;

            // Un en-tête de proxy n'a de sens que s'il désigne une adresse différente
            // de celle vue par le serveur : sinon il n'y a pas d'intermédiaire.
            $annonce = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $cle ] ) ) )[0] );
            $vue     = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';

            if ( ! filter_var( $annonce, FILTER_VALIDATE_IP ) || $annonce === $vue ) continue;

            if ( get_option( self::OPTION_PROXY_SEEN ) !== $nom ) {
                update_option( self::OPTION_PROXY_SEEN, $nom, false );
            }
            return;
        }

        if ( get_option( self::OPTION_PROXY_SEEN ) ) {
            delete_option( self::OPTION_PROXY_SEEN );
        }
    }

    /** Avertissement administrateur : un proxy est apparu devant le site. */
    public static function proxy_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $nom = get_option( self::OPTION_PROXY_SEEN );
        if ( ! $nom ) return;

        $ligne = ( 'cloudflare' === $nom )
            ? "define( 'KMFAMILY_TRUSTED_PROXY', 'cloudflare' );"
            : "define( 'KMFAMILY_TRUSTED_PROXY', true );";

        echo '<div class="notice notice-warning"><p><strong>KM Family</strong> — ';
        echo esc_html( sprintf(
            /* translators: %s: nom de l'en-tête de proxy détecté */
            __( 'Le site semble désormais servi derrière un intermédiaire (en-tête « %s » détecté). Sans réglage, tous vos visiteurs partagent la même adresse IP côté serveur : ils se bloquent mutuellement au bout de quelques tentatives de connexion, et le moteur de risque voit toutes les commandes venir de la même adresse. Ajoutez cette ligne dans wp-config.php :', 'km-family' ),
            $nom
        ) );
        echo '</p><p><code>' . esc_html( $ligne ) . '</code></p></div>';
    }

    /**
     * Adresse IP du visiteur. Best-effort : on ne peut jamais faire une confiance
     * absolue aux en-têtes HTTP (usurpables), mais pour de la limitation de débit
     * anti-abus (pas une décision de sécurité critique type contrôle d'accès), une IP
     * approximative suffit très largement à freiner le spam.
     */
    public static function client_ip(): string {
        /**
         * CORRECTIF SÉCURITÉ v3.3.1 — LIMITE DE DÉBIT CONTOURNABLE EN UNE LIGNE.
         * L'ancien code faisait confiance à HTTP_CF_CONNECTING_IP puis HTTP_X_FORWARDED_FOR
         * SANS AUCUNE CONDITION. Or ces en-têtes sont écrits par le client : il suffisait
         * d'envoyer un `X-Forwarded-For` différent à chaque requête pour obtenir une clé de
         * compteur différente à chaque fois, et donc n'être JAMAIS limité. La protection
         * anti-force-brute de la connexion (8 essais / 10 min), l'anti-spam d'inscription et
         * le plafond de déclarations de paiement étaient tous neutralisés de cette façon.
         *
         * REMOTE_ADDR, lui, n'est pas falsifiable : il vient de la couche TCP. On ne lit donc
         * un en-tête de proxy que si le site est explicitement déclaré derrière un proxy de
         * confiance (Cloudflare, répartiteur de charge…), auquel cas REMOTE_ADDR est l'IP du
         * proxy et non celle du visiteur.
         *
         * Pour activer, ajouter dans wp-config.php :
         *     define( 'KMFAMILY_TRUSTED_PROXY', true );          // proxy générique (X-Forwarded-For)
         *     define( 'KMFAMILY_TRUSTED_PROXY', 'cloudflare' );  // Cloudflare (CF-Connecting-IP)
         * ou brancher le filtre 'kmfamily_trusted_proxy_header'.
         */
        $trusted = defined( 'KMFAMILY_TRUSTED_PROXY' ) ? KMFAMILY_TRUSTED_PROXY : false;

        $header = '';
        if ( 'cloudflare' === $trusted ) {
            $header = 'HTTP_CF_CONNECTING_IP';
        } elseif ( $trusted ) {
            $header = 'HTTP_X_FORWARDED_FOR';
        }

        /** Permet de forcer un autre en-tête (ex. HTTP_TRUE_CLIENT_IP) sans toucher au code. */
        $header = (string) apply_filters( 'kmfamily_trusted_proxy_header', $header );

        if ( $header && ! empty( $_SERVER[ $header ] ) ) {
            $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }

        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }

        return '0.0.0.0';
    }

    /**
     * CORRECTIF v3.4.0 — LES LIMITES PAR IP PUNISSAIENT LES CLIENTS LÉGITIMES.
     *
     * Toutes les limites étaient indexées sur l'adresse IP. Or les opérateurs mobiles
     * d'Afrique de l'Ouest — Orange, MTN, Moov — font transiter des milliers d'abonnés
     * derrière un même petit lot d'adresses publiques (CGNAT). Sur ce site, dont le
     * public est très majoritairement sur données mobiles, cela signifiait que huit
     * tentatives de connexion CUMULÉES sur tout le réseau Orange CI suffisaient à
     * bloquer l'ensemble des abonnés de cet opérateur pendant dix minutes. Le même
     * raisonnement valait pour les déclarations de paiement, plafonnées à dix : un
     * samedi soir de lancement, des acheteurs parfaitement honnêtes se voyaient refuser
     * leur déclaration parce que d'autres inconnus avaient payé avant eux.
     *
     * La limite porte donc désormais sur le COMPTE dès que la personne est connectée —
     * ce qui est le cas pour toutes les actions de paiement, le compte étant obligatoire.
     * L'adresse IP ne sert plus que pour les actions réellement anonymes.
     *
     * @param string $action  Action limitée (ex. 'create_order').
     * @param int    $max     Nombre d'appels autorisés dans la fenêtre.
     * @param int    $window  Durée de la fenêtre, en secondes.
     * @param string $scope   Clé de regroupement explicite (ex. un identifiant de
     *                        connexion). Vide = compte si connecté, sinon adresse IP.
     */
    public static function is_rate_limited( string $action, int $max_attempts, int $window_seconds, string $scope = '' ): bool {
        if ( $scope === '' ) {
            $user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
            $scope   = $user_id ? 'u' . $user_id : 'ip' . self::client_ip();
        }

        $key   = 'kmfamily_rl_' . $action . '_' . md5( $scope );
        $count = (int) get_transient( $key );

        if ( $count >= $max_attempts ) return true;

        set_transient( $key, $count + 1, $window_seconds );
        return false;
    }

    /**
     * Limite anti-force-brute de la connexion, sur DEUX axes complémentaires.
     *
     * Un seul axe ne suffit pas :
     *  - uniquement par identifiant, et un attaquant essaie un mot de passe courant sur
     *    des milliers de comptes différents (« password spraying ») sans jamais être
     *    freiné ;
     *  - uniquement par IP, et le CGNAT décrit plus haut bloque des quartiers entiers.
     *
     * On applique donc un plafond STRICT par compte visé (c'est lui qu'on protège) et un
     * plafond LARGE par adresse, assez haut pour ne jamais gêner un opérateur mobile mais
     * assez bas pour rendre le balayage massif impraticable depuis une seule source.
     *
     * @return bool true si la tentative doit être refusée.
     */
    public static function login_attempt_blocked( string $identifiant ): bool {
        $cible = strtolower( trim( $identifiant ) );

        if ( $cible !== '' && self::is_rate_limited( 'login_compte', 8, 10 * MINUTE_IN_SECONDS, 'login:' . $cible ) ) {
            return true;
        }

        return self::is_rate_limited( 'login_source', 60, 10 * MINUTE_IN_SECONDS, 'ip' . self::client_ip() );
    }

    /**
     * Réponse standard (wp_send_json_error, HTTP 429) pour un dépassement de limite.
     */
    public static function rate_limit_response() {
        // BUGFIX : un code 429 explicite ici fait que jQuery traite la réponse comme un
        // échec réseau/serveur générique côté admin-ajax (tout code hors 2xx déclenche
        // .fail()), au lieu d'afficher le message pourtant bien présent dans le corps
        // JSON — la personne voit "problème réseau ou serveur" au lieu de "trop de
        // tentatives, réessaie dans quelques minutes", ce qui laisse penser que tout le
        // système est cassé. On reste en 200 + JSON, cohérent avec les autres
        // wp_send_json_error() de ce plugin (géré par le chemin succès/échec normal).
        wp_send_json_error(
            array( 'message' => __( 'Trop de tentatives depuis cette connexion. Merci de réessayer dans quelques minutes.', 'km-family' ) )
        );
    }
}
