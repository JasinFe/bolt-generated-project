<?php
/**
 * KM Family — Smart Payment Center (Centre de Paiement Intelligent)
 *
 * Orchestre la commande de bout en bout, indépendamment du moyen de paiement :
 *  1. Création de la commande (montant recalculé côté serveur, jamais fait confiance au client)
 *  2. Page intermédiaire /km-payment/{order_ref}/ qui affiche QR / bouton / copie du lien
 *     selon le moyen choisi, adaptée au device (mobile vs desktop)
 *  3. Suivi d'état : pending -> initiated -> (awaiting_confirmation) -> confirmed -> active
 *  4. Activation automatique de l'abonnement dès confirmation (webhook ou admin)
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Payment_Center {

    public static function init() {
        // CORRECTIF v3.3.1 — ORDRE DES RÈGLES DE RÉÉCRITURE.
        // L'enregistrement ET le flush se faisaient tous deux sur 'init' à la priorité 10,
        // et ce module est initialisé AVANT KMFamily_Media_Session / KMFamily_Media_Protector
        // (voir l'ordre des ::init() dans km-family.php). Le flush partait donc alors que les
        // règles ^km-family-stream/… et ^km-family-media/… n'avaient pas encore été déclarées :
        // elles disparaissaient des permaliens régénérés, et toute lecture de média renvoyait
        // un 404 après chaque mise à jour de version, jusqu'à un ré-enregistrement manuel des
        // permaliens. On déclare tôt (priorité 5) et on flushe tard (priorité 99), une fois que
        // TOUS les modules ont ajouté leurs règles — même logique que KMFamily_Direct_Link.
        add_action( 'init',              array( __CLASS__, 'register_rewrite_rule' ), 5 );
        add_action( 'init',              array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
        add_filter( 'query_vars',        array( __CLASS__, 'add_query_vars' ) );
        add_filter( 'template_include',  array( __CLASS__, 'load_payment_center_template' ), 99 );
        add_action( 'template_redirect', array( __CLASS__, 'handle_tracking_redirect' ), 5 );
        add_action( 'template_redirect', array( __CLASS__, 'guard_payment_center_access' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'rest_api_init',      array( __CLASS__, 'register_endpoints' ) );

        add_action( 'wp_ajax_kmfamily_create_order',        array( __CLASS__, 'ajax_create_order' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_create_order', array( __CLASS__, 'ajax_create_order_guest' ) );

        add_action( 'wp_ajax_kmfamily_order_status',        array( __CLASS__, 'ajax_order_status' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_order_status', array( __CLASS__, 'ajax_order_status' ) );

        add_action( 'wp_ajax_kmfamily_mark_initiated',        array( __CLASS__, 'ajax_mark_initiated' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_mark_initiated', array( __CLASS__, 'ajax_mark_initiated' ) );

        // SÉCURITÉ ANTI-FRAUDE : équivalent signé de mark_initiated. Le front-end l'utilise
        // désormais en priorité (voir enqueue_assets() + template payment-center.php pour
        // l'émission du jeton, et km-payment-center.js pour son envoi au clic). L'ancien
        // couple mark_initiated/ajax reste actif en repli (legacy, non signé) pour ne jamais
        // bloquer un paiement si le jeton a expiré ou si une page en cache le sert sans jeton
        // à jour — mais ce cas est alors journalisé comme signal de risque plus faible.
        add_action( 'wp_ajax_kmfamily_confirm_redirect',        array( __CLASS__, 'ajax_confirm_redirect' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_confirm_redirect', array( __CLASS__, 'ajax_confirm_redirect' ) );

        // ÉVÉNEMENT DEMANDÉ : "retour" — voir mark_returned_core(). Utilise sendBeacon
        // côté JS (fiable même si l'onglet se referme juste après), d'où le hook nopriv.
        add_action( 'wp_ajax_kmfamily_mark_returned',        array( __CLASS__, 'ajax_mark_returned' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_mark_returned', array( __CLASS__, 'ajax_mark_returned' ) );

        add_action( 'wp_ajax_kmfamily_declare_payment',        array( __CLASS__, 'ajax_declare_payment' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_declare_payment', array( __CLASS__, 'ajax_declare_payment' ) );

        add_action( 'admin_post_kmfamily_confirm_order', array( __CLASS__, 'handle_admin_confirm_order' ) );
        add_action( 'admin_post_kmfamily_reject_order',  array( __CLASS__, 'handle_admin_reject_order' ) );
    }

    /**
     * Route REST de secours : sur cet hébergement, certaines requêtes admin-ajax.php
     * sont bloquées par le WAF/ModSecurity (403). Le front-end retente automatiquement
     * via ces endpoints REST quand ça arrive — même logique que init-payment.
     */
    public static function register_endpoints() {
        register_rest_route( 'kmfamily/v1', '/create-order', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_create_order' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );

        // BUGFIX : mark-initiated et declare-payment n'avaient aucun repli REST, contrairement
        // à create-order et init-payment. Résultat : quand admin-ajax.php est bloqué (WAF de
        // l'hébergeur) ou que la requête échoue pour une autre raison réseau, l'étape "Paiement
        // initié" ne s'enregistrait jamais côté serveur (l'interface l'affichait quand même,
        // de façon purement visuelle), et la déclaration de paiement échouait avec un message
        // générique sans aucune retentative. Ces deux actions restent accessibles aux invités
        // (permission_callback permissif), exactement comme leurs équivalents wp_ajax_nopriv_.
        register_rest_route( 'kmfamily/v1', '/mark-initiated', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_mark_initiated' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kmfamily/v1', '/declare-payment', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_declare_payment' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kmfamily/v1', '/confirm-redirect', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_confirm_redirect' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kmfamily/v1', '/mark-returned', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_mark_returned' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Démarre le tracking d'un parcours de paiement pour une commande + un moyen de
     * paiement précis, et émet les deux jetons nécessaires :
     *  - 'hmac_token'      : jeton signé à usage unique (KMFamily_Security_Tokens),
     *                        envoyé par le bouton "Payer avec X" pour prouver le clic.
     *  - 'tracking_token'  : identifiant persistant du parcours (KMFamily_Payment_Tracking),
     *                        encodé dans le QR Code — c'est lui qui rend le QR "dynamique"
     *                        (nouveau à chaque chargement de page, à usage/durée limités).
     */
    public static function build_payment_tokens( string $order_ref, string $method ): array {
        $tracking_token = class_exists( 'KMFamily_Payment_Tracking' )
            ? KMFamily_Payment_Tracking::generate( $order_ref, $method )
            : '';

        $hmac_token = class_exists( 'KMFamily_Security_Tokens' )
            ? KMFamily_Security_Tokens::issue( $order_ref, $method )
            : '';

        return array(
            'hmac_token'     => $hmac_token,
            'tracking_token' => $tracking_token,
        );
    }

    /**
     * @deprecated Conservé pour compatibilité — utiliser build_payment_tokens().
     */
    public static function issue_redirect_token( string $order_ref, string $method ): string {
        return self::build_payment_tokens( $order_ref, $method )['hmac_token'];
    }

    /**
     * Cœur de la mesure anti-fraude demandée : confirme qu'une redirection de paiement
     * (clic sur "Payer avec X", ou réveil du QR) a bien été déclenchée pour CETTE commande
     * et CE moyen de paiement précis, via un jeton signé à usage unique — pas juste un
     * order_ref rejouable. Journalise l'événement dans le journal chaîné, puis marque la
     * commande comme "initiée" (même effet que l'ancien mark_initiated, mais avec preuve).
     */
    private static function confirm_redirect_core( $order_ref, $method, $token, $source = 'button' ) {
        $order_ref = sanitize_text_field( $order_ref );
        $method    = sanitize_key( $method );
        $source    = in_array( $source, array( 'button', 'qr_reveal' ), true ) ? $source : 'button';
        $device    = wp_is_mobile() ? 'mobile' : 'desktop';

        $order = KMFamily_Orders::get( $order_ref );
        if ( ! self::can_access_order( $order ) ) {
            return new WP_Error( 'order_forbidden', __( 'Commande introuvable.', 'km-family' ), array( 'status' => 403 ) );
        }

        $verify = class_exists( 'KMFamily_Security_Tokens' )
            ? KMFamily_Security_Tokens::verify_and_consume( $token, $order_ref, $method )
            : true; // filet de sécurité si la classe n'est pas chargée, ne doit jamais bloquer un paiement

        if ( is_wp_error( $verify ) ) {
            if ( class_exists( 'KMFamily_Event_Log' ) ) {
                KMFamily_Event_Log::log( $order_ref, 'redirect_token_rejected', array(
                    'method' => $method,
                    'reason' => $verify->get_error_code(),
                ), 'user:' . get_current_user_id() );
            }
            return $verify;
        }

        // ÉVÉNEMENT DEMANDÉ : "bouton cliqué" — distinct du scan QR (qui, lui, passe par
        // handle_tracking_redirect()). On journalise le geste précis (bouton vs QR révélé)
        // et on incrémente click_count sur le tracking dédié.
        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, $source === 'button' ? 'button_clicked' : 'qr_reveal_clicked', array(
                'method' => $method,
            ), 'user:' . get_current_user_id() );

            KMFamily_Event_Log::log( $order_ref, 'redirect_confirmed', array(
                'method' => $method,
                'device' => $device,
                'source' => $source,
            ), 'user:' . get_current_user_id() );
        }

        if ( class_exists( 'KMFamily_Payment_Tracking' ) ) {
            KMFamily_Payment_Tracking::record_click( $order_ref, $method );
        }

        KMFamily_Orders::mark_initiated( $order_ref, $device, $method ?: null );
        return true;
    }

    public static function ajax_confirm_redirect() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );

        $result = self::confirm_redirect_core(
            sanitize_text_field( $_POST['order_ref'] ?? '' ),
            sanitize_key( $_POST['gateway'] ?? '' ),
            sanitize_text_field( $_POST['token'] ?? '' ),
            sanitize_key( $_POST['source'] ?? 'button' )
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
        }
        wp_send_json_success();
    }

    /**
     * AUDIT QUALITÉ — COHÉRENCE AJAX/REST : chaque endpoint AJAX de ce fichier exige un
     * nonce via check_ajax_referer(). Leurs équivalents REST (ajoutés comme repli pour les
     * clients qui bloquent admin-ajax.php) ne le faisaient PAS — seule confirm_redirect
     * était protégée, via le jeton HMAC. mark_initiated/declare_payment/mark_returned en
     * REST n'avaient donc AUCUNE vérification de nonce, alors que leurs jumeaux AJAX si :
     * une incohérence exploitable (n'importe quel script pouvait appeler direction REST en
     * contournant la protection nonce prévue). Ce helper aligne les deux surfaces.
     */
    private static function verify_rest_nonce( WP_REST_Request $request ) {
        // Le JS (KMFamilyRequest, voir km-family.js) envoie le nonce d'action 'kmfamily_nonce'
        // dans le corps de la requête ('nonce'), pas dans l'en-tête X-WP-Nonce (qui porte un
        // nonce WordPress standard pour l'action 'wp_rest', différente — il ne validerait de
        // toute façon jamais ici, les nonces WP sont liés à une action précise).
        $nonce = sanitize_text_field( $request->get_param( 'nonce' ) ?: '' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'kmfamily_nonce' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Session invalide ou expirée — recharge la page.', 'km-family' ), array( 'status' => 403 ) );
        }
        return true;
    }

    public static function rest_confirm_redirect( WP_REST_Request $request ) {
        $nonce_check = self::verify_rest_nonce( $request );
        if ( is_wp_error( $nonce_check ) ) return $nonce_check;

        $result = self::confirm_redirect_core(
            sanitize_text_field( $request->get_param( 'order_ref' ) ?: '' ),
            sanitize_key( $request->get_param( 'gateway' ) ?: '' ),
            sanitize_text_field( $request->get_param( 'token' ) ?: '' ),
            sanitize_key( $request->get_param( 'source' ) ?: 'button' )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * ÉVÉNEMENT DEMANDÉ : "retour" — l'utilisateur revient sur l'onglet du navigateur
     * après être parti payer dans l'app Wave/Orange/MTN/Moov (détecté côté JS via
     * l'API Page Visibility, voir km-payment-center.js).
     */
    private static function mark_returned_core( $order_ref, $method ) {
        $order_ref = sanitize_text_field( $order_ref );
        $method    = sanitize_key( $method );

        $order = KMFamily_Orders::get( $order_ref );
        if ( ! self::can_access_order( $order ) ) {
            return new WP_Error( 'order_forbidden', __( 'Commande introuvable.', 'km-family' ), array( 'status' => 403 ) );
        }

        if ( class_exists( 'KMFamily_Payment_Tracking' ) ) {
            KMFamily_Payment_Tracking::record_returned( $order_ref, $method );
        }
        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, 'user_returned', array( 'method' => $method ), 'user:' . get_current_user_id() );
        }
        return true;
    }

    public static function ajax_mark_returned() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );
        $result = self::mark_returned_core( $_POST['order_ref'] ?? '', $_POST['gateway'] ?? '' );
        if ( is_wp_error( $result ) ) wp_send_json_error();
        wp_send_json_success();
    }

    public static function rest_mark_returned( WP_REST_Request $request ) {
        $nonce_check = self::verify_rest_nonce( $request );
        if ( is_wp_error( $nonce_check ) ) return $nonce_check;

        $result = self::mark_returned_core( $request->get_param( 'order_ref' ) ?: '', $request->get_param( 'gateway' ) ?: '' );
        if ( is_wp_error( $result ) ) return $result;
        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Équivalent REST de ajax_mark_initiated(), même logique d'autorisation (can_access_order).
     */
    public static function rest_mark_initiated( WP_REST_Request $request ) {
        $nonce_check = self::verify_rest_nonce( $request );
        if ( is_wp_error( $nonce_check ) ) return $nonce_check;

        $order_ref = sanitize_text_field( $request->get_param( 'order_ref' ) ?: '' );
        $gateway   = sanitize_key( $request->get_param( 'gateway' ) ?: '' );
        $device    = wp_is_mobile() ? 'mobile' : 'desktop';

        $order = KMFamily_Orders::get( $order_ref );
        if ( ! self::can_access_order( $order ) ) {
            return new WP_Error( 'order_forbidden', __( 'Commande introuvable.', 'km-family' ), array( 'status' => 403 ) );
        }

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, 'initiated_legacy', array( 'gateway' => $gateway, 'device' => $device ), 'user:' . get_current_user_id() );
        }
        KMFamily_Orders::mark_initiated( $order_ref, $device, $gateway ?: null );

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Équivalent REST de ajax_declare_payment(), même logique (rate-limit, autorisation, validation).
     */
    public static function rest_declare_payment( WP_REST_Request $request ) {
        $nonce_check = self::verify_rest_nonce( $request );
        if ( is_wp_error( $nonce_check ) ) return $nonce_check;

        if ( KMFamily_Security::is_rate_limited( 'declare_payment', 10, 15 * MINUTE_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', __( 'Trop de tentatives, réessaie dans quelques minutes.', 'km-family' ), array( 'status' => 429 ) );
        }

        $order_ref = sanitize_text_field( $request->get_param( 'order_ref' ) ?: '' );
        $reference = sanitize_text_field( $request->get_param( 'reference' ) ?: '' );
        $note      = sanitize_textarea_field( $request->get_param( 'note' ) ?: '' );

        if ( ! $reference ) {
            return new WP_Error( 'missing_reference', __( 'Merci de renseigner la référence de ta transaction.', 'km-family' ), array( 'status' => 400 ) );
        }

        $order = KMFamily_Orders::get( $order_ref );
        if ( ! self::can_access_order( $order ) ) {
            return new WP_Error( 'order_forbidden', __( 'Commande introuvable.', 'km-family' ), array( 'status' => 403 ) );
        }

        $result = KMFamily_Orders::declare_payment( $order_ref, $reference, $note );
        if ( is_wp_error( $result ) ) {
            return new WP_Error( 'declare_failed', $result->get_error_message(), array( 'status' => 500 ) );
        }

        self::log_declaration_and_score( $order_ref );

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Journalise la déclaration de paiement dans le journal chaîné, puis fait tourner
     * le moteur de risque et range le résultat dans les métadonnées de la commande
     * (visible ensuite dans Abonnements > Commandes en attente + le tableau de bord
     * Sécurité Paiement). Appelée après TOUTE déclaration réussie (AJAX ou REST).
     */
    private static function log_declaration_and_score( $order_ref ) {
        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, 'payment_declared', array(), 'user:' . get_current_user_id() );
        }

        $order = KMFamily_Orders::get( $order_ref );
        if ( $order && class_exists( 'KMFamily_Risk_Engine' ) ) {
            $risk = KMFamily_Risk_Engine::compute_score( $order );
            KMFamily_Orders::update_meta( $order_ref, 'risk', $risk );

            if ( $risk['level'] === KMFamily_Risk_Engine::LEVEL_CRITICAL && class_exists( 'KMFamily_Event_Log' ) ) {
                KMFamily_Event_Log::log( $order_ref, 'risk_alert_critical', $risk, 'system' );
            }
        }
    }

    /**
     * Même logique que ajax_create_order(), adaptée à l'objet WP_REST_Request
     * (pas de nonce admin-ajax ici : l'authentification REST passe par X-WP-Nonce,
     * déjà vérifiée par WordPress via le permission_callback + le cookie nonce standard).
     */
    public static function rest_create_order( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        $artiste_id     = absint( $request->get_param( 'artiste_id' ) );
        $palier         = sanitize_key( $request->get_param( 'palier' ) );
        $periodicity    = sanitize_key( $request->get_param( 'periodicity' ) ?: 'monthly' );
        $client_montant = absint( $request->get_param( 'montant' ) );

        if ( ! $artiste_id || ! $palier || ! get_the_title( $artiste_id ) ) {
            return new WP_Error( 'invalid_order', __( 'Artiste ou palier invalide.', 'km-family' ), array( 'status' => 400 ) );
        }

        $montant = self::compute_amount( $palier, $periodicity, $client_montant );
        if ( ! $montant ) {
            return new WP_Error( 'invalid_amount', __( 'Montant invalide.', 'km-family' ), array( 'status' => 400 ) );
        }

        $order_ref = KMFamily_Orders::create( array(
            'user_id'     => $user_id,
            'artiste_id'  => $artiste_id,
            'palier'      => $palier,
            'periodicity' => $periodicity,
            'montant'     => $montant,
        ) );

        if ( is_wp_error( $order_ref ) ) {
            return new WP_Error( 'order_failed', $order_ref->get_error_message(), array( 'status' => 500 ) );
        }

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'order_ref'    => $order_ref,
                'redirect_url' => home_url( '/km-payment/' . $order_ref . '/' ),
            ),
        ), 200 );
    }

    public static function register_rewrite_rule() {
        add_rewrite_rule(
            '^km-payment/([^/]+)/?$',
            'index.php?kmfamily_order_ref=$matches[1]',
            'top'
        );

        // MESURE ANTI-FRAUDE / TRACKING : route dédiée pour le QR Code dynamique. Le QR
        // n'encode plus le lien marchand brut mais CETTE url, propre à un tracking_token
        // unique (voir KMFamily_Payment_Tracking) — un simple hit sur cette route suffit à
        // enregistrer un scan/clic et à faire progresser la commande, avant un aller
        // immédiat (302) vers le vrai lien marchand.
        add_rewrite_rule(
            '^km-pay/([^/]+)/?$',
            'index.php?kmfamily_tracking_token=$matches[1]',
            'top'
        );

    }

    /**
     * BUGFIX : ces règles ont été ajoutées après la première activation du plugin (Smart
     * Payment Center, v2.2.0). register_activation_hook() ne se redéclenche PAS sur une
     * simple mise à jour de fichiers — donc la règle restait absente du cache
     * "rewrite_rules" en base, et /km-payment/{ref}/ renvoyait un 404 malgré un code
     * correct. On flush une seule fois à chaque changement de version du plugin.
     *
     * Exécuté en priorité 99 sur 'init' (voir init()) pour que les règles de TOUS les
     * modules — streaming média inclus — soient déjà déclarées au moment du flush.
     */
    public static function maybe_flush_rewrite_rules() {
        if ( get_option( 'kmfamily_rewrite_version' ) !== KMFAMILY_VERSION ) {
            flush_rewrite_rules( false );
            update_option( 'kmfamily_rewrite_version', KMFAMILY_VERSION );
        }
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'kmfamily_order_ref';
        $vars[] = 'kmfamily_tracking_token';
        return $vars;
    }

    /**
     * Point d'entrée du QR Code dynamique. Toute requête sur /km-pay/{tracking_token}/
     * passe par ici AVANT le chargement d'un template — on journalise, on met à jour le
     * tracking, puis on redirige (302) vers le vrai lien marchand récupéré côté serveur
     * (jamais fait confiance à une URL fournie par le client).
     */
    public static function handle_tracking_redirect() {
        $token = get_query_var( 'kmfamily_tracking_token' );
        if ( ! $token ) return;

        // AUDIT DE ROBUSTESSE : cette route DOIT toujours ré-exécuter le PHP (comptage de
        // scan_count, journalisation) — jamais être servie depuis un cache de page (WP
        // Rocket/LiteSpeed) qui figerait le comptage au premier scan et casserait la
        // redirection dès qu'un jeton expire. Même précaution que guard_payment_center_access().
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();

        $token = sanitize_text_field( $token );
        $row   = class_exists( 'KMFamily_Payment_Tracking' ) ? KMFamily_Payment_Tracking::get_by_token( $token ) : null;

        if ( ! $row ) {
            wp_die(
                esc_html__( "Ce lien de paiement n'est plus valide. Retourne sur la page de ta commande et réessaie.", 'km-family' ),
                esc_html__( 'Lien invalide', 'km-family' ),
                array( 'response' => 404 )
            );
        }

        if ( KMFamily_Payment_Tracking::is_expired( $row ) ) {
            $back = home_url( '/km-payment/' . rawurlencode( $row['order_ref'] ) . '/' );
            wp_die(
                sprintf(
                    /* translators: %s: lien de retour vers la page de paiement */
                    wp_kses( __( 'Ce QR Code a expiré (il est à usage limité dans le temps, par sécurité). <a href="%s">Retourne sur la page de paiement</a> pour en générer un nouveau.', 'km-family' ), array( 'a' => array( 'href' => array() ) ) ),
                    esc_url( $back )
                ),
                esc_html__( 'QR Code expiré', 'km-family' ),
                array( 'response' => 410 )
            );
        }

        $order_ref = $row['order_ref'];
        $method    = $row['method'];

        KMFamily_Payment_Tracking::record_scan( $token );

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, 'qr_scanned', array( 'method' => $method, 'tracking_token' => $token ), 'anonymous' );
            KMFamily_Event_Log::log( $order_ref, 'redirect_initiated', array( 'method' => $method, 'via' => 'qr' ), 'anonymous' );
        }

        KMFamily_Orders::mark_initiated( $order_ref, wp_is_mobile() ? 'mobile' : 'desktop', $method ?: null );

        $merchant_link = KMFamily_Payment_Methods::get_merchant_link( $method );
        if ( ! $merchant_link ) {
            wp_die( esc_html__( 'Moyen de paiement momentanément indisponible.', 'km-family' ), '', array( 'response' => 500 ) );
        }

        wp_redirect( esc_url_raw( $merchant_link ), 302 );
        exit;
    }

    public static function enqueue_assets() {
        // Uniquement sur la page du centre de paiement, pour ne pas alourdir le reste du site.
        if ( ! get_query_var( 'kmfamily_order_ref' ) ) return;

        wp_enqueue_style( 'kmfamily-payment-center', KMFAMILY_URL . 'assets/css/km-payment-center.css', array( 'km-family' ), KMFAMILY_VERSION );

        // Générateur de QR code 100% client-side, sans dépendance à une API externe
        // (fiabilité : fonctionne même si un service tiers de génération de QR tombe).
        wp_enqueue_script( 'kmfamily-qrcode-vendor', KMFAMILY_URL . 'assets/js/vendor/qrcode.min.js', array(), '1.0.0', true );
        wp_enqueue_script( 'kmfamily-payment-center', KMFAMILY_URL . 'assets/js/km-payment-center.js', array( 'jquery', 'km-family', 'kmfamily-qrcode-vendor' ), KMFAMILY_VERSION, true );

        wp_localize_script( 'kmfamily-payment-center', 'KMFamilyPayment', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'kmfamily_nonce' ),
            'is_mobile' => wp_is_mobile(),
            'dashboard_url' => ( $p = get_option( 'kmfamily_page_dashboard' ) ) ? get_permalink( $p ) : home_url( '/' ),
            'i18n'     => array(
                'copied'          => __( 'Lien copié !', 'km-family' ),
                'copy_failed'     => __( 'Impossible de copier — sélectionne et copie manuellement.', 'km-family' ),
                'checking'        => __( 'Vérification en cours…', 'km-family' ),
                'confirmed'       => __( 'Paiement confirmé ! Ton abonnement est actif 🎉', 'km-family' ),
                'declare_error'   => __( 'Merci de renseigner la référence de ta transaction.', 'km-family' ),
                'declare_success' => __( 'Merci ! Ton paiement est en cours de vérification, tu recevras une confirmation sous peu.', 'km-family' ),
            ),
        ) );
    }

    /**
     * Recalcule le montant côté serveur à partir du palier + périodicité — ne fait jamais
     * confiance au montant envoyé par le client (sauf palier "Libre" où l'utilisateur choisit).
     */
    private static function compute_amount( $palier, $periodicity, $client_montant ) {
        $data = KMFamily_Paliers::get( $palier );
        if ( ! $data ) return 0;

        if ( $palier === 'libre' ) {
            $min = $data['prix_min'] ?? 500;
            return max( $min, absint( $client_montant ) );
        }

        $base = absint( $data['prix'] );
        if ( class_exists( 'KMFamily_Periodicity' ) && KMFamily_Periodicity::exists( $periodicity ) ) {
            $computed = KMFamily_Periodicity::calculate_price( $base, $periodicity );
            return absint( $computed['total'] ?? $base );
        }
        return $base;
    }

    public static function ajax_create_order() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );

        if ( KMFamily_Security::is_rate_limited( 'create_order', 8, 15 * MINUTE_IN_SECONDS ) ) {
            KMFamily_Security::rate_limit_response();
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( array(
                'message'      => __( 'Vous devez créer un compte pour rejoindre la KM Family.', 'km-family' ),
                'register_url' => self::get_auth_url(),
                'login_url'    => self::get_auth_url(),
            ) );
        }

        $artiste_id  = absint( $_POST['artiste_id'] ?? 0 );
        $palier      = sanitize_key( $_POST['palier'] ?? '' );
        $periodicity = sanitize_key( $_POST['periodicity'] ?? 'monthly' );
        $client_montant = absint( $_POST['montant'] ?? 0 );

        if ( ! $artiste_id || ! $palier || ! get_the_title( $artiste_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Artiste ou palier invalide.', 'km-family' ) ) );
        }

        $montant = self::compute_amount( $palier, $periodicity, $client_montant );
        if ( ! $montant ) {
            wp_send_json_error( array( 'message' => __( 'Montant invalide.', 'km-family' ) ) );
        }

        $order_ref = KMFamily_Orders::create( array(
            'user_id'     => $user_id,
            'artiste_id'  => $artiste_id,
            'palier'      => $palier,
            'periodicity' => $periodicity,
            'montant'     => $montant,
        ) );

        if ( is_wp_error( $order_ref ) ) {
            wp_send_json_error( array( 'message' => $order_ref->get_error_message() ) );
        }

        wp_send_json_success( array(
            'order_ref'    => $order_ref,
            'redirect_url' => home_url( '/km-payment/' . $order_ref . '/' ),
        ) );
    }

    /**
     * BUGFIX MAJEUR : "Vous devez créer un compte" renvoyait vers wp_registration_url() /
     * wp_login_url() — les pages WordPress natives génériques, sans rapport avec le formulaire
     * KM Family personnalisé (auto-connexion, redirection contextuelle...). Pire : si
     * l'inscription native WordPress est désactivée sur le site (cas fréquent, réglage
     * "Settings > General > Membership"), ce lien menait à une impasse — la KM Family a sa
     * propre inscription (ajax_register) qui ne dépend pas de ce réglage.
     * On pointe désormais vers la page KM Family dédiée (kmfamily_page_login), qui gère elle-même
     * l'onglet connexion/inscription.
     */
    private static function get_auth_url() {
        $login_page = get_option( 'kmfamily_page_login' );
        return $login_page ? get_permalink( $login_page ) : wp_login_url();
    }

    public static function ajax_create_order_guest() {
        wp_send_json_error( array(
            'message'      => __( 'Vous devez créer un compte pour rejoindre la KM Family.', 'km-family' ),
            'register_url' => self::get_auth_url(),
            'login_url'    => self::get_auth_url(),
        ) );
    }

    /**
     * BUGFIX MAJEUR : l'usage prévu du QR est justement de payer depuis un AUTRE appareil que
     * celui où la commande a été créée (typiquement : ordinateur pour choisir le palier, puis
     * téléphone — qui a l'app Wave/Orange Money — pour scanner et payer). Ce téléphone n'a PAS
     * la session WordPress du compte : is_user_logged_in() y est toujours faux. L'ancien code
     * redirigeait alors systématiquement vers la page de connexion, empêchant complètement le
     * scan de mener au paiement marchand — exactement le bug remonté ("le QR ne conduit pas au
     * paiement marchand").
     * La order_ref (10 caractères aléatoires, générée côté serveur) joue déjà le rôle de jeton
     * d'accès secret pour CETTE commande précise : la connaître suffit pour consulter/payer
     * cette commande sur un appareil non connecté, sans exposer les commandes des autres
     * membres. On ne bloque que si un membre EST connecté sur cet appareil et que ce n'est pas
     * le sien (cf. can_access_order()).
     */
    private static function can_access_order( $order ) {
        if ( ! $order ) return false;
        $user_id = get_current_user_id();
        if ( ! $user_id ) return true; // Appareil invité : la order_ref fait office d'autorisation.
        return KMFamily_Orders::user_owns_order( $order, $user_id );
    }

    public static function ajax_order_status() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );

        $order_ref = sanitize_text_field( $_POST['order_ref'] ?? '' );
        $order = KMFamily_Orders::get( $order_ref );

        if ( ! self::can_access_order( $order ) ) {
            wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'km-family' ) ) );
        }

        wp_send_json_success( array(
            'status' => $order['status'],
            'stage'  => KMFamily_Orders::get_display_stage( $order['status'] ),
        ) );
    }

    public static function ajax_mark_initiated() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );

        $order_ref = sanitize_text_field( $_POST['order_ref'] ?? '' );
        $gateway   = sanitize_key( $_POST['gateway'] ?? '' );
        $device    = wp_is_mobile() ? 'mobile' : 'desktop';

        $order = KMFamily_Orders::get( $order_ref );
        if ( ! self::can_access_order( $order ) ) {
            wp_send_json_error();
        }

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, 'initiated_legacy', array( 'gateway' => $gateway, 'device' => $device ), 'user:' . get_current_user_id() );
        }
        KMFamily_Orders::mark_initiated( $order_ref, $device, $gateway ?: null );
        wp_send_json_success();
    }

    public static function ajax_declare_payment() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );

        if ( KMFamily_Security::is_rate_limited( 'declare_payment', 10, 15 * MINUTE_IN_SECONDS ) ) {
            KMFamily_Security::rate_limit_response();
        }

        $order_ref = sanitize_text_field( $_POST['order_ref'] ?? '' );
        $reference = sanitize_text_field( $_POST['reference'] ?? '' );
        $note      = sanitize_textarea_field( $_POST['note'] ?? '' );

        if ( ! $reference ) {
            wp_send_json_error( array( 'message' => __( 'Merci de renseigner la référence de ta transaction.', 'km-family' ) ) );
        }

        $order = KMFamily_Orders::get( $order_ref );
        if ( ! self::can_access_order( $order ) ) {
            wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'km-family' ) ) );
        }

        $result = KMFamily_Orders::declare_payment( $order_ref, $reference, $note );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        self::log_declaration_and_score( $order_ref );

        wp_send_json_success();
    }

    /**
     * Confirmation manuelle par l'admin (moyens de paiement sans webhook).
     */
    public static function handle_admin_confirm_order() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_confirm_order', 'kmfamily_nonce' ) ) {
            wp_die( 'Accès refusé' );
        }

        $order_ref = sanitize_text_field( $_POST['order_ref'] ?? '' );

        // LE RISK ENGINE INFLUENCE RÉELLEMENT LA DÉCISION : pour une commande au score
        // critique, la confirmation en un clic est bloquée côté SERVEUR (la case à cocher
        // affichée dans l'admin n'est qu'un confort visuel — l'attribut HTML "required" seul
        // ne protège de rien, un formulaire peut toujours être soumis autrement). L'admin
        // reste décisionnaire : il peut passer outre, mais explicitement et de façon tracée.
        $order = KMFamily_Orders::get( $order_ref );

        /**
         * MESURE ANTI-FRAUDE v3.4.0 — DERNIER CONTRÔLE AVANT ACTIVATION.
         * Le refus des références en double s'applique au moment de la déclaration. Mais
         * entre cette déclaration et le clic de l'administrateur, il peut s'écouler des
         * heures : deux commandes déclarées à quelques secondes d'écart avec le même reçu
         * passent toutes deux le contrôle initial (aucune n'est encore confirmée), et rien
         * n'empêchait ensuite de les confirmer l'une après l'autre. On revérifie donc ici,
         * juste avant l'effet irréversible. Un administrateur peut passer outre — c'est sa
         * décision — mais il doit la prendre en connaissance de cause.
         */
        if ( $order && ! empty( $order['proof_reference'] ) && empty( $_POST['override_risk'] ) ) {
            $conflit = KMFamily_Orders::find_reference_conflict( $order['proof_reference'], $order_ref );
            if ( $conflit ) {
                if ( class_exists( 'KMFamily_Event_Log' ) ) {
                    KMFamily_Event_Log::log( $order_ref, 'admin_confirm_blocked_duplicate_reference', array(
                        'reference'       => $order['proof_reference'],
                        'commande_source' => $conflit,
                    ), 'admin:' . get_current_user_id() );
                }
                wp_safe_redirect( add_query_arg(
                    array( 'kmfamily_order_duplicate' => 1 ),
                    admin_url( 'admin.php?page=kmfamily-subscriptions' )
                ) );
                exit;
            }
        }

        if ( $order && class_exists( 'KMFamily_Risk_Engine' ) ) {
            $risk = KMFamily_Risk_Engine::compute_score( $order );
            if ( $risk['level'] === KMFamily_Risk_Engine::LEVEL_CRITICAL && empty( $_POST['override_risk'] ) ) {
                if ( class_exists( 'KMFamily_Event_Log' ) ) {
                    KMFamily_Event_Log::log( $order_ref, 'admin_confirm_blocked_risk', $risk, 'admin:' . get_current_user_id() );
                }
                wp_safe_redirect( add_query_arg( 'kmfamily_order_risk_blocked', 1, admin_url( 'admin.php?page=kmfamily-subscriptions' ) ) );
                exit;
            }

            if ( $risk['level'] === KMFamily_Risk_Engine::LEVEL_CRITICAL && class_exists( 'KMFamily_Event_Log' ) ) {
                KMFamily_Event_Log::log( $order_ref, 'admin_confirm_risk_override', $risk, 'admin:' . get_current_user_id() );
            }
        }

        $result = KMFamily_Orders::confirm_and_activate( $order_ref, array(
            'admin_note' => sanitize_textarea_field( $_POST['admin_note'] ?? '' ),
        ) );

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, is_wp_error( $result ) ? 'admin_confirm_failed' : 'admin_confirmed', array(), 'admin:' . get_current_user_id() );
        }
        if ( ! is_wp_error( $result ) && class_exists( 'KMFamily_Payment_Tracking' ) ) {
            KMFamily_Payment_Tracking::record_confirmed( $order_ref );
        }

        $redirect = admin_url( 'admin.php?page=kmfamily-subscriptions' );
        $redirect = add_query_arg( is_wp_error( $result ) ? 'kmfamily_order_error' : 'kmfamily_order_confirmed', 1, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Rejet manuel par l'admin (référence introuvable, montant ne correspondant pas,
     * doublon, tentative frauduleuse...). Miroir de handle_admin_confirm_order() —
     * comblait un vrai manque : aucune action n'existait jusqu'ici pour refuser une
     * déclaration de paiement erronée sans devoir soit l'activer à tort, soit la
     * laisser bloquée indéfiniment dans la liste d'attente.
     */
    public static function handle_admin_reject_order() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_reject_order', 'kmfamily_nonce' ) ) {
            wp_die( 'Accès refusé' );
        }

        $order_ref = sanitize_text_field( $_POST['order_ref'] ?? '' );
        $result = KMFamily_Orders::reject( $order_ref, sanitize_textarea_field( $_POST['admin_note'] ?? '' ) );

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order_ref, is_wp_error( $result ) ? 'admin_reject_failed' : 'admin_rejected', array(), 'admin:' . get_current_user_id() );
        }
        if ( ! is_wp_error( $result ) && class_exists( 'KMFamily_Payment_Tracking' ) ) {
            KMFamily_Payment_Tracking::record_rejected( $order_ref );
        }

        $redirect = admin_url( 'admin.php?page=kmfamily-subscriptions' );
        $redirect = add_query_arg( is_wp_error( $result ) ? 'kmfamily_order_error' : 'kmfamily_order_rejected', 1, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Vérifs d'accès (auth, ownership) avant que le template ne s'affiche.
     * Doit tourner sur template_redirect (avant tout output) pour pouvoir rediriger.
     */
    public static function guard_payment_center_access() {
        $order_ref = get_query_var( 'kmfamily_order_ref' );
        if ( ! $order_ref ) return;

        $order_ref = sanitize_text_field( $order_ref );
        $order = KMFamily_Orders::get( $order_ref );

        // BUGFIX : nocache_headers() suffit pour les navigateurs/proxys, mais pas toujours
        // pour les plugins de cache de page complète (WP Rocket, LiteSpeed Cache, W3TC...),
        // qui génèrent souvent le HTML en amont sans tenir compte des en-têtes de réponse.
        // Cette page contient un jeton de sécurité (nonce) propre à chaque visite : si elle
        // est mise en cache, tous les visiteurs suivants héritent d'un jeton périmé et le
        // paiement échoue avec un message d'erreur générique — cas observé notamment pour
        // les visiteurs mobiles arrivant "à froid" via un lien partagé ou un QR code.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        nocache_headers();

        if ( ! $order ) return; // 404 naturel du thème, rien à protéger.

        // BUGFIX : on ne force plus la connexion pour un appareil invité (ex. le téléphone qui
        // scanne le QR) — voir can_access_order(). On ne bloque que le cas où un AUTRE membre
        // est connecté sur cet appareil et consulte la commande de quelqu'un d'autre.
        if ( is_user_logged_in() && ! KMFamily_Orders::user_owns_order( $order, get_current_user_id() ) ) {
            wp_safe_redirect( home_url( '/' ) );
            exit;
        }
    }

    public static function load_payment_center_template( $template ) {
        if ( ! get_query_var( 'kmfamily_order_ref' ) ) return $template;

        $theme_template = locate_template( 'payment-center.php' );
        return $theme_template ?: KMFAMILY_PATH . 'templates/payment-center.php';
    }
}
