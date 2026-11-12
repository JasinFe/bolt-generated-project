<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calculs de consommation, sans dépendance Laravel (testables seuls).
 *
 * Tout passe par des « segments » : [debut (timestamp), fin (timestamp), kwh, valide].
 * Trois sources produisent des segments, puis repartirParJour() les ventile par jour.
 */
final class CalculConsommation
{
    /** Écart négatif toléré (arrondi de l'afficheur, 0,1 kWh) avant de déclarer une incohérence. */
    public const TOLERANCE_KWH = 0.5;

    /**
     * Relevés de CRÉDIT (le solde baisse avec la consommation, remonte avec les recharges).
     * Consommation entre deux relevés = crédit avant + recharges ajoutées entre les deux − crédit après.
     *
     * @param  array<array{ts:int, kwh:float}>  $releves  triés par ts
     * @param  array<array{ts:int, kwh:?float}>  $ajouts  recharges saisies ; kwh null = montant inconnu
     * @return array<array{debut:int, fin:int, kwh:float, valide:bool}>
     */
    public static function segmentsDepuisCredits(array $releves, array $ajouts): array
    {
        $segments = [];

        for ($i = 1, $n = count($releves); $i < $n; $i++) {
            $a = $releves[$i - 1];
            $b = $releves[$i];
            $ajoute = 0.0;
            $inconnu = false;

            foreach ($ajouts as $r) {
                // Intervalle ]a, b] : une recharge confirmée par le relevé b y est incluse.
                if ($r['ts'] > $a['ts'] && $r['ts'] <= $b['ts']) {
                    if ($r['kwh'] === null) {
                        $inconnu = true;
                    } else {
                        $ajoute += $r['kwh'];
                    }
                }
            }

            $kwh = $a['kwh'] + $ajoute - $b['kwh'];
            $valide = ! $inconnu && $kwh >= -self::TOLERANCE_KWH;

            $segments[] = ['debut' => $a['ts'], 'fin' => $b['ts'], 'kwh' => $valide ? max(0.0, $kwh) : 0.0, 'valide' => $valide];
        }

        return $segments;
    }

    /**
     * Relevés d'INDEX (consommation cumulée, ne fait qu'augmenter). Plus simple et plus robuste.
     *
     * @param  array<array{ts:int, kwh:float}>  $releves  triés par ts
     */
    public static function segmentsDepuisIndex(array $releves): array
    {
        $segments = [];

        for ($i = 1, $n = count($releves); $i < $n; $i++) {
            $kwh = $releves[$i]['kwh'] - $releves[$i - 1]['kwh'];
            $valide = $kwh >= -self::TOLERANCE_KWH;
            $segments[] = ['debut' => $releves[$i - 1]['ts'], 'fin' => $releves[$i]['ts'], 'kwh' => $valide ? max(0.0, $kwh) : 0.0, 'valide' => $valide];
        }

        return $segments;
    }

    /**
     * Mesures IoT : compteur d'impulsions cumulé (voyant 1600 imp/kWh).
     * Si le cumul redescend, l'ESP32 a redémarré : on compte depuis zéro et on signale
     * le segment comme douteux (des impulsions non sauvegardées ont pu être perdues).
     *
     * @param  array<array{ts:int, cumul:int}>  $mesures  triées par ts
     */
    public static function segmentsDepuisImpulsions(array $mesures, int $impulsionsParKwh): array
    {
        $segments = [];

        for ($i = 1, $n = count($mesures); $i < $n; $i++) {
            $delta = $mesures[$i]['cumul'] - $mesures[$i - 1]['cumul'];
            $redemarrage = $delta < 0;
            if ($redemarrage) {
                $delta = $mesures[$i]['cumul'];
            }

            $segments[] = [
                'debut' => $mesures[$i - 1]['ts'],
                'fin' => $mesures[$i]['ts'],
                'kwh' => (float) $delta / $impulsionsParKwh,
                'valide' => ! $redemarrage,
            ];
        }

        return $segments;
    }

    /**
     * Ventile les segments par jour calendaire, au prorata du temps.
     * Un jour est « complet » s'il est couvert de minuit à minuit par des segments valides.
     *
     * @return array<string, array{kwh:float, couvert_s:int, complet:bool}> clé Y-m-d
     */
    public static function repartirParJour(array $segments, string $fuseau = 'Africa/Abidjan'): array
    {
        $tz = new DateTimeZone($fuseau);
        $jours = [];

        foreach ($segments as $s) {
            if ($s['fin'] <= $s['debut']) {
                continue;
            }

            $debutJour = (new DateTimeImmutable('@'.$s['debut']))->setTimezone($tz)->setTime(0, 0);
            $taux = $s['kwh'] / ($s['fin'] - $s['debut']);

            for ($j = $debutJour; $j->getTimestamp() < $s['fin']; $j = $j->modify('+1 day')) {
                $cle = $j->format('Y-m-d');
                $finJ = $j->modify('+1 day')->getTimestamp();
                $recouvrement = min($s['fin'], $finJ) - max($s['debut'], $j->getTimestamp());
                if ($recouvrement <= 0) {
                    continue;
                }

                $jours[$cle] ??= ['kwh' => 0.0, 'couvert_s' => 0, 'invalide' => false, 'duree_s' => $finJ - $j->getTimestamp()];

                if ($s['valide']) {
                    $jours[$cle]['kwh'] += $taux * $recouvrement;
                    $jours[$cle]['couvert_s'] += $recouvrement;
                } else {
                    $jours[$cle]['invalide'] = true;
                }
            }
        }

        ksort($jours);

        return array_map(fn ($j) => [
            'kwh' => round($j['kwh'], 3),
            'couvert_s' => $j['couvert_s'],
            'complet' => ! $j['invalide'] && $j['couvert_s'] >= $j['duree_s'] - 60,
        ], $jours);
    }

    /**
     * Choisit, jour par jour, la meilleure source : IoT complet > relevés complets > ce qui couvre le plus.
     *
     * @return array<string, array{kwh:float, complet:bool, source:string}>
     */
    public static function fusionner(array $iot, array $releves): array
    {
        $resultat = [];

        foreach (array_unique([...array_keys($iot), ...array_keys($releves)]) as $jour) {
            $i = $iot[$jour] ?? null;
            $r = $releves[$jour] ?? null;

            $choix = match (true) {
                $i && $i['complet'] => ['iot', $i],
                $r && $r['complet'] => ['releves', $r],
                $i && (! $r || $i['couvert_s'] >= $r['couvert_s']) => ['iot', $i],
                default => ['releves', $r],
            };

            if ($choix[1]['couvert_s'] > 0) {
                $resultat[$jour] = ['kwh' => $choix[1]['kwh'], 'complet' => $choix[1]['complet'], 'source' => $choix[0]];
            }
        }

        ksort($resultat);

        return $resultat;
    }
}
