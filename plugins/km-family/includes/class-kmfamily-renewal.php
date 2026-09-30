<?php
/**
 * KM Family — Relances de renouvellement (LOT 1)
 *
 * PROBLÈME TRAITÉ :
 * tout le modèle KM Family est en prépayé ponctuel. À l'échéance, le membre
 * doit refaire volontairement la démarche de paiement. Sans relance, une part
 * importante de la base disparaît silencieusement à chaque échéance — c'est
 * la principale fuite de revenu de la plateforme, et elle ne coûte rien à
 * colmater.
 *
 * CE QUI EXISTAIT DÉJÀ (et qui n'est pas touché) :
 * KMFamily_Notifications envoie un avertissement à J-3 et un message le jour
 * de l'expiration, déclenchés par KMFamily_Subscriptions::check_expirations().
 * Ces deux envois restent en place. Ce module ajoute les étapes manquantes
 * — en amont et surtout APRÈS l'expiration, là où se joue la reconquête —
 * sans jamais doubler un envoi existant (l'étape J-3 est volontairement
 * absente de l'échelle ci-dessous).
 *
 * APPORT PRINCIPAL : un lien de renouvellement qui mène directement aux
 * paliers du bon artiste. Les emails existants pointent vers la page publique
 * de l'artiste avec une ancre « #paliers » qui n'existe pas forcément.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Renewal {

    const OPTION_ENABLED   = 'kmfamily_renewal_reminders';
    const OPTION_LAST_RUN  = 'kmfamily_renewal_last_run';

    /**
     * Échelle de relance. Clé = identifiant stocké (idempotence), valeur =
     * décalage en jours par rapport à l'échéance (négatif = avant).
     * J-3 et J+0 sont assurés par KMFamily_Notifications : ne pas les ajouter ici.
     */
    public static function stages() {
        return apply_filters( 'kmfamily_renewal_stages', array(
            'j7'    => -7,
            'j1'    => -1,
            'plus2' => 2,
            'plus7' => 7,
            'plus15'=> 15,
        ) );
    }

    public static function init() {
        add_action( 'kmfamily_daily_subscription_check', array( __CLASS__, 'run' ), 30 );

        // Un cycle payé remet le compteur de relances à zéro : sans ça, un
        // membre qui renouvelle puis arrive à une nouvelle échéance ne serait
        // plus jamais relancé (les étapes resteraient marquées comme envoyées).
        add_action( 'kmfamily_subscription_renewed',  array( __CLASS__, 'on_renewed' ), 20, 4 );
        add_action( 'kmfamily_subscription_upgraded', array( __CLASS__, 'on_upgraded' ), 20, 5 );
    }

    public static function on_renewed( $user_id, $artiste_id, $palier, $args ) {
        if ( class_exists( 'KMFamily_Memberships' ) ) {
            KMFamily_Memberships::reset_reminders( $user_id, $artiste_id );
        }
    }

    public static function on_upgraded( $user_id, $artiste_id, $palier, $ancien, $args ) {
        self::on_renewed( $user_id, $artiste_id, $palier, $args );
    }

    public static function is_enabled() {
        // Activé par défaut : l'absence de relance est le comportement à corriger.
        $v = get_option( self::OPTION_ENABLED, null );
        return $v === null ? true : (bool) $v;
    }

    /**
     * URL de renouvellement pour un artiste donné.
     * Passe par la page « Rejoindre la KM Family », qui bascule
     * automatiquement sur les paliers de l'artiste demandé
     * (KMFamily_Shortcodes::paliers_all lit ?artiste_id=).
     */
    public static function renewal_url( $artiste_id ) {
        $page_id = (int) get_option( 'kmfamily_page_paliers' );
        $base    = $page_id ? get_permalink( $page_id ) : '';

        if ( ! $base ) {
            // Repli : page publique de l'artiste, comme les emails historiques.
            $base = get_permalink( $artiste_id );
            return $base ?: home_url( '/' );
        }

        return add_query_arg( array(
            'artiste_id' => absint( $artiste_id ),
            'utm_source' => 'relance',
        ), $base );
    }

    /**
     * Passe quotidienne. Pour chaque étape, on ne traite que les adhésions
     * dont l'échéance tombe exactement dans la fenêtre du jour visé — donc
     * pas de rattrapage massif si le cron a sauté plusieurs jours, ce qui
     * évite d'envoyer cinq emails d'un coup au même membre.
     */
    public static function run() {
        if ( ! self::is_enabled() ) return;
        if ( ! class_exists( 'KMFamily_Memberships' ) || ! KMFamily_Memberships::is_ready() ) return;

        $sent = 0;

        foreach ( self::stages() as $stage => $offset ) {
            // expires_at = aujourd'hui + N jours  ⇔  N = -offset
            $rows = KMFamily_Memberships::get_expiring_in_days( -$offset );

            foreach ( (array) $rows as $m ) {
                // mark_reminder_sent() renvoie false si l'étape a déjà été
                // envoyée : c'est lui qui garantit l'unicité, avant l'envoi.
                if ( ! KMFamily_Memberships::mark_reminder_sent( $m['id'], $stage ) ) continue;
                if ( self::send( $m, $stage, $offset ) ) $sent++;
            }
        }

        update_option( self::OPTION_LAST_RUN, array( 'time' => current_time( 'mysql' ), 'sent' => $sent ) );
        return $sent;
    }

    private static function send( array $m, $stage, $offset ) {
        $user = get_userdata( $m['user_id'] );
        if ( ! $user || ! is_email( $user->user_email ) ) return false;

        $artiste_name = get_the_title( $m['artiste_id'] ) ?: __( 'votre artiste', 'km-family' );
        $palier_data  = class_exists( 'KMFamily_Paliers' ) ? KMFamily_Paliers::get( $m['palier'] ) : null;
        $palier_nom   = $palier_data['nom'] ?? strtoupper( $m['palier'] );
        $url          = self::renewal_url( $m['artiste_id'] );
        $jours        = abs( (int) $offset );

        if ( $offset < 0 ) {
            $subject = sprintf(
                /* translators: 1: nom artiste, 2: nombre de jours */
                __( 'Votre soutien à %1$s se termine dans %2$d jour(s)', 'km-family' ),
                $artiste_name, $jours
            );
            $titre = __( 'VOTRE PLACE VOUS ATTEND', 'km-family' );
            $intro = sprintf(
                __( 'Votre adhésion <strong>%1$s</strong> auprès de <strong>%2$s</strong> arrive à échéance dans <strong>%3$d jour(s)</strong>. Un renouvellement en une minute et rien ne s\'interrompt.', 'km-family' ),
                esc_html( $palier_nom ), esc_html( $artiste_name ), $jours
            );
            $cta = __( 'RENOUVELER MAINTENANT', 'km-family' );
        } else {
            $subject = sprintf(
                __( '%1$s — votre place est toujours là', 'km-family' ),
                $artiste_name
            );
            $titre = __( 'REVENEZ QUAND VOUS VOULEZ', 'km-family' );
            $intro = sprintf(
                __( 'Votre adhésion auprès de <strong>%1$s</strong> s\'est terminée il y a %2$d jour(s). Les contenus exclusifs continuent d\'arriver — votre place vous attend dès que vous le souhaitez.', 'km-family' ),
                esc_html( $artiste_name ), $jours
            );
            $cta = __( 'REPRENDRE MON SOUTIEN', 'km-family' );
        }

        $body = self::render_email( array(
            'title'    => $titre,
            'greeting' => sprintf( __( 'Bonjour %s,', 'km-family' ), esc_html( $user->display_name ) ),
            'intro'    => $intro,
            'cta_url'  => $url,
            'cta_text' => $cta,
        ) );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: KM FAMILY <' . get_option( 'admin_email' ) . '>',
        );

        $ok = wp_mail( $user->user_email, $subject, $body, $headers );

        do_action( 'kmfamily_renewal_reminder_sent', $m, $stage, $ok );

        // On n'écrit PAS dans KMFamily_Event_Log : ce journal est chaîné par
        // hachage et indexé par commande, il est la preuve d'intégrité du
        // parcours de paiement. Y mêler des événements qui ne sont pas des
        // commandes affaiblirait ce rôle. L'état des relances vit dans la
        // colonne `reminders` de l'adhésion, et le hook ci-dessus permet de
        // brancher un journal dédié si besoin.

        return (bool) $ok;
    }

    /**
     * Gabarit d'email autonome. On ne réutilise pas
     * KMFamily_Notifications::render_email_template(), qui est privé : le
     * rendre public juste pour ce module créerait un couplage inutile entre
     * deux fichiers qui n'ont pas le même rythme d'évolution.
     */
    private static function render_email( $d ) {
        $brand = '#d4af37';
        ob_start(); ?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?php echo esc_html( $d['title'] ); ?></title></head>
<body style="margin:0;padding:0;background:#0a0a0a;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0a;padding:28px 12px;">
<tr><td align="center">
  <table width="100%" style="max-width:560px;background:#141414;border:1px solid #262626;border-radius:14px;overflow:hidden;">
    <tr><td style="padding:26px 28px 6px;">
      <div style="font-size:11px;letter-spacing:.18em;color:<?php echo $brand; ?>;font-weight:700;">KM FAMILY</div>
      <h1 style="margin:10px 0 0;font-size:21px;line-height:1.3;color:#fff;"><?php echo esc_html( $d['title'] ); ?></h1>
    </td></tr>
    <tr><td style="padding:18px 28px 0;color:#d8d8d8;font-size:15px;line-height:1.6;">
      <p style="margin:0 0 12px;"><?php echo esc_html( $d['greeting'] ); ?></p>
      <p style="margin:0 0 18px;"><?php echo wp_kses_post( $d['intro'] ); ?></p>
    </td></tr>
    <tr><td align="center" style="padding:8px 28px 30px;">
      <a href="<?php echo esc_url( $d['cta_url'] ); ?>"
         style="display:inline-block;background:<?php echo $brand; ?>;color:#141414;text-decoration:none;font-weight:800;font-size:14px;letter-spacing:.04em;padding:14px 28px;border-radius:10px;">
        <?php echo esc_html( $d['cta_text'] ); ?>
      </a>
    </td></tr>
    <tr><td style="padding:0 28px 26px;color:#6a6a6a;font-size:11.5px;line-height:1.6;border-top:1px solid #262626;padding-top:16px;">
      <?php esc_html_e( 'Vous recevez ce message parce que vous êtes membre de la KM Family.', 'km-family' ); ?>
    </td></tr>
  </table>
</td></tr></table>
</body></html>
        <?php
        return ob_get_clean();
    }
}
