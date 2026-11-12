<?php

namespace App\Http\Controllers;

use App\Enums\RechargeStatut;
use App\Models\Alerte;
use App\Models\Compteur;
use App\Services\AutonomieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TableauDeBordController extends Controller
{
    // GET /compteurs/{compteur}/tableau-de-bord
    public function show(Request $request, Compteur $compteur, AutonomieService $autonomie): JsonResponse
    {
        $this->authorize('view', $compteur);

        $fuseau = config('energie.fuseau', 'Africa/Abidjan');
        $aujourdhui = Carbon::now($fuseau);
        $situation = $autonomie->situation($compteur);
        $jours = $this->jours($compteur, $aujourdhui->copy()->subDays(29)->toDateString(), $aujourdhui->toDateString());
        $complets = array_filter($jours, fn ($j) => $j['complet']);

        $rechargesMois = $compteur->recharges()
            ->where('statut', '!=', RechargeStatut::Rejete->value)
            ->where('date_achat', '>=', $aujourdhui->copy()->startOfMonth()->utc());

        return response()->json([
            'compteur' => $compteur->only(['id', 'libelle', 'numero_sts', 'actif']),
            'role' => $compteur->roleDe($request->user())->value,
            'credit' => $situation ? [
                'dernier_releve_kwh' => $situation['dernier_releve_kwh'],
                'dernier_releve_le' => $situation['dernier_releve_le']->toIso8601String(),
                'recharges_depuis_kwh' => $situation['recharges_depuis_kwh'],
                'estime_kwh' => $situation['credit_estime_kwh'],
                'seuil_faible_kwh' => (float) $compteur->seuil_credit_faible_kwh,
                'faible' => $situation['credit_estime_kwh'] <= (float) $compteur->seuil_credit_faible_kwh,
            ] : null,
            // null tant qu'il n'y a pas au moins un jour complet de consommation.
            'autonomie' => $situation['autonomie'] ?? null,
            'consommation' => [
                'jours' => $jours,
                'total_30j_kwh' => round(array_sum(array_column($jours, 'kwh')), 2),
                'moyenne_kwh_jour' => $complets ? round(array_sum(array_column($complets, 'kwh')) / count($complets), 2) : null,
            ],
            'recharges' => [
                'en_attente' => $compteur->recharges()->where('statut', RechargeStatut::EnAttente->value)->count(),
                'mois_fcfa' => (int) (clone $rechargesMois)->sum('montant_fcfa'),
                'mois_kwh' => round((float) (clone $rechargesMois)->sum('credit_kwh'), 2),
            ],
            'alertes_non_lues' => Alerte::where('compteur_id', $compteur->id)->whereNull('lue_le')->count(),
        ]);
    }

    // GET /compteurs/{compteur}/consommations?du=2026-10-01&au=2026-10-31
    public function consommations(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('view', $compteur);

        $data = $request->validate([
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
        ]);

        $au = $data['au'] ?? Carbon::now(config('energie.fuseau', 'Africa/Abidjan'))->toDateString();
        $du = $data['du'] ?? Carbon::parse($au)->subDays(29)->toDateString();

        if (Carbon::parse($du)->diffInDays(Carbon::parse($au)) > 366) {
            return response()->json(['message' => 'La période ne peut pas dépasser un an.'], 422);
        }

        $jours = $this->jours($compteur, $du, $au);

        return response()->json([
            'du' => $du,
            'au' => $au,
            'jours' => $jours,
            'total_kwh' => round(array_sum(array_column($jours, 'kwh')), 2),
        ]);
    }

    // GET /compteurs/{compteur}/alertes
    public function alertes(Compteur $compteur): JsonResponse
    {
        $this->authorize('view', $compteur);

        return response()->json(Alerte::where('compteur_id', $compteur->id)->latest()->paginate(30));
    }

    // POST /alertes/{alerte}/lue
    public function marquerLue(Alerte $alerte): JsonResponse
    {
        $this->authorize('view', Compteur::findOrFail($alerte->compteur_id));
        $alerte->update(['lue_le' => $alerte->lue_le ?? now()]);

        return response()->json($alerte);
    }

    /** @return array<array{jour:string, kwh:float, source:string, complet:bool}> */
    private function jours(Compteur $compteur, string $du, string $au): array
    {
        return $compteur->consommationsJournalieres()
            ->whereDate('jour', '>=', $du)->whereDate('jour', '<=', $au)
            ->orderBy('jour')->get()
            ->map(fn ($c) => [
                'jour' => $c->jour->toDateString(),
                'kwh' => (float) $c->kwh,
                'source' => $c->source,
                'complet' => $c->complet,
            ])->all();
    }
}
