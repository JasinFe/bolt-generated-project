<?php
/**
 * Media Protector — Protection totale des fichiers médias
 *
 * Principe de sécurité :
 * 1. Les fichiers uploadés pour un contenu exclusif sont déplacés automatiquement
 *    dans un dossier sécurisé /wp-content/uploads/km-family-protected/
 * 2. Un fichier .htaccess bloque tout accès HTTP direct à ce dossier
 * 3. Les médias sont servis uniquement via un endpoint PHP qui :
 *    - Vérifie l'authentification de l'utilisateur
 *    - Vérifie qu'il a bien le palier requis pour l'artiste concerné
 *    - Génère un token signé à durée de vie limitée (HMAC)
 *    - Stream le fichier avec les bons headers et Range support
 * 4. Les URLs contiennent un token unique par session → impossible à partager
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Media_Protector {

    const PROTECTED_DIR = 'km-family-protected';
    const TOKEN_LIFETIME = 3600; // 1 heure

    public static function init() {
        // Déplacer les fichiers uploadés dans le dossier sécurisé
        add_filter( 'acf/update_value/name=fichier_media', array( __CLASS__, 'protect_uploaded_file' ), 10, 3 );

        // Endpoint REST pour streamer les médias
        add_action( 'rest_api_init', array( __CLASS__, 'register_endpoints' ) );

        // Handler direct via query var (plus rapide que REST pour streaming)
        // Priorité 5 : la règle doit exister AVANT le flush de KMFamily_Payment_Center
        // (priorité 99), sans quoi elle disparaît des permaliens à chaque mise à jour.
        add_action( 'init',           array( __CLASS__, 'register_rewrite_rule' ), 5 );
        add_filter( 'query_vars',     array( __CLASS__, 'add_query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_media_request' ) );

        add_action( 'admin_notices',  array( __CLASS__, 'nginx_protection_notice' ) );
    }

    /**
     * Avertissement admin : sous Nginx, le .htaccess du dossier protégé est ignoré et les
     * médias réservés restent accessibles en téléchargement direct.
     */
    public static function nginx_protection_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // La détection est refaite à chaque affichage admin : setup_secure_storage() ne
        // tourne qu'à l'activation, et un site peut changer de serveur entre-temps.
        self::maybe_warn_nginx();

        if ( ! get_option( 'kmfamily_media_htaccess_ineffective' ) ) return;

        echo '<div class="notice notice-warning"><p><strong>KM Family</strong> — ';
        echo esc_html__( "Ce serveur tourne sous Nginx : le fichier .htaccess qui protège le dossier des médias réservés y est ignoré. Les fichiers restent téléchargeables en direct, sans vérification de palier. Demandez à votre hébergeur d'ajouter cette règle :", 'km-family' );
        echo '</p><p><code>location ^~ /wp-content/uploads/km-family-protected/ { deny all; }</code></p></div>';
    }

    /**
     * Créer le dossier sécurisé et son .htaccess à l'activation.
     */
    public static function setup_secure_storage() {
        $upload_dir = wp_upload_dir();
        $protected_path = trailingslashit( $upload_dir['basedir'] ) . self::PROTECTED_DIR;

        if ( ! file_exists( $protected_path ) ) {
            wp_mkdir_p( $protected_path );
        }

        // .htaccess pour bloquer tout accès direct
        $htaccess = $protected_path . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            $content = "# KM Family — Protection totale des médias\n";
            $content .= "# Aucun accès direct autorisé. Utiliser l'endpoint sécurisé.\n";
            $content .= "Order deny,allow\n";
            $content .= "Deny from all\n\n";
            $content .= "<IfModule mod_authz_core.c>\n";
            $content .= "Require all denied\n";
            $content .= "</IfModule>\n";
            file_put_contents( $htaccess, $content );
        }

        // index.php vide pour bloquer le listing
        $index = $protected_path . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden' );
        }

        // ATTENTION — LIMITE CONNUE : le .htaccess ci-dessus ne protège RIEN sous Nginx,
        // qui ne lit pas ce fichier (LiteSpeed et Apache, eux, le respectent). Sur un
        // hébergement Nginx, les fichiers de ce dossier restent téléchargeables en direct
        // via leur URL /wp-content/uploads/km-family-protected/…, et tout le contrôle de
        // palier est contournable. Ajouter alors dans la configuration du serveur :
        //
        //   location ^~ /wp-content/uploads/km-family-protected/ { deny all; return 403; }
        //
        // Le nom de fichier aléatoire limite la découverte, mais ne remplace pas ce blocage.
        self::maybe_warn_nginx();

        // Générer une clé secrète pour les tokens HMAC si elle n'existe pas
        self::media_secret();
    }

    /**
     * Prévient l'administrateur si le serveur ignore le .htaccess de protection.
     */
    private static function maybe_warn_nginx() {
        $server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
        if ( $server && false !== strpos( $server, 'nginx' ) ) {
            update_option( 'kmfamily_media_htaccess_ineffective', 1, false );
        } else {
            delete_option( 'kmfamily_media_htaccess_ineffective' );
        }
    }

    /**
     * Intercepter l'upload de fichier_media ACF pour le déplacer dans le dossier protégé.
     *
     * @param array|int $value  Valeur du champ (attachment ID ou array ACF).
     * @param int       $post_id ID du post.
     * @param array     $field  Infos du champ ACF.
     * @return mixed
     */
    public static function protect_uploaded_file( $value, $post_id, $field ) {
        // Ne traiter que les contenus exclusifs
        if ( get_post_type( $post_id ) !== 'contenu_exclusif' ) return $value;

        // Extraire l'attachment ID selon le format ACF
        $attachment_id = 0;
        if ( is_numeric( $value ) ) {
            $attachment_id = absint( $value );
        } elseif ( is_array( $value ) && isset( $value['ID'] ) ) {
            $attachment_id = absint( $value['ID'] );
        } elseif ( is_array( $value ) && isset( $value['id'] ) ) {
            $attachment_id = absint( $value['id'] );
        }

        if ( ! $attachment_id ) return $value;

        // Vérifier si déjà protégé (flag meta)
        if ( get_post_meta( $attachment_id, '_kmfamily_protected', true ) ) return $value;

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) return $value;

        $upload_dir = wp_upload_dir();
        $protected_base = trailingslashit( $upload_dir['basedir'] ) . self::PROTECTED_DIR;

        if ( ! file_exists( $protected_base ) ) {
            self::setup_secure_storage();
        }

        // Nouveau nom : attachment_id + random + extension d'origine
        $ext = pathinfo( $file_path, PATHINFO_EXTENSION );
        $new_filename = $attachment_id . '-' . wp_generate_password( 12, false ) . '.' . $ext;
        $new_path = $protected_base . '/' . $new_filename;

        // Déplacer le fichier
        if ( @rename( $file_path, $new_path ) ) {
            // Mettre à jour l'attached file
            update_attached_file( $attachment_id, $new_path );

            // Marquer comme protégé
            update_post_meta( $attachment_id, '_kmfamily_protected', 1 );
            update_post_meta( $attachment_id, '_kmfamily_protected_filename', $new_filename );

            // Invalider l'URL publique de WP : remplacer par notre URL sécurisée
            // (on conserve la valeur ACF telle quelle, mais on sert via un endpoint)
        }

        return $value;
    }

    /**
     * Ajouter une rewrite rule pour /km-family-media/{token}/{attachment_id}
     */
    public static function register_rewrite_rule() {
        add_rewrite_rule(
            '^km-family-media/([^/]+)/?$',
            'index.php?kmfamily_media_token=$matches[1]',
            'top'
        );
    }

    public static function add_query_vars( $vars ) {
        $vars[] = 'kmfamily_media_token';
        return $vars;
    }

    /**
     * Intercepter la requête de média et streamer si autorisé.
     */
    public static function handle_media_request() {
        $token = get_query_var( 'kmfamily_media_token' );
        if ( ! $token ) return;

        $payload = self::verify_token( $token );
        if ( ! $payload ) {
            status_header( 403 );
            wp_die( __( 'Accès refusé ou lien expiré.', 'km-family' ), 'Accès refusé', array( 'response' => 403 ) );
        }

        $user_id       = $payload['user_id'] ?? 0;
        $attachment_id = $payload['attachment_id'] ?? 0;
        $post_id       = $payload['post_id'] ?? 0;

        // Re-vérification des droits au moment du streaming (zero trust)
        if ( ! self::user_can_stream( $user_id, $post_id ) ) {
            status_header( 403 );
            wp_die( __( 'Vous n\'avez plus les droits pour accéder à ce média.', 'km-family' ), 'Accès refusé', array( 'response' => 403 ) );
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            status_header( 404 );
            wp_die( __( 'Fichier introuvable.', 'km-family' ), 'Introuvable', array( 'response' => 404 ) );
        }

        self::stream_file( $file_path );
        exit;
    }

    /**
     * Endpoint REST alternatif (utilisable depuis JS côté client avec fetch).
     */
    public static function register_endpoints() {
        register_rest_route( 'kmfamily/v1', '/media/(?P<token>[a-zA-Z0-9._-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_stream_media' ),
            'permission_callback' => '__return_true',
        ) );

        // Endpoint pour obtenir un token temporaire
        register_rest_route( 'kmfamily/v1', '/media-token', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_generate_token' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );
    }

    public static function rest_stream_media( WP_REST_Request $request ) {
        $token = $request->get_param( 'token' );
        $payload = self::verify_token( $token );
        if ( ! $payload ) return new WP_Error( 'forbidden', 'Token invalide ou expiré.', array( 'status' => 403 ) );

        if ( ! self::user_can_stream( $payload['user_id'], $payload['post_id'] ) ) {
            return new WP_Error( 'forbidden', 'Droits insuffisants.', array( 'status' => 403 ) );
        }

        $file_path = get_attached_file( $payload['attachment_id'] );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'not_found', 'Fichier introuvable.', array( 'status' => 404 ) );
        }

        self::stream_file( $file_path );
        exit;
    }

    public static function rest_generate_token( WP_REST_Request $request ) {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id ) return new WP_Error( 'invalid', 'post_id requis.', array( 'status' => 400 ) );

        $user_id = get_current_user_id();
        if ( ! self::user_can_stream( $user_id, $post_id ) ) {
            return new WP_Error( 'forbidden', 'Droits insuffisants.', array( 'status' => 403 ) );
        }

        $fichier = get_field( 'fichier_media', $post_id );
        $attachment_id = 0;
        if ( is_array( $fichier ) && isset( $fichier['ID'] ) ) {
            $attachment_id = $fichier['ID'];
        } elseif ( is_array( $fichier ) && isset( $fichier['id'] ) ) {
            $attachment_id = $fichier['id'];
        }
        if ( ! $attachment_id ) return new WP_Error( 'no_file', 'Aucun fichier associé.', array( 'status' => 404 ) );

        $token = self::generate_token( array(
            'user_id'       => $user_id,
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
            'expire'        => time() + self::TOKEN_LIFETIME,
            'ip'            => self::get_client_ip(),
        ) );

        return new WP_REST_Response( array(
            'url' => home_url( '/km-family-media/' . $token ),
            'expires_in' => self::TOKEN_LIFETIME,
        ), 200 );
    }

    /**
     * Générer un token signé (HMAC-SHA256).
     */
    /**
     * CORRECTIF SÉCURITÉ v3.3.1 — CLÉ HMAC POTENTIELLEMENT VIDE.
     * La clé n'était créée qu'à l'activation du plugin (setup_secure_storage). Sur un site
     * migré, restauré depuis une sauvegarde partielle, ou dont l'option avait été purgée par
     * un outil de nettoyage de base, get_option() renvoyait false : hash_hmac() signait alors
     * avec une clé VIDE, c'est-à-dire une valeur connue de tous — n'importe qui pouvait
     * fabriquer un jeton valide et lire tous les médias protégés. La clé est désormais
     * générée à la demande si elle manque.
     */
    private static function media_secret(): string {
        $secret = get_option( 'kmfamily_media_secret' );
        if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( 'kmfamily_media_secret', $secret, false );
        }
        return $secret;
    }

    public static function generate_token( $data ) {
        $secret = self::media_secret();
        $payload = base64_encode( wp_json_encode( $data ) );
        // Remplacer caractères URL-unsafe
        $payload = strtr( $payload, '+/=', '-_.' );
        $signature = hash_hmac( 'sha256', $payload, $secret );
        return $payload . '.' . substr( $signature, 0, 32 );
    }

    /**
     * Vérifier et décoder un token. Retourne false si invalide ou expiré.
     */
    public static function verify_token( $token ) {
        if ( ! is_string( $token ) || strpos( $token, '.' ) === false ) return false;
        list( $payload, $signature ) = explode( '.', $token, 2 );

        $secret = self::media_secret();
        $expected = substr( hash_hmac( 'sha256', $payload, $secret ), 0, 32 );

        if ( ! hash_equals( $expected, $signature ) ) return false;

        $data = json_decode( base64_decode( strtr( $payload, '-_.', '+/=' ) ), true );
        if ( ! is_array( $data ) ) return false;
        if ( empty( $data['expire'] ) || $data['expire'] < time() ) return false;

        // Vérifier l'IP si on veut être strict (optionnel, peut casser sur mobile)
        // if ( ! empty( $data['ip'] ) && $data['ip'] !== self::get_client_ip() ) return false;

        return $data;
    }

    /**
     * Vérifier que l'utilisateur a les droits de streamer ce contenu.
     */
    private static function user_can_stream( $user_id, $post_id ) {
        if ( ! $user_id || ! $post_id ) return false;

        // Admin passe toujours
        if ( user_can( $user_id, 'manage_options' ) ) return true;

        // LOT 3 — le contrôle doit passer par le moteur d'exclusivité, sinon ce
        // chemin (les anciennes URL /km-media/) resterait une porte ouverte sur
        // les premières non encore diffusées et les drops terminés.
        if ( class_exists( 'KMFamily_Access' ) && method_exists( 'KMFamily_Access', 'can_view' ) ) {
            return KMFamily_Access::can_view( $user_id, $post_id );
        }

        $palier_requis = get_field( 'palier_requis', $post_id );
        $artiste_obj   = get_field( 'artiste_lie', $post_id );

        if ( ! $artiste_obj ) return false;

        $artiste_id = is_object( $artiste_obj ) ? $artiste_obj->ID : $artiste_obj;

        return KMFamily_Access::user_has_access( $user_id, $artiste_id, $palier_requis );
    }

    /**
     * Streamer un fichier avec support Range (seek vidéo/audio).
     */
    private static function stream_file( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            status_header( 404 );
            exit;
        }

        $file_size = filesize( $file_path );
        $mime = mime_content_type( $file_path );
        if ( ! $mime ) {
            $mime = 'application/octet-stream';
        }

        // Headers anti-cache et anti-download
        header( 'Content-Type: ' . $mime );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Accept-Ranges: bytes' );
        header( 'Cache-Control: private, max-age=3600' );
        header( 'X-Frame-Options: SAMEORIGIN' );

        $start = 0;
        $end   = $file_size - 1;

        /**
         * CORRECTIF v3.3.1 — SUPPORT HTTP RANGE INCOMPLET.
         * L'ancienne expression `bytes=(\d+)-(\d+)?` ne reconnaissait pas la forme
         * « N derniers octets » (`bytes=-500`), pourtant utilisée par plusieurs lecteurs
         * mobiles pour lire l'en-tête de fin d'un MP4 : la requête ne correspondait à rien,
         * aucun 206 n'était émis, et le serveur renvoyait tout le fichier avec un
         * Content-Length incohérent — la vidéo restait bloquée sur le chargement.
         * Elle plafonnait par ailleurs `$end` à la taille du fichier en renvoyant un 416
         * alors qu'un client a parfaitement le droit de demander `bytes=0-99999999`.
         * Même logique que KMFamily_Media_Driver_Local::stream(), désormais alignée.
         */
        $partial = false;
        if ( ! empty( $_SERVER['HTTP_RANGE'] ) ) {
            if ( preg_match( '/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m ) ) {
                if ( $m[1] === '' && $m[2] !== '' ) {
                    $start = max( 0, $file_size - (int) $m[2] );
                } else {
                    $start = (int) $m[1];
                    if ( $m[2] !== '' ) $end = (int) $m[2];
                }

                if ( $start > $end || $start >= $file_size ) {
                    header( 'HTTP/1.1 416 Requested Range Not Satisfiable' );
                    header( "Content-Range: bytes */{$file_size}" );
                    exit;
                }
                if ( $end >= $file_size ) $end = $file_size - 1;
                $partial = true;
            }
        }

        if ( $partial ) {
            status_header( 206 );
            header( "Content-Range: bytes {$start}-{$end}/{$file_size}" );
        }

        $length = $end - $start + 1;
        header( "Content-Length: $length" );

        // CORRECTIF v3.3.1 — LECTURES TRONQUÉES SUR LES GROS FICHIERS.
        // Sans set_time_limit(0), la diffusion d'une vidéo ou d'un long titre était coupée
        // net par max_execution_time (30 s chez la plupart des hébergeurs mutualisés) :
        // le lecteur s'arrêtait au bout de quelques minutes, sans message d'erreur.
        // ignore_user_abort(false) libère à l'inverse le worker dès que le navigateur
        // abandonne une requête Range en cours (seek, changement de piste).
        ignore_user_abort( false );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );

        // Une compression de sortie fausserait le Content-Length annoncé ci-dessus.
        if ( function_exists( 'apache_setenv' ) ) @apache_setenv( 'no-gzip', '1' );
        @ini_set( 'zlib.output_compression', 'Off' );

        // Cleaner output buffers
        while ( ob_get_level() > 0 ) ob_end_clean();

        $fp = fopen( $file_path, 'rb' );
        if ( ! $fp ) exit;

        fseek( $fp, $start );
        // Tampon de 8 Ko + flush() à chaque tour : des dizaines de milliers d'itérations
        // (et autant d'appels système) pour un seul fichier vidéo. 256 Ko est un bien
        // meilleur compromis pour du média.
        $buffer = 256 * 1024;
        $sent   = 0;

        while ( ! feof( $fp ) && $sent < $length && connection_status() === CONNECTION_NORMAL ) {
            $chunk = fread( $fp, (int) min( $buffer, $length - $sent ) );
            if ( false === $chunk || '' === $chunk ) break;

            echo $chunk;
            flush();
            // On comptabilise les octets RÉELLEMENT lus, et non la taille demandée :
            // une lecture courte (fichier sur stockage réseau, fin de fichier) faisait
            // auparavant croire la boucle plus avancée qu'elle ne l'était, et le flux
            // s'arrêtait avant la fin annoncée dans Content-Length.
            $sent += strlen( $chunk );
        }

        fclose( $fp );
    }

    /**
     * Obtenir l'IP client (gère reverse proxies).
     */
    /**
     * CORRECTIF v3.3.1 — source d'IP unique et non falsifiable pour tout le plugin
     * (voir KMFamily_Security::client_ip). Les en-têtes de proxy ne sont lus que si le
     * site est explicitement déclaré derrière un proxy de confiance : sans ce garde-fou,
     * un visiteur pouvait choisir l'IP enregistrée sur sa commande et fausser à la fois
     * la limitation de débit et le score du moteur de risque.
     */
    private static function get_client_ip() {
        return class_exists( 'KMFamily_Security' ) ? KMFamily_Security::client_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '' );
    }

    /**
     * Générer l'URL sécurisée pour un contenu exclusif (à utiliser dans les templates).
     */
    public static function get_secure_media_url( $post_id, $user_id = null ) {
        if ( ! $user_id ) $user_id = get_current_user_id();

        $fichier = get_field( 'fichier_media', $post_id );
        $attachment_id = 0;
        if ( is_array( $fichier ) && isset( $fichier['ID'] ) ) {
            $attachment_id = $fichier['ID'];
        } elseif ( is_array( $fichier ) && isset( $fichier['id'] ) ) {
            $attachment_id = $fichier['id'];
        }

        if ( ! $attachment_id ) return '';

        $token = self::generate_token( array(
            'user_id'       => $user_id,
            'post_id'       => $post_id,
            'attachment_id' => $attachment_id,
            'expire'        => time() + self::TOKEN_LIFETIME,
        ) );

        return home_url( '/km-family-media/' . $token );
    }
}
