<?php

namespace App\Services;

use App\Enums\RechargeStatut;
use App\Models\Compteur;
use App\Support\ReglesAlertes;
use Illuminate\Support\Carbon;

class AutonomieService
{
    /**
     * Estimation du nombre de jours restants à partir du crédit estimé MAINTENANT.
     * Basée sur la moyenne mobile des derniers jours COMPLETS, jamais sur la seule journée en cours.
     *
     * @return array{credit_kwh:float, moyenne_kwh_jour:float, jours_min:int, jours_max:int, jours_estimes:int, fiable:bool}|null
     */
    public function estimer(Compteur $compteur): ?array
    {
        return $this->situation($compteur)['autonomie'] ?? null;
    }

    /**
     * Situation du crédit : dernier relevé, crédit estimé maintenant, autonomie.
     *
     * Crédit estimé = dernier relevé de crédit
     *               + recharges tapées sur le compteur depuis (pas encore confirmées par un relevé)
     *               − consommation moyenne depuis ce relevé.
     *
     * @return array{dernier_releve_kwh:float, dernier_releve_le:Carbon, recharges_depuis_kwh:float,
     *               credit_estime_kwh:float, autonomie:?array}|null  null = aucun relevé de crédit
     */
    public function situation(Compteur $compteur): ?array
    {
        $releve = $compteur->dernierCredit();
        if (! $releve) {
            return null;
        }

        // Une recharge confirmée crée son propre relevé : seules les « saisies » restent à ajouter.
        $ajouts = (float) $compteur->recharges()
            ->where('statut', RechargeStatut::Saisi->value)
            ->where('date_saisie', '>', $releve->releve_le)
            ->sum('credit_kwh');

        $base = (float) $releve->valeur_kwh + $ajouts;
        $fenetre = (int) config('energie.fenetre_moyenne_jours', 7);
        $jours = $this->derniersJoursComplets($compteur, $fenetre);

        $autonomie = self::calculer($base, $jours, $fenetre);
        $credit = $base;

        if ($autonomie) {
            $credit = ReglesAlertes::creditEstime(
                $base,
                $releve->releve_le->diffInMinutes(now()) / 60,
                $autonomie['moyenne_kwh_jour']
            );
            $autonomie = self::calculer($credit, $jours, $fenetre);
        }

        return [
            'dernier_releve_kwh' => (float) $releve->valeur_kwh,
            'dernier_releve_le' => $releve->releve_le,
            'recharges_depuis_kwh' => round($ajouts, 2),
            'credit_estime_kwh' => round($credit, 2),
            'autonomie' => $autonomie,
        ];
    }

    /** @return float[] kWh par jour, du plus récent au plus ancien (aujourd'hui exclu) */
    private function derniersJoursComplets(Compteur $compteur, int $fenetre): array
    {
        $aujourdhui = Carbon::now(config('energie.fuseau', 'Africa/Abidjan'))->toDateString();

        return $compteur->consommationsJournalieres()
            ->where('complet', true)
            ->whereDate('jour', '<', $aujourdhui)
            ->orderByDesc('jour')
            ->limit($fenetre)
            ->pluck('kwh')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Calcul pur (testable sans base).
     *
     * @param  float[]  $consommations  kWh par jour, du plus récent au plus ancien
     */
    public static function calculer(float $creditKwh, array $consommations, int $fenetre = 7): ?array
    {
        $consommations = array_values(array_filter($consommations, fn ($v) => $v > 0));
        if ($consommations === []) {
            return null;
        }

        $moyenne = array_sum($consommations) / count($consommations);
        $min = min($consommations);
        $max = max($consommations);

        return [
            'credit_kwh' => round($creditKwh, 2),
            'moyenne_kwh_jour' => round($moyenne, 2),
            // Fourchette : jour le plus gourmand → jour le plus sobre.
            'jours_min' => (int) floor($creditKwh / $max),
            'jours_max' => (int) floor($creditKwh / $min),
            'jours_estimes' => (int) floor($creditKwh / $moyenne),
            // Moins d'une fenêtre complète d'historique : afficher « estimation provisoire ».
            'fiable' => count($consommations) >= $fenetre,
        ];
    }
}
