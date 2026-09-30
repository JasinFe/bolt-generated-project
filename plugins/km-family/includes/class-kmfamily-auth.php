<?php
/**
 * Classe Auth : page de connexion moderne KM Family
 *
 * Gère :
 * - Connexion
 * - Inscription rapide
 * - Mot de passe oublié
 * - Changement de mot de passe (depuis le dashboard)
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Auth {

    public static function init() {
        // AJAX handlers
        add_action( 'wp_ajax_nopriv_kmfamily_login',         array( __CLASS__, 'ajax_login' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_register',      array( __CLASS__, 'ajax_register' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_forgot',        array( __CLASS__, 'ajax_forgot_password' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_reset_password', array( __CLASS__, 'ajax_reset_password' ) );
        add_action( 'wp_ajax_kmfamily_change_password',      array( __CLASS__, 'ajax_change_password' ) );
        add_action( 'wp_ajax_kmfamily_change_palier',        array( __CLASS__, 'ajax_change_palier' ) );

        // REST routes alternatives (contournent les WAF bloquant admin-ajax.php)
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // Redirection après login vers l'espace membre
        add_filter( 'login_redirect', array( __CLASS__, 'custom_login_redirect' ), 10, 3 );

        /**
         * Filtres globaux : partout dans le site (thème, widgets, autres plugins) où du code
         * appelle wp_login_url() / wp_registration_url() / wp_lostpassword_url() pour générer
         * un lien "se connecter" / "créer un compte" / "mot de passe oublié", on renvoie vers
         * la page KM Family dédiée plutôt que vers wp-login.php par défaut. On préserve
         * volontairement l'accès administrateur : si le lien sert à revenir vers /wp-admin/
         * (session expirée pendant une visite de l'admin, par ex.), on laisse WordPress faire
         * son travail normal — sinon un admin dont la session expire se retrouverait bloqué
         * sur une page membre au lieu de sa page de connexion habituelle.
         */
        add_filter( 'login_url',        array( __CLASS__, 'filter_login_url' ), 10, 3 );
        add_filter( 'register_url',     array( __CLASS__, 'filter_register_url' ) );
        add_filter( 'lostpassword_url', array( __CLASS__, 'filter_lostpassword_url' ), 10, 2 );

        // Rewrite pour /espace-membre/reset-password/?key=...&login=...
        add_action( 'init', array( __CLASS__, 'maybe_handle_reset_password' ) );

        // Déjà connecté(e) sur la page de connexion : on reprend le parcours (next) — ou le
        // dashboard — AVANT tout affichage. Faire ce redirect depuis le shortcode était trop
        // tard (en-têtes déjà envoyés) et ignorait de toute façon le paramètre "next".
        add_action( 'template_redirect', array( __CLASS__, 'redirect_logged_in_from_login_page' ), 5 );
    }

    /**
     * Visiteur déjà connecté qui ouvre la page de connexion (ex. lien direct de soutien
     * ouvert dans un navigateur où la session est encore active) : renvoi immédiat vers
     * la destination demandée, sinon vers l'espace membre.
     */
    public static function redirect_logged_in_from_login_page() {
        if ( ! is_user_logged_in() || is_admin() ) return;

        $login_page = (int) get_option( 'kmfamily_page_login' );
        if ( ! $login_page || ! is_page( $login_page ) ) return;

        // Ne pas gêner l'édition / l'aperçu (Elementor, Customizer) ni le lien de reset.
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['preview'] ) || is_customize_preview() ) return;
        if ( isset( $_GET['action'] ) && sanitize_key( wp_unslash( $_GET['action'] ) ) === 'rp' ) return;

        nocache_headers();
        wp_safe_redirect( self::get_post_login_redirect( isset( $_GET['next'] ) ? wp_unslash( $_GET['next'] ) : '' ) );
        exit;
    }

    /**
     * Destination après connexion : "next" validé, sinon l'espace membre.
     */
    public static function get_post_login_redirect( $next = '' ) {
        $dashboard = get_option( 'kmfamily_page_dashboard' );
        $fallback  = $dashboard ? get_permalink( $dashboard ) : home_url( '/' );
        return KMFamily_Direct_Link::safe_next( $next, $fallback );
    }

    private static function targets_wp_admin( $redirect_to ) {
        return is_admin() || ( $redirect_to && strpos( $redirect_to, 'wp-admin' ) !== false );
    }

    public static function filter_login_url( $login_url, $redirect, $force_reauth ) {
        if ( self::targets_wp_admin( $redirect ) ) return $login_url;

        $page = get_option( 'kmfamily_page_login' );
        if ( ! $page ) return $login_url;

        $url = get_permalink( $page );
        if ( $redirect ) $url = add_query_arg( 'next', rawurlencode( $redirect ), $url );
        return $url;
    }

    public static function filter_register_url( $register_url ) {
        if ( self::targets_wp_admin( '' ) ) return $register_url;

        $page = get_option( 'kmfamily_page_login' );
        return $page ? add_query_arg( 'tab', 'register', get_permalink( $page ) ) : $register_url;
    }

    public static function filter_lostpassword_url( $lostpassword_url, $redirect ) {
        if ( self::targets_wp_admin( $redirect ) ) return $lostpassword_url;

        $page = get_option( 'kmfamily_page_login' );
        return $page ? add_query_arg( 'tab', 'forgot', get_permalink( $page ) ) : $lostpassword_url;
    }

    /**
     * REST routes alternatives (bypass WAF)
     */
    public static function register_rest_routes() {
        register_rest_route( 'kmfamily/v1', '/auth/login', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wrap_login' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'kmfamily/v1', '/auth/register', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wrap_register' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'kmfamily/v1', '/auth/forgot', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wrap_forgot' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'kmfamily/v1', '/auth/reset-password', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wrap_reset_password' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'kmfamily/v1', '/auth/change-password', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wrap_change_password' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );
        register_rest_route( 'kmfamily/v1', '/auth/change-palier', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wrap_change_palier' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );
    }

    /**
     * CORRECTIF SÉCURITÉ v3.3.1 — LE NONCE ÉTAIT FABRIQUÉ CÔTÉ SERVEUR.
     *
     * Ces routes REST servent de repli quand admin-ajax.php est bloqué par le WAF de
     * l'hébergeur. Elles réinjectaient dans $_POST un nonce fraîchement généré par le
     * serveur lui-même (`$_POST['nonce'] = wp_create_nonce(...)`) AVANT d'appeler le
     * handler AJAX : la vérification faite ensuite par ce handler portait donc sur un
     * jeton que le serveur venait d'écrire, et ne prouvait plus rien. Toute la protection
     * anti-rejeu / anti-CSRF des handlers d'authentification était neutralisée dès lors
     * qu'on passait par le chemin REST — qu'il suffisait d'appeler directement.
     *
     * On vérifie désormais un VRAI nonce fourni par le client : celui envoyé dans le corps
     * de la requête par KMFamilyRequest (action 'kmfamily_auth_nonce'), ou, à défaut, le
     * nonce REST standard de WordPress (en-tête X-WP-Nonce, action 'wp_rest') que le même
     * code JS transmet déjà. Le repli anti-WAF continue donc de fonctionner à l'identique
     * pour le front-end légitime, mais un appel forgé est rejeté.
     */
    private static function verify_request_nonce( WP_REST_Request $request ) {
        $body_nonce = $request->get_param( 'nonce' );
        if ( is_string( $body_nonce ) && $body_nonce && wp_verify_nonce( $body_nonce, 'kmfamily_auth_nonce' ) ) {
            return true;
        }

        $rest_nonce = $request->get_header( 'x_wp_nonce' );
        if ( is_string( $rest_nonce ) && $rest_nonce && wp_verify_nonce( $rest_nonce, 'wp_rest' ) ) {
            return true;
        }

        return false;
    }

    private static function rest_wrap( $method, $request ) {
        if ( ! self::verify_request_nonce( $request ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'data'    => array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ),
            ), 403 );
        }

        $_POST = array_merge( $_POST, $request->get_params() );

        // Le handler AJAX appelé ci-dessous revérifie ce nonce : on lui transmet celui que
        // le client a réellement envoyé (déjà validé ci-dessus), jamais un nonce fabriqué.
        // Si le client s'est authentifié via X-WP-Nonce seulement, on aligne la clé attendue.
        $body_nonce = $request->get_param( 'nonce' );
        if ( ! is_string( $body_nonce ) || ! $body_nonce || ! wp_verify_nonce( $body_nonce, 'kmfamily_auth_nonce' ) ) {
            $_POST['nonce'] = wp_create_nonce( 'kmfamily_auth_nonce' );
        } else {
            $_POST['nonce'] = $body_nonce;
        }

        ob_start();
        call_user_func( array( __CLASS__, $method ) );
        $output = ob_get_clean();
        $data = json_decode( $output, true );

        if ( $data && isset( $data['success'] ) ) {
            return new WP_REST_Response( $data, $data['success'] ? 200 : 400 );
        }
        return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'Erreur inconnue.' ) ), 500 );
    }

    public static function rest_wrap_login( $r )            { return self::rest_wrap( 'ajax_login', $r ); }
    public static function rest_wrap_register( $r )         { return self::rest_wrap( 'ajax_register', $r ); }
    public static function rest_wrap_forgot( $r )           { return self::rest_wrap( 'ajax_forgot_password', $r ); }
    public static function rest_wrap_reset_password( $r )   { return self::rest_wrap( 'ajax_reset_password', $r ); }
    public static function rest_wrap_change_password( $r )  { return self::rest_wrap( 'ajax_change_password', $r ); }
    public static function rest_wrap_change_palier( $r )    { return self::rest_wrap( 'ajax_change_palier', $r ); }

    /**
     * AJAX login
     */
    public static function ajax_login() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        $login    = sanitize_text_field( $_POST['login'] ?? '' );
        $password = $_POST['password'] ?? '';
        $remember = ! empty( $_POST['remember'] );

        if ( ! $login || ! $password ) {
            wp_send_json_error( array( 'message' => __( 'Tous les champs sont obligatoires.', 'km-family' ) ) );
        }

        // Anti-force-brute sur deux axes : strict par compte visé, large par source.
        // Voir KMFamily_Security::login_attempt_blocked() — l'ancien plafond unique de
        // 8 tentatives par adresse IP bloquait tous les abonnés d'un même opérateur
        // mobile, qui partagent la même adresse publique.
        if ( KMFamily_Security::login_attempt_blocked( $login ) ) {
            KMFamily_Security::rate_limit_response();
        }

        $creds = array(
            'user_login'    => $login,
            'user_password' => $password,
            'remember'      => $remember,
        );

        $user = wp_signon( $creds, is_ssl() );

        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array( 'message' => __( 'Identifiants incorrects. Vérifiez votre email et mot de passe.', 'km-family' ) ) );
        }

        // Retour vers la page de soutien d'origine (paramètre "next"), validé côté serveur.
        $redirect = self::get_post_login_redirect( isset( $_POST['next'] ) ? wp_unslash( $_POST['next'] ) : '' );

        wp_send_json_success( array(
            'message'  => __( 'Connexion réussie !', 'km-family' ),
            'redirect' => $redirect,
        ) );
    }

    /**
     * AJAX register
     */
    public static function ajax_register() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        // Anti-spam : ce endpoint n'avait aucune limite de tentatives.
        if ( KMFamily_Security::is_rate_limited( 'register', 5, 15 * MINUTE_IN_SECONDS ) ) {
            KMFamily_Security::rate_limit_response();
        }

        $email     = sanitize_email( $_POST['email'] ?? '' );
        $full_name = sanitize_text_field( $_POST['full_name'] ?? '' );
        $password  = $_POST['password'] ?? '';

        if ( ! is_email( $email ) || ! $full_name || strlen( $password ) < 8 ) {
            wp_send_json_error( array( 'message' => __( 'Veuillez remplir tous les champs. Le mot de passe doit contenir au moins 8 caractères.', 'km-family' ) ) );
        }

        if ( email_exists( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Cet email est déjà enregistré. Connectez-vous plutôt.', 'km-family' ) ) );
        }

        // Username = partie avant le @ + nombre aléatoire si conflit
        $username = sanitize_user( strtolower( explode( '@', $email )[0] ), true );
        $base_username = $username;
        $i = 1;
        while ( username_exists( $username ) ) {
            $username = $base_username . $i;
            $i++;
        }

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $full_name,
            'first_name'   => $full_name,
            'role'         => 'subscriber',
        ) );

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        }

        // Auto-login
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );

        // BUGFIX (suite) : reprendre l'abonnement en cours plutôt que renvoyer systématiquement
        // vers la liste générale des artistes — voir le commentaire équivalent côté JS
        // (assets/js/km-family.js, handleSubscribeSuccess) qui fournit ce paramètre "next".
        // Sans parcours en cours : liste des artistes ; sinon retour vers le soutien visé.
        $paliers_page = get_option( 'kmfamily_page_paliers' );
        $fallback     = $paliers_page ? get_permalink( $paliers_page ) : home_url( '/' );
        $redirect     = KMFamily_Direct_Link::safe_next( isset( $_POST['next'] ) ? wp_unslash( $_POST['next'] ) : '', $fallback );

        wp_send_json_success( array(
            'message'  => __( 'Compte créé avec succès !', 'km-family' ),
            'redirect' => $redirect,
        ) );
    }

    /**
     * AJAX mot de passe oublié
     */
    public static function ajax_forgot_password() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        // CORRECTIF v3.3.1 : contrairement à ajax_login() et ajax_register(), ce point
        // d'entrée n'avait AUCUNE limite. Il envoie pourtant un e-mail à chaque appel :
        // en boucle, il permettait de saturer la boîte d'un membre (harcèlement), de
        // brûler le quota d'envoi du site — et donc de faire classer le domaine comme
        // source de spam, ce qui coupe aussi les e-mails de confirmation de paiement.
        if ( KMFamily_Security::is_rate_limited( 'forgot', 5, 15 * MINUTE_IN_SECONDS ) ) {
            KMFamily_Security::rate_limit_response();
        }

        $email = sanitize_email( $_POST['email'] ?? '' );
        if ( ! is_email( $email ) ) {
            wp_send_json_error( array( 'message' => __( 'Email invalide.', 'km-family' ) ) );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            // Ne pas révéler si l'email existe (sécurité)
            wp_send_json_success( array( 'message' => __( 'Si un compte existe avec cet email, un lien de réinitialisation a été envoyé.', 'km-family' ) ) );
        }

        // Générer la clé de reset
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            wp_send_json_error( array( 'message' => __( 'Erreur lors de la génération du lien.', 'km-family' ) ) );
        }

        // Construire l'URL de reset (pointe vers la page de connexion)
        $login_page = get_option( 'kmfamily_page_login' );
        $reset_url  = $login_page
            ? add_query_arg( array( 'action' => 'rp', 'key' => $key, 'login' => rawurlencode( $user->user_login ) ), get_permalink( $login_page ) )
            : network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ), 'login' );

        // Envoyer l'email
        $subject = '🔐 Réinitialisez votre mot de passe KM FAMILY';
        $body    = self::render_reset_email( $user, $reset_url );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: KM FAMILY <' . get_option( 'admin_email' ) . '>',
        );

        wp_mail( $user->user_email, $subject, $body, $headers );

        wp_send_json_success( array( 'message' => __( 'Un email avec les instructions vient de vous être envoyé.', 'km-family' ) ) );
    }

    /**
     * AJAX changement de mot de passe (dashboard)
     */
    public static function ajax_change_password() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Vous devez être connecté.', 'km-family' ) ) );
        }

        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';

        if ( strlen( $new ) < 8 ) {
            wp_send_json_error( array( 'message' => __( 'Le nouveau mot de passe doit contenir au moins 8 caractères.', 'km-family' ) ) );
        }

        $user = wp_get_current_user();
        if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
            wp_send_json_error( array( 'message' => __( 'Mot de passe actuel incorrect.', 'km-family' ) ) );
        }

        wp_set_password( $new, $user->ID );

        // Reconnecter l'utilisateur (wp_set_password le déconnecte)
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true, is_ssl() );

        wp_send_json_success( array( 'message' => __( 'Mot de passe modifié avec succès.', 'km-family' ) ) );
    }

    /**
     * AJAX changement de palier pour un abonnement existant.
     *
     * NOTE : en production, ce handler doit initier un nouveau paiement si upgrade.
     * Pour la démo / tests, on applique le changement immédiatement.
     */
    public static function ajax_change_palier() {
        // BUGFIX SÉCURITÉ : même correctif que ajax_update_profile()/ajax_upload_avatar()
        // — wp_verify_nonce() était appelée mais son résultat totalement ignoré (pas
        // même assigné à une variable), donc sans aucun effet protecteur.
        $nonce = $_POST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Vous devez être connecté.', 'km-family' ) ) );
        }

        $artiste_id = absint( $_POST['artiste_id'] ?? 0 );
        $new_palier = sanitize_key( $_POST['palier']     ?? '' );

        if ( ! $artiste_id || ! $new_palier ) {
            wp_send_json_error( array( 'message' => __( 'Paramètres manquants.', 'km-family' ) ) );
        }

        if ( ! KMFamily_Paliers::exists( $new_palier ) ) {
            wp_send_json_error( array( 'message' => __( 'Palier invalide.', 'km-family' ) ) );
        }

        $user_id      = get_current_user_id();
        $current_sub  = KMFamily_Subscriptions::get_user_subscription( $user_id, $artiste_id );

        if ( ! $current_sub ) {
            wp_send_json_error( array( 'message' => __( 'Aucun abonnement trouvé pour cet artiste.', 'km-family' ) ) );
        }

        if ( $current_sub['palier'] === $new_palier ) {
            wp_send_json_error( array( 'message' => __( 'Vous êtes déjà à ce palier.', 'km-family' ) ) );
        }

        // Comparer les niveaux pour décider : upgrade ou downgrade ?
        $hierarchy    = KMFamily_Paliers::get_hierarchy();
        $old_level    = $hierarchy[ $current_sub['palier'] ] ?? 0;
        $new_level    = $hierarchy[ $new_palier ] ?? 0;
        $is_upgrade   = $new_level > $old_level;
        $palier_data  = KMFamily_Paliers::get( $new_palier );

        // Pour un upgrade : il faudrait initier un paiement de la différence
        // Pour un downgrade : on attend la fin de la période courante
        // Pour la v2.1, on redirige vers la page artiste pour finaliser le paiement
        $artiste_url = get_permalink( $artiste_id );

        if ( $is_upgrade ) {
            // Rediriger vers paiement (à implémenter côté gateway)
            wp_send_json_success( array(
                'message'     => __( 'Redirection vers le paiement de votre nouveau palier...', 'km-family' ),
                'redirect'    => $artiste_url . '#paliers',
                'action_type' => 'upgrade',
                'new_palier'  => $palier_data['nom'],
                'montant'     => $palier_data['prix'],
            ) );
        } else {
            // BUGFIX : l'ancien code écrivait "palier_pending" en promettant un changement
            // automatique "à la prochaine échéance" — mais rien nulle part dans le plugin ne
            // relisait jamais cette valeur (aucun cron, aucune relance de paiement automatique
            // dans ce système). Le membre recevait un message de confirmation qui ne se
            // concrétisait jamais. Une baisse de palier ne coûtant pas plus cher, elle
            // s'applique désormais immédiatement — le membre conserve sa date d'expiration déjà
            // payée, avec les avantages du nouveau palier (inférieur) à partir de maintenant.
            $subs = KMFamily_Subscriptions::get_user_subscriptions( $user_id );
            $subs[ $artiste_id ]['palier']  = $new_palier;
            $subs[ $artiste_id ]['montant'] = $palier_data['prix'];
            unset( $subs[ $artiste_id ]['palier_pending'], $subs[ $artiste_id ]['palier_pending_since'] );
            update_user_meta( $user_id, KMFamily_Subscriptions::META_KEY, $subs );

            if ( class_exists( 'KMFamily_Badges' ) ) {
                KMFamily_Badges::update_user_badge( $user_id );
            }

            // CORRECTIF v3.3.1 — DÉSYNCHRONISATION DU SOCLE « ADHÉSIONS ».
            // La baisse de palier n'écrivait que dans user_meta. Or KMFamily_Memberships
            // alimente sa table UNIQUEMENT via les hooks 'kmfamily_subscription_activated',
            // '…_renewed', '…_upgraded' et '…_cancelled' — jamais '…_downgraded', qui
            // n'est écouté par personne. Résultat : une fois la reprise faite (is_ready()),
            // toutes les LECTURES basculent sur cette table, qui conservait indéfiniment
            // l'ancien palier. Les comptages par palier, le palier dominant, le revenu par
            // artiste et les exports admin affichaient donc des chiffres faux après chaque
            // baisse de palier. On resynchronise explicitement la ligne concernée.
            if ( class_exists( 'KMFamily_Memberships' ) && method_exists( 'KMFamily_Memberships', 'sync_from_hook' ) ) {
                KMFamily_Memberships::sync_from_hook( $user_id, $artiste_id, $new_palier, array(
                    'montant'     => (int) $palier_data['prix'],
                    'periodicity' => $current_sub['periodicity'] ?? 'monthly',
                    'gateway'     => $current_sub['gateway'] ?? 'manual',
                ) );
            }

            do_action( 'kmfamily_subscription_downgraded', $user_id, $artiste_id, $new_palier, $current_sub['palier'] );

            wp_send_json_success( array(
                'message'     => sprintf(
                    __( 'Ton palier est maintenant %s, actif jusqu\'au %s (date déjà payée inchangée).', 'km-family' ),
                    $palier_data['nom'],
                    date_i18n( 'd/m/Y', $current_sub['expire'] )
                ),
                'action_type' => 'downgrade_applied',
                'reload'      => true,
            ) );
        }
    }

    /**
     * Gérer le reset password lorsque l'utilisateur clique sur le lien email.
     */
    /**
     * Soumission du nouveau mot de passe (formulaire "Nouveau mot de passe" de la page
     * de connexion). Remplace l'ancien POST direct vers wp-login.php?action=resetpass,
     * qui éjectait l'utilisateur vers la page WordPress par défaut au lieu de rester
     * sur la page KM Family — c'est exactement le bug rapporté.
     */
    public static function ajax_reset_password() {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        // CORRECTIF v3.3.1 : sans plafond, ce point d'entrée autorisait un nombre illimité
        // d'essais de clés de réinitialisation.
        if ( KMFamily_Security::is_rate_limited( 'reset_password', 10, 15 * MINUTE_IN_SECONDS ) ) {
            KMFamily_Security::rate_limit_response();
        }

        $key   = sanitize_text_field( $_POST['key'] ?? '' );
        $login = sanitize_text_field( $_POST['login'] ?? '' );
        $pass1 = $_POST['pass1'] ?? '';
        $pass2 = $_POST['pass2'] ?? '';

        if ( ! $key || ! $login || ! $pass1 || ! $pass2 ) {
            wp_send_json_error( array( 'message' => __( 'Tous les champs sont obligatoires.', 'km-family' ) ) );
        }

        if ( $pass1 !== $pass2 ) {
            wp_send_json_error( array( 'message' => __( 'Les deux mots de passe ne correspondent pas.', 'km-family' ) ) );
        }

        if ( strlen( $pass1 ) < 8 ) {
            wp_send_json_error( array( 'message' => __( 'Le mot de passe doit contenir au moins 8 caractères.', 'km-family' ) ) );
        }

        // Fonctions natives WordPress (wp-includes/user.php) — pas besoin de charger wp-login.php.
        $user = check_password_reset_key( $key, $login );

        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array(
                'message' => __( "Ce lien de réinitialisation est invalide ou a expiré. Merci de refaire une demande.", 'km-family' ),
                'expired' => true,
            ) );
        }

        wp_set_password( $pass1, $user->ID );

        /**
         * Même action que celle déclenchée par wp-login.php?action=resetpass, pour rester
         * compatible avec d'éventuels plugins qui s'y accrochent (ex. journal de sécurité).
         */
        do_action( 'after_password_reset', $user, $pass1 );

        // Connexion immédiate : l'adhérent n'a pas à se reconnecter juste après avoir changé son mot de passe.
        wp_clear_auth_cookie();
        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true, is_ssl() );

        $dashboard = get_option( 'kmfamily_page_dashboard' );
        $redirect  = $dashboard ? get_permalink( $dashboard ) : home_url();

        wp_send_json_success( array(
            'message'  => __( 'Mot de passe mis à jour ! Connexion en cours…', 'km-family' ),
            'redirect' => $redirect,
        ) );
    }

    /**
     * @deprecated Le lien de reset pointe vers la page de connexion KM Family
     * (voir ajax_forgot_password) et le formulaire est maintenant traité par
     * ajax_reset_password() en AJAX — cette méthode ne fait plus rien mais reste
     * accrochée à 'init' pour rétrocompatibilité avec d'anciens liens en circulation.
     */
    public static function maybe_handle_reset_password() {
        if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'rp' ) return;
        if ( empty( $_GET['key'] ) || empty( $_GET['login'] ) ) return;
        // Rien à faire ici : templates/login.php affiche le formulaire,
        // et ajax_reset_password() traite la soumission via AJAX.
    }

    /**
     * Rediriger les membres vers le dashboard après connexion.
     */
    public static function custom_login_redirect( $redirect_to, $request, $user ) {
        // Une destination explicitement demandée (hors wp-admin) est respectée : sinon un
        // lien "connectez-vous pour soutenir X" via wp-login.php?redirect_to=... renvoyait
        // toujours vers l'espace membre.
        if ( ! empty( $request ) && ! self::targets_wp_admin( $request ) ) {
            $safe = KMFamily_Direct_Link::safe_next( $request, '' );
            if ( $safe ) return $safe;
        }

        if ( isset( $user->roles ) && is_array( $user->roles ) ) {
            if ( in_array( 'kmfamily_member', $user->roles, true ) || in_array( 'subscriber', $user->roles, true ) ) {
                $dashboard = get_option( 'kmfamily_page_dashboard' );
                if ( $dashboard ) {
                    return get_permalink( $dashboard );
                }
            }
        }
        return $redirect_to;
    }

    /**
     * HTML email de reset password.
     */
    private static function render_reset_email( $user, $reset_url ) {
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a0a;color:#fff;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:40px 20px;">
    <tr><td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#141414;border-radius:16px;overflow:hidden;">
            <tr><td style="background:linear-gradient(135deg,#d4af37 0%,#b8941f 100%);padding:40px 30px;text-align:center;">
                <div style="color:#0a0a0a;font-size:32px;font-weight:900;letter-spacing:2px;">KM FAMILY</div>
            </td></tr>
            <tr><td style="padding:40px 30px;color:#e5e5e5;font-size:16px;line-height:1.6;">
                <h1 style="color:#d4af37;margin:0 0 20px;">🔐 Réinitialisation de mot de passe</h1>
                <p>Bonjour <?php echo esc_html( $user->display_name ); ?>,</p>
                <p>Quelqu'un a demandé la réinitialisation de votre mot de passe KM FAMILY. Si ce n'est pas vous, ignorez cet email.</p>
                <p>Sinon, cliquez sur le bouton ci-dessous pour définir un nouveau mot de passe :</p>
                <p style="text-align:center;margin:30px 0;">
                    <a href="<?php echo esc_url( $reset_url ); ?>" style="display:inline-block;background:#d4af37;color:#0a0a0a;padding:16px 36px;text-decoration:none;border-radius:50px;font-weight:700;letter-spacing:1px;">RÉINITIALISER MON MOT DE PASSE</a>
                </p>
                <p style="color:#999;font-size:13px;">Ce lien expire dans 24h. Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br><code style="background:#222;padding:8px;display:block;margin-top:8px;border-radius:4px;word-break:break-all;"><?php echo esc_url( $reset_url ); ?></code></p>
            </td></tr>
            <tr><td style="background:#0a0a0a;padding:30px;text-align:center;color:#666;font-size:12px;">
                KOPHI'S MUSIC — Abidjan, CI
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
