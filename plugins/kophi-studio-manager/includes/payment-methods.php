<?php
/**
 * KOPHI'S MUSIC STUDIO — Moyens de paiement manuels
 * ==================================================
 * Adapté du même système que "KM FAMILY" (label KOPHI'S MUSIC) : liens marchands
 * Wave / Orange Money / MTN Money / Moov Money, saisis une fois dans les réglages,
 * réutilisés pour TOUS les paiements du studio (achat de beat, acompte de session,
 * mix & mastering, beat custom...).
 *
 * Un moyen de paiement n'apparaît sur la page de paiement QUE si son lien marchand
 * est configuré dans KOPHI Studio → Paramètres → Paiement. CinetPay (automatique,
 * si configuré) reste disponible en plus, pas à la place.
 */
if (!defined('ABSPATH')) exit;

/**
 * Retourne la liste des moyens de paiement manuels configurés, chacun avec son lien
 * marchand direct — c'est CE lien que le QR encode et que le bouton "Payer avec X"
 * ouvre, sans étape intermédiaire (retour d'expérience du système KM Family : encoder
 * directement le lien marchand est plus fiable qu'un aller-retour par une page tierce,
 * l'app du moyen de paiement scanné reconnaît alors nativement son propre lien).
 *
 * @return array<string,array{label:string,icon:string,merchant_link:string}>
 */
function kophi_get_manual_payment_methods(): array {
    $all = [
        'wave' => [
            'label'         => 'Wave',
            'icon'          => '🌊',
            'merchant_link' => trim((string) get_option('kophi_wave_merchant_link', '')),
        ],
        'orange_money' => [
            'label'         => 'Orange Money',
            'icon'          => '🟠',
            'merchant_link' => trim((string) get_option('kophi_om_merchant_link', '')),
        ],
        'mtn_money' => [
            'label'         => 'MTN Money',
            'icon'          => '🟡',
            'merchant_link' => trim((string) get_option('kophi_mtn_merchant_link', '')),
        ],
        'moov_money' => [
            'label'         => 'Moov Money',
            'icon'          => '🔵',
            'merchant_link' => trim((string) get_option('kophi_moov_merchant_link', '')),
        ],
    ];

    // Ne garder que les moyens dont le lien marchand est réellement configuré.
    return array_filter($all, fn($m) => !empty($m['merchant_link']));
}

/**
 * CinetPay est-il configuré (Site ID + clé API) ? Détermine si l'option
 * "paiement automatique en un clic" doit apparaître à côté des moyens manuels.
 */
function kophi_is_cinetpay_configured(): bool {
    return (bool) get_option('kophi_cinetpay_site_id', '') && (bool) get_option('kophi_cinetpay_api_key', '');
}
