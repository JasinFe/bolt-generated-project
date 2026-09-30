<?php
/**
 * KM Family — Pilote Cloudflare Stream (LOT 5, première partie)
 *
 * POURQUOI CE PRESTATAIRE, POUR CE PUBLIC :
 * Cloudflare dispose d'un point de présence à Abidjan même (et d'un second à
 * Yamoussoukro). Pour une audience majoritairement ivoirienne sur réseaux
 * mobiles, c'est la différence entre une vidéo qui démarre et une vidéo qui
 * tourne. Sa facturation est en outre à la MINUTE REGARDÉE et non au
 * gigaoctet : améliorer la qualité d'encodage n'augmente donc jamais la
 * facture, ce qui évite d'avoir à brider la qualité pour contenir les coûts.
 *
 * DIFFÉRENCE AVEC LE PILOTE GÉNÉRIQUE :
 * KMFamily_Media_Driver_Remote signe ses URL par condensat (schéma Bunny).
 * Cloudflare attend un jeton JWT signé en RS256 avec une clé privée RSA. C'est
 * exactement la raison pour laquelle sign_url() avait été isolée au Lot 2 :
 * changer de prestataire ne touche ni le lecteur, ni le contrôle d'accès, ni
 * les sessions média.
 *
 * CE QUI NE CHANGE PAS : WordPress reste seul juge des droits. Le jeton n'est
 * fabriqué qu'APRÈS que KMFamily_Media_Session a validé la session, et sa
 * durée de vie est volontairement courte — une fois le lien remis au
 * navigateur, nous ne sommes plus dans la boucle.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Cloudflare_Stream implements KMFamily_Media_Driver_Interface {

    const OPTION_CUSTOMER = 'kmfamily_cf_customer_code'; // customer-xxxxxxxx
    const OPTION_ACCOUNT  = 'kmfamily_cf_account_id';
    const OPTION_TOKEN    = 'kmfamily_cf_api_token';
    const OPTION_KEY_ID   = 'kmfamily_cf_key_id';
    const OPTION_KEY_PEM  = 'kmfamily_cf_key_pem';
    const OPTION_TTL      = 'kmfamily_cf_ttl';
    const OPTION_PROVIDER = 'kmfamily_remote_provider';  // bunny | cloudflare

    const META_UID        = '_kmfamily_cf_uid';
    const META_ETAT       = '_kmfamily_cf_etat';

    /** Limite de l'envoi direct par l'API. Au-delà, TUS est requis. */
    const MAX_UPLOAD_BYTES = 209715200; // 200 Mo

    /**
     * Durée de vie du jeton de lecture. Courte à dessein : c'est la seule
     * protection qui subsiste une fois l'URL partie chez le prestataire, la
     * révocation côté serveur ne s'appliquant plus qu'à l'ouverture d'une
     * nouvelle session.
     */
    const DEFAULT_TTL = 3600;

    public static function init() {
        add_filter( 'kmfamily_media_drivers', array( __CLASS__, 'register_driver' ) );
        add_filter( 'kmfamily_media_driver_for_content', array( __CLASS__, 'route' ), 10, 2 );

        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 15 );
        add_action( 'admin_post_kmfamily_save_cf', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_kmfamily_cf_upload', array( __CLASS__, 'handle_upload' ) );

        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
    }

    public static function register_driver( $drivers ) {
        $drivers['cloudflare'] = new self();
        return $drivers;
    }

    /**
     * Quand le routeur du Lot 2 conclut « distant », on aiguille vers le
     * prestataire réellement configuré. Aucune ligne du routeur n'est modifiée :
     * tout passe par le filtre qu'il expose déjà.
     */
    public static function route( $choice, $content_id ) {
        if ( $choice !== 'remote' ) return $choice;
        if ( get_option( self::OPTION_PROVIDER, 'cloudflare' ) !== 'cloudflare' ) return $choice;
        return 'cloudflare';
    }

    // ==========================================================
    // CONTRAT DU PILOTE
    // ==========================================================

    public function get_id() { return 'cloudflare'; }

    public function is_configured() {
        return get_option( self::OPTION_CUSTOMER )
            && get_option( self::OPTION_KEY_ID )
            && get_option( self::OPTION_KEY_PEM )
            && function_exists( 'openssl_sign' );
    }

    public function is_available( $content_id ) {
        return $this->is_configured() && self::get_uid( $content_id ) !== '';
    }

    public function capabilities() {
        return array(
            'adaptive'     => true,
            'quality_menu' => true,
            'thumbnails'   => true,
            'byte_range'   => true,
        );
    }

    public static function get_uid( $content_id ) {
        $uid = (string) get_post_meta( absint( $content_id ), self::META_UID, true );
        if ( $uid ) return $uid;

        // Compatibilité avec le champ générique du Lot 2, pour qu'une
        // configuration déjà saisie à la main continue de fonctionner.
        return (string) get_post_meta( absint( $content_id ), KMFamily_Media_Driver_Remote::META_REMOTE, true );
    }

    public static function ttl() {
        $t = (int) get_option( self::OPTION_TTL, self::DEFAULT_TTL );
        return ( $t >= 60 && $t <= 86400 ) ? $t : self::DEFAULT_TTL;
    }

    public function get_playback( array $session ) {
        $uid = self::get_uid( $session['content_id'] );
        if ( ! $uid ) {
            return new WP_Error( 'no_remote', __( 'Cette vidéo n\'a pas encore été envoyée vers Cloudflare.', 'km-family' ) );
        }

        $jeton = self::sign_token( $uid, $session['user_id'] );
        if ( is_wp_error( $jeton ) ) return $jeton;

        $base = 'https://' . self::customer_host() . '/' . $jeton;

        return array(
            'type'      => 'hls',
            'src'       => $base . '/manifest/video.m3u8',
            'mime'      => 'application/vnd.apple.mpegurl',
            'poster'    => $base . '/thumbnails/thumbnail.jpg',
            'qualities' => array( 'auto', '1080p', '720p', '480p', '360p' ),
        );
    }

    private static function customer_host() {
        $code = trim( (string) get_option( self::OPTION_CUSTOMER ) );
        // Le code peut être saisi avec ou sans le préfixe « customer- ».
        if ( strpos( $code, 'customer-' ) !== 0 ) $code = 'customer-' . $code;
        return $code . '.cloudflarestream.com';
    }

    // ==========================================================
    // SIGNATURE DU JETON (JWT RS256)
    // ==========================================================

    /** Base64 dit « URL-safe », sans remplissage — exigé par le format JWT. */
    public static function b64url( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * La clé privée fournie par Cloudflare arrive encodée en base64.
     * On accepte les deux formes : certains la recopient déjà décodée.
     */
    public static function normalize_pem( $pem ) {
        $pem = trim( (string) $pem );
        if ( $pem === '' ) return '';
        if ( strpos( $pem, '-----BEGIN' ) !== false ) return $pem;

        $decoded = base64_decode( $pem, true );
        return ( $decoded && strpos( $decoded, '-----BEGIN' ) !== false ) ? $decoded : '';
    }

    /**
     * @return string|WP_Error jeton JWT
     */
    public static function sign_token( $uid, $user_id = 0, $ttl = null ) {
        if ( ! function_exists( 'openssl_sign' ) ) {
            return new WP_Error( 'no_openssl', __( 'L\'extension OpenSSL de PHP est requise pour signer les lectures.', 'km-family' ) );
        }

        $key_id = trim( (string) get_option( self::OPTION_KEY_ID ) );
        $pem    = self::normalize_pem( get_option( self::OPTION_KEY_PEM ) );

        if ( ! $key_id || ! $pem ) {
            return new WP_Error( 'no_key', __( 'Clé de signature Cloudflare absente ou illisible.', 'km-family' ) );
        }

        $now = time();
        $ttl = $ttl ?: self::ttl();

        $entete = array( 'alg' => 'RS256', 'kid' => $key_id );
        $charge = array(
            'sub' => (string) $uid,
            'kid' => $key_id,
            // 30 secondes de tolérance : l'horloge d'un hébergement mutualisé
            // n'est pas toujours parfaitement synchronisée, et un jeton
            // « pas encore valide » produit une erreur incompréhensible pour
            // le membre.
            'nbf' => $now - 30,
            'exp' => $now + $ttl,
        );

        $charge = apply_filters( 'kmfamily_cf_token_payload', $charge, $uid, $user_id );

        $signe = self::b64url( wp_json_encode( $entete ) ) . '.' . self::b64url( wp_json_encode( $charge ) );

        $cle = openssl_pkey_get_private( $pem );
        if ( ! $cle ) {
            return new WP_Error( 'bad_key', __( 'Clé privée Cloudflare invalide.', 'km-family' ) );
        }

        $signature = '';
        $ok = openssl_sign( $signe, $signature, $cle, OPENSSL_ALGO_SHA256 );
        if ( ! $ok ) {
            return new WP_Error( 'sign_failed', __( 'La signature du jeton a échoué.', 'km-family' ) );
        }

        return $signe . '.' . self::b64url( $signature );
    }

    // ==========================================================
    // ENVOI D'UNE VIDÉO
    // ==========================================================

    public static function can_upload() {
        return get_option( self::OPTION_ACCOUNT ) && get_option( self::OPTION_TOKEN ) && function_exists( 'curl_init' );
    }

    /**
     * Envoie le fichier local d'un contenu vers Cloudflare Stream.
     *
     * On utilise cURL avec CURLFile plutôt que wp_remote_post : construire un
     * corps multipart en PHP obligerait à charger tout le fichier en mémoire,
     * ce qui dépasse la limite mémoire d'un hébergement mutualisé dès la
     * première vidéo un peu longue.
     *
     * @return string|WP_Error identifiant Cloudflare
     */
    public static function upload( $content_id ) {
        $content_id = absint( $content_id );

        if ( ! self::can_upload() ) {
            return new WP_Error( 'not_configured', __( 'Identifiant de compte, jeton API et extension cURL sont requis pour l\'envoi.', 'km-family' ) );
        }

        $attachment_id = KMFamily_Media_Router::get_attachment_id( $content_id );
        $chemin = $attachment_id ? get_attached_file( $attachment_id ) : '';

        if ( ! $chemin || ! file_exists( $chemin ) ) {
            return new WP_Error( 'no_file', __( 'Aucun fichier local à envoyer pour ce contenu.', 'km-family' ) );
        }

        $taille = filesize( $chemin );
        if ( $taille > self::MAX_UPLOAD_BYTES ) {
            return new WP_Error( 'too_big', sprintf(
                __( 'Fichier de %1$s Mo : au-delà de %2$s Mo, l\'envoi doit se faire depuis le tableau de bord Cloudflare, puis l\'identifiant collé ici.', 'km-family' ),
                number_format( $taille / 1048576, 0, ',', ' ' ),
                number_format( self::MAX_UPLOAD_BYTES / 1048576, 0, ',', ' ' )
            ) );
        }

        $url = 'https://api.cloudflare.com/client/v4/accounts/'
             . rawurlencode( (string) get_option( self::OPTION_ACCOUNT ) ) . '/stream';

        $ch = curl_init( $url );
        curl_setopt_array( $ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . get_option( self::OPTION_TOKEN ) ),
            CURLOPT_POSTFIELDS     => array(
                'file' => new CURLFile( $chemin ),
                // Sans ceci, la vidéo serait lisible par toute personne
                // connaissant son identifiant, et toute la chaîne de droits
                // construite depuis le Lot 0 serait contournable par une URL.
                'requireSignedURLs' => 'true',
                'meta' => wp_json_encode( array( 'name' => get_the_title( $content_id ) ) ),
            ),
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_CONNECTTIMEOUT => 20,
        ) );

        $reponse = curl_exec( $ch );
        $erreur  = curl_error( $ch );
        $code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        curl_close( $ch );

        if ( $reponse === false ) {
            return new WP_Error( 'curl', sprintf( __( 'Envoi impossible : %s', 'km-family' ), $erreur ) );
        }

        $data = json_decode( $reponse, true );

        if ( $code !== 200 || empty( $data['success'] ) || empty( $data['result']['uid'] ) ) {
            $msg = $data['errors'][0]['message'] ?? sprintf( __( 'Réponse inattendue (HTTP %d).', 'km-family' ), $code );
            return new WP_Error( 'api', $msg );
        }

        $uid = sanitize_text_field( $data['result']['uid'] );

        update_post_meta( $content_id, self::META_UID, $uid );
        update_post_meta( $content_id, self::META_ETAT, 'envoye' );

        // Le fichier local est CONSERVÉ. Cloudflare ne permet pas de
        // retélécharger l'original, et une plateforme dont les masters
        // n'existent que chez un prestataire est une plateforme qui ne
        // s'appartient plus.
        do_action( 'kmfamily_cf_uploaded', $content_id, $uid );

        return $uid;
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function add_meta_box() {
        add_meta_box(
            'kmfamily_cf',
            __( 'KM Family — Diffusion Cloudflare', 'km-family' ),
            array( __CLASS__, 'render_meta_box' ),
            'contenu_exclusif',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        $uid    = self::get_uid( $post->ID );
        $driver = new self();
        $taille = KMFamily_Media_Router::file_size( $post->ID );
        ?>
        <?php if ( ! $driver->is_configured() ) : ?>
            <p><?php esc_html_e( 'Cloudflare Stream n\'est pas configuré.', 'km-family' ); ?>
               <a href="<?php echo esc_url( admin_url( 'admin.php?page=kmfamily-cloudflare' ) ); ?>"><?php esc_html_e( 'Configurer', 'km-family' ); ?></a></p>
        <?php elseif ( $uid ) : ?>
            <p style="color:#008a20"><strong>✔ <?php esc_html_e( 'Diffusé par Cloudflare', 'km-family' ); ?></strong></p>
            <p><code style="font-size:11px;word-break:break-all"><?php echo esc_html( $uid ); ?></code></p>
            <p class="description"><?php esc_html_e( 'Le fichier local est conservé comme master.', 'km-family' ); ?></p>
        <?php else : ?>
            <p>
                <?php if ( $taille ) : ?>
                    <?php printf( esc_html__( 'Fichier local : %s Mo', 'km-family' ), esc_html( number_format( $taille / 1048576, 1, ',', ' ' ) ) ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Aucun fichier local.', 'km-family' ); ?>
                <?php endif; ?>
            </p>
            <?php if ( $taille && $taille <= self::MAX_UPLOAD_BYTES && self::can_upload() ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="kmfamily_cf_upload" />
                    <input type="hidden" name="content_id" value="<?php echo esc_attr( $post->ID ); ?>" />
                    <?php wp_nonce_field( 'kmfamily_cf_upload_' . $post->ID, 'kmfamily_cf_nonce' ); ?>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Envoyer vers Cloudflare', 'km-family' ); ?></button>
                    <p class="description"><?php esc_html_e( 'L\'envoi peut prendre plusieurs minutes. Ne fermez pas la page.', 'km-family' ); ?></p>
                </form>
            <?php elseif ( $taille > self::MAX_UPLOAD_BYTES ) : ?>
                <p class="description"><?php esc_html_e( 'Fichier trop lourd pour un envoi direct : passez par le tableau de bord Cloudflare, puis collez l\'identifiant ci-dessous.', 'km-family' ); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public static function handle_upload() {
        $content_id = absint( $_POST['content_id'] ?? 0 );

        if ( ! current_user_can( 'edit_post', $content_id )
             || ! check_admin_referer( 'kmfamily_cf_upload_' . $content_id, 'kmfamily_cf_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        $res = self::upload( $content_id );
        $arg = is_wp_error( $res ) ? array( 'kmcf_err' => rawurlencode( $res->get_error_message() ) ) : array( 'kmcf_ok' => 1 );

        wp_safe_redirect( add_query_arg( $arg, get_edit_post_link( $content_id, 'raw' ) ) );
        exit;
    }

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Cloudflare Stream', 'km-family' ),
            __( 'Cloudflare Stream', 'km-family' ),
            'manage_options',
            'kmfamily-cloudflare',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_save_cf', 'kmfamily_cf_settings_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        $provider = sanitize_key( $_POST['provider'] ?? 'cloudflare' );
        update_option( self::OPTION_PROVIDER, in_array( $provider, array( 'bunny', 'cloudflare' ), true ) ? $provider : 'cloudflare' );

        update_option( self::OPTION_CUSTOMER, sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ) );
        update_option( self::OPTION_ACCOUNT,  sanitize_text_field( wp_unslash( $_POST['account'] ?? '' ) ) );
        update_option( self::OPTION_KEY_ID,   sanitize_text_field( wp_unslash( $_POST['key_id'] ?? '' ) ) );
        update_option( self::OPTION_TTL,      absint( $_POST['ttl'] ?? self::DEFAULT_TTL ) );

        // Jeton API et clé privée : champs en écriture seule. Ils ne sont
        // réécrits que si une valeur est fournie, et jamais réaffichés — un
        // secret recopié dans une page HTML finit dans un cache ou une capture
        // d'écran.
        $token = trim( (string) wp_unslash( $_POST['api_token'] ?? '' ) );
        if ( $token !== '' ) update_option( self::OPTION_TOKEN, $token );

        $pem = trim( (string) wp_unslash( $_POST['key_pem'] ?? '' ) );
        if ( $pem !== '' ) update_option( self::OPTION_KEY_PEM, $pem );

        wp_safe_redirect( admin_url( 'admin.php?page=kmfamily-cloudflare&saved=1' ) );
        exit;
    }

    /** Diagnostic : la configuration produit-elle un jeton valide ? */
    public static function self_test() {
        $d = new self();
        if ( ! $d->is_configured() ) {
            return array( false, __( 'Configuration incomplète.', 'km-family' ) );
        }

        $jeton = self::sign_token( 'test-uid-000000000000000000000000', 0, 60 );
        if ( is_wp_error( $jeton ) ) return array( false, $jeton->get_error_message() );

        // Vérification locale de la signature : si la clé privée est valide,
        // la clé publique qu'on en dérive doit valider ce qu'elle a signé.
        $parts = explode( '.', $jeton );
        if ( count( $parts ) !== 3 ) return array( false, __( 'Jeton mal formé.', 'km-family' ) );

        $pem = self::normalize_pem( get_option( self::OPTION_KEY_PEM ) );
        $res = openssl_pkey_get_private( $pem );
        $pub = $res ? openssl_pkey_get_details( $res )['key'] : '';

        $sig = base64_decode( strtr( $parts[2], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $parts[2] ) % 4 ) % 4 ) );
        $ok  = $pub && openssl_verify( $parts[0] . '.' . $parts[1], $sig, $pub, OPENSSL_ALGO_SHA256 ) === 1;

        return $ok
            ? array( true, __( 'Signature vérifiée localement : la configuration est cohérente.', 'km-family' ) )
            : array( false, __( 'La signature produite n\'est pas vérifiable. Vérifiez la clé privée.', 'km-family' ) );
    }

    public static function render_page() {
        $provider = get_option( self::OPTION_PROVIDER, 'cloudflare' );
        list( $test_ok, $test_msg ) = self::self_test();
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Cloudflare Stream', 'km-family' ); ?></h1>

            <?php if ( isset( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'km-family' ); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-<?php echo $test_ok ? 'success' : 'warning'; ?> inline" style="max-width:820px">
                <p><strong><?php esc_html_e( 'Diagnostic :', 'km-family' ); ?></strong> <?php echo esc_html( $test_msg ); ?></p>
            </div>

            <p style="max-width:820px">
                <?php esc_html_e( 'Cloudflare facture à la minute regardée, sans supplément régional et sans frais de bande passante. La qualité d\'encodage n\'a donc aucun effet sur la facture — inutile de brider la vidéo pour contenir les coûts.', 'km-family' ); ?>
            </p>
            <p style="max-width:820px">
                <strong><?php esc_html_e( 'À faire dès le premier jour :', 'km-family' ); ?></strong>
                <?php esc_html_e( 'la diffusion est facturée après coup, sans plafond. Configurez une alerte de budget dans votre compte Cloudflare avant de mettre la première vidéo en ligne.', 'km-family' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_save_cf" />
                <?php wp_nonce_field( 'kmfamily_save_cf', 'kmfamily_cf_settings_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Prestataire distant', 'km-family' ); ?></th>
                        <td>
                            <label><input type="radio" name="provider" value="cloudflare" <?php checked( $provider, 'cloudflare' ); ?> /> Cloudflare Stream</label><br />
                            <label><input type="radio" name="provider" value="bunny" <?php checked( $provider, 'bunny' ); ?> /> <?php esc_html_e( 'Bunny (pilote générique)', 'km-family' ); ?></label>
                            <p class="description"><?php esc_html_e( 'S\'applique aux contenus que le routeur envoie en diffusion distante. Les contenus locaux ne sont pas concernés.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="customer"><?php esc_html_e( 'Code client', 'km-family' ); ?></label></th>
                        <td>
                            <input type="text" id="customer" name="customer" class="regular-text"
                                   value="<?php echo esc_attr( get_option( self::OPTION_CUSTOMER, '' ) ); ?>"
                                   placeholder="customer-xxxxxxxxxxxxxxxx" />
                            <p class="description"><?php esc_html_e( 'Visible dans l\'URL de lecture fournie par Cloudflare.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="key_id"><?php esc_html_e( 'Identifiant de la clé de signature', 'km-family' ); ?></label></th>
                        <td><input type="text" id="key_id" name="key_id" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_KEY_ID, '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="key_pem"><?php esc_html_e( 'Clé privée', 'km-family' ); ?></label></th>
                        <td>
                            <textarea id="key_pem" name="key_pem" rows="4" class="large-text" autocomplete="off"
                                      placeholder="<?php echo get_option( self::OPTION_KEY_PEM ) ? esc_attr__( '— enregistrée —', 'km-family' ) : ''; ?>"></textarea>
                            <p class="description"><?php esc_html_e( 'Collez la valeur « pem » renvoyée par Cloudflare (encodée en base64 ou déjà décodée, les deux sont acceptées). Laisser vide pour conserver la clé actuelle : elle n\'est jamais réaffichée.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ttl"><?php esc_html_e( 'Durée de vie d\'un lien de lecture', 'km-family' ); ?></label></th>
                        <td>
                            <input type="number" id="ttl" name="ttl" class="small-text" min="60" max="86400" value="<?php echo esc_attr( self::ttl() ); ?>" /> <?php esc_html_e( 'secondes', 'km-family' ); ?>
                            <p class="description"><?php esc_html_e( 'Une heure convient à la plupart des vidéos. Une durée plus courte limite la portée d\'un lien recopié, mais coupe la lecture d\'un contenu plus long que cette durée.', 'km-family' ); ?></p>
                        </td>
                    </tr>

                    <tr><th colspan="2"><h2 style="margin:8px 0 0"><?php esc_html_e( 'Envoi des vidéos (optionnel)', 'km-family' ); ?></h2></th></tr>
                    <tr>
                        <th scope="row"><label for="account"><?php esc_html_e( 'Identifiant de compte', 'km-family' ); ?></label></th>
                        <td><input type="text" id="account" name="account" class="regular-text" value="<?php echo esc_attr( get_option( self::OPTION_ACCOUNT, '' ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="api_token"><?php esc_html_e( 'Jeton API', 'km-family' ); ?></label></th>
                        <td>
                            <input type="password" id="api_token" name="api_token" class="regular-text" autocomplete="new-password"
                                   placeholder="<?php echo get_option( self::OPTION_TOKEN ) ? esc_attr__( '— enregistré —', 'km-family' ) : ''; ?>" />
                            <p class="description"><?php esc_html_e( 'Droit « Stream : Modifier » uniquement. Sert au bouton d\'envoi depuis la fiche d\'un contenu ; sans lui, il reste possible de coller l\'identifiant d\'une vidéo envoyée manuellement.', 'km-family' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
