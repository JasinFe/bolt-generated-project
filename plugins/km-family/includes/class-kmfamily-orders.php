<?php
/**
 * KM Family — Commandes (Smart Payment Center)
 *
 * Table dédiée pour suivre le cycle de vie d'une commande, quel que soit
 * le moyen de paiement (automatique via API/webhook, ou manuel via lien
 * marchand + déclaration utilisateur + confirmation admin).
 *
 * Cycle : pending -> initiated -> (awaiting_confirmation) -> confirmed -> active
 *                                                          -> expired / cancelled / failed
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Orders {

    const DB_VERSION_OPTION = 'kmfamily_orders_db_version';
    // 1.2 : AUDIT QUALITÉ — deux bugs réels trouvés en testant avec des données réalistes :
    //  1) `status VARCHAR(20)` était trop court pour sa propre valeur 'awaiting_confirmation'
    //     (21 caractères) — silencieusement tronquée en 'awaiting_confirmatio' en mode SQL non
    //     strict (ou en échec d'écriture pur en mode strict), ce qui cassait TOUTE requête
    //     `WHERE status = 'awaiting_confirmation'` (liste admin, compteur de notifications,
    //     Risk Engine...). Élargi à VARCHAR(30) + réparation ponctuelle des lignes déjà
    //     tronquées ci-dessous.
    //  2) `ip` et `proof_reference` n'avaient aucun index alors que le Risk Engine les
    //     interroge à chaque déclaration de paiement ET à chaque affichage de la liste des
    //     commandes en attente (une requête par commande affichée) — scan complet de table
    //     garanti dès que la table grossit. Index ajoutés.
    // 1.3 : INTÉGRATION BILLETTERIE — ajout de `product_type` pour distinguer les commandes
    // d'abonnement ('subscription', comportement inchangé, valeur par défaut) des commandes
    // de billets ('ticket'). Par choix délibéré, on NE crée PAS de nouvelle table : les
    // colonnes existantes sont réinterprétées selon le type de produit plutôt que dupliquées,
    // pour que TOUT le socle anti-fraude (jetons signés, tracking QR, risk engine, rate
    // limiting, file d'attente admin, repli REST anti-WAF) profite aux billets sans réécriture :
    //   - artiste_id  -> pour un billet : ID de l'évènement (CPT 'evenements')
    //   - palier      -> pour un billet : slug du type de billet (kmb_types_billets)
    //   - periodicity -> pour un billet : toujours 'once' (non pertinent, conservé pour schéma)
    //   - meta (JSON, déjà prévu extensible) -> pour un billet : prenom/nom/email/telephone/
    //     notes/quantite de l'acheteur (voir KMFamily_Tickets)
    // Utiliser get_context_id()/get_variant_ref() plutôt que artiste_id/palier directement
    // dans tout nouveau code, pour rester lisible indépendamment du type de produit.
    // 1.4 : ANTI-FRAUDE — colonne `proof_ref_norm`. La reference de transaction declaree
    // par l'acheteur etait comparee telle quelle : « AB-123 », « ab 123 » et « AB123 »
    // etaient donc consideres comme trois references differentes, alors qu'il s'agit du
    // meme recu Mobile Money. Il suffisait d'ajouter un espace pour reutiliser un recu
    // deja consomme sur une autre commande. On stocke desormais une forme normalisee
    // (majuscules, uniquement lettres et chiffres), indexee, qui sert aux comparaisons.
    const DB_VERSION        = '1.4';
    const ORDER_TTL         = 24 * HOUR_IN_SECONDS;
    const TICKET_ORDER_TTL  = 45 * MINUTE_IN_SECONDS; // billets : capacité limitée, on libère vite une place non payée

    const PRODUCT_SUBSCRIPTION = 'subscription';
    const PRODUCT_TICKET       = 'ticket';

    public static function init() {
        self::maybe_upgrade_table();
        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'expire_stale_orders' ) );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'kmfamily_orders';
    }

    /**
     * Créer/mettre à jour la table via dbDelta (idempotent).
     */
    public static function maybe_upgrade_table() {
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;
        self::create_table();
        self::repair_truncated_status();
        self::backfill_proof_ref_norm();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Réparation ponctuelle (v1.2) : sur une base qui tournait en mode SQL non strict,
     * `status` a pu être tronqué en 'awaiting_confirmatio' (20 caractères) avant que la
     * colonne ne soit élargie ci-dessus. Sans ce correctif, ces commandes resteraient
     * invisibles pour toujours dans la liste "en attente de confirmation", même après
     * l'élargissement de la colonne (les données déjà écrites ne se corrigent pas
     * toutes seules). Sans effet si la colonne n'a jamais été tronquée (requête à 0 ligne).
     */
    private static function repair_truncated_status() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = %s WHERE status = %s",
            'awaiting_confirmation', 'awaiting_confirmatio'
        ) );
    }

    /**
     * Remplit `proof_ref_norm` pour les commandes deja declarees avant la v1.4 du schema.
     * Sans cela, le controle anti-doublon ne verrait pas l'historique existant et une
     * reference deja utilisee avant la mise a jour resterait rejouable une fois.
     */
    private static function backfill_proof_ref_norm() {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            "SELECT order_ref, proof_reference FROM {$table}
             WHERE proof_reference IS NOT NULL AND proof_reference != '' AND proof_ref_norm IS NULL
             LIMIT 5000",
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $wpdb->update(
                $table,
                array( 'proof_ref_norm' => self::normalize_reference( $row['proof_reference'] ) ),
                array( 'order_ref' => $row['order_ref'] ),
                array( '%s' ), array( '%s' )
            );
        }
    }

    /**
     * Forme comparable d'une reference de transaction : majuscules, lettres et chiffres
     * uniquement. « AB-123 », « ab 123 » et « Ab123 » donnent tous « AB123 ».
     */
    public static function normalize_reference( $reference ) {
        $norm = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $reference ) );
        return substr( $norm, 0, 120 );
    }

    /**
     * Cette reference a-t-elle deja servi sur une AUTRE commande encore en vie ?
     *
     * Un meme recu Mobile Money ne peut pas couvrir deux commandes distinctes : c'est la
     * fraude la plus simple et la plus rentable sur un paiement verifie a la main — payer
     * une fois, puis declarer le meme recu sur dix commandes en esperant que l'administrateur
     * ne recoupe pas. Jusqu'ici, ce cas n'ajoutait que 35 points au score de risque, soit un
     * niveau « eleve » qui n'empechait PAS la confirmation en un clic (seul le niveau
     * critique, a 70 points, est bloque).
     *
     * On ne bloque volontairement PAS si l'autre commande a ete rejetee, annulee ou expiree :
     * dans ce cas la reference n'a jamais ete honoree, et l'acheteur a le droit de la
     * redeclarer sur une nouvelle commande (cas legitime frequent : la commande precedente a
     * expire avant la verification).
     *
     * @return string|null La reference de la commande en conflit, ou null.
     */
    public static function find_reference_conflict( $reference, $exclude_order_ref ) {
        global $wpdb;

        $norm = self::normalize_reference( $reference );
        if ( $norm === '' ) return null;

        $table = self::table_name();
        $ref = $wpdb->get_var( $wpdb->prepare(
            "SELECT order_ref FROM {$table}
             WHERE proof_ref_norm = %s AND order_ref != %s
               AND status IN ( 'awaiting_confirmation', 'confirming', 'confirmed', 'active' )
             ORDER BY id ASC LIMIT 1",
            $norm, $exclude_order_ref
        ) );

        return $ref ?: null;
    }

    public static function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_ref VARCHAR(40) NOT NULL,
            product_type VARCHAR(20) NOT NULL DEFAULT 'subscription',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            artiste_id BIGINT UNSIGNED NOT NULL,
            palier VARCHAR(30) NOT NULL,
            periodicity VARCHAR(20) NOT NULL DEFAULT 'monthly',
            montant BIGINT UNSIGNED NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'XOF',
            gateway VARCHAR(30) NOT NULL DEFAULT 'none',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            device VARCHAR(10) DEFAULT NULL,
            proof_reference VARCHAR(120) DEFAULT NULL,
            proof_ref_norm VARCHAR(120) DEFAULT NULL,
            proof_note TEXT DEFAULT NULL,
            admin_note TEXT DEFAULT NULL,
            meta LONGTEXT DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            initiated_at DATETIME DEFAULT NULL,
            declared_at DATETIME DEFAULT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            active_at DATETIME DEFAULT NULL,
            rejected_at DATETIME DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_ref (order_ref),
            KEY user_id (user_id),
            KEY status (status),
            KEY product_type (product_type),
            KEY artiste_id (artiste_id),
            KEY ip (ip),
            KEY proof_reference (proof_reference),
            KEY proof_ref_norm (proof_ref_norm),
            KEY declared_at (declared_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    /**
     * Générer une référence de commande courte, lisible et unique.
     * Format : KMORD-XXXXXXXXXX (10 caractères alphanumériques majuscules).
     * Volontairement court : impacte directement la densité du QR code.
     */
    public static function generate_ref() {
        global $wpdb;
        $table = self::table_name();

        do {
            $ref = 'KMORD-' . strtoupper( wp_generate_password( 10, false, false ) );
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_ref = %s", $ref ) );
        } while ( $exists );

        return $ref;
    }

    /**
     * Créer une nouvelle commande à l'état "pending".
     *
     * @return string|WP_Error order_ref ou erreur
     */
    public static function create( $args ) {
        global $wpdb;

        $defaults = array(
            'product_type' => self::PRODUCT_SUBSCRIPTION,
            'user_id'     => 0,
            'artiste_id'  => 0,
            'palier'      => '',
            'periodicity' => 'monthly',
            'montant'     => 0,
            'currency'    => 'XOF',
            'gateway'     => 'none',
            'meta'        => array(),
        );
        $args = wp_parse_args( $args, $defaults );
        $product_type = in_array( $args['product_type'], array( self::PRODUCT_SUBSCRIPTION, self::PRODUCT_TICKET ), true )
            ? $args['product_type'] : self::PRODUCT_SUBSCRIPTION;
        $is_ticket = $product_type === self::PRODUCT_TICKET;

        // COMPTE OBLIGATOIRE (billets ET abonnements) : un billet sans compte ne peut
        // alimenter aucun programme de fidélité — voir KMFamily_Loyalty. L'achat invité,
        // permis dans une itération précédente de cette intégration, est donc retiré ici.
        if ( ! $args['user_id'] ) {
            return new WP_Error( 'account_required', __( 'Un compte KM Family est requis pour continuer.', 'km-family' ) );
        }
        if ( ! $args['artiste_id'] || ! $args['palier'] ) {
            return new WP_Error( 'invalid_order', __( 'Paramètres de commande invalides.', 'km-family' ) );
        }
        // Un billet gratuit a un montant de 0 par conception (kmb_types_billets.est_gratuit) :
        // seul un abonnement (jamais gratuit dans ce système) exige un montant strictement positif.
        if ( ! $is_ticket && ! $args['montant'] ) {
            return new WP_Error( 'invalid_order', __( 'Paramètres de commande invalides.', 'km-family' ) );
        }
        if ( $is_ticket && $args['montant'] < 0 ) {
            return new WP_Error( 'invalid_order', __( 'Montant invalide.', 'km-family' ) );
        }

        if ( ! $is_ticket && ! KMFamily_Paliers::exists( $args['palier'] ) ) {
            return new WP_Error( 'invalid_palier', __( 'Palier inconnu.', 'km-family' ) );
        }

        $ref   = self::generate_ref();
        $table = self::table_name();
        $now   = current_time( 'mysql' );
        $ttl   = $is_ticket ? self::TICKET_ORDER_TTL : self::ORDER_TTL;

        $wpdb->insert( $table, array(
            'order_ref'    => $ref,
            'product_type' => $product_type,
            'user_id'      => absint( $args['user_id'] ),
            'artiste_id'   => absint( $args['artiste_id'] ),
            'palier'       => sanitize_key( $args['palier'] ),
            'periodicity'  => $is_ticket ? 'once' : sanitize_key( $args['periodicity'] ),
            'montant'      => absint( $args['montant'] ),
            'currency'     => sanitize_text_field( $args['currency'] ),
            'gateway'      => sanitize_key( $args['gateway'] ),
            'status'       => 'pending',
            'ip'           => self::get_client_ip(),
            'meta'         => wp_json_encode( is_array( $args['meta'] ) ? $args['meta'] : array() ),
            'created_at'   => $now,
            // CORRECTIF v3.3.1 — FUSEAU HORAIRE.
            // `expires_at` était calculé sur l'horloge UTC (time()) alors que created_at et
            // TOUTES les comparaisons d'expiration utilisent l'heure locale du site
            // (current_time('mysql')). Sur un site dont le fuseau n'est pas UTC, l'écart se
            // reportait tel quel sur la durée de vie réelle : avec un décalage de +2 h, une
            // commande de billet (TTL 45 min) naissait DÉJÀ expirée et sa place réservée était
            // libérée au premier passage du cron, avant même que l'acheteur ait pu payer.
            // On reste désormais sur la même horloge que le reste de la table.
            'expires_at'   => self::local_datetime( $ttl ),
        ), array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

        return $ref;
    }

    /**
     * Accesseurs lisibles, indépendants du type de produit — préférer ceux-ci à artiste_id/
     * palier directement dans tout nouveau code touchant potentiellement des billets.
     */
    public static function get_context_id( array $order ) {
        return absint( $order['artiste_id'] );
    }
    public static function get_variant_ref( array $order ) {
        return $order['palier'];
    }
    public static function is_ticket_order( array $order ) {
        return ( $order['product_type'] ?? self::PRODUCT_SUBSCRIPTION ) === self::PRODUCT_TICKET;
    }

    /**
     * CORRECTIF v3.3.1 — FILET DE SÉCURITÉ DES WEBHOOKS.
     * Une transaction de paiement n'existait QUE dans un transient de 24 h
     * (KMFamily_Payment_Gateway::create_transaction). Si le webhook arrivait après son
     * expiration — passerelle en retard, file de notification bloquée, nouvelle tentative
     * le lendemain — ou si le cache objet du site avait évincé la clé, la réponse était
     * "txn_not_found" : l'argent était encaissé chez la passerelle mais l'abonnement
     * n'était JAMAIS activé, sans aucune trace exploitable.
     * On enregistre donc aussi l'identifiant de transaction dans la commande, ce qui
     * permet de la retrouver même transient perdu.
     */
    public static function find_by_transaction( $transaction_id ) {
        global $wpdb;
        $transaction_id = sanitize_text_field( $transaction_id );
        if ( ! $transaction_id ) return null;

        $table = self::table_name();
        $ref   = $wpdb->get_var( $wpdb->prepare(
            "SELECT order_ref FROM {$table} WHERE meta LIKE %s ORDER BY id DESC LIMIT 1",
            '%' . $wpdb->esc_like( '"transaction_id":"' . $transaction_id . '"' ) . '%'
        ) );

        return $ref ? self::get( $ref ) : null;
    }

    public static function get( $order_ref ) {
        global $wpdb;
        $table = self::table_name();
        $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_ref = %s", $order_ref ), ARRAY_A );

        if ( $order && ! empty( $order['meta'] ) ) {
            $decoded = json_decode( $order['meta'], true );
            $order['meta'] = is_array( $decoded ) ? $decoded : array();
        }

        return $order ?: null;
    }

    /**
     * Une commande n'appartient qu'à son propriétaire (sauf admin) : vérification systématique
     * avant toute lecture/action déclenchée côté front.
     */
    public static function user_owns_order( $order, $user_id ) {
        if ( ! $order ) return false;
        if ( current_user_can( 'manage_options' ) ) return true;
        return absint( $order['user_id'] ) === absint( $user_id );
    }

    /**
     * Fusionner une clé dans les métadonnées JSON d'une commande (ex. résultat du
     * moteur de risque). N'écrase jamais les autres clés déjà présentes.
     */
    public static function update_meta( $order_ref, $key, $value ) {
        global $wpdb;
        $order = self::get( $order_ref );
        if ( ! $order ) return false;

        $meta         = is_array( $order['meta'] ) ? $order['meta'] : array();
        $meta[ $key ] = $value;

        return $wpdb->update( self::table_name(),
            array( 'meta' => wp_json_encode( $meta ) ),
            array( 'order_ref' => $order_ref ),
            array( '%s' ), array( '%s' )
        );
    }

    public static function set_gateway( $order_ref, $gateway ) {
        global $wpdb;
        $wpdb->update( self::table_name(),
            array( 'gateway' => sanitize_key( $gateway ) ),
            array( 'order_ref' => $order_ref ),
            array( '%s' ), array( '%s' )
        );
    }

    /**
     * Marquer la commande comme "initiée" : l'utilisateur a effectivement vu/déclenché
     * un moyen de paiement (clic bouton, affichage QR, copie du lien).
     */
    public static function mark_initiated( $order_ref, $device = null, $gateway = null ) {
        global $wpdb;
        $order = self::get( $order_ref );
        if ( ! $order ) return false;
        if ( ! in_array( $order['status'], array( 'pending', 'initiated' ), true ) ) return false;

        $data = array( 'status' => 'initiated' );
        if ( empty( $order['initiated_at'] ) ) {
            $data['initiated_at'] = current_time( 'mysql' );
        }
        if ( $device ) $data['device'] = sanitize_key( $device );
        if ( $gateway ) $data['gateway'] = sanitize_key( $gateway );

        // BUGFIX : le résultat de $wpdb->update() n'était jamais vérifié — en cas d'échec
        // silencieux (erreur SQL, ligne non trouvée...), la fonction retournait quand même
        // "true" et l'événement était déclenché comme si tout s'était bien passé.
        $updated = $wpdb->update( self::table_name(), $data, array( 'order_ref' => $order_ref ) );
        if ( false === $updated ) {
            error_log( 'KM Family - échec mark_initiated (' . $order_ref . ') : ' . $wpdb->last_error );
            return false;
        }

        do_action( 'kmfamily_order_initiated', $order_ref, $order );
        return true;
    }

    /**
     * L'admin rejette une déclaration de paiement (référence introuvable dans l'app,
     * montant ne correspondant pas, doublon, tentative frauduleuse...).
     * MANQUE COMBLÉ : jusqu'ici, la seule action possible sur une commande déclarée
     * était "Confirmer" — aucun moyen de refuser une déclaration erronée ou frauduleuse
     * sans soit activer à tort un abonnement non payé, soit laisser la commande
     * indéfiniment coincée dans la liste d'attente.
     */
    public static function reject( $order_ref, $admin_note = '' ) {
        global $wpdb;
        $order = self::get( $order_ref );
        if ( ! $order ) return new WP_Error( 'not_found', __( 'Commande introuvable.', 'km-family' ) );

        if ( in_array( $order['status'], array( 'confirmed', 'active' ), true ) ) {
            return new WP_Error( 'already_confirmed', __( 'Cette commande est déjà validée, elle ne peut plus être rejetée.', 'km-family' ) );
        }

        $updated = $wpdb->update( self::table_name(), array(
            'status'      => 'rejected',
            'admin_note'  => sanitize_textarea_field( $admin_note ),
            'rejected_at' => current_time( 'mysql' ),
        ), array( 'order_ref' => $order_ref ) );

        if ( false === $updated ) {
            return new WP_Error( 'db_error', __( "Échec de l'enregistrement du rejet.", 'km-family' ) );
        }

        self::release_ticket_hold_if_needed( $order );
        do_action( 'kmfamily_order_rejected', $order_ref, $order, $admin_note );
        return true;
    }

    /**
     * L'utilisateur déclare avoir payé (moyens manuels : Wave, Orange Money, MTN, Moov).
     * Ne confirme rien automatiquement — place la commande en file d'attente admin.
     */
    public static function declare_payment( $order_ref, $reference, $note = '' ) {
        global $wpdb;
        $table = self::table_name();
        $order = self::get( $order_ref );
        if ( ! $order ) return new WP_Error( 'not_found', __( 'Commande introuvable.', 'km-family' ) );

        if ( in_array( $order['status'], array( 'confirmed', 'active' ), true ) ) {
            return new WP_Error( 'already_confirmed', __( 'Cette commande est déjà validée.', 'km-family' ) );
        }

        /**
         * MESURE ANTI-FRAUDE : jusqu'ici, n'importe qui connaissant une référence de
         * commande (order_ref) pouvait déclarer un paiement sans jamais avoir cliqué
         * sur "Payer avec X" ni scanné le QR — rien ne reliait la déclaration à une
         * réelle tentative de paiement. On exige désormais qu'un moyen de paiement ait
         * été explicitement initié (mark_initiated, déclenché par le clic sur le bouton
         * ou le QR) avant d'accepter une déclaration. Ça ne prouve pas le paiement en
         * lui-même — seule la vérification manuelle de la référence dans l'app le fait —
         * mais ça ferme la fraude la plus simple (déclarer sans même avoir ouvert l'app),
         * et l'horodatage d'initiation aide l'admin à juger la plausibilité du délai.
         */
        if ( empty( $order['initiated_at'] ) ) {
            return new WP_Error(
                'not_initiated',
                __( 'Merci de d\'abord cliquer sur "Payer avec" ton moyen de paiement (ou scanner le QR) avant de confirmer ta transaction.', 'km-family' )
            );
        }

        $reference = sanitize_text_field( $reference );
        $note      = sanitize_textarea_field( $note );
        $now       = current_time( 'mysql' );

        /**
         * MESURE ANTI-FRAUDE v3.4.0 — UN RECU, UNE COMMANDE.
         * Voir find_reference_conflict() pour le detail du raisonnement : reutiliser un
         * meme recu Mobile Money sur plusieurs commandes est la fraude la plus simple et
         * la plus rentable de ce parcours, et le score de risque seul ne l'empechait pas
         * d'etre confirmee en un clic. C'est desormais un refus, pas un avertissement.
         */
        $conflit = self::find_reference_conflict( $reference, $order_ref );
        if ( $conflit ) {
            if ( class_exists( 'KMFamily_Event_Log' ) ) {
                KMFamily_Event_Log::log( $order_ref, 'declare_rejected_duplicate_reference', array(
                    'reference'       => $reference,
                    'commande_source' => $conflit,
                ), 'user:' . get_current_user_id() );
            }
            return new WP_Error(
                'reference_deja_utilisee',
                __( "Cette référence de transaction a déjà été déclarée pour une autre commande. Si tu as réellement effectué deux paiements distincts, renseigne la référence propre à CE paiement. En cas de doute, contacte-nous en précisant les deux commandes.", 'km-family' )
            );
        }

        $reference_norm = self::normalize_reference( $reference );

        // AUDIT QUALITÉ — PROTECTION CONTRE LA DOUBLE SOUMISSION : la première déclaration
        // doit notifier l'admin (do_action ci-dessous) ; une resoumission (double-clic,
        // requête réseau rejouée automatiquement par le navigateur, ou simple correction
        // volontaire d'une référence mal saisie) ne doit PAS renvoyer un deuxième e-mail.
        // La clause WHERE ci-dessous rend la distinction atomique et fiable : si la commande
        // est DÉJÀ en 'awaiting_confirmation', cet UPDATE n'affecte aucune ligne (0), et on
        // sait alors qu'il s'agit d'une resoumission — sans fenêtre de course possible entre
        // la lecture du statut et l'écriture (même logique que confirm_and_activate()).
        $affected = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'awaiting_confirmation', proof_reference = %s, proof_ref_norm = %s, proof_note = %s, declared_at = %s
             WHERE order_ref = %s AND status != 'awaiting_confirmation'",
            $reference, $reference_norm, $note, $now, $order_ref
        ) );

        if ( false === $affected ) {
            error_log( 'KM Family - échec declare_payment (' . $order_ref . ') : ' . $wpdb->last_error );
            return new WP_Error( 'db_update_failed', __( "L'enregistrement a échoué côté serveur (erreur base de données). Réessaie, ou contacte-nous avec ta référence de transaction si le problème persiste.", 'km-family' ) );
        }

        if ( 0 === $affected ) {
            // Resoumission : on met à jour la référence/note (utile si l'utilisateur corrige
            // une faute de frappe) sans redéclencher la notification admin ni le journal
            // d'événement de "première déclaration".
            $wpdb->update( $table, array(
                'proof_reference' => $reference,
                'proof_ref_norm'  => $reference_norm,
                'proof_note'      => $note,
            ), array( 'order_ref' => $order_ref ) );
            return true;
        }

        do_action( 'kmfamily_order_declared', $order_ref, $order );
        return true;
    }

    /**
     * Confirmer le paiement et activer l'abonnement en une seule opération atomique.
     * Appelée soit automatiquement (webhook CinetPay/Paystack), soit manuellement (admin).
     */
    public static function confirm_and_activate( $order_ref, $args = array() ) {
        global $wpdb;
        $table = self::table_name();

        $order = self::get( $order_ref );
        if ( ! $order ) return new WP_Error( 'not_found', __( 'Commande introuvable.', 'km-family' ) );

        if ( $order['status'] === 'active' ) {
            return true; // déjà traitée — idempotent (évite double-activation si webhook rejoué)
        }

        // AUDIT QUALITÉ — PROTECTION CONTRE LA CONCURRENCE : l'ancien code lisait le statut
        // ('SELECT'), le testait en PHP, puis écrivait 'active' seulement à la toute fin.
        // Entre la lecture et l'écriture, deux appels concurrents (double-clic admin, webhook
        // + confirmation manuelle en même temps, deux onglets admin ouverts) passaient TOUS
        // LES DEUX le test "pas encore actif", et activaient l'abonnement DEUX FOIS — vérifié
        // empiriquement avec deux requêtes SQL lancées en parallèle réel. On "réserve"
        // maintenant la commande de façon atomique AVANT tout effet de bord irréversible :
        // seule UNE requête concurrente peut réussir cet UPDATE (garanti par le verrouillage
        // de ligne du moteur de stockage, pas par la logique PHP) — l'autre reçoit 0 ligne
        // affectée et s'arrête immédiatement, sans jamais activer deux fois.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'confirming' WHERE order_ref = %s AND status NOT IN ( 'active', 'confirming' )",
            $order_ref
        ) );

        if ( ! $claimed ) {
            // Une autre requête a déjà pris la main sur cette commande (ou l'a déjà activée
            // entre-temps) — pas une erreur du point de vue de l'appelant, juste rien à refaire.
            return true;
        }

        $defaults = array(
            'transaction_id' => $order['proof_reference'] ?: $order_ref,
            'admin_note'     => '',
        );
        $args = wp_parse_args( $args, $defaults );

        if ( self::is_ticket_order( $order ) ) {
            $activated = class_exists( 'KMFamily_Tickets' )
                ? KMFamily_Tickets::issue( $order, $args['transaction_id'] )
                : false;
        } else {
            $activated = KMFamily_Subscriptions::activate( $order['user_id'], $order['artiste_id'], $order['palier'], array(
                'transaction_id' => $args['transaction_id'],
                'montant'        => $order['montant'],
                'gateway'        => $order['gateway'],
                'periodicity'    => $order['periodicity'],
            ) );
        }

        if ( ! $activated ) {
            // On "rend" la commande (retour à son statut d'avant la réservation) pour ne pas
            // la laisser bloquée indéfiniment sur 'confirming' — une nouvelle tentative de
            // confirmation doit rester possible après un échec.
            $wpdb->update( $table, array( 'status' => $order['status'] ), array( 'order_ref' => $order_ref ) );
            return new WP_Error( 'activation_failed', __( "Échec de l'activation de l'abonnement.", 'km-family' ) );
        }

        $now = current_time( 'mysql' );
        $wpdb->update( $table, array(
            'status'       => 'active',
            'confirmed_at' => $order['confirmed_at'] ?: $now,
            'active_at'    => $now,
            'admin_note'   => $args['admin_note'] ? sanitize_textarea_field( $args['admin_note'] ) : $order['admin_note'],
        ), array( 'order_ref' => $order_ref ) );

        do_action( 'kmfamily_order_activated', $order_ref, $order );
        return true;
    }

    public static function cancel( $order_ref, $reason = '' ) {
        global $wpdb;
        $order = self::get( $order_ref );
        $wpdb->update( self::table_name(), array(
            'status'     => 'cancelled',
            'admin_note' => sanitize_textarea_field( $reason ),
        ), array( 'order_ref' => $order_ref ) );

        if ( $order ) {
            self::release_ticket_hold_if_needed( $order );
            do_action( 'kmfamily_order_cancelled', $order_ref, $order );
        }
    }

    /**
     * Libère la place de billet réservée (hold) au moment de la commande, si celle-ci
     * n'a jamais été finalisée — évite qu'une place reste indisponible indéfiniment pour
     * un billet jamais payé (commande expirée, rejetée, ou annulée).
     */
    private static function release_ticket_hold_if_needed( array $order ) {
        if ( ! self::is_ticket_order( $order ) ) return;
        if ( in_array( $order['status'], array( 'active' ), true ) ) return; // déjà émis, ne pas libérer
        if ( class_exists( 'KMFamily_Tickets' ) ) {
            KMFamily_Tickets::release_hold( $order );
        }
    }

    /**
     * Expirer les commandes non finalisées après leur TTL (cron quotidien).
     * BILLETTERIE : les commandes de billets expirées libèrent d'abord leur place réservée
     * (hold posé à la création, voir KMFamily_Tickets::create_order) — sans quoi une place
     * jamais payée resterait indisponible pour toujours.
     */
    public static function expire_stale_orders() {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time( 'mysql' );

        // CORRECTIF v3.3.1 : la sélection était restreinte aux billets, si bien que
        // 'kmfamily_order_expired' n'était JAMAIS déclenché pour une commande d'abonnement
        // abandonnée — alors que l'UPDATE ci-dessous les passait bien à 'expired'. Tout
        // module branché sur cet événement (relance, journal, statistiques d'abandon) ne
        // voyait donc que les billets. On parcourt désormais les deux types ; la libération
        // de place reste, elle, réservée aux billets (release_ticket_hold_if_needed).
        $expiring = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status IN ('pending','initiated','awaiting_confirmation')
             AND expires_at < %s",
            $now
        ), ARRAY_A );

        foreach ( $expiring as $order ) {
            if ( ! empty( $order['meta'] ) ) {
                $decoded = json_decode( $order['meta'], true );
                $order['meta'] = is_array( $decoded ) ? $decoded : array();
            }
            self::release_ticket_hold_if_needed( $order );
            do_action( 'kmfamily_order_expired', $order['order_ref'], $order );
        }

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'expired'
             WHERE status IN ('pending','initiated','awaiting_confirmation')
             AND expires_at < %s",
            $now
        ) );
    }

    public static function get_user_orders( $user_id, $limit = 20 ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id, $limit
        ), ARRAY_A );
    }

    /**
     * Commandes en attente de vérification manuelle (admin) : moyens de paiement
     * sans webhook, où l'utilisateur a déclaré avoir payé.
     */
    public static function get_pending_manual_orders( $limit = 50 ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'awaiting_confirmation' ORDER BY declared_at ASC LIMIT %d",
            $limit
        ), ARRAY_A );
    }

    /**
     * Nombre de commandes en attente de vérification — utilisé pour le badge visible
     * dans la barre d'outils admin (voir KMFamily_Notifications::admin_bar_pending_payments).
     */
    public static function count_awaiting_confirmation() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'awaiting_confirmation'" );
    }

    /**
     * Étape "logique" (1 à 4) pour l'affichage de la timeline, quel que soit
     * le statut technique précis en base.
     */
    public static function get_display_stage( $status ) {
        switch ( $status ) {
            case 'pending':
                return 1;
            case 'initiated':
            case 'awaiting_confirmation':
                return 2;
            case 'confirming': // état transitoire très bref (voir confirm_and_activate) — traité comme "confirmé"
            case 'confirmed':
                return 3;
            case 'active':
                return 4;
            default: // expired, cancelled, failed
                return 0;
        }
    }

    /**
     * Date/heure au format MySQL, exprimée dans le fuseau du site (comme
     * current_time('mysql')), décalée de $offset_seconds.
     *
     * Toutes les colonnes DATETIME de ce plugin sont écrites en heure locale : mélanger
     * gmdate(time()) et current_time() dans une même table produit des comparaisons
     * fausses dès que le fuseau du site n'est pas UTC.
     */
    public static function local_datetime( $offset_seconds = 0 ) {
        return gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + (int) $offset_seconds );
    }

    /**
     * CORRECTIF v3.3.1 — source d'IP unique et non falsifiable pour tout le plugin
     * (voir KMFamily_Security::client_ip). Les en-têtes de proxy ne sont lus que si le
     * site est explicitement déclaré derrière un proxy de confiance : sans ce garde-fou,
     * un visiteur pouvait choisir l'IP enregistrée sur sa commande et fausser à la fois
     * la limitation de débit et le score du moteur de risque.
     */
    private static function get_client_ip() {
        $ip = class_exists( 'KMFamily_Security' ) ? KMFamily_Security::client_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '' );
        return '0.0.0.0' === $ip ? '' : $ip;
    }
}
