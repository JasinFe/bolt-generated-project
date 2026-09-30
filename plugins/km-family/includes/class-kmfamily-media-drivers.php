<?php
/**
 * KM Family — Moteur média : pilotes de diffusion (LOT 2)
 *
 * PRINCIPE : WordPress décide QUI a le droit de lire QUOI. Cette décision
 * n'est jamais dupliquée. Un « pilote » se contente ensuite de fabriquer une
 * source de lecture à partir d'une autorisation déjà accordée.
 *
 * Deux pilotes coexistent :
 *
 *  - LOCAL   : le fichier est servi par le serveur WordPress. Coût nul,
 *              aucune dépendance externe. Limite structurelle : chaque
 *              lecture en cours occupe un worker PHP pendant toute la durée
 *              du fichier. Parfait pour l'audio (quelques Mo), dangereux pour
 *              la vidéo longue.
 *
 *  - REMOTE  : le fichier est délivré par un prestataire (Bunny Stream,
 *              Cloudflare Stream, Mux…) via une URL signée. Débit adaptatif,
 *              CDN, zéro charge serveur. Coût récurrent.
 *
 * POURQUOI capabilities() EXISTE : les deux pilotes n'ont PAS les mêmes
 * fonctions. Le débit adaptatif et le menu de qualité n'existent que côté
 * distant. Si le lecteur supposait ces fonctions toujours disponibles,
 * l'abstraction mentirait et le pilote local ne pourrait pas l'honorer. Le
 * lecteur n'affiche donc un contrôle que si le pilote le déclare.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Contrat commun aux pilotes de diffusion.
 */
interface KMFamily_Media_Driver_Interface {

    /** Identifiant court et stable du pilote ('local', 'remote'). */
    public function get_id();

    /** Le pilote est-il utilisable en l'état (configuré, fichier présent) ? */
    public function is_available( $content_id );

    /**
     * Fonctions réellement offertes. Le lecteur s'y conforme strictement.
     *
     * @return array adaptive, quality_menu, thumbnails, byte_range
     */
    public function capabilities();

    /**
     * Source de lecture pour une session déjà autorisée.
     *
     * @param array $session Ligne de session média (KMFamily_Media_Session).
     * @return array|WP_Error array( 'type' => 'file'|'hls', 'src' => url, 'mime' => ..., 'qualities' => array )
     */
    public function get_playback( array $session );
}

// ══════════════════════════════════════════════════════════════
// PILOTE LOCAL
// ══════════════════════════════════════════════════════════════
class KMFamily_Media_Driver_Local implements KMFamily_Media_Driver_Interface {

    public function get_id() { return 'local'; }

    public function is_available( $content_id ) {
        return KMFamily_Media_Router::get_attachment_id( $content_id ) > 0;
    }

    public function capabilities() {
        return array(
            'adaptive'     => false, // un seul fichier, un seul débit
            'quality_menu' => false,
            'thumbnails'   => false,
            'byte_range'   => true,  // le seek fonctionne (Range HTTP)
        );
    }

    public function get_playback( array $session ) {
        $path = get_attached_file( $session['attachment_id'] );
        if ( ! $path || ! file_exists( $path ) ) {
            return new WP_Error( 'not_found', __( 'Fichier introuvable.', 'km-family' ) );
        }

        return array(
            'type'      => 'file',
            'src'       => home_url( '/km-family-stream/' . $session['session_key'] ),
            'mime'      => get_post_mime_type( $session['attachment_id'] ) ?: 'application/octet-stream',
            'qualities' => array(),
        );
    }

    /**
     * Envoi du fichier avec support Range.
     *
     * NOTE DE PERFORMANCE, à ne pas perdre de vue : cette boucle monopolise un
     * worker PHP-FPM du début à la fin du fichier. C'est acceptable pour un
     * titre audio de quelques mégaoctets, pas pour une vidéo. C'est
     * exactement la raison d'être du routage vers le pilote distant.
     */
    public function stream( array $session ) {
        $path = get_attached_file( $session['attachment_id'] );
        if ( ! $path || ! file_exists( $path ) ) {
            status_header( 404 );
            exit;
        }

        $size = filesize( $path );
        $mime = get_post_mime_type( $session['attachment_id'] );
        if ( ! $mime && function_exists( 'mime_content_type' ) ) $mime = mime_content_type( $path );
        if ( ! $mime ) $mime = 'application/octet-stream';

        $start = 0;
        $end   = $size - 1;
        $partial = false;

        if ( ! empty( $_SERVER['HTTP_RANGE'] ) ) {
            // Trois formes valides : "bytes=500-999", "bytes=500-" et
            // "bytes=-500" (les N derniers octets). L'ancienne implémentation
            // ignorait la troisième, que certains lecteurs mobiles utilisent.
            if ( preg_match( '/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m ) ) {
                if ( $m[1] === '' && $m[2] !== '' ) {
                    $start = max( 0, $size - (int) $m[2] );
                } else {
                    $start = (int) $m[1];
                    if ( $m[2] !== '' ) $end = (int) $m[2];
                }

                if ( $start > $end || $start >= $size ) {
                    header( 'HTTP/1.1 416 Requested Range Not Satisfiable' );
                    header( "Content-Range: bytes */{$size}" );
                    exit;
                }
                if ( $end >= $size ) $end = $size - 1;
                $partial = true;
            }
        }

        $length = $end - $start + 1;

        if ( $partial ) {
            status_header( 206 );
            header( "Content-Range: bytes {$start}-{$end}/{$size}" );
        }

        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . $length );
        header( 'Accept-Ranges: bytes' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, no-store' );

        // Le navigateur abandonne souvent une requête Range en cours de route
        // (seek, changement de piste). Sans ignore_user_abort(false), certains
        // hébergements laissent le worker tourner jusqu'au bout dans le vide.
        ignore_user_abort( false );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 0 );

        while ( ob_get_level() > 0 ) ob_end_clean();

        $fp = fopen( $path, 'rb' );
        if ( ! $fp ) exit;

        fseek( $fp, $start );
        $sent = 0;
        $chunk = 65536;

        while ( ! feof( $fp ) && $sent < $length && connection_status() === CONNECTION_NORMAL ) {
            $read = min( $chunk, $length - $sent );
            echo fread( $fp, $read );
            flush();
            $sent += $read;
        }

        fclose( $fp );
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// PILOTE DISTANT
// ══════════════════════════════════════════════════════════════
/**
 * Pilote pour un prestataire de diffusion à URL signée.
 *
 * Volontairement générique : il signe une URL selon le schéma le plus répandu
 * (jeton = hash du secret + chemin + expiration), ce qui couvre Bunny Stream
 * et plusieurs autres. Le schéma exact de signature est isolé dans
 * sign_url() et dans le filtre `kmfamily_remote_media_url`, pour qu'un
 * changement de prestataire n'affecte qu'une seule méthode.
 *
 * Tant qu'aucun prestataire n'est configuré, is_available() renvoie false et
 * le routeur retombe silencieusement sur le pilote local.
 */
class KMFamily_Media_Driver_Remote implements KMFamily_Media_Driver_Interface {

    const OPTION_BASE   = 'kmfamily_remote_media_base';   // https://vz-xxxx.b-cdn.net
    const OPTION_SECRET = 'kmfamily_remote_media_secret';
    const OPTION_TTL    = 'kmfamily_remote_media_ttl';    // secondes
    const META_REMOTE   = '_kmfamily_remote_id';          // identifiant du média chez le prestataire

    public function get_id() { return 'remote'; }

    public function is_configured() {
        return get_option( self::OPTION_BASE ) && get_option( self::OPTION_SECRET );
    }

    public function is_available( $content_id ) {
        return $this->is_configured() && $this->get_remote_id( $content_id ) !== '';
    }

    public function capabilities() {
        return array(
            'adaptive'     => true,
            'quality_menu' => true,
            'thumbnails'   => true,
            'byte_range'   => true,
        );
    }

    public function get_remote_id( $content_id ) {
        return (string) get_post_meta( absint( $content_id ), self::META_REMOTE, true );
    }

    public function ttl() {
        $t = (int) get_option( self::OPTION_TTL, 14400 );
        return $t > 0 ? $t : 14400;
    }

    public function get_playback( array $session ) {
        $remote_id = $this->get_remote_id( $session['content_id'] );
        if ( ! $remote_id ) {
            return new WP_Error( 'no_remote', __( 'Aucun média distant associé à ce contenu.', 'km-family' ) );
        }

        $path = '/' . rawurlencode( $remote_id ) . '/playlist.m3u8';
        $url  = $this->sign_url( $path, $session['user_id'] );

        return array(
            'type'      => 'hls',
            'src'       => $url,
            'mime'      => 'application/vnd.apple.mpegurl',
            'qualities' => array( 'auto', '1080p', '720p', '480p', '360p' ),
        );
    }

    /**
     * Signature d'URL. L'expiration est PORTÉE PAR L'URL et vérifiée par le
     * prestataire : contrairement au pilote local, nous ne sommes plus dans la
     * boucle une fois le lien remis au navigateur. C'est le prix du CDN — et
     * c'est pourquoi la durée de vie doit rester courte et la session
     * révocable côté serveur.
     */
    protected function sign_url( $path, $user_id ) {
        $base    = rtrim( (string) get_option( self::OPTION_BASE ), '/' );
        $secret  = (string) get_option( self::OPTION_SECRET );
        $expires = time() + $this->ttl();

        $token = hash( 'sha256', $secret . $path . $expires, true );
        $token = rtrim( strtr( base64_encode( $token ), '+/', '-_' ), '=' );

        $url = add_query_arg( array(
            'token'   => $token,
            'expires' => $expires,
        ), $base . $path );

        /**
         * Permet d'adapter le schéma de signature à un autre prestataire sans
         * toucher à cette classe.
         */
        return apply_filters( 'kmfamily_remote_media_url', $url, $path, $expires, $user_id );
    }
}

// ══════════════════════════════════════════════════════════════
// ROUTEUR
// ══════════════════════════════════════════════════════════════
/**
 * Choisit le pilote pour un contenu donné.
 *
 * La règle de routage est la vraie décision de ce lot : sans elle, « les deux
 * options » devient un moyen de ne jamais choisir. Par défaut, on route sur le
 * type et le poids — l'audio et les fichiers légers restent locaux, le lourd
 * part chez le prestataire — avec une surcharge possible contenu par contenu.
 */
class KMFamily_Media_Router {

    const OPTION_MODE      = 'kmfamily_media_mode';       // auto | local | remote
    const OPTION_THRESHOLD = 'kmfamily_media_threshold';  // octets
    const META_FORCE       = '_kmfamily_media_driver';    // 'local' | 'remote' sur le contenu

    const DEFAULT_THRESHOLD = 52428800; // 50 Mo

    private static $drivers = null;

    public static function drivers() {
        if ( self::$drivers === null ) {
            self::$drivers = apply_filters( 'kmfamily_media_drivers', array(
                'local'  => new KMFamily_Media_Driver_Local(),
                'remote' => new KMFamily_Media_Driver_Remote(),
            ) );
        }
        return self::$drivers;
    }

    public static function get_driver( $id ) {
        $d = self::drivers();
        return $d[ $id ] ?? $d['local'];
    }

    public static function threshold() {
        $t = (int) get_option( self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD );
        return $t > 0 ? $t : self::DEFAULT_THRESHOLD;
    }

    /** ID de la pièce jointe associée à un contenu exclusif. */
    public static function get_attachment_id( $content_id ) {
        $fichier = function_exists( 'get_field' )
            ? get_field( 'fichier_media', $content_id )
            : get_post_meta( $content_id, 'fichier_media', true );

        if ( is_numeric( $fichier ) ) return absint( $fichier );
        if ( is_array( $fichier ) ) return absint( $fichier['ID'] ?? ( $fichier['id'] ?? 0 ) );
        return 0;
    }

    public static function file_size( $content_id ) {
        $aid = self::get_attachment_id( $content_id );
        if ( ! $aid ) return 0;
        $path = get_attached_file( $aid );
        return ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
    }

    /**
     * Pilote retenu pour ce contenu.
     *
     * Ordre de décision, du plus explicite au plus général :
     *   1. surcharge posée sur le contenu ;
     *   2. mode global forcé ;
     *   3. règle automatique (type + poids) ;
     * et, en dernier ressort, repli sur le local si le pilote choisi n'est pas
     * disponible — une facture impayée ou un incident chez le prestataire ne
     * doit pas rendre la plateforme entièrement muette.
     */
    public static function resolve( $content_id ) {
        $content_id = absint( $content_id );
        $drivers    = self::drivers();

        $choice = '';

        $force = get_post_meta( $content_id, self::META_FORCE, true );
        if ( $force && isset( $drivers[ $force ] ) ) {
            $choice = $force;
        }

        if ( ! $choice ) {
            $mode = get_option( self::OPTION_MODE, 'auto' );
            if ( $mode === 'local' || $mode === 'remote' ) {
                $choice = $mode;
            }
        }

        if ( ! $choice ) {
            $type = function_exists( 'get_field' ) ? get_field( 'type_media', $content_id ) : get_post_meta( $content_id, 'type_media', true );
            $size = self::file_size( $content_id );

            // L'audio reste local quel que soit son poids : quelques mégaoctets,
            // aucune dépendance, aucun coût. La vidéo part au-delà du seuil.
            $choice = ( $type === 'audio' || $size <= self::threshold() ) ? 'local' : 'remote';
        }

        $choice = apply_filters( 'kmfamily_media_driver_for_content', $choice, $content_id );

        $driver = $drivers[ $choice ] ?? $drivers['local'];
        if ( ! $driver->is_available( $content_id ) ) {
            $driver = $drivers['local'];
        }

        return $driver;
    }
}
