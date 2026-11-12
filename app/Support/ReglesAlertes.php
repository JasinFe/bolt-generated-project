<?php

namespace App\Support;

/** Règles de décision des alertes, sans dépendance Laravel. */
final class ReglesAlertes
{
    /**
     * Crédit probable maintenant : dernier crédit relevé moins la consommation moyenne
     * depuis ce relevé. Ne descend pas sous zéro.
     */
    public static function creditEstime(float $creditReleve, float $heuresDepuisReleve, float $moyenneKwhJour): float
    {
        return max(0.0, round($creditReleve - $moyenneKwhJour * $heuresDepuisReleve / 24, 2));
    }

    /**
     * Surconsommation : le jour dépasse la référence de plus de $seuilPct %.
     * Référence = moyenne du même jour de semaine (au moins 2 valeurs), sinon moyenne des 7 derniers jours.
     * Sous $minimumKwh d'écart absolu, on ne dit rien (évite les alertes sur de petites valeurs).
     *
     * @return array{reference:float, ecart_pct:int}|null null = rien à signaler
     */
    public static function surconsommation(
        float $jourKwh,
        array $memeJourSemaine,
        array $septDerniersJours,
        int $seuilPct = 30,
        float $minimumKwh = 1.0
    ): ?array {
        $base = count($memeJourSemaine) >= 2 ? $memeJourSemaine : $septDerniersJours;
        if (count($base) < 2) {
            return null;
        }

        $reference = array_sum($base) / count($base);
        if ($reference <= 0) {
            return null;
        }

        $ecart = $jourKwh - $reference;
        if ($ecart < $minimumKwh || $jourKwh <= $reference * (1 + $seuilPct / 100)) {
            return null;
        }

        return ['reference' => round($reference, 2), 'ecart_pct' => (int) round($ecart / $reference * 100)];
    }
}
