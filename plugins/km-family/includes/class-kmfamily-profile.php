<?php
/**
 * Profil membre — Édition des informations + upload avatar
 *
 * Utilise le système d'avatar local stocké en user_meta
 * (compatible avec la filter get_avatar existante).
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Profile {

    const AVATAR_META_KEY  = 'kmfamily_avatar_id';
    const AVATAR_URL_KEY   = 'kmfamily_avatar_url';
    const MAX_FILE_SIZE    = 2097152; // 2 MB
    const ALLOWED_TYPES    = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

    public static function init() {
        // Surcharger l'avatar WordPress par l'avatar custom uploadé
        add_filter( 'get_avatar_url', array( __CLASS__, 'use_custom_avatar' ), 10, 3 );
        add_filter( 'get_avatar',     array( __CLASS__, 'filter_avatar_img' ), 5, 5 );

        // AJAX handlers classiques
        add_action( 'wp_ajax_kmfamily_update_profile', array( __CLASS__, 'ajax_update_profile' ) );
        add_action( 'wp_ajax_kmfamily_upload_avatar',  array( __CLASS__, 'ajax_upload_avatar' ) );
        add_action( 'wp_ajax_kmfamily_delete_avatar',  array( __CLASS__, 'ajax_delete_avatar' ) );

        // REST API alternatifs (contournent certains WAF qui bloquent admin-ajax.php)
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

        // Regénérer les nonces si le cache expire
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'refresh_nonce_if_cached' ), 200 );
    }

    /**
     * REST routes alternatives pour contourner les WAF bloquant admin-ajax.
     */
    public static function register_rest_routes() {
        register_rest_route( 'kmfamily/v1', '/profile/update', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_update_profile' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );

        register_rest_route( 'kmfamily/v1', '/profile/avatar', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_upload_avatar' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );

        register_rest_route( 'kmfamily/v1', '/profile/avatar', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'rest_delete_avatar' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );
    }

    public static function refresh_nonce_if_cached() {
        // Aucune action ici directement, mais permet à des plugins de cache de hook
    }

    /**
     * CORRECTIF SÉCURITÉ v3.3.1 — même défaut que KMFamily_Auth::rest_wrap() : ces replis
     * REST écrivaient eux-mêmes dans $_POST un nonce généré côté serveur, si bien que la
     * vérification faite ensuite par le handler AJAX ne validait plus rien. On exige
     * désormais un nonce réellement fourni par le client (corps de requête, action
     * 'kmfamily_auth_nonce') ou, à défaut, le nonce REST standard (en-tête X-WP-Nonce) —
     * les deux étant déjà envoyés par KMFamilyRequest côté JS.
     */
    private static function verify_request_nonce( WP_REST_Request $request ) {
        $body_nonce = $request->get_param( 'nonce' );
        if ( is_string( $body_nonce ) && $body_nonce && wp_verify_nonce( $body_nonce, 'kmfamily_auth_nonce' ) ) {
            return $body_nonce;
        }

        $rest_nonce = $request->get_header( 'x_wp_nonce' );
        if ( is_string( $rest_nonce ) && $rest_nonce && wp_verify_nonce( $rest_nonce, 'wp_rest' ) ) {
            return wp_create_nonce( 'kmfamily_auth_nonce' );
        }

        return false;
    }

    private static function rest_nonce_error() {
        return new WP_REST_Response( array(
            'success' => false,
            'data'    => array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ),
        ), 403 );
    }

    /**
     * REST wrapper pour update_profile
     */
    public static function rest_update_profile( WP_REST_Request $request ) {
        $nonce = self::verify_request_nonce( $request );
        if ( ! $nonce ) return self::rest_nonce_error();

        $_POST = array_merge( $_POST, wp_slash( $request->get_params() ) );
        $_POST['nonce'] = $nonce;

        ob_start();
        self::ajax_update_profile();
        $output = ob_get_clean();
        $data = json_decode( $output, true );

        if ( $data && isset( $data['success'] ) ) {
            return new WP_REST_Response( $data, $data['success'] ? 200 : 400 );
        }
        return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'Erreur inconnue.' ) ), 500 );
    }

    /**
     * REST wrapper pour upload_avatar
     */
    public static function rest_upload_avatar( WP_REST_Request $request ) {
        $nonce = self::verify_request_nonce( $request );
        if ( ! $nonce ) return self::rest_nonce_error();

        if ( empty( $_FILES['avatar'] ) ) {
            return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'Aucun fichier.' ) ), 400 );
        }
        $_POST['nonce'] = $nonce;

        ob_start();
        self::ajax_upload_avatar();
        $output = ob_get_clean();
        $data = json_decode( $output, true );

        if ( $data && isset( $data['success'] ) ) {
            return new WP_REST_Response( $data, $data['success'] ? 200 : 400 );
        }
        return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'Erreur inconnue.' ) ), 500 );
    }

    /**
     * REST wrapper pour delete_avatar
     */
    public static function rest_delete_avatar( WP_REST_Request $request ) {
        $nonce = self::verify_request_nonce( $request );
        if ( ! $nonce ) return self::rest_nonce_error();

        $_POST['nonce'] = $nonce;

        ob_start();
        self::ajax_delete_avatar();
        $output = ob_get_clean();
        $data = json_decode( $output, true );

        if ( $data && isset( $data['success'] ) ) {
            return new WP_REST_Response( $data, $data['success'] ? 200 : 400 );
        }
        return new WP_REST_Response( array( 'success' => false, 'data' => array( 'message' => 'Erreur inconnue.' ) ), 500 );
    }

    /**
     * Retourne l'URL de l'avatar custom d'un utilisateur (ou null).
     */
    public static function get_avatar_url( $user_id ) {
        $url = get_user_meta( $user_id, self::AVATAR_URL_KEY, true );
        if ( $url ) return $url;

        $attachment_id = get_user_meta( $user_id, self::AVATAR_META_KEY, true );
        if ( $attachment_id ) {
            $url = wp_get_attachment_url( $attachment_id );
            if ( $url ) {
                update_user_meta( $user_id, self::AVATAR_URL_KEY, $url );
                return $url;
            }
        }
        return null;
    }

    /**
     * Remplacer l'URL gravatar par l'avatar custom.
     */
    public static function use_custom_avatar( $url, $id_or_email, $args ) {
        $user_id = self::resolve_user_id( $id_or_email );
        if ( ! $user_id ) return $url;

        $custom = self::get_avatar_url( $user_id );
        return $custom ? $custom : $url;
    }

    /**
     * Forcer le HTML avatar à utiliser notre URL (les thèmes peuvent appeler get_avatar directement).
     */
    public static function filter_avatar_img( $avatar, $id_or_email, $size, $default, $alt ) {
        $user_id = self::resolve_user_id( $id_or_email );
        if ( ! $user_id ) return $avatar;

        $custom_url = self::get_avatar_url( $user_id );
        if ( ! $custom_url ) return $avatar;

        $size = (int) $size;
        $alt  = $alt ? esc_attr( $alt ) : '';

        return sprintf(
            '<img alt="%1$s" src="%2$s" class="avatar avatar-%3$d photo kmfamily-custom-avatar" height="%3$d" width="%3$d" loading="lazy" />',
            $alt,
            esc_url( $custom_url ),
            $size
        );
    }

    private static function resolve_user_id( $id_or_email ) {
        if ( is_numeric( $id_or_email ) ) return (int) $id_or_email;
        if ( is_object( $id_or_email ) && ! empty( $id_or_email->user_id ) ) return (int) $id_or_email->user_id;
        if ( is_object( $id_or_email ) && ! empty( $id_or_email->ID ) ) return (int) $id_or_email->ID;
        if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            return $user ? $user->ID : 0;
        }
        return 0;
    }

    /**
     * AJAX : mise à jour infos profil (display_name, first/last name, description, etc.)
     */
    public static function ajax_update_profile() {
        // BUGFIX SÉCURITÉ : le nonce était calculé (wp_verify_nonce) mais sa valeur
        // n'était jamais utilisée pour bloquer quoi que ce soit — seule la connexion
        // était vérifiée, ce qui n'empêche pas une requête forgée depuis un autre site
        // (CSRF) tant que le cookie de session est envoyé par le navigateur. On applique
        // désormais réellement la vérification.
        $nonce = $_POST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Non autorisé. Connectez-vous d\'abord.', 'km-family' ) ) );
        }

        $user_id = get_current_user_id();

        // wp_unslash : sans lui « O'Brien » devenait « O\'Brien » en base.
        $in           = wp_unslash( $_POST );
        $display_name = mb_substr( sanitize_text_field( $in['display_name'] ?? '' ), 0, 60 );
        $first_name   = sanitize_text_field( $in['first_name']   ?? '' );
        $last_name    = sanitize_text_field( $in['last_name']    ?? '' );
        $description  = wp_kses_post(     $in['description']  ?? '' );
        $email        = sanitize_email(   $in['email']        ?? '' );
        $phone        = sanitize_text_field( $in['phone']        ?? '' );
        $city         = sanitize_text_field( $in['city']         ?? '' );

        if ( ! $display_name ) {
            wp_send_json_error( array( 'message' => __( 'Le nom d\'affichage est obligatoire.', 'km-family' ) ) );
        }

        // Vérifier email
        if ( $email ) {
            if ( ! is_email( $email ) ) {
                wp_send_json_error( array( 'message' => __( 'Email invalide.', 'km-family' ) ) );
            }
            $existing = email_exists( $email );
            if ( $existing && $existing !== $user_id ) {
                wp_send_json_error( array( 'message' => __( 'Cet email est déjà utilisé.', 'km-family' ) ) );
            }

            /**
             * CORRECTIF SÉCURITÉ v3.4.1 — PRISE DE CONTRÔLE DE COMPTE.
             * L'e-mail pouvait être changé sans ressaisir le mot de passe : quiconque
             * disposait un instant de la session (téléphone prêté, session restée ouverte,
             * cookie volé) remplaçait l'adresse puis déclenchait « mot de passe oublié »
             * vers SA boîte — le compte, ses abonnements et ses billets étaient perdus.
             */
            $current_user = wp_get_current_user();
            if ( strtolower( $email ) !== strtolower( $current_user->user_email ) ) {
                if ( KMFamily_Security::is_rate_limited( 'change_email', 5, 15 * MINUTE_IN_SECONDS ) ) {
                    KMFamily_Security::rate_limit_response();
                }
                $pwd = (string) ( $in['current_password'] ?? '' );
                if ( $pwd === '' || ( ! wp_check_password( $pwd, $current_user->user_pass, $user_id )
                                      && ! wp_check_password( wp_slash( $pwd ), $current_user->user_pass, $user_id ) ) ) {
                    wp_send_json_error( array( 'message' => __( 'Pour changer d\'e-mail, saisissez votre mot de passe actuel.', 'km-family' ) ) );
                }
            }
        }

        $update = array(
            'ID'           => $user_id,
            'display_name' => $display_name,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'description'  => $description,
        );
        if ( $email ) $update['user_email'] = $email;

        $result = wp_update_user( $update );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        update_user_meta( $user_id, 'kmfamily_phone', $phone );
        update_user_meta( $user_id, 'kmfamily_city',  $city );

        wp_send_json_success( array( 'message' => __( 'Profil mis à jour avec succès.', 'km-family' ) ) );
    }

    /**
     * AJAX : upload avatar
     */
    public static function ajax_upload_avatar() {
        // BUGFIX SÉCURITÉ : même correctif que ajax_update_profile() — le nonce était
        // calculé mais jamais réellement vérifié.
        $nonce = $_POST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Non autorisé. Connectez-vous d\'abord.', 'km-family' ) ) );
        }

        if ( empty( $_FILES['avatar'] ) || ! isset( $_FILES['avatar']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Aucun fichier reçu.', 'km-family' ) ) );
        }

        $file = $_FILES['avatar'];

        // Vérifier erreur PHP d'upload
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            $php_errors = array(
                UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (limite serveur).',
                UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux (limite du formulaire).',
                UPLOAD_ERR_PARTIAL    => 'Upload interrompu. Réessayez.',
                UPLOAD_ERR_NO_FILE    => 'Aucun fichier envoyé.',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
                UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire sur le disque.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqué par une extension PHP.',
            );
            $err_msg = $php_errors[ $file['error'] ] ?? 'Erreur PHP d\'upload (' . $file['error'] . ').';
            wp_send_json_error( array( 'message' => $err_msg ) );
        }

        // Vérifier taille
        if ( $file['size'] > self::MAX_FILE_SIZE ) {
            wp_send_json_error( array( 'message' => __( 'Image trop lourde (max 2 Mo).', 'km-family' ) ) );
        }

        // Vérifier type via finfo si disponible, sinon fallback sur $_FILES['type']
        $mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $file['tmp_name'] );
                finfo_close( $finfo );
            }
        }
        if ( ! $mime && ! empty( $file['type'] ) ) {
            $mime = $file['type'];
        }

        if ( ! in_array( $mime, self::ALLOWED_TYPES, true ) ) {
            wp_send_json_error( array( 'message' => sprintf( __( 'Format non supporté (%s). Utilisez JPG, PNG, WebP ou GIF.', 'km-family' ), $mime ?: 'inconnu' ) ) );
        }

        $user_id = get_current_user_id();

        // CORRECTIF SÉCURITÉ v3.3.1 — ÉLÉVATION DE PRIVILÈGE PERSISTANTE.
        // L'ancien code appelait $user->add_cap('upload_files') puis remove_cap() après coup.
        // WP_User::add_cap() n'est PAS temporaire : il ÉCRIT la capacité dans la méta
        // wp_capabilities de la personne, en base. Si la requête s'interrompt entre les deux
        // (timeout PHP sur un gros fichier, dépassement mémoire, erreur fatale d'un autre
        // plugin, coupure réseau), la capacité `upload_files` reste acquise DÉFINITIVEMENT :
        // le membre peut alors téléverser des fichiers arbitraires dans la médiathèque du
        // site. Cela réécrivait par ailleurs deux fois la ligne usermeta à chaque avatar.
        // On accorde désormais la capacité uniquement en mémoire, le temps de cet appel,
        // via le filtre prévu pour ça — rien n'est écrit en base, et une interruption ne
        // laisse aucune trace.
        $grant_upload = function( $allcaps ) {
            $allcaps['upload_files'] = true;
            return $allcaps;
        };
        add_filter( 'user_has_cap', $grant_upload, 9999 );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $overrides = array(
            'test_form' => false,
            'mimes'     => array(
                'jpg|jpeg' => 'image/jpeg',
                'png'      => 'image/png',
                'gif'      => 'image/gif',
                'webp'     => 'image/webp',
            ),
        );

        $uploaded = wp_handle_upload( $file, $overrides );

        // Retirer la capacité accordée en mémoire (aucune écriture en base à annuler).
        remove_filter( 'user_has_cap', $grant_upload, 9999 );

        if ( isset( $uploaded['error'] ) ) {
            wp_send_json_error( array( 'message' => 'Upload: ' . $uploaded['error'] ) );
        }

        if ( empty( $uploaded['file'] ) || empty( $uploaded['url'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Upload échoué (fichier introuvable après enregistrement).', 'km-family' ) ) );
        }

        // Créer un attachment
        $attachment = array(
            'post_mime_type' => $uploaded['type'],
            'post_title'     => 'kmfamily-avatar-' . $user_id,
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $user_id,
        );
        $attach_id = wp_insert_attachment( $attachment, $uploaded['file'] );

        if ( is_wp_error( $attach_id ) || ! $attach_id ) {
            @unlink( $uploaded['file'] );
            wp_send_json_error( array( 'message' => __( 'Impossible de créer l\'attachement WordPress.', 'km-family' ) ) );
        }

        $attach_data = wp_generate_attachment_metadata( $attach_id, $uploaded['file'] );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        // Supprimer l'ancien avatar
        $old_id = get_user_meta( $user_id, self::AVATAR_META_KEY, true );
        if ( $old_id && $old_id != $attach_id ) {
            wp_delete_attachment( $old_id, true );
        }

        update_user_meta( $user_id, self::AVATAR_META_KEY, $attach_id );
        update_user_meta( $user_id, self::AVATAR_URL_KEY, $uploaded['url'] );

        wp_send_json_success( array(
            'message' => __( 'Photo de profil mise à jour.', 'km-family' ),
            'url'     => $uploaded['url'],
        ) );
    }

    /**
     * AJAX : supprimer l'avatar custom
     */
    public static function ajax_delete_avatar() {
        $nonce = $_POST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, 'kmfamily_auth_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Session expirée, merci de recharger la page.', 'km-family' ) ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Non autorisé.', 'km-family' ) ) );
        }

        $user_id = get_current_user_id();
        $old_id = get_user_meta( $user_id, self::AVATAR_META_KEY, true );
        if ( $old_id ) {
            wp_delete_attachment( $old_id, true );
        }

        delete_user_meta( $user_id, self::AVATAR_META_KEY );
        delete_user_meta( $user_id, self::AVATAR_URL_KEY );

        wp_send_json_success( array( 'message' => __( 'Photo de profil supprimée.', 'km-family' ) ) );
    }
}
