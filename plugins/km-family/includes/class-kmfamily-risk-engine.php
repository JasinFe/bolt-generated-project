<?php
/**
 * KM Family — Moteur d'analyse des risques (Smart Payment Center)
 * ===================================================================
 * Calcule un score de risque de fraude (0-100) pour une commande, à partir de
 * signaux objectifs tirés du journal d'événements chaîné et de la table des
 * commandes. Ne bloque JAMAIS automatiquement une commande — le paiement manuel
 * (Wave/Orange/MTN/Moov) reste vérifié humainement par un admin ; ce score est un
 * outil d'aide à la décision qui remonte les dossiers à examiner en priorité et
 * réduit le risque de valider une fausse déclaration par simple lassitude/volume.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Risk_Engine {

    const LEVEL_LOW      = 'low';
    const LEVEL_MEDIUM    = 'medium';
    const LEVEL_HIGH      = 'high';
    const LEVEL_CRITICAL  = 'critical';

    /**
     * Calcule le score de risque d'une commande.
     *
     * @return array { score:int, level:string, reasons:string[] }
     */
    public static function compute_score( array $order ): array {
        $score   = 0;
        $reasons = array();

        $order_ref = $order['order_ref'];
        $events    = class_exists( 'KMFamily_Event_Log' ) ? KMFamily_Event_Log::get_events_for_order( $order_ref ) : array();

        $has_signed_redirect = false;
        $declare_event_ip    = null;
        foreach ( $events as $ev ) {
            if ( $ev['event_type'] === 'redirect_confirmed' ) $has_signed_redirect = true;
            if ( $ev['event_type'] === 'payment_declared' )   $declare_event_ip = $ev['ip'];
        }

        $is_declared_or_further = in_array( $order['status'], array( 'awaiting_confirmation', 'confirmed', 'active' ), true );

        // 1) Déclaration sans aucune trace d'initiation (ni signée, ni legacy).
        if ( $is_declared_or_further && empty( $order['initiated_at'] ) ) {
            $score += 50;
            $reasons[] = __( "Déclaration de paiement sans aucune trace de clic/scan préalable.", 'km-family' );
        } elseif ( $is_declared_or_further && ! $has_signed_redirect ) {
            // Initiée, mais uniquement via l'ancien mécanisme non signé (ou jeton absent/expiré) :
            // signal plus faible que l'absence totale, mais toujours notable.
            $score += 20;
            $reasons[] = __( "Initiation enregistrée sans jeton signé de redirection (mécanisme legacy ou jeton expiré).", 'km-family' );
        }

        // 2) Délai anormalement court entre l'initiation et la déclaration.
        if ( ! empty( $order['initiated_at'] ) && ! empty( $order['declared_at'] ) ) {
            $delay = strtotime( $order['declared_at'] ) - strtotime( $order['initiated_at'] );
            if ( $delay < 10 ) {
                $score += 30;
                $reasons[] = sprintf( __( "Délai extrêmement court entre clic et déclaration (%ds).", 'km-family' ), max( 0, $delay ) );
            } elseif ( $delay < 30 ) {
                $score += 15;
                $reasons[] = sprintf( __( "Délai très court entre clic et déclaration (%ds).", 'km-family' ), max( 0, $delay ) );
            }
        }

        // 3) Adresse IP différente entre la création de la commande et la déclaration.
        if ( $declare_event_ip && ! empty( $order['ip'] ) && $declare_event_ip !== $order['ip'] ) {
            $score += 15;
            $reasons[] = __( "Adresse IP différente entre la création de la commande et la déclaration de paiement.", 'km-family' );
        }

        // 4) Référence de transaction déjà utilisée pour une AUTRE commande.
        //    Depuis la v3.4.0, le cas « l'autre commande est encore vivante » est REFUSÉ
        //    en amont par KMFamily_Orders::declare_payment() : il ne peut plus arriver ici.
        //    Ce qui reste visible à ce stade, c'est la réutilisation d'une référence dont
        //    la commande précédente a été rejetée, annulée ou expirée — légitime dans le
        //    cas d'une commande expirée avant vérification, très suspect dans le cas d'une
        //    commande précédemment REJETÉE : l'administrateur avait alors déjà jugé cette
        //    référence non valable, et elle revient sur une nouvelle commande.
        if ( ! empty( $order['proof_reference'] ) ) {
            $reutilisations = self::proof_reference_reuse_states( $order['proof_reference'], $order_ref );

            if ( ! empty( $reutilisations['rejected'] ) ) {
                $score += 55;
                $reasons[] = __( "Référence déjà déclarée sur une commande précédemment REJETÉE par un administrateur.", 'km-family' );
            } elseif ( ! empty( $reutilisations['autres'] ) ) {
                $score += 35;
                $reasons[] = __( "Cette référence de transaction a déjà été déclarée sur une autre commande (expirée ou annulée).", 'km-family' );
            }
        }

        // 6) Historique du compte : des commandes déjà rejetées pour cette personne.
        //    Un membre dont les déclarations ont déjà été invalidées mérite un second
        //    regard — c'est le signal le plus prédictif d'une récidive, et il manquait
        //    totalement : le score ne regardait que la commande en cours, jamais la
        //    personne derrière.
        if ( ! empty( $order['user_id'] ) ) {
            $rejets = self::rejected_orders_for_user( (int) $order['user_id'], $order_ref );
            if ( $rejets >= 2 ) {
                $score += 30;
                $reasons[] = sprintf( __( "Ce compte a déjà %d déclarations rejetées.", 'km-family' ), $rejets );
            } elseif ( 1 === $rejets ) {
                $score += 15;
                $reasons[] = __( "Ce compte a déjà une déclaration rejetée.", 'km-family' );
            }
        }

        // 5) Volume anormal : plusieurs commandes déclarées depuis la même IP en peu de temps.
        if ( ! empty( $order['ip'] ) && self::recent_declarations_from_ip( $order['ip'], $order_ref ) >= 3 ) {
            $score += 15;
            $reasons[] = __( "Plusieurs commandes déclarées récemment depuis la même adresse IP.", 'km-family' );
        }

        $score = min( 100, $score );

        return array(
            'score'   => $score,
            'level'   => self::level_for_score( $score ),
            'reasons' => $reasons,
        );
    }

    public static function level_for_score( int $score ): string {
        if ( $score >= 70 ) return self::LEVEL_CRITICAL;
        if ( $score >= 40 ) return self::LEVEL_HIGH;
        if ( $score >= 20 ) return self::LEVEL_MEDIUM;
        return self::LEVEL_LOW;
    }

    public static function level_label( string $level ): string {
        switch ( $level ) {
            case self::LEVEL_CRITICAL: return __( '🔴 Critique', 'km-family' );
            case self::LEVEL_HIGH:     return __( '🟠 Élevé', 'km-family' );
            case self::LEVEL_MEDIUM:   return __( '🟡 Modéré', 'km-family' );
            default:                   return __( '🟢 Faible', 'km-family' );
        }
    }

    /**
     * Où cette référence a-t-elle déjà servi, et dans quel état sont ces commandes ?
     *
     * La comparaison se fait sur la forme NORMALISÉE (voir
     * KMFamily_Orders::normalize_reference) : l'ancienne version comparait les chaînes
     * brutes, si bien qu'ajouter un espace ou changer la casse suffisait à faire passer
     * un reçu déjà utilisé pour une référence inédite.
     *
     * @return array{rejected:int, autres:int}
     */
    private static function proof_reference_reuse_states( string $reference, string $exclude_order_ref ): array {
        global $wpdb;
        $table = KMFamily_Orders::table_name();
        $norm  = KMFamily_Orders::normalize_reference( $reference );

        if ( $norm === '' ) return array( 'rejected' => 0, 'autres' => 0 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS nb FROM {$table}
             WHERE proof_ref_norm = %s AND order_ref != %s
             GROUP BY status",
            $norm, $exclude_order_ref
        ), ARRAY_A );

        $out = array( 'rejected' => 0, 'autres' => 0 );
        foreach ( (array) $rows as $row ) {
            if ( 'rejected' === $row['status'] ) {
                $out['rejected'] += (int) $row['nb'];
            } else {
                $out['autres'] += (int) $row['nb'];
            }
        }
        return $out;
    }

    /** Nombre de commandes déjà rejetées pour ce compte. */
    private static function rejected_orders_for_user( int $user_id, string $exclude_order_ref ): int {
        global $wpdb;
        if ( ! $user_id ) return 0;
        $table = KMFamily_Orders::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND order_ref != %s AND status = 'rejected'",
            $user_id, $exclude_order_ref
        ) );
    }

    private static function recent_declarations_from_ip( string $ip, string $exclude_order_ref, int $window_seconds = HOUR_IN_SECONDS ): int {
        global $wpdb;
        $table = KMFamily_Orders::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE ip = %s AND order_ref != %s AND declared_at IS NOT NULL
             AND declared_at > %s",
            $ip, $exclude_order_ref, KMFamily_Orders::local_datetime( - $window_seconds )
        ) );
    }
}
