<?php
/**
 * Passerelle de paiement — CinetPay + Paystack
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Payment_Gateway {

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_endpoints' ) );

        add_action( 'wp_ajax_kmfamily_initiate_payment',        array( __CLASS__, 'ajax_initiate_payment' ) );
        add_action( 'wp_ajax_nopriv_kmfamily_initiate_payment', array( __CLASS__, 'ajax_initiate_payment_guest' ) );
    }

    public static function register_endpoints() {
        register_rest_route( 'kmfamily/v1', '/webhook/cinetpay', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_cinetpay_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kmfamily/v1', '/webhook/paystack', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_paystack_webhook' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'kmfamily/v1', '/init-payment', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'init_payment' ),
            'permission_callback' => function() { return is_user_logged_in(); },
        ) );
    }

    public static function init_payment( WP_REST_Request $request ) {
        $artiste_id      = absint( $request->get_param( 'artiste_id' ) );
        $palier          = sanitize_key( $request->get_param( 'palier' ) );
        $montant_client  = absint( $request->get_param( 'montant' ) ); // jamais utilisé pour facturer, voir plus bas
        $periodicity     = sanitize_key( $request->get_param( 'periodicity' ) ?: 'monthly' );
        $order_ref       = sanitize_text_field( $request->get_param( 'order_ref' ) ?: '' );
        $user_id         = get_current_user_id();

        if ( ! $artiste_id || ! $palier || ! $user_id ) {
            return new WP_Error( 'invalid_params', __( 'Paramètres invalides', 'km-family' ), array( 'status' => 400 ) );
        }

        // COMPTE OBLIGATOIRE (billets ET abonnements) : la commande doit exister et appartenir
        // à l'utilisateur connecté — plus d'exception "achat invité" pour un billet.
        $order = $order_ref && class_exists( 'KMFamily_Orders' ) ? KMFamily_Orders::get( $order_ref ) : null;
        if ( ! $order || ! KMFamily_Orders::user_owns_order( $order, $user_id ) ) {
            return new WP_Error( 'invalid_order', __( 'Commande invalide.', 'km-family' ), array( 'status' => 403 ) );
        }

        $is_ticket = KMFamily_Orders::is_ticket_order( $order );

        /**
         * CORRECTIF SÉCURITÉ v3.4.1 — PALIER PAYÉ ≠ PALIER ACTIVÉ.
         * Le montant était recalculé à partir du palier/artiste/périodicité envoyés par le
         * navigateur, alors que l'activation (confirm_and_activate) utilise ceux de la
         * COMMANDE. Il suffisait de créer une commande « Diamant » puis d'appeler
         * init-payment avec palier=bronze : on payait le prix Bronze, le contrôle de montant
         * du webhook (comparé à la transaction) passait, et le palier Diamant était activé.
         * La commande est désormais l'unique source de vérité.
         */
        $artiste_id  = (int) $order['artiste_id'];
        $palier      = (string) $order['palier'];
        $periodicity = (string) ( $order['periodicity'] ?: 'monthly' );

        // Commande déjà payée/clôturée : on ne relance jamais un second encaissement.
        if ( in_array( $order['status'], array( 'confirming', 'confirmed', 'active', 'cancelled', 'rejected', 'expired' ), true ) ) {
            return new WP_Error( 'order_closed', __( 'Cette commande n\'est plus payable.', 'km-family' ), array( 'status' => 409 ) );
        }

        if ( $is_ticket ) {
            /**
             * BILLETTERIE — MONTANT : contrairement aux abonnements (recalculé ici depuis le
             * registre des paliers), le montant d'un billet a déjà été calculé et figé au prix
             * officiel au moment de la création de la commande (KMFamily_Tickets::create_order(),
             * à partir de kmb_ticket_types — jamais depuis une valeur du navigateur). On réutilise
             * donc TOUJOURS order['montant'] tel quel : le recalculer ici nécessiterait de relire
             * le prix courant du billet, qui a pu changer depuis (et romprait le prix affiché/promis
             * à l'acheteur pendant la fenêtre de paiement).
             */
            $montant = (int) $order['montant'];
            if ( $montant <= 0 ) {
                return new WP_Error( 'invalid_params', __( 'Paramètres invalides', 'km-family' ), array( 'status' => 400 ) );
            }
        } else {
            // Valider la périodicité
            if ( ! class_exists( 'KMFamily_Periodicity' ) || ! KMFamily_Periodicity::exists( $periodicity ) ) {
                $periodicity = 'monthly';
            }

            /**
             * CORRECTIF SÉCURITÉ CRITIQUE : le montant facturé était jusqu'ici celui envoyé
             * par le navigateur (absint($_POST['montant'])), jamais confronté au prix
             * officiel du palier. N'importe qui pouvait, en modifiant la requête (outils
             * développeur du navigateur, ou simple rejeu manuel), payer 100 FCFA et se voir
             * activer le palier Diamant (25 000 FCFA) — le serveur ne vérifiait jamais que
             * le montant correspondait au palier + à la périodicité demandés.
             * Le montant réel est désormais TOUJOURS recalculé ici à partir du prix officiel
             * du palier (KMFamily_Paliers) et de la périodicité (KMFamily_Periodicity) —
             * la valeur envoyée par le client n'est plus utilisée pour la facturation.
             * Seule exception légitime : le palier "Libre" (montant choisi par la personne),
             * où l'on applique simplement le minimum autorisé.
             */
            $palier_def  = class_exists( 'KMFamily_Paliers' ) ? KMFamily_Paliers::get_all() : array();
            $palier_info = $palier_def[ $palier ] ?? null;

            if ( ! $palier_info ) {
                return new WP_Error( 'invalid_palier', __( 'Palier invalide.', 'km-family' ), array( 'status' => 400 ) );
            }

            if ( ! empty( $palier_info['is_free'] ) || 'libre' === $palier ) {
                // Palier « Libre » : le montant choisi a été figé à la création de la commande.
                $montant = max( (int) $order['montant'], (int) ( $palier_info['prix_min'] ?? 500 ) );
            } else {
                $price = KMFamily_Periodicity::calculate_price( (int) $palier_info['prix'], $periodicity );
                $montant = (int) $price['total'];
            }

            if ( ! $montant ) {
                return new WP_Error( 'invalid_params', __( 'Paramètres invalides', 'km-family' ), array( 'status' => 400 ) );
            }
        }

        $settings = get_option( 'kmfamily_settings', array() );
        $gateway  = $settings['payment_gateway'] ?? 'none';

        $transaction_id = self::create_transaction( $user_id, $artiste_id, $palier, $montant, $gateway, $periodicity, $order_ref, $is_ticket );

        if ( $order_ref && class_exists( 'KMFamily_Orders' ) ) {
            KMFamily_Orders::set_gateway( $order_ref, $gateway );
            KMFamily_Orders::mark_initiated( $order_ref, wp_is_mobile() ? 'mobile' : 'desktop', $gateway );
        }

        switch ( $gateway ) {
            case 'cinetpay':
                return self::init_cinetpay_payment( $transaction_id, $user_id, $artiste_id, $palier, $montant, $order );
            case 'paystack':
                return self::init_paystack_payment( $transaction_id, $user_id, $artiste_id, $palier, $montant, $order );
            default:
                return new WP_REST_Response( array(
                    'status'         => 'pending',
                    'message'        => __( 'Aucune passerelle configurée. Contactez un administrateur.', 'km-family' ),
                    'transaction_id' => $transaction_id,
                ), 200 );
        }
    }

    private static function create_transaction( $user_id, $artiste_id, $palier, $montant, $gateway, $periodicity = 'monthly', $order_ref = '', $is_ticket = false ) {
        $transaction_id = 'KMF-' . ( $user_id ?: 'guest' ) . '-' . $artiste_id . '-' . time() . '-' . wp_generate_password( 6, false, false );

        // CORRECTIF v3.3.1 — le transient passe de 24 h à 7 jours : certaines passerelles
        // Mobile Money réessaient leur notification pendant plusieurs jours en cas de
        // problème réseau, et l'ancienne fenêtre les faisait retomber sur "txn_not_found".
        set_transient( 'kmfamily_txn_' . $transaction_id, array(
            'user_id'     => $user_id,
            'artiste_id'  => $artiste_id,
            'palier'      => $palier,
            'periodicity' => $periodicity,
            'montant'     => $montant,
            'gateway'     => $gateway,
            'is_ticket'   => $is_ticket,
            'status'      => 'pending',
            'order_ref'   => $order_ref, // relie la transaction à une commande Smart Payment Center, si présente
            'created'     => time(),
        ), 7 * DAY_IN_SECONDS );

        // Filet de sécurité durable : un transient peut être évincé par le cache objet du
        // site à tout moment. L'identifiant est donc aussi écrit dans la commande, ce qui
        // permet de retrouver le paiement même si le transient a disparu
        // (voir KMFamily_Orders::find_by_transaction et resolve_transaction ci-dessous).
        if ( $order_ref && class_exists( 'KMFamily_Orders' ) ) {
            KMFamily_Orders::update_meta( $order_ref, 'transaction_id', $transaction_id );
        }

        return $transaction_id;
    }

    /**
     * Retrouve le contexte d'une transaction : transient d'abord, commande ensuite.
     * Retourne null si la transaction est totalement inconnue du site.
     */
    private static function resolve_transaction( $transaction_id ) {
        $txn = get_transient( 'kmfamily_txn_' . $transaction_id );
        if ( is_array( $txn ) ) return $txn;

        if ( ! class_exists( 'KMFamily_Orders' ) ) return null;

        $order = KMFamily_Orders::find_by_transaction( $transaction_id );
        if ( ! $order ) return null;

        return array(
            'user_id'     => (int) $order['user_id'],
            'artiste_id'  => (int) $order['artiste_id'],
            'palier'      => $order['palier'],
            'periodicity' => $order['periodicity'],
            'montant'     => (int) $order['montant'],
            'gateway'     => $order['gateway'],
            'is_ticket'   => KMFamily_Orders::is_ticket_order( $order ),
            'status'      => $order['status'],
            'order_ref'   => $order['order_ref'],
            'recovered'   => true,
        );
    }

    /**
     * CORRECTIF SÉCURITÉ v3.3.1 — LE MONTANT RÉELLEMENT PAYÉ N'ÉTAIT JAMAIS VÉRIFIÉ.
     * Les deux webhooks se contentaient de constater "paiement accepté" et activaient
     * l'abonnement au palier demandé, sans jamais confronter la somme encaissée au montant
     * attendu. Toute divergence — session de paiement modifiée côté passerelle, montant
     * saisi manuellement plus faible sur un canal Mobile Money, transaction partielle —
     * passait donc inaperçue et donnait le palier complet. On refuse désormais un paiement
     * inférieur à l'attendu (avec une tolérance d'un franc pour les arrondis de conversion),
     * et on le journalise pour vérification manuelle plutôt que d'activer en silence.
     */
    private static function amount_matches( $transaction_id, $txn, $paid_amount ) {
        $expected = (int) ( $txn['montant'] ?? 0 );
        $paid     = (int) round( (float) $paid_amount );

        if ( $expected <= 0 ) return true; // billet gratuit : rien à comparer

        // CORRECTIF v3.4.1 : un montant absent/nul dans la réponse de la passerelle était
        // accepté (« rien de comparable ») — on active désormais uniquement sur preuve.
        if ( $paid <= 0 ) {
            error_log( sprintf( 'KM Family - montant webhook absent (%s) : activation refusée.', $transaction_id ) );
            return false;
        }

        if ( $paid + 1 < $expected ) {
            if ( class_exists( 'KMFamily_Event_Log' ) && ! empty( $txn['order_ref'] ) ) {
                KMFamily_Event_Log::log( $txn['order_ref'], 'webhook_amount_mismatch', array(
                    'transaction_id' => $transaction_id,
                    'attendu'        => $expected,
                    'recu'           => $paid,
                ), 'system' );
            }
            error_log( sprintf(
                'KM Family - montant webhook incohérent (%s) : attendu %d, reçu %d — activation refusée.',
                $transaction_id, $expected, $paid
            ) );
            return false;
        }

        return true;
    }

    /**
     * Identité du client à transmettre à la passerelle : un abonné a un compte WordPress,
     * un acheteur de billet peut être invité — dans ce cas on lit prénom/nom/email dans les
     * métadonnées de la commande (renseignées à la création par KMFamily_Tickets::create_order()).
     */
    private static function resolve_customer( $user_id, $order = null ) {
        if ( $order && KMFamily_Orders::is_ticket_order( $order ) ) {
            $meta = is_array( $order['meta'] ) ? $order['meta'] : array();
            return (object) array(
                'display_name' => trim( ( $meta['prenom'] ?? '' ) . ' ' . ( $meta['nom'] ?? '' ) ) ?: 'Client',
                'user_email'   => $meta['email'] ?? '',
            );
        }
        $user = get_userdata( $user_id );
        return $user ?: (object) array( 'display_name' => 'Client', 'user_email' => '' );
    }

    /**
     * Libellé de description envoyé à la passerelle : "Palier X" pour un abonnement,
     * "Billet — Nom du type" pour un billet.
     */
    private static function resolve_description( $artiste_id, $palier, $order = null ) {
        if ( $order && KMFamily_Orders::is_ticket_order( $order ) ) {
            $meta = is_array( $order['meta'] ) ? $order['meta'] : array();
            return sprintf(
                __( 'Billet — %1$s — %2$s', 'km-family' ),
                get_the_title( $artiste_id ),
                $meta['ticket_type_nom'] ?? $palier
            );
        }
        return sprintf(
            __( 'KM Family — %s — Palier %s', 'km-family' ),
            get_the_title( $artiste_id ),
            KMFamily_CPT::get_palier_label( $palier )
        );
    }

    /**
     * CinetPay : https://docs.cinetpay.com/api/1.0-fr/checkout/initialisation
     */
    private static function init_cinetpay_payment( $transaction_id, $user_id, $artiste_id, $palier, $montant, $order = null ) {
        $settings = get_option( 'kmfamily_settings', array() );
        $api_key  = $settings['cinetpay_apikey'] ?? '';
        $site_id  = $settings['cinetpay_siteid'] ?? '';

        if ( ! $api_key || ! $site_id ) {
            return new WP_Error( 'no_credentials', __( 'CinetPay n\'est pas configuré.', 'km-family' ), array( 'status' => 500 ) );
        }

        $customer = self::resolve_customer( $user_id, $order );

        $payload = array(
            'apikey'         => $api_key,
            'site_id'        => $site_id,
            'transaction_id' => $transaction_id,
            'amount'         => $montant,
            'currency'       => 'XOF',
            'description'    => self::resolve_description( $artiste_id, $palier, $order ),
            'return_url'     => home_url( '/paiement-reussi/' ),
            'notify_url'     => home_url( '/wp-json/kmfamily/v1/webhook/cinetpay' ),
            'customer_name'  => $customer->display_name,
            'customer_email' => $customer->user_email,
            'channels'       => 'MOBILE_MONEY',
            'lang'           => 'fr',
            'metadata'       => wp_json_encode( array(
                'user_id'    => $user_id,
                'artiste_id' => $artiste_id,
                'palier'     => $palier,
            ) ),
        );

        $response = wp_remote_post( 'https://api-checkout.cinetpay.com/v2/payment', array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['data']['payment_url'] ) ) {
            error_log( 'KM Family - init CinetPay en échec : ' . wp_json_encode( $body['message'] ?? $body['code'] ?? '' ) );
            return new WP_Error( 'cinetpay_error', __( 'Erreur CinetPay', 'km-family' ), array( 'status' => 502 ) );
        }

        return new WP_REST_Response( array(
            'status'         => 'ok',
            'payment_url'    => $body['data']['payment_url'],
            'transaction_id' => $transaction_id,
        ), 200 );
    }

    /**
     * Paystack : https://paystack.com/docs/api/transaction/
     */
    private static function init_paystack_payment( $transaction_id, $user_id, $artiste_id, $palier, $montant, $order = null ) {
        $settings = get_option( 'kmfamily_settings', array() );
        $secret   = $settings['paystack_seckey'] ?? '';

        if ( ! $secret ) {
            return new WP_Error( 'no_credentials', __( 'Paystack n\'est pas configuré.', 'km-family' ), array( 'status' => 500 ) );
        }

        $customer       = self::resolve_customer( $user_id, $order );
        $amount_subunit = $montant * 100;

        if ( ! $customer->user_email ) {
            return new WP_Error( 'missing_email', __( 'Email requis pour payer par cette voie.', 'km-family' ), array( 'status' => 400 ) );
        }

        $payload = array(
            'email'        => $customer->user_email,
            'amount'       => $amount_subunit,
            'currency'     => 'XOF',
            'reference'    => $transaction_id,
            'callback_url' => home_url( '/paiement-reussi/' ),
            // Mobile Money en premier : c'est le canal dominant en Côte d'Ivoire (Orange Money
            // en tête, puis MTN et Moov), la carte reste disponible en second choix.
            'channels'     => array( 'mobile_money', 'card' ),
            'metadata'     => array(
                'user_id'    => $user_id,
                'artiste_id' => $artiste_id,
                'palier'     => $palier,
            ),
        );

        $response = wp_remote_post( 'https://api.paystack.co/transaction/initialize', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['data']['authorization_url'] ) ) {
            error_log( 'KM Family - init Paystack en échec : ' . wp_json_encode( $body['message'] ?? '' ) );
            return new WP_Error( 'paystack_error', __( 'Erreur Paystack', 'km-family' ), array( 'status' => 502 ) );
        }

        return new WP_REST_Response( array(
            'status'         => 'ok',
            'payment_url'    => $body['data']['authorization_url'],
            'transaction_id' => $transaction_id,
        ), 200 );
    }

    public static function handle_cinetpay_webhook( WP_REST_Request $request ) {
        $settings = get_option( 'kmfamily_settings', array() );
        $api_key  = $settings['cinetpay_apikey'] ?? '';
        $site_id  = $settings['cinetpay_siteid'] ?? '';

        $transaction_id = sanitize_text_field( $request->get_param( 'cpm_trans_id' ) );
        if ( ! $transaction_id ) {
            return new WP_REST_Response( array( 'status' => 'error', 'message' => 'Missing transaction_id' ), 400 );
        }

        /**
         * CORRECTIF SÉCURITÉ v3.4.0 — AMPLIFICATION DE DÉNI DE SERVICE.
         *
         * Cet endpoint est public par nécessité (une passerelle ne peut pas s'authentifier
         * avec un cookie). L'ancien ordre des opérations en faisait une arme : à CHAQUE
         * requête reçue, même avec un identifiant de transaction inventé, le serveur
         * déclenchait un appel HTTP sortant vers CinetPay AVANT toute vérification locale,
         * avec un délai d'attente de 30 secondes. Autrement dit, une requête d'un octet
         * immobilisait un processus PHP pendant jusqu'à 30 secondes. Quelques dizaines de
         * requêtes simultanées — triviales à envoyer — suffisaient à saturer le pool de
         * processus de l'hébergement et à mettre tout le site hors ligne, sans qu'aucune
         * limite de débit n'entre en jeu.
         *
         * On inverse donc l'ordre : la transaction doit d'abord exister CHEZ NOUS. Une
         * référence inconnue est rejetée localement, en quelques millisecondes, sans
         * qu'aucun appel réseau ne soit déclenché. Le contrôle anti-fraude auprès de
         * CinetPay reste évidemment en place, mais il n'est plus atteignable par un tiers.
         */
        $txn = self::resolve_transaction( $transaction_id );
        if ( ! $txn ) {
            return new WP_REST_Response( array( 'status' => 'txn_not_found' ), 200 );
        }

        // Seconde barrière : même pour des références connues, on plafonne la cadence.
        // Une passerelle légitime ne rejoue pas la même notification en boucle.
        if ( class_exists( 'KMFamily_Security' )
             && KMFamily_Security::is_rate_limited( 'webhook_cinetpay', 10, 5 * MINUTE_IN_SECONDS, 'txn:' . $transaction_id ) ) {
            return new WP_REST_Response( array( 'status' => 'rate_limited' ), 429 );
        }

        // Double-check anti-fraude : vérifier directement chez CinetPay
        $check = wp_remote_post( 'https://api-checkout.cinetpay.com/v2/payment/check', array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'apikey'         => $api_key,
                'site_id'        => $site_id,
                'transaction_id' => $transaction_id,
            ) ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $check ) ) {
            return new WP_REST_Response( array( 'status' => 'error' ), 500 );
        }

        $body = json_decode( wp_remote_retrieve_body( $check ), true );

        if ( empty( $body['data']['status'] ) || $body['data']['status'] !== 'ACCEPTED' ) {
            return new WP_REST_Response( array( 'status' => 'not_accepted' ), 200 );
        }

        if ( ! empty( $body['data']['currency'] ) && strtoupper( $body['data']['currency'] ) !== 'XOF' ) {
            error_log( 'KM Family - devise CinetPay inattendue pour ' . $transaction_id );
            return new WP_REST_Response( array( 'status' => 'currency_mismatch' ), 200 );
        }

        // La transaction a déjà été résolue en amont (voir le correctif d'amplification).
        if ( ! self::amount_matches( $transaction_id, $txn, $body['data']['amount'] ?? 0 ) ) {
            return new WP_REST_Response( array( 'status' => 'amount_mismatch' ), 200 );
        }

        self::finalize_transaction( $transaction_id, $txn, 'cinetpay' );

        delete_transient( 'kmfamily_txn_' . $transaction_id );

        return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    public static function handle_paystack_webhook( WP_REST_Request $request ) {
        $settings = get_option( 'kmfamily_settings', array() );
        $secret   = $settings['paystack_seckey'] ?? '';

        // CORRECTIF : si Paystack n'est pas configuré (secret vide), hash_hmac() avec une clé
        // vide produisait quand même un HMAC valide et vérifiable — un webhook pourrait alors
        // être accepté avec une signature devinable. On rejette explicitement ce cas.
        if ( ! $secret ) {
            return new WP_REST_Response( array( 'status' => 'not_configured' ), 500 );
        }

        $signature = (string) $request->get_header( 'x_paystack_signature' );
        $payload   = $request->get_body();
        $computed  = hash_hmac( 'sha512', $payload, $secret );

        if ( ! $signature || ! hash_equals( $computed, $signature ) ) {
            return new WP_REST_Response( array( 'status' => 'invalid_signature' ), 401 );
        }

        $data = json_decode( $payload, true );

        if ( empty( $data['event'] ) || $data['event'] !== 'charge.success' ) {
            return new WP_REST_Response( array( 'status' => 'ignored' ), 200 );
        }

        $transaction_id = sanitize_text_field( $data['data']['reference'] ?? '' );
        $txn = self::resolve_transaction( $transaction_id );

        if ( ! $txn ) {
            // 200 plutôt que 404 : Paystack désactive un endpoint qui répond durablement
            // en erreur, et un rejeu légitime (transient déjà purgé) ne doit pas compter
            // comme un échec de livraison.
            return new WP_REST_Response( array( 'status' => 'txn_not_found' ), 200 );
        }

        if ( ! empty( $data['data']['currency'] ) && strtoupper( $data['data']['currency'] ) !== 'XOF' ) {
            return new WP_REST_Response( array( 'status' => 'currency_mismatch' ), 200 );
        }

        // Paystack exprime le montant en sous-unité (voir init_paystack_payment) : on
        // reconvertit avant comparaison avec le montant attendu, exprimé en francs.
        if ( ! self::amount_matches( $transaction_id, $txn, ( (float) ( $data['data']['amount'] ?? 0 ) ) / 100 ) ) {
            return new WP_REST_Response( array( 'status' => 'amount_mismatch' ), 200 );
        }

        self::finalize_transaction( $transaction_id, $txn, 'paystack' );

        delete_transient( 'kmfamily_txn_' . $transaction_id );

        return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    /**
     * Point unique d'activation après confirmation d'un paiement automatique.
     * Si la transaction est rattachée à une commande Smart Payment Center (order_ref),
     * on délègue entièrement à KMFamily_Orders (qui gère elle-même l'appel à
     * KMFamily_Subscriptions::activate) pour ne jamais activer deux fois le même
     * abonnement. Sans order_ref (anciens boutons non encore migrés), on garde
     * l'activation directe pour rester rétrocompatible.
     */
    private static function finalize_transaction( $transaction_id, $txn, $gateway ) {
        if ( ! empty( $txn['order_ref'] ) && class_exists( 'KMFamily_Orders' ) ) {
            KMFamily_Orders::confirm_and_activate( $txn['order_ref'], array(
                'transaction_id' => $transaction_id,
            ) );
            return;
        }

        KMFamily_Subscriptions::activate( $txn['user_id'], $txn['artiste_id'], $txn['palier'], array(
            'transaction_id' => $transaction_id,
            'montant'        => $txn['montant'],
            'gateway'        => $gateway,
            'periodicity'    => $txn['periodicity'] ?? 'monthly',
        ) );
    }

    public static function ajax_initiate_payment() {
        check_ajax_referer( 'kmfamily_nonce', 'nonce' );

        $request = new WP_REST_Request( 'POST' );
        $request->set_param( 'artiste_id',  absint( $_POST['artiste_id'] ?? 0 ) );
        $request->set_param( 'palier',      sanitize_key( $_POST['palier'] ?? '' ) );
        $request->set_param( 'montant',     absint( $_POST['montant'] ?? 0 ) );
        $request->set_param( 'periodicity', sanitize_key( $_POST['periodicity'] ?? 'monthly' ) );
        $request->set_param( 'order_ref',   sanitize_text_field( $_POST['order_ref'] ?? '' ) );

        $result = self::init_payment( $request );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result->get_data() );
    }

    public static function ajax_initiate_payment_guest() {
        $login_page = get_option( 'kmfamily_page_login' );
        $auth_url   = $login_page ? get_permalink( $login_page ) : wp_login_url();

        // COMPTE OBLIGATOIRE (billets ET abonnements) : voir KMFamily_Orders::create() et
        // KMFamily_Tickets::create_order(). Un billet ne peut plus atteindre cette étape sans
        // compte — la contrainte est vérifiée bien plus tôt, à la création de la commande.
        wp_send_json_error( array(
            'message'      => __( 'Vous devez créer un compte pour rejoindre la KM Family.', 'km-family' ),
            'register_url' => $auth_url,
            'login_url'    => $auth_url,
        ) );
    }
}
