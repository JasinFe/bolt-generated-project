<?php
/**
 * Compatibilité minimale avec les rares fonctions WordPress qu'appelle app/.
 *
 * Inventaire fait sur la 1.875.5 : sur ~478 000 lignes, app/ n'appelle
 * WordPress qu'à une vingtaine d'endroits, tous protégés par function_exists()
 * et presque tous dotés d'un repli natif (flux PHP pour HTTP). Seuls trois
 * sujets méritent une réponse ici :
 *
 *   1. wp_mail          — sans lui, FKC_Courriel se déclare « indisponible ».
 *                         On l'envoie par mail(), c'est-à-dire par msmtp
 *                         (sendmail_path), relayé vers un vrai serveur SMTP.
 *   2. wp_next_scheduled — l'écran « Processus » et le brouillard affichent
 *                         le prochain passage. Les tâches sont désormais lancées
 *                         par le cron SYSTÈME (deploiement/cron/finakop) ; on
 *                         annonce donc son prochain créneau réel.
 *   3. get_option       — seul « admin_email » est lu (sujet des notifications
 *                         push). Les autres options WordPress n'existent plus.
 *
 * On NE définit PAS wp_remote_* ni wp_upload_dir : leurs replis natifs dans
 * app/ sont le bon comportement hors WordPress.
 */

if ( ! function_exists( 'wp_mail' ) ) {
	/**
	 * @param string|array $to
	 * @param string       $subject
	 * @param string       $message
	 * @param string|array $headers
	 * @return bool
	 */
	function wp_mail( $to, $subject, $message, $headers = array() ) {
		$cfg  = fka_config();
		$from = (string) ( $cfg['mail_from'] ?? '' );
		$to   = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
		$h    = is_array( $headers ) ? $headers : preg_split( '/\r?\n/', (string) $headers );
		$h    = array_values( array_filter( array_map( 'trim', $h ) ) );
		if ( '' !== $from ) { $h[] = 'From: ' . $from; }
		$h[] = 'MIME-Version: 1.0';
		if ( ! preg_grep( '/^Content-Type:/i', $h ) ) { $h[] = 'Content-Type: text/plain; charset=UTF-8'; }
		// Sujet encodé (accents) ; les retours ligne ont déjà été retirés par FKC_Courriel.
		$sujet = function_exists( 'mb_encode_mimeheader' )
			? mb_encode_mimeheader( (string) $subject, 'UTF-8', 'B', "\r\n" )
			: '=?UTF-8?B?' . base64_encode( (string) $subject ) . '?=';
		$env = '';
		if ( preg_match( '/<([^>]+)>/', $from, $m ) ) { $env = '-f' . $m[1]; }
		elseif ( filter_var( $from, FILTER_VALIDATE_EMAIL ) ) { $env = '-f' . $from; }
		return '' !== $env
			? mail( $to, $sujet, (string) $message, implode( "\r\n", $h ), $env )
			: mail( $to, $sujet, (string) $message, implode( "\r\n", $h ) );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/** Prochain créneau du cron système (voir deploiement/cron/finakop). */
	function wp_next_scheduled( $hook ) {
		$t = time();
		switch ( $hook ) {
			case 'fkc_bpe_worker':             // */5 * * * *
				return $t - ( $t % 300 ) + 300;
			case 'fkc_balayage_temporisation': // 7 * * * *
				$h = $t - ( $t % 3600 ) + 420;
				return $h > $t ? $h : $h + 3600;
		}
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	/** La planification est portée par la crontab : rien à enregistrer. */
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) { return true; }
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) { return 0; }
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		if ( 'admin_email' === $option ) {
			$v = (string) ( fka_config()['admin_email'] ?? '' );
			return '' !== $v ? $v : $default;
		}
		return $default;
	}
}
