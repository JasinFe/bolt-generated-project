<?php
/**
 * KM Family — Pont Billetterie (kmb_*) <-> Smart Payment Center
 * ===================================================================
 * Ce fichier ne remplace PAS le catalogue de billets (kmb_ticket_types, prix, capacité,
 * metabox d'édition) qui reste géré par le snippet "KM Évènements" — il orchestre la
 * COMMANDE et le PAIEMENT d'un billet en s'appuyant entièrement sur le socle déjà construit
 * pour les abonnements : KMFamily_Orders (cycle de vie + anti-double-activation),
 * KMFamily_Security_Tokens (preuve de clic/scan), KMFamily_Payment_Tracking (QR dynamique),
 * KMFamily_Risk_Engine (score de fraude), KMFamily_Security (rate limiting),
 * KMFamily_Payment_Methods / Payment_Gateway (Wave, Orange Money, MTN, Moov, CinetPay, Paystack).
 *
 * Ce que ce fichier ajoute de spécifique aux billets :
 *  - Calcul du montant EXCLUSIVEMENT côté serveur à partir du prix officiel du type de
 *    billet (jamais depuis une valeur envoyée par le client — même défaut corrigé que
 *    KMFamily_Payment_Gateway::init_payment() pour les paliers).
 *  - Réservation de capacité atomique ("hold") au moment de la commande, avec libération
 *    automatique si la commande n'aboutit pas (expirée / rejetée / annulée) — voir
 *    KMFamily_Orders::release_ticket_hold_if_needed().
 *  - Émission du billet (référence = order_ref, e-mail de confirmation) une fois la
 *    commande confirmée, quel que soit le chemin de confirmation (webhook automatique
 *    CinetPay/Paystack, ou validation manuelle admin pour Wave/Orange Money/MTN/Moov).
 *  - Parcours 100% invité : contrairement aux abonnements, un billet ne nécessite pas de
 *    compte KM Family (voir KMFamily_Orders::create() et can_access_order() côté
 *    Payment Center, qui traite déjà order_ref comme jeton d'accès pour un appareil non
 *    connecté).
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Tickets {

    public static function init() {
        // Reprend l'action du menu billetterie existant (kmb_reserver_v10) — voir le patch
        // du snippet billetterie : les handlers AJAX/REST délèguent maintenant ici plutôt
        // que d'écrire directement dans wp_kmb_reservations.
        add_action( 'wp_ajax_kmb_reserver_v10',        array( __CLASS__, 'ajax_create_order' ) );
        add_action( 'wp_ajax_nopriv_kmb_reserver_v10', array( __CLASS__, 'ajax_create_order' ) );

        add_action( 'rest_api_init', array( __CLASS__, 'register_endpoints' ) );

        // Notifications admin dédiées billets (badge distinct de celui des abonnements
        // dans la barre d'outils, si KMFamily_Notifications::admin_bar_pending_payments
        // souhaite les compter séparément — sinon le compteur global suffit déjà).
        add_action( 'kmfamily_order_activated', array( __CLASS__, 'maybe_log_ticket_activation' ), 10, 2 );
    }

    public static function register_endpoints() {
        register_rest_route( 'kmfamily/v1', '/create-ticket-order', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_create_order' ),
            'permission_callback' => '__return_true', // achat invité autorisé, comme kmb_reserver_v10 nopriv
        ) );
    }

    /* ─────────────────────────────────────────────────────────────
       CRÉATION DE COMMANDE
       ───────────────────────────────────────────────────────────── */

    public static function ajax_create_order() {
        check_ajax_referer( 'kmb_nonce', 'nonce' );
        $result = self::create_order( wp_unslash( $_POST ) );

        if ( is_wp_error( $result ) ) {
            $payload = array( 'message' => $result->get_error_message() );
            $err_data = $result->get_error_data();
            if ( is_array( $err_data ) ) $payload += $err_data; // register_url / login_url si présents
            wp_send_json_error( $payload );
        }
        wp_send_json_success( $result );
    }

    public static function rest_create_order( WP_REST_Request $request ) {
        $params = $request->get_params();
        // Le nonce 'kmb_nonce' (action front billetterie) reste vérifié pour cohérence avec
        // l'AJAX — le repli REST ne doit pas être une porte dérobée plus permissive que la
        // route qu'il remplace (voir KMFamily_Payment_Center::verify_rest_nonce() pour le
        // même principe côté abonnements).
        $nonce = sanitize_text_field( $params['nonce'] ?? '' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'kmb_nonce' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Session invalide ou expirée — recharge la page.', 'km-family' ), array( 'status' => 403 ) );
        }

        $result = self::create_order( $params );
        if ( is_wp_error( $result ) ) {
            $err_data = $result->get_error_data();
            $status_data = array( 'status' => 400 );
            if ( is_array( $err_data ) ) $status_data += $err_data; // register_url / login_url si présents
            return new WP_Error( 'order_failed', $result->get_error_message(), $status_data );
        }
        return new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
    }

    /**
     * Cœur de la création d'une commande de billet. Appelé par l'AJAX, le repli REST,
     * et potentiellement tout autre point d'entrée futur (ex. billetterie sur invitation).
     *
     * @return array|WP_Error { order_ref, status:'confirmed'|'pending', redirect_url, montant }
     */
    public static function create_order( array $data ) {
        if ( KMFamily_Security::is_rate_limited( 'create_ticket_order', 8, 15 * MINUTE_IN_SECONDS ) ) {
            return new WP_Error( 'rate_limited', __( 'Trop de tentatives depuis cette connexion. Réessaie dans quelques minutes.', 'km-family' ) );
        }

        // COMPTE OBLIGATOIRE : voir KMFamily_Orders::create() pour la même contrainte côté
        // stockage. Vérifiée ici EN AMONT de toute réservation de capacité — inutile de poser
        // puis relâcher un hold pour quelqu'un qui ne pourra de toute façon pas continuer.
        // C'est aussi ce qui permet le programme de fidélité (KMFamily_Loyalty) : chaque
        // billet, comme chaque abonnement, est désormais rattaché à un membre identifié.
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            $login_page = get_option( 'kmfamily_page_login' );
            $auth_url   = $login_page ? get_permalink( $login_page ) : wp_login_url();
            return new WP_Error(
                'account_required',
                __( 'Crée un compte KM Family (gratuit) pour réserver ton billet — il te servira aussi à suivre tes points de fidélité et retrouver tous tes billets.', 'km-family' ),
                array( 'register_url' => $auth_url, 'login_url' => $auth_url )
            );
        }

        $event_id = absint( $data['event_id'] ?? 0 );
        $slug     = sanitize_text_field( $data['ticket_type_id'] ?? '' );
        $quantite = max( 1, absint( $data['quantite'] ?? 1 ) );
        $user     = wp_get_current_user();

        // NOM DE L'ACHETEUR : modifiable (on peut réserver au nom d'un proche), mais l'EMAIL
        // est TOUJOURS celui du compte connecté, jamais une valeur du formulaire — sans quoi
        // n'importe qui pourrait saisir un autre email pour échapper au rate limiting par
        // compte et fausser le programme de fidélité (un même acheteur multipliant les
        // "identités" pour multiplier ses points, ou au contraire pour ne jamais en gagner).
        $prenom    = sanitize_text_field( $data['prenom'] ?? '' ) ?: $user->first_name ?: $user->display_name;
        $nom       = sanitize_text_field( $data['nom'] ?? '' ) ?: $user->last_name;
        $email     = $user->user_email;
        $telephone = sanitize_text_field( $data['telephone'] ?? '' );
        $notes     = sanitize_textarea_field( $data['notes'] ?? '' );

        if ( ! $event_id || get_post_type( $event_id ) !== 'evenements' ) {
            return new WP_Error( 'invalid_event', __( 'Évènement invalide.', 'km-family' ) );
        }
        if ( ! $prenom || ! $nom ) {
            return new WP_Error( 'invalid_buyer', __( 'Prénom et nom requis.', 'km-family' ) );
        }
        if ( ! function_exists( 'kmb_get_types' ) ) {
            return new WP_Error( 'billetterie_unavailable', __( 'Billetterie momentanément indisponible.', 'km-family' ) );
        }
        if ( get_field( 'event_status', $event_id ) === 'cancelled' ) {
            return new WP_Error( 'event_cancelled', __( 'Cet évènement a été annulé.', 'km-family' ) );
        }

        $type = self::find_ticket_type( $event_id, $slug );
        if ( ! $type ) {
            return new WP_Error( 'invalid_ticket_type', __( 'Type de billet introuvable.', 'km-family' ) );
        }
        if ( $type['complet'] ) {
            return new WP_Error( 'sold_out', __( 'Ce billet est complet.', 'km-family' ) );
        }
        if ( ! $type['dispo_vente'] ) {
            return new WP_Error( 'sales_closed', __( "La vente n'est pas ouverte.", 'km-family' ) );
        }
        if ( $quantite > $type['max_par_commande'] ) {
            return new WP_Error( 'max_exceeded', sprintf( __( 'Maximum %d billet(s) par commande.', 'km-family' ), $type['max_par_commande'] ) );
        }

        // MONTANT : toujours recalculé ici à partir du prix officiel du type de billet —
        // jamais depuis une valeur envoyée par le navigateur (même correctif que
        // KMFamily_Payment_Gateway::init_payment() pour les paliers d'abonnement).
        $montant = $type['est_gratuit'] ? 0 : (int) round( $type['prix'] * $quantite );

        // RÉSERVATION DE CAPACITÉ ATOMIQUE ("hold") : contrairement à l'ancienne
        // kmb_creer_reservation() qui lisait 'places_restantes' puis écrivait séparément
        // (fenêtre de course exploitable par deux acheteurs simultanés sur la dernière
        // place), cette étape est une unique requête UPDATE conditionnelle — seule une
        // requête concurrente peut réussir tant que la capacité est disponible.
        $claim = self::claim_capacity( $type['db_id'], $quantite, $type['places_total'] );
        if ( is_wp_error( $claim ) ) {
            return $claim;
        }

        $order_ref = KMFamily_Orders::create( array(
            'product_type' => KMFamily_Orders::PRODUCT_TICKET,
            'user_id'      => $user_id,
            'artiste_id'   => $event_id,              // réutilisé comme "context_id" (voir get_context_id())
            'palier'       => $type['slug'],           // réutilisé comme "variant_ref" (voir get_variant_ref())
            'montant'      => $montant,
            'currency'     => kmb_opt( 'devise' ) ?: 'XOF',
            'meta'         => array(
                'ticket_type_db_id' => $type['db_id'],
                'ticket_type_nom'   => $type['nom'],
                'quantite'          => $quantite,
                'prenom'            => $prenom,
                'nom'               => $nom,
                'email'             => $email,
                'telephone'         => $telephone,
                'notes'             => $notes,
                'hold_released'     => false,
            ),
        ) );

        if ( is_wp_error( $order_ref ) ) {
            // La commande n'a pas pu être créée : on libère immédiatement le hold posé
            // juste au-dessus pour ne pas bloquer une place pour rien.
            self::release_capacity( $type['db_id'], $quantite );
            return $order_ref;
        }

        // BILLET GRATUIT : aucune raison de faire passer l'acheteur par le Smart Payment
        // Center (choix du moyen de paiement, QR, déclaration...) — on confirme et on émet
        // le billet immédiatement, comme le faisait l'ancienne billetterie pour
        // est_gratuit=true. C'est le SEUL cas de confirmation instantanée : tout billet
        // payant, quel que soit le moyen choisi ensuite, passe par le parcours complet.
        if ( 0 === $montant ) {
            $activated = KMFamily_Orders::confirm_and_activate( $order_ref, array( 'transaction_id' => 'FREE' ) );
            if ( is_wp_error( $activated ) ) {
                return $activated;
            }
            return array(
                'order_ref'    => $order_ref,
                'ref'          => $order_ref, // alias pour compatibilité avec le JS front billetterie existant
                'status'       => 'confirmed',
                'redirect_url' => null,
                'montant'      => 0,
            );
        }

        return array(
            'order_ref'    => $order_ref,
            'ref'          => $order_ref, // alias pour compatibilité avec le JS front billetterie existant
            'status'       => 'pending',
            'redirect_url' => home_url( '/km-payment/' . $order_ref . '/' ),
            'montant'      => $montant,
        );
    }

    /* ─────────────────────────────────────────────────────────────
       CAPACITÉ (hold / libération) — opérations atomiques sur kmb_ticket_types
       ───────────────────────────────────────────────────────────── */

    private static function find_ticket_type( $event_id, $slug ) {
        foreach ( kmb_get_types( $event_id ) as $t ) {
            if ( $t['slug'] === $slug ) return $t;
        }
        return null;
    }

    /**
     * Réserve $quantite places de façon atomique : la clause WHERE fait tout le travail de
     * verrouillage (garanti par le moteur de stockage, pas par une lecture PHP préalable).
     * places_total NULL = capacité illimitée, pas de contrainte.
     */
    private static function claim_capacity( $db_id, $quantite, $places_total ) {
        global $wpdb;
        if ( ! $db_id ) return true; // type de billet sans ligne DB dédiée (billetterie non initialisée) — pas bloquant
        $table = $wpdb->prefix . 'kmb_ticket_types';

        if ( null === $places_total ) {
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET places_vendues = places_vendues + %d WHERE id = %d", $quantite, $db_id ) );
            return true;
        }

        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET places_vendues = places_vendues + %d
             WHERE id = %d AND places_vendues + %d <= places_total",
            $quantite, $db_id, $quantite
        ) );

        if ( ! $affected ) {
            return new WP_Error( 'sold_out', __( "Il ne reste plus assez de places pour cette quantité — quelqu'un vient peut-être de réserver les dernières.", 'km-family' ) );
        }
        return true;
    }

    private static function release_capacity( $db_id, $quantite ) {
        global $wpdb;
        if ( ! $db_id ) return;
        $table = $wpdb->prefix . 'kmb_ticket_types';
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET places_vendues = GREATEST(0, places_vendues - %d) WHERE id = %d", $quantite, $db_id ) );
    }

    /**
     * Appelé par KMFamily_Orders quand une commande de billet n'aboutit pas (expirée,
     * rejetée par l'admin, ou annulée). Idempotent via le flag 'hold_released' en meta —
     * un rejet suivi d'une expiration du même cron ne libère jamais deux fois la même place.
     */
    public static function release_hold( array $order ) {
        $meta = is_array( $order['meta'] ) ? $order['meta'] : array();
        if ( ! empty( $meta['hold_released'] ) ) return;
        if ( empty( $meta['ticket_type_db_id'] ) || empty( $meta['quantite'] ) ) return;

        self::release_capacity( (int) $meta['ticket_type_db_id'], (int) $meta['quantite'] );
        KMFamily_Orders::update_meta( $order['order_ref'], 'hold_released', true );

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order['order_ref'], 'ticket_hold_released', array(
                'quantite' => $meta['quantite'],
            ), 'system' );
        }
    }

    /* ─────────────────────────────────────────────────────────────
       ÉMISSION DU BILLET (à la confirmation)
       ───────────────────────────────────────────────────────────── */

    /**
     * Appelé par KMFamily_Orders::confirm_and_activate() une fois la commande "réservée"
     * atomiquement (protection anti-double-activation déjà assurée en amont, identique aux
     * abonnements). Le hold posé à la création DEVIENT la vente définitive : on ne touche
     * plus places_vendues ici, on se contente d'émettre le billet et de notifier l'acheteur.
     */
    public static function issue( array $order, $transaction_id ) {
        $meta = is_array( $order['meta'] ) ? $order['meta'] : array();
        if ( empty( $meta['email'] ) ) return false;

        self::send_confirmation_email( $order, $meta, $transaction_id );

        if ( class_exists( 'KMFamily_Event_Log' ) ) {
            KMFamily_Event_Log::log( $order['order_ref'], 'ticket_issued', array(
                'event_id' => KMFamily_Orders::get_context_id( $order ),
                'quantite' => $meta['quantite'] ?? 1,
            ), 'system' );
        }

        return true;
    }

    public static function maybe_log_ticket_activation( $order_ref, $order ) {
        // Point d'extension : brancher ici un webhook externe (ex. scanner d'entrée,
        // billetterie physique) si besoin plus tard — volontairement laissé simple.
    }

    /**
     * E-mail de confirmation — même identité visuelle que l'e-mail existant de la
     * billetterie (kmb_email_confirmation), réécrit pour lire une commande KM Family
     * plutôt qu'une ligne de wp_kmb_reservations.
     */
    private static function send_confirmation_email( array $order, array $meta, $transaction_id ) {
        $event_id = KMFamily_Orders::get_context_id( $order );
        $event    = get_the_title( $event_id );
        $couleur  = function_exists( 'kmb_opt' ) ? kmb_opt( 'couleur' ) : '#50C878';
        $logo     = function_exists( 'kmb_opt' ) ? kmb_opt( 'logo_url' ) : '';
        $pied     = function_exists( 'kmb_opt' ) ? kmb_opt( 'pied_email' ) : get_bloginfo( 'name' );
        $montant  = function_exists( 'kmb_prix' ) ? kmb_prix( (float) $order['montant'] ) : number_format( (float) $order['montant'], 0, ',', ' ' ) . ' ' . $order['currency'];

        $msg_perso = get_post_meta( $event_id, 'kmb_message_confirmation', true );
        $msg = $msg_perso
            ? strtr( $msg_perso, array( '{evenement}' => $event, '{event}' => $event, '{nom}' => $meta['prenom'] . ' ' . $meta['nom'], '{prenom}' => $meta['prenom'] ) )
            : sprintf( __( "%s vous remercie d'avoir réservé votre place pour « %s » ! Votre billet est confirmé.", 'km-family' ), get_bloginfo( 'name' ), $event );

        $infos    = get_post_meta( $event_id, 'kmb_infos_pratiques', true );
        $lien     = get_permalink( $event_id );
        $logo_h   = $logo ? "<img src='{$logo}' style='max-height:48px;max-width:160px;' alt=''>" : '';
        $type_nom = $meta['ticket_type_nom'] ?? '';

        $body  = "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'></head>";
        $body .= "<body style='margin:0;padding:0;background:#0D0D15;font-family:Segoe UI,Arial,sans-serif;color:#E0E0F0;'>";
        $body .= "<table width='100%' cellpadding='0' cellspacing='0' style='background:#0D0D15;padding:32px 0;'><tr><td align='center'>";
        $body .= "<table width='580' cellpadding='0' cellspacing='0' style='max-width:580px;background:#12121E;border-radius:16px;overflow:hidden;border:1px solid rgba(255,255,255,.07);'>";
        $body .= "<tr><td style='background:linear-gradient(135deg,#0A0A0F,#1A1A2E);padding:30px 36px;border-bottom:2px solid {$couleur};text-align:center;'>{$logo_h}<p style='margin:10px 0 0;font-size:12px;color:#9898B8;letter-spacing:.15em;text-transform:uppercase;'>Confirmation de réservation</p></td></tr>";
        $body .= "<tr><td style='padding:32px 36px;'>";
        $body .= "<h2 style='font-size:22px;font-weight:800;color:#fff;margin:0 0 8px;'>Réservation confirmée ✅</h2>";
        $body .= "<p style='color:#9898B8;font-size:14px;margin:0 0 24px;'>" . esc_html( $msg ) . "</p>";
        $body .= "<div style='background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);border-left:4px solid {$couleur};border-radius:0 12px 12px 0;padding:20px 22px;margin-bottom:22px;'>";
        $body .= "<table width='100%' cellpadding='5' cellspacing='0' style='font-size:14px;'>";
        $body .= "<tr><td style='color:#9898B8;width:150px;'>Référence</td><td style='color:#fff;font-weight:900;font-size:17px;letter-spacing:.05em;'>" . esc_html( $order['order_ref'] ) . "</td></tr>";
        $body .= "<tr><td style='color:#9898B8;'>Évènement</td><td style='color:#fff;font-weight:700;'>" . esc_html( $event ) . "</td></tr>";
        $body .= "<tr><td style='color:#9898B8;'>Type billet</td><td style='color:#fff;'>" . esc_html( $type_nom ) . "</td></tr>";
        $body .= "<tr><td style='color:#9898B8;'>Quantité</td><td style='color:#fff;'>" . esc_html( $meta['quantite'] ?? 1 ) . " billet(s)</td></tr>";
        $body .= "<tr><td style='color:#9898B8;'>Montant</td><td style='color:{$couleur};font-weight:900;font-size:19px;'>" . esc_html( $montant ) . "</td></tr>";
        $body .= "<tr><td style='color:#9898B8;'>Nom</td><td style='color:#fff;'>" . esc_html( ( $meta['prenom'] ?? '' ) . ' ' . ( $meta['nom'] ?? '' ) ) . "</td></tr>";
        $body .= "</table></div>";
        $body .= "<div style='background:rgba(80,200,120,.06);border:1px solid rgba(80,200,120,.2);border-radius:12px;padding:16px;margin-bottom:22px;text-align:center;'>";
        $body .= "<p style='margin:0 0 4px;font-size:11px;color:#9898B8;text-transform:uppercase;letter-spacing:.1em;'>Présentez cette référence à l'entrée</p>";
        $body .= "<p style='margin:0;font-size:30px;font-weight:900;color:{$couleur};letter-spacing:.08em;'>" . esc_html( $order['order_ref'] ) . "</p></div>";
        if ( $infos ) {
            $body .= "<div style='margin-bottom:18px;'><h4 style='color:#9898B8;font-size:11px;text-transform:uppercase;letter-spacing:.12em;margin:0 0 8px;'>Informations pratiques</h4><div style='font-size:13px;color:#C0C0D8;line-height:1.7;'>" . wp_kses_post( $infos ) . "</div></div>";
        }
        $body .= "<div style='text-align:center;margin-top:24px;'><a href='" . esc_url( $lien ) . "' style='display:inline-block;padding:13px 28px;background:linear-gradient(135deg,{$couleur},#AFFF6E);color:#0A0A0F;font-weight:800;font-size:13px;text-decoration:none;border-radius:50px;'>Voir l'évènement →</a></div>";
        $body .= "</td></tr><tr><td style='background:#0A0A0F;padding:18px 36px;text-align:center;border-top:1px solid rgba(255,255,255,.05);'><p style='margin:0;font-size:11px;color:#4A4A6A;'>" . esc_html( $pied ) . "</p></td></tr>";
        $body .= "</table></td></tr></table></body></html>";

        $from_name = function_exists( 'kmb_opt' ) ? kmb_opt( 'email_from_name' ) : get_bloginfo( 'name' );
        $from_addr = function_exists( 'kmb_opt' ) ? kmb_opt( 'email_from' ) : get_option( 'admin_email' );
        wp_mail( $meta['email'], '🎟️ Confirmation — ' . $event, $body, array( 'Content-Type: text/html; charset=UTF-8', "From: {$from_name} <{$from_addr}>" ) );

        // Notification admin (même schéma que l'ancien kmb_email_admin), en réutilisant les
        // réglages billetterie s'ils existent.
        $admin_email = function_exists( 'kmb_opt' ) ? kmb_opt( 'email_admin' ) : get_option( 'admin_email' );
        $admin_url   = admin_url( 'admin.php?page=kmfamily-subscriptions' );
        $admin_body  = "<p>Nouveau billet confirmé pour <strong>{$event}</strong></p>"
            . "<p>Référence : <strong>" . esc_html( $order['order_ref'] ) . "</strong> — " . esc_html( $meta['quantite'] ?? 1 ) . " billet(s) — " . esc_html( $montant ) . "</p>"
            . "<p>Acheteur : " . esc_html( ( $meta['prenom'] ?? '' ) . ' ' . ( $meta['nom'] ?? '' ) ) . " — " . esc_html( $meta['email'] ?? '' ) . "</p>"
            . "<p><a href='" . esc_url( $admin_url ) . "'>Gérer les commandes →</a></p>";
        wp_mail( $admin_email, '🔔 Billet confirmé — ' . $order['order_ref'], $admin_body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    /* ─────────────────────────────────────────────────────────────
       LOOKUP PUBLIC ("Mes billets" / page de confirmation)
       ───────────────────────────────────────────────────────────── */

    /**
     * Retourne les données d'affichage pour une référence donnée, que ce soit une commande
     * KM Family récente (préfixe KMORD-) ou — rétrocompatibilité — une ancienne ligne de
     * wp_kmb_reservations (préfixe KM-) créée avant cette intégration.
     */
    public static function lookup_reference( $ref ) {
        $ref = strtoupper( trim( $ref ) );
        if ( ! $ref ) return null;

        if ( 0 === strpos( $ref, 'KMORD-' ) ) {
            $order = KMFamily_Orders::get( $ref );
            if ( ! $order || ! KMFamily_Orders::is_ticket_order( $order ) ) return null;

            $meta = is_array( $order['meta'] ) ? $order['meta'] : array();
            $status_map = array(
                'active'                => 'confirmed',
                'confirming'            => 'confirmed',
                'awaiting_confirmation' => 'pending',
                'pending'               => 'pending',
                'initiated'             => 'pending',
                'expired'               => 'cancelled',
                'rejected'              => 'cancelled',
                'cancelled'             => 'cancelled',
            );

            return array(
                'ref'     => $order['order_ref'],
                'event'   => get_the_title( KMFamily_Orders::get_context_id( $order ) ),
                'prenom'  => $meta['prenom'] ?? '',
                'nom'     => $meta['nom'] ?? '',
                'qte'     => $meta['quantite'] ?? 1,
                'statut'  => $status_map[ $order['status'] ] ?? 'pending',
                'montant' => function_exists( 'kmb_prix' ) ? kmb_prix( (float) $order['montant'] ) : $order['montant'] . ' ' . $order['currency'],
            );
        }

        // Rétrocompatibilité : anciennes réservations pré-intégration.
        if ( 0 === strpos( $ref, 'KM-' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'kmb_reservations';
            if ( ! $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) return null;
            $r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ref = %s", $ref ), ARRAY_A );
            if ( ! $r ) return null;
            return array(
                'ref'     => $r['ref'],
                'event'   => get_the_title( $r['event_id'] ),
                'prenom'  => $r['prenom'],
                'nom'     => $r['nom'],
                'qte'     => $r['quantite'],
                'statut'  => $r['statut'],
                'montant' => function_exists( 'kmb_prix' ) ? kmb_prix( (float) $r['montant_total'] ) : $r['montant_total'],
            );
        }

        return null;
    }
}
