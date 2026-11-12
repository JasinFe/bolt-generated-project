<?php

namespace App\Http\Controllers;

use App\Exceptions\CodeDejaEnregistre;
use App\Exceptions\TransitionRechargeInterdite;
use App\Http\Requests\StoreRechargeRequest;
use App\Models\Compteur;
use App\Models\Recharge;
use App\Services\ConsommationService;
use App\Services\RechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RechargeController extends Controller
{
    public function __construct(
        private readonly RechargeService $service,
        private readonly ConsommationService $consommations,
    ) {}

    // GET /compteurs/{compteur}/recharges
    public function index(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('viewAny', [Recharge::class, $compteur]);

        return response()->json(
            $compteur->recharges()->latest('date_achat')->paginate(20)   // code_masque uniquement
        );
    }

    // POST /compteurs/{compteur}/recharges
    public function store(StoreRechargeRequest $request, Compteur $compteur): JsonResponse
    {
        try {
            $recharge = $this->service->enregistrer(
                $compteur,
                $request->code(),
                $request->safe()->except('code'),
                $request->user()
            );
        } catch (CodeDejaEnregistre $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'recharge_existante' => $e->existante?->only(['numero', 'statut', 'date_achat']),
            ], 409);
        }

        return response()->json($recharge->fresh(), 201);
    }

    // POST /recharges/{recharge}/reveler — pour le mode saisie au clavier
    public function reveler(Request $request, Recharge $recharge): JsonResponse
    {
        $this->authorize('revelerCode', $recharge);

        $code = $recharge->revelerCode($request->user());

        return response()
            ->json(['groupes' => explode(' ', $code->enGroupes())])
            ->header('Cache-Control', 'no-store');
    }

    // POST /recharges/{recharge}/saisi
    public function saisi(Request $request, Recharge $recharge): JsonResponse
    {
        $this->authorize('update', $recharge);
        $data = $request->validate(['credit_avant_kwh' => ['nullable', 'numeric', 'min:0']]);

        return $this->transition(fn () => $recharge->marquerSaisi($data['credit_avant_kwh'] ?? null), $recharge);
    }

    // POST /recharges/{recharge}/confirmer
    public function confirmer(Request $request, Recharge $recharge): JsonResponse
    {
        $this->authorize('update', $recharge);
        $data = $request->validate(['credit_apres_kwh' => ['required', 'numeric', 'min:0']]);

        return $this->transition(fn () => $recharge->confirmer((float) $data['credit_apres_kwh'], $request->user()), $recharge);
    }

    // POST /recharges/{recharge}/rejeter
    public function rejeter(Request $request, Recharge $recharge): JsonResponse
    {
        $this->authorize('update', $recharge);
        $data = $request->validate(['message_compteur' => ['required', 'string', 'max:120']]);

        return $this->transition(fn () => $recharge->rejeter($data['message_compteur']), $recharge);
    }

    private function transition(callable $action, Recharge $recharge): JsonResponse
    {
        try {
            $action();
        } catch (TransitionRechargeInterdite $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $recharge->refresh();
        // Une recharge saisie ou confirmée change le calcul entre deux relevés de crédit.
        $this->consommations->recalculer($recharge->compteur);

        return response()->json(array_merge(
            $recharge->toArray(),
            ['ecart_credit_kwh' => $recharge->ecartCreditKwh()]   // > 0 : moins de crédit que prévu
        ));
    }
}
