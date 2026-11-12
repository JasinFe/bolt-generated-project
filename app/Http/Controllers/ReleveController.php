<?php

namespace App\Http\Controllers;

use App\Exceptions\ReleveIncoherent;
use App\Models\Compteur;
use App\Models\Releve;
use App\Services\ReleveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReleveController extends Controller
{
    public function __construct(private readonly ReleveService $service) {}

    // GET /compteurs/{compteur}/releves
    public function index(Compteur $compteur): JsonResponse
    {
        $this->authorize('view', $compteur);

        return response()->json($compteur->releves()->latest('releve_le')->latest('id')->paginate(30));
    }

    // POST /compteurs/{compteur}/releves
    public function store(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('ajouterReleve', $compteur);

        $data = $request->validate([
            'type' => ['required', Rule::in(['credit', 'index'])],
            'valeur_kwh' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'releve_le' => ['nullable', 'date', 'before_or_equal:now'],
            'forcer' => ['sometimes', 'boolean'],
        ]);

        try {
            $releve = $this->service->enregistrer(
                $compteur,
                $data['type'],
                (float) $data['valeur_kwh'],
                isset($data['releve_le']) ? Carbon::parse($data['releve_le']) : now(),
                $request->user(),
                (bool) ($data['forcer'] ?? false)
            );
        } catch (ReleveIncoherent $e) {
            return response()->json([
                'message' => $e->getMessage(),
                // true : renvoyer la même requête avec forcer=true pour enregistrer quand même.
                'peut_forcer' => ! $e->bloquant,
            ], 422);
        }

        return response()->json($releve, 201);
    }

    // DELETE /releves/{releve} — pour corriger une faute de frappe ; pas les relevés de confirmation
    public function destroy(Request $request, Releve $releve): JsonResponse
    {
        $this->authorize('ajouterReleve', $releve->compteur);

        if ($releve->source !== 'manuel') {
            return response()->json(['message' => 'Seuls les relevés saisis à la main peuvent être supprimés.'], 422);
        }

        $role = $releve->compteur->roleDe($request->user());
        if ($releve->user_id !== $request->user()->id && ! $role->peutGererRecharges()) {
            abort(403);
        }

        $this->service->supprimer($releve);

        return response()->json(null, 204);
    }
}
