<?php
/**
 * Système de notifications KM Family
 *
 * Envoie des emails émotionnels aux membres et aux admins lors d'événements clés :
 * - Nouvel abonnement
 * - Renouvellement
 * - Upgrade de palier
 * - Expiration proche
 * - Expiration
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Notifications {

    /**
     * Message émotionnel central — version courte (emails, petits espaces).
     */
    const EMOTIONAL_MESSAGE = "L'Urban Gospel Francophone a besoin de ses piliers. Et ces piliers… c'est vous. En rejoignant la KM FAMILY, vous ne prenez pas seulement un abonnement — vous devenez un véritable soutien et un pilier du mouvement. Grâce à vos contributions, nous finançons la production musicale, les clips, la promotion et les événements. En retour, vous entrez dans l'intimité du mouvement : démos inédites, avant-premières, lives privés et rencontres avec les artistes.";

    /**
     * Message émotionnel version HTML riche (pour les pages dédiées).
     */
    public static function get_emotional_message_rich() {
        $html = '<p>L\'Urban Gospel Francophone a besoin de ses piliers.&nbsp;<strong>Et ces piliers… c\'est vous.</strong></p>';
        $html .= '<p>En rejoignant la KM FAMILY, vous ne prenez pas seulement un abonnement.&nbsp;<strong>Vous devenez un véritable soutien et un pilier du mouvement.</strong></p>';
        $html .= '<p>Grâce à vos contributions, nous finançons chaque étape de la création artistique :</p>';
        $html .= '<ul class="kmfamily-emotional-list">';
        $html .= '<li><span class="kmfamily-emotional-icon">🎵</span> La production musicale</li>';
        $html .= '<li><span class="kmfamily-emotional-icon">🎬</span> La réalisation de clips professionnels</li>';
        $html .= '<li><span class="kmfamily-emotional-icon">📣</span> La promotion efficace</li>';
        $html .= '<li><span class="kmfamily-emotional-icon">🎤</span> L\'organisation de concerts et d\'événements</li>';
        $html .= '</ul>';
        $html .= '<p>Nous donnons enfin aux artistes urban gospel francophones&nbsp;<strong>les moyens de briller comme ils le méritent</strong>.</p>';
        $html .= '<p>En retour, vous entrez dans l\'intimité du mouvement :</p>';
        $html .= '<ul class="kmfamily-emotional-list">';
        $html .= '<li><span class="kmfamily-emotional-icon">🔓</span> Accès exclusif aux démos inédites</li>';
        $html .= '<li><span class="kmfamily-emotional-icon">✨</span> Avant-premières, lives privés</li>';
        $html .= '<li><span class="kmfamily-emotional-icon">🤝</span> Rencontres et privilèges avec les artistes</li>';
        $html .= '<li><span class="kmfamily-emotional-icon">💛</span> Une connexion directe avec le label</li>';
        $html .= '</ul>';
        $html .= '<p class="kmfamily-emotional-cta"><strong>Vous ne financez pas juste des projets. Vous participez à l\'essor d\'un mouvement culturel.</strong></p>';
        $html .= '<p class="kmfamily-emotional-signature">Rejoignez la KM FAMILY. Devenez pilier. Faites grandir l\'Urban Gospel Francophone avec nous.</p>';
        return apply_filters( 'kmfamily_emotional_message_rich', $html );
    }

    public static function init() {
        // Emails aux membres
        add_action( 'kmfamily_subscription_activated',       array( __CLASS__, 'send_welcome_email' ), 10, 4 );
        add_action( 'kmfamily_subscription_upgraded',        array( __CLASS__, 'send_upgrade_email' ), 10, 5 );
        add_action( 'kmfamily_subscription_renewed',         array( __CLASS__, 'send_renewal_email' ), 10, 4 );
        add_action( 'kmfamily_subscription_expiring_soon',   array( __CLASS__, 'send_expiring_soon_email' ), 10, 3 );
        add_action( 'kmfamily_subscription_expired',         array( __CLASS__, 'send_expired_email' ), 10, 3 );

        // Notifications admin
        add_action( 'kmfamily_subscription_activated',       array( __CLASS__, 'notify_admin_new_member' ), 10, 4 );

        // Smart Payment Center : un adhérent a déclaré un paiement manuel (Wave/OM/MTN/Moov) — à vérifier vite.
        add_action( 'kmfamily_order_declared',                array( __CLASS__, 'notify_admin_order_declared' ), 10, 2 );

        // L'admin a rejeté une déclaration de paiement — le membre doit le savoir,
        // sinon il attend indéfiniment une activation qui ne viendra jamais.
        add_action( 'kmfamily_order_rejected',                array( __CLASS__, 'notify_member_order_rejected' ), 10, 3 );

        // Notifications dashboard WP pour l'admin
        add_action( 'kmfamily_subscription_activated',       array( __CLASS__, 'add_admin_notification' ), 10, 4 );

        // Affichage des notifications admin en backoffice
        add_action( 'admin_notices',                         array( __CLASS__, 'display_admin_notifications' ) );

        // Modifier le from de WordPress pour les emails KM Family
        add_filter( 'kmfamily_email_from_name', function() { return 'KM FAMILY'; } );

        // FIABILITÉ : wp_mail() échoue silencieusement sur de nombreux hébergements
        // (pas de SMTP configuré, mail() PHP bridé...) — jusqu'ici, un email de
        // notification de paiement qui échouait ne laissait AUCUNE trace nulle part.
        // On journalise l'échec ET on ajoute une entrée dans le panneau in-app, qui ne
        // dépend pas de l'email pour fonctionner.
        add_action( 'wp_mail_failed', array( __CLASS__, 'log_mail_failure' ) );

        // Indicateur visible sur TOUTE page d'administration (barre d'outils en haut),
        // pas seulement dans le sous-menu "Notifications" qu'il faut penser à ouvrir —
        // garantit que l'équipe voit un paiement à confirmer même si l'email n'arrive
        // jamais et même si elle ne consulte pas spécifiquement ce sous-menu.
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_pending_payments' ), 100 );

        // Le CSS du plugin n'est chargé que sur ses propres pages (voir
        // KMFamily_Admin::enqueue_admin_assets) — la barre d'outils, elle, doit être
        // stylée partout, donc un petit style inline dédié plutôt que d'alourdir le
        // chargement admin sur des pages qui n'ont rien à voir avec KM Family.
        add_action( 'admin_head', array( __CLASS__, 'admin_bar_alert_style' ) );
        add_action( 'wp_head', array( __CLASS__, 'admin_bar_alert_style' ) ); // barre visible aussi côté front pour un admin connecté
    }

    /**
     * Adresse de destination des notifications KM Family (nouveau membre, paiement à
     * confirmer...). BUGFIX : ces emails étaient envoyés à get_option('admin_email'),
     * le réglage générique "Adresse e-mail d'administration" de WordPress — une
     * adresse SANS AUCUN RAPPORT avec le compte utilisé par le plugin SMTP pour
     * ENVOYER les emails (ex. contact@kophisgroup.com). Le SMTP peut être parfaitement
     * configuré et les emails partir sans problème... vers une adresse que personne
     * ne surveille. Un réglage dédié (Réglages KM Family → Général) permet de choisir
     * explicitement le destinataire, avec repli sur admin_email si non renseigné.
     */
    public static function get_notification_email(): string {
        $settings = get_option( 'kmfamily_settings', array() );
        $email    = is_array( $settings ) ? ( $settings['notification_email'] ?? '' ) : '';
        return is_email( $email ) ? $email : get_option( 'admin_email' );
    }

    public static function admin_bar_alert_style() {
        if ( ! is_admin_bar_showing() ) return;
        echo '<style>#wp-admin-bar-kmfamily-pending-payments > .ab-item { background: #d4af37 !important; color: #17171a !important; font-weight: 700; }
#wp-admin-bar-kmfamily-pending-payments > .ab-item:hover { background: #ffd700 !important; }</style>';
    }

    /**
     * Trace un échec d'envoi wp_mail() — sans ça, un email de confirmation de paiement
     * qui échoue silencieusement est indiscernable d'un email jamais déclenché.
     */
    public static function log_mail_failure( $wp_error ) {
        error_log( '[KM Family] Échec envoi email : ' . $wp_error->get_error_message() );

        $notifs   = get_option( 'kmfamily_admin_notifications', array() );
        $notifs[] = array(
            'type'    => 'mail_failed',
            'message' => '⚠️ Un email de notification KM Family n\'a pas pu être envoyé (' . esc_html( $wp_error->get_error_message() ) . '). Vérifiez la config d\'envoi d\'emails du site (SMTP).',
            'time'    => time(),
            'read'    => false,
        );
        if ( count( $notifs ) > 50 ) $notifs = array_slice( $notifs, -50 );
        update_option( 'kmfamily_admin_notifications', $notifs );
    }

    /**
     * Badge dans la barre d'outils admin (visible sur TOUTES les pages wp-admin,
     * contrairement au sous-menu Notifications) : nombre de commandes en attente de
     * vérification suite à une déclaration de paiement manuel.
     */
    public static function admin_bar_pending_payments( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'KMFamily_Orders' ) ) return;

        $count = KMFamily_Orders::count_awaiting_confirmation();
        if ( ! $count ) return;

        $wp_admin_bar->add_node( array(
            'id'    => 'kmfamily-pending-payments',
            'title' => '💰 ' . $count . ' paiement' . ( $count > 1 ? 's' : '' ) . ' à confirmer',
            'href'  => admin_url( 'admin.php?page=kmfamily-subscriptions' ),
            'meta'  => array( 'class' => 'kmfamily-admin-bar-alert' ),
        ) );
    }

    /**
     * Email de bienvenue — nouveau membre
     */
    public static function send_welcome_email( $user_id, $artiste_id, $palier, $args ) {
        $user         = get_userdata( $user_id );
        if ( ! $user ) return;

        $palier_data  = KMFamily_Paliers::get( $palier );
        $artiste_name = get_the_title( $artiste_id );
        $dashboard    = get_option( 'kmfamily_page_dashboard' );
        $dashboard_url = $dashboard ? get_permalink( $dashboard ) : home_url();

        $subject = sprintf(
            '🎵 Bienvenue dans la KM FAMILY de %s, %s !',
            $artiste_name,
            $user->display_name
        );

        $body = self::render_email_template( array(
            'title'    => '🎉 BIENVENUE DANS LA KM FAMILY',
            'greeting' => sprintf( 'Bonjour %s,', esc_html( $user->display_name ) ),
            'intro'    => sprintf(
                'Merci d\'avoir rejoint la KM FAMILY de <strong>%s</strong> au palier <strong>%s — %s</strong>.',
                esc_html( $artiste_name ),
                esc_html( $palier_data['nom'] ),
                esc_html( $palier_data['statut'] )
            ),
            'emotional' => self::EMOTIONAL_MESSAGE,
            'palier'   => $palier_data,
            'cta_url'  => $dashboard_url,
            'cta_text' => 'ACCÉDER À MON ESPACE',
        ) );

        self::send( $user->user_email, $subject, $body );
    }

    /**
     * Email upgrade de palier
     */
    public static function send_upgrade_email( $user_id, $artiste_id, $new_palier, $old_palier, $args ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $new_data     = KMFamily_Paliers::get( $new_palier );
        $old_data     = KMFamily_Paliers::get( $old_palier );
        $artiste_name = get_the_title( $artiste_id );

        $subject = sprintf(
            '⚡ Félicitations %s, vous passez au palier %s !',
            $user->display_name,
            $new_data['nom']
        );

        $body = self::render_email_template( array(
            'title'    => '⚡ VOUS MONTEZ EN PUISSANCE',
            'greeting' => sprintf( 'Bonjour %s,', esc_html( $user->display_name ) ),
            'intro'    => sprintf(
                'Vous venez de passer du palier <strong>%s</strong> à <strong>%s — %s</strong> pour soutenir <strong>%s</strong>. Merci infiniment pour votre engagement renforcé !',
                esc_html( $old_data['nom'] ),
                esc_html( $new_data['nom'] ),
                esc_html( $new_data['statut'] ),
                esc_html( $artiste_name )
            ),
            'emotional' => self::EMOTIONAL_MESSAGE,
            'palier'   => $new_data,
            'cta_url'  => get_permalink( get_option( 'kmfamily_page_dashboard' ) ),
            'cta_text' => 'DÉCOUVRIR MES NOUVEAUX AVANTAGES',
        ) );

        self::send( $user->user_email, $subject, $body );
    }

    /**
     * Email renouvellement
     */
    public static function send_renewal_email( $user_id, $artiste_id, $palier, $args ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $palier_data  = KMFamily_Paliers::get( $palier );
        $artiste_name = get_the_title( $artiste_id );

        $subject = sprintf(
            '🙏 Merci pour votre fidélité, %s !',
            $user->display_name
        );

        $body = self::render_email_template( array(
            'title'    => '🙏 MERCI POUR VOTRE FIDÉLITÉ',
            'greeting' => sprintf( 'Bonjour %s,', esc_html( $user->display_name ) ),
            'intro'    => sprintf(
                'Votre soutien à <strong>%s</strong> au palier <strong>%s</strong> vient d\'être renouvelé. Votre fidélité est une bénédiction pour l\'artiste et pour tout le label.',
                esc_html( $artiste_name ),
                esc_html( $palier_data['nom'] )
            ),
            'emotional' => self::EMOTIONAL_MESSAGE,
            'palier'   => $palier_data,
            'cta_url'  => get_permalink( get_option( 'kmfamily_page_dashboard' ) ),
            'cta_text' => 'MON ESPACE MEMBRE',
        ) );

        self::send( $user->user_email, $subject, $body );
    }

    /**
     * Email expiration prochaine (J-3)
     */
    public static function send_expiring_soon_email( $user_id, $artiste_id, $sub ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $artiste_name = get_the_title( $artiste_id );
        $artiste_url  = get_permalink( $artiste_id );
        $palier_data  = KMFamily_Paliers::get( $sub['palier'] );
        $days_left    = ceil( ( $sub['expire'] - time() ) / DAY_IN_SECONDS );

        $subject = sprintf(
            '⏰ Votre soutien à %s expire dans %d jours',
            $artiste_name,
            $days_left
        );

        $body = self::render_email_template( array(
            'title'    => '⏰ NE BRISEZ PAS LA CHAÎNE DE SOUTIEN',
            'greeting' => sprintf( 'Bonjour %s,', esc_html( $user->display_name ) ),
            'intro'    => sprintf(
                'Votre adhésion à la KM FAMILY de <strong>%s</strong> (palier <strong>%s</strong>) expire dans <strong>%d jours</strong>. Sans renouvellement, vous perdrez l\'accès à tous les contenus exclusifs.',
                esc_html( $artiste_name ),
                esc_html( $palier_data['nom'] ),
                $days_left
            ),
            'emotional' => self::EMOTIONAL_MESSAGE,
            'palier'   => $palier_data,
            'cta_url'  => $artiste_url . '#paliers',
            'cta_text' => 'RENOUVELER MON SOUTIEN',
        ) );

        self::send( $user->user_email, $subject, $body );
    }

    /**
     * Email expiration
     */
    public static function send_expired_email( $user_id, $artiste_id, $sub ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $artiste_name = get_the_title( $artiste_id );
        $artiste_url  = get_permalink( $artiste_id );

        $subject = sprintf(
            'Votre soutien à %s a expiré — On vous attend 💛',
            $artiste_name
        );

        $body = self::render_email_template( array(
            'title'    => '💛 ON VOUS ATTEND DE PIED FERME',
            'greeting' => sprintf( 'Bonjour %s,', esc_html( $user->display_name ) ),
            'intro'    => sprintf(
                'Votre adhésion à la KM FAMILY de <strong>%s</strong> a expiré. Vous n\'avez plus accès aux contenus exclusifs, mais votre place est toujours là.',
                esc_html( $artiste_name )
            ),
            'emotional' => self::EMOTIONAL_MESSAGE,
            'cta_url'   => $artiste_url . '#paliers',
            'cta_text'  => 'REPRENDRE MON SOUTIEN',
        ) );

        self::send( $user->user_email, $subject, $body );
    }

    /**
     * Notifier l'admin d'un nouveau membre
     */
    public static function notify_admin_order_declared( $order_ref, $order ) {
        $order = KMFamily_Orders::get( $order_ref ); // relire : le statut/référence viennent d'être mis à jour
        if ( ! $order ) return;

        $user         = get_userdata( $order['user_id'] );
        $palier_data  = KMFamily_Paliers::get( $order['palier'] );
        $artiste_name = get_the_title( $order['artiste_id'] );
        $method       = KMFamily_Payment_Methods::get( $order['gateway'] );

        $subject = sprintf( '[KM Family] Paiement à confirmer — %s', $order['order_ref'] );

        $body = sprintf(
            "Un adhérent a déclaré un paiement manuel à vérifier.\n\n" .
            "Commande : %s\n" .
            "Membre : %s (%s)\n" .
            "Artiste : %s\n" .
            "Palier : %s\n" .
            "Moyen : %s\n" .
            "Montant : %s %s\n" .
            "Référence déclarée : %s\n\n" .
            "Confirmer ici : %s",
            $order['order_ref'],
            $user ? $user->display_name : '#' . $order['user_id'],
            $user ? $user->user_email : '',
            $artiste_name,
            $palier_data['nom'] ?? $order['palier'],
            $method['label'] ?? $order['gateway'],
            number_format( $order['montant'], 0, ',', ' ' ),
            $order['currency'],
            $order['proof_reference'],
            admin_url( 'admin.php?page=kmfamily-subscriptions' )
        );

        wp_mail( self::get_notification_email(), $subject, $body );

        $notifs = get_option( 'kmfamily_admin_notifications', array() );
        $notifs[] = array(
            'type'    => 'order_declared',
            'message' => sprintf(
                '💰 <strong>%s</strong> a déclaré un paiement <strong>%s</strong> pour <strong>%s</strong> (%s %s) — à confirmer.',
                esc_html( $user ? $user->display_name : '#' . $order['user_id'] ),
                esc_html( $method['label'] ?? $order['gateway'] ),
                esc_html( $artiste_name ),
                esc_html( number_format( $order['montant'], 0, ',', ' ' ) ),
                esc_html( $order['currency'] )
            ),
            'time'    => time(),
            'read'    => false,
        );
        if ( count( $notifs ) > 50 ) $notifs = array_slice( $notifs, -50 );
        update_option( 'kmfamily_admin_notifications', $notifs );
    }

    /**
     * Le membre est notifié quand sa déclaration de paiement est rejetée — sans ça,
     * il attendrait indéfiniment une activation qui ne viendra jamais, sans savoir
     * pourquoi ni quoi faire.
     */
    public static function notify_member_order_rejected( $order_ref, $order, $admin_note ) {
        $user = get_userdata( $order['user_id'] );
        if ( ! $user || ! $user->user_email ) return;

        $artiste_name = get_the_title( $order['artiste_id'] );
        $contact_email = self::get_notification_email();

        $subject = "[KM Family] Ta déclaration de paiement n'a pas pu être validée";

        $body = sprintf(
            "Bonjour %s,\n\n" .
            "Ta déclaration de paiement pour le palier de %s (réf. %s) n'a pas pu être validée par notre équipe.\n\n" .
            "%s" .
            "Si tu penses qu'il s'agit d'une erreur, ou si tu as besoin d'aide pour finaliser ton paiement, contacte-nous à %s.\n\n" .
            "— L'équipe KM Family",
            $user->display_name,
            $artiste_name,
            $order['order_ref'],
            $admin_note ? "Motif indiqué : {$admin_note}\n\n" : '',
            $contact_email
        );

        wp_mail( $user->user_email, $subject, $body );
    }

    public static function notify_admin_new_member( $user_id, $artiste_id, $palier, $args ) {
        $user         = get_userdata( $user_id );
        $palier_data  = KMFamily_Paliers::get( $palier );
        $artiste_name = get_the_title( $artiste_id );

        $subject = sprintf( '[KM Family] Nouveau membre %s pour %s', $palier_data['nom'], $artiste_name );

        $body = sprintf(
            "Nouveau membre KM Family !\n\n" .
            "Membre : %s (%s)\n" .
            "Artiste : %s\n" .
            "Palier : %s — %s\n" .
            "Montant : %s FCFA\n" .
            "Gateway : %s\n" .
            "Transaction : %s\n\n" .
            "Voir dans l'admin : %s",
            $user->display_name,
            $user->user_email,
            $artiste_name,
            $palier_data['nom'],
            $palier_data['statut'],
            number_format( $args['montant'] ?? 0, 0, ',', ' ' ),
            $args['gateway'] ?? 'manual',
            $args['transaction_id'] ?? '-',
            admin_url( 'admin.php?page=kmfamily-subscriptions' )
        );

        wp_mail( self::get_notification_email(), $subject, $body );
    }

    /**
     * Ajouter une notification dans le dashboard WP.
     */
    public static function add_admin_notification( $user_id, $artiste_id, $palier, $args ) {
        $notifs = get_option( 'kmfamily_admin_notifications', array() );
        $user = get_userdata( $user_id );
        $palier_data  = KMFamily_Paliers::get( $palier );
        $artiste_name = get_the_title( $artiste_id );

        $notifs[] = array(
            'type'    => 'new_member',
            'message' => sprintf(
                '🎉 Nouveau membre <strong>%s</strong> : <strong>%s</strong> a rejoint la KM Family de <strong>%s</strong> au palier <strong>%s</strong>.',
                esc_html( $user->display_name ),
                esc_html( $user->user_email ),
                esc_html( $artiste_name ),
                esc_html( $palier_data['nom'] )
            ),
            'time'    => time(),
            'read'    => false,
        );

        // Conserver les 50 dernières
        if ( count( $notifs ) > 50 ) {
            $notifs = array_slice( $notifs, -50 );
        }

        update_option( 'kmfamily_admin_notifications', $notifs );
    }

    /**
     * Afficher les notifications non-lues dans l'admin.
     */
    public static function display_admin_notifications() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $notifs = get_option( 'kmfamily_admin_notifications', array() );
        if ( empty( $notifs ) ) return;

        $unread = array_filter( $notifs, function( $n ) { return empty( $n['read'] ); } );
        if ( empty( $unread ) ) return;

        // N'afficher que les 5 dernières non-lues
        $to_show = array_slice( array_reverse( $unread ), 0, 5 );

        foreach ( $to_show as $notif ) {
            echo '<div class="notice notice-success is-dismissible kmfamily-admin-notif">';
            echo '<p>' . wp_kses_post( $notif['message'] ) . '</p>';
            echo '</div>';
        }

        // BUGFIX : cette boucle marquait TOUTES les notifications non lues comme lues
        // (pas seulement les 5 affichées ci-dessus) — s'il y en avait plus de 5 en
        // attente (plusieurs déclarations de paiement rapprochées, par ex.), les plus
        // anciennes étaient silencieusement marquées "lues" sans jamais avoir été
        // montrées à l'équipe. On ne marque désormais comme lues que celles qu'on
        // vient réellement d'afficher.
        $shown_times = array_column( $to_show, 'time' );
        foreach ( $notifs as &$n ) {
            if ( in_array( $n['time'], $shown_times, true ) ) {
                $n['read'] = true;
            }
        }
        update_option( 'kmfamily_admin_notifications', $notifs );
    }

    /**
     * Render le HTML d'un email.
     */
    private static function render_email_template( $data ) {
        $brand_color = '#d4af37';
        $title    = $data['title'] ?? '';
        $greeting = $data['greeting'] ?? '';
        $intro    = $data['intro'] ?? '';
        $emotional = $data['emotional'] ?? '';
        $palier   = $data['palier'] ?? null;
        $cta_url  = $data['cta_url'] ?? home_url();
        $cta_text = $data['cta_text'] ?? 'Accéder à mon espace';

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $title ); ?></title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0a0a0a;color:#fff;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:40px 20px;">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#141414;border-radius:16px;overflow:hidden;">
                <!-- Header -->
                <tr>
                    <td style="background:linear-gradient(135deg,#d4af37 0%,#b8941f 100%);padding:40px 30px;text-align:center;">
                        <div style="color:#0a0a0a;font-size:32px;font-weight:900;letter-spacing:2px;">KM FAMILY</div>
                        <div style="color:#0a0a0a;font-size:12px;margin-top:8px;opacity:0.8;">KOPHI'S MUSIC — Urban Gospel</div>
                    </td>
                </tr>
                <!-- Title -->
                <tr>
                    <td style="padding:40px 30px 20px;text-align:center;">
                        <h1 style="color:<?php echo esc_attr( $brand_color ); ?>;margin:0;font-size:24px;letter-spacing:1px;">
                            <?php echo esc_html( $title ); ?>
                        </h1>
                    </td>
                </tr>
                <!-- Content -->
                <tr>
                    <td style="padding:0 30px 30px;color:#e5e5e5;font-size:16px;line-height:1.6;">
                        <p style="margin:0 0 16px;"><?php echo wp_kses_post( $greeting ); ?></p>
                        <p style="margin:0 0 20px;"><?php echo wp_kses_post( $intro ); ?></p>
                        <?php if ( $emotional ) : ?>
                        <div style="background:rgba(212,175,55,0.1);border-left:4px solid <?php echo esc_attr( $brand_color ); ?>;padding:20px;margin:24px 0;border-radius:4px;">
                            <p style="margin:0;font-style:italic;color:#f5f5f5;"><?php echo wp_kses_post( $emotional ); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if ( $palier && ! empty( $palier['benefits'] ) ) : ?>
                        <h3 style="color:<?php echo esc_attr( $brand_color ); ?>;margin:30px 0 12px;font-size:16px;">Vos avantages <?php echo esc_html( $palier['nom'] ); ?> :</h3>
                        <ul style="padding-left:20px;margin:0;">
                            <?php foreach ( $palier['benefits'] as $benefit ) : ?>
                                <li style="margin:6px 0;color:#e5e5e5;"><?php echo esc_html( $benefit ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- CTA -->
                <tr>
                    <td style="padding:0 30px 40px;text-align:center;">
                        <a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $brand_color ); ?>;color:#0a0a0a;padding:16px 36px;text-decoration:none;border-radius:50px;font-weight:700;letter-spacing:1px;font-size:14px;">
                            <?php echo esc_html( $cta_text ); ?>
                        </a>
                    </td>
                </tr>
                <!-- Footer -->
                <tr>
                    <td style="background:#0a0a0a;padding:30px;text-align:center;color:#666;font-size:12px;border-top:1px solid #222;">
                        <p style="margin:0 0 8px;">KOPHI'S GROUP SAS — Abidjan, Côte d'Ivoire</p>
                        <p style="margin:0;">
                            <a href="https://kophismusic.com" style="color:<?php echo esc_attr( $brand_color ); ?>;text-decoration:none;">kophismusic.com</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Envoi d'email avec HTML.
     */
    private static function send( $to, $subject, $body ) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: KM FAMILY <' . get_option( 'admin_email' ) . '>',
        );

        wp_mail( $to, $subject, $body, $headers );
    }

    /**
     * Retourner le message émotionnel (utilisable dans les templates).
     */
    public static function get_emotional_message() {
        return apply_filters( 'kmfamily_emotional_message', self::EMOTIONAL_MESSAGE );
    }
}
