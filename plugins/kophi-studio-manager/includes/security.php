<?php
/**
 * KOPHI'S MUSIC STUDIO — Anti-abus / limitation de débit
 * ========================================================
 * Les endpoints publics de paiement (création de commande, déclaration de paiement,
 * appel CinetPay) n'avaient aucune limite de fréquence — n'importe qui pouvait les
 * appeler en boucle (spam de fausses commandes, saturation de la boîte mail/WhatsApp
 * de l'équipe avec de fausses déclarations de paiement, ou usage abusif du crédit
 * CinetPay). On limite désormais chaque action par adresse IP, via des transients
 * (pas besoin de table dédiée, auto-nettoyage par expiration).
 */
if (!defined('ABSPATH')) exit;

/**
 * Adresse IP du visiteur. Best-effort : on ne peut jamais faire une confiance absolue
 * aux en-têtes HTTP (usurpables), mais pour de la limitation de débit anti-abus
 * (pas une décision de sécurité critique type contrôle d'accès), une IP approximative
 * suffit très largement à freiner le spam.
 */
function kophi_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Retourne true si la limite est dépassée pour cette action + cette IP (et incrémente
 * le compteur sinon). Exemple : kophi_rate_limit_exceeded('payment_init', 8, 900)
 * autorise 8 appels par IP toutes les 15 minutes pour l'action "payment_init".
 */
function kophi_rate_limit_exceeded(string $action, int $max_attempts, int $window_seconds): bool {
    $key   = 'kophi_rl_' . $action . '_' . md5(kophi_client_ip());
    $count = (int) get_transient($key);

    if ($count >= $max_attempts) return true;

    set_transient($key, $count + 1, $window_seconds);
    return false;
}

/**
 * Réponse REST standard pour un dépassement de limite (HTTP 429).
 */
function kophi_rate_limit_error(): WP_Error {
    return new WP_Error(
        'rate_limited',
        'Trop de tentatives depuis cette connexion. Merci de réessayer dans quelques minutes.',
        ['status' => 429]
    );
}
