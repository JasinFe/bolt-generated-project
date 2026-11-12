<?php

namespace App\Services;

use App\Enums\RechargeStatut;
use App\Models\Compteur;
use App\Models\ConsommationJournaliere;
use App\Support\CalculConsommation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ConsommationService
{
    /**
     * Recalcule les consommations journalières des $jours derniers jours.
     * On recalcule une fenêtre large (35 jours par défaut) car un relevé saisi
     * en retard modifie les jours passés.
     *
     * @return int nombre de jours écrits
     */
    public function recalculer(Compteur $compteur, int $jours = 35): int
    {
        $fuseau = config('energie.fuseau', 'Africa/Abidjan');
        $depuis = Carbon::now($fuseau)->startOfDay()->subDays($jours);

        $parReleves = $this->depuisReleves($compteur, $depuis);
        $parIot = $this->depuisIot($compteur, $depuis);

        $fusion = CalculConsommation::fusionner(
            CalculConsommation::repartirParJour($parIot, $fuseau),
            CalculConsommation::repartirParJour($parReleves, $fuseau),
        );

        // On ne garde que la fenêtre demandée (les segments peuvent déborder avant).
        $debut = $depuis->format('Y-m-d');
        $lignes = [];
        foreach ($fusion as $jour => $c) {
            if ($jour < $debut) {
                continue;
            }
            $lignes[] = [
                'compteur_id' => $compteur->id,
                'jour' => $jour,
                'kwh' => $c['kwh'],
                'source' => $c['source'],
                'complet' => $c['complet'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($compteur, $debut, $lignes) {
            // Un jour qui n'a plus de données (relevé supprimé) doit disparaître.
            ConsommationJournaliere::where('compteur_id', $compteur->id)->whereDate('jour', '>=', $debut)->delete();
            if ($lignes) {
                ConsommationJournaliere::insert($lignes);
            }
        });

        return count($lignes);
    }

    private function depuisReleves(Compteur $compteur, Carbon $depuis): array
    {
        // Index cumulé : prioritaire s'il existe (pas besoin des recharges).
        $index = $this->serie($compteur, 'index', $depuis);
        if (count($index) >= 2) {
            return CalculConsommation::segmentsDepuisIndex($index);
        }

        $credits = $this->serie($compteur, 'credit', $depuis);
        if (count($credits) < 2) {
            return [];
        }

        $ajouts = $compteur->recharges()
            ->whereIn('statut', [RechargeStatut::Saisi->value, RechargeStatut::Confirme->value])
            ->where(fn ($q) => $q->where('date_saisie', '>', Carbon::createFromTimestamp($credits[0]['ts']))
                ->orWhere('date_confirmation', '>', Carbon::createFromTimestamp($credits[0]['ts'])))
            ->get()
            ->map(function ($r) {
                $kwh = $r->credit_kwh !== null ? (float) $r->credit_kwh
                    : (($r->credit_avant_kwh !== null && $r->credit_apres_kwh !== null)
                        ? (float) $r->credit_apres_kwh - (float) $r->credit_avant_kwh
                        : null);

                return ['ts' => ($r->date_saisie ?? $r->date_confirmation)->getTimestamp(), 'kwh' => $kwh];
            })
            ->all();

        return CalculConsommation::segmentsDepuisCredits($credits, $ajouts);
    }

    /** Le dernier relevé AVANT la fenêtre + tous ceux de la fenêtre. */
    private function serie(Compteur $compteur, string $type, Carbon $depuis): array
    {
        $base = $compteur->releves()->where('type', $type);

        $avant = (clone $base)->where('releve_le', '<', $depuis)->latest('releve_le')->latest('id')->first();
        $dans = (clone $base)->where('releve_le', '>=', $depuis)->orderBy('releve_le')->orderBy('id')->get();

        return collect([$avant])->filter()->concat($dans)
            ->map(fn ($r) => ['ts' => $r->releve_le->getTimestamp(), 'kwh' => (float) $r->valeur_kwh])
            ->values()->all();
    }

    private function depuisIot(Compteur $compteur, Carbon $depuis): array
    {
        $capteur = $compteur->capteur;
        if (! $capteur) {
            return [];
        }

        $q = DB::table('mesures_iot')->where('capteur_id', $capteur->id);

        $avant = (clone $q)->where('mesure_le', '<', $depuis)->orderByDesc('mesure_le')->first(['mesure_le', 'impulsions_cumul']);
        $dans = (clone $q)->where('mesure_le', '>=', $depuis)->orderBy('mesure_le')->get(['mesure_le', 'impulsions_cumul']);

        $mesures = collect([$avant])->filter()->concat($dans)
            ->map(fn ($m) => ['ts' => Carbon::parse($m->mesure_le)->getTimestamp(), 'cumul' => (int) $m->impulsions_cumul])
            ->values()->all();

        return CalculConsommation::segmentsDepuisImpulsions($mesures, $compteur->impulsions_par_kwh ?: 1600);
    }
}
