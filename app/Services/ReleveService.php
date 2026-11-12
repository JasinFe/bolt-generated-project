<?php

namespace App\Services;

use App\Enums\RechargeStatut;
use App\Exceptions\ReleveIncoherent;
use App\Models\Compteur;
use App\Models\Releve;
use App\Models\User;
use App\Support\ControleReleve;
use Illuminate\Support\Carbon;

class ReleveService
{
    public function __construct(private readonly ConsommationService $consommations) {}

    /**
     * Enregistre un relevé manuel après contrôle de cohérence, puis recalcule les consommations.
     *
     * @param  bool  $forcer  accepter un crédit plus élevé qu'attendu (recharge non enregistrée dans l'application)
     *
     * @throws ReleveIncoherent
     */
    public function enregistrer(Compteur $compteur, string $type, float $valeur, Carbon $le, User $auteur, bool $forcer = false): Releve
    {
        $precedent = $compteur->releves()->where('type', $type)->where('releve_le', '<=', $le)->latest('releve_le')->latest('id')->first();

        if ($type === 'index') {
            $suivant = $compteur->releves()->where('type', 'index')->where('releve_le', '>', $le)->oldest('releve_le')->first();
            $erreur = ControleReleve::index(
                $valeur,
                $precedent ? (float) $precedent->valeur_kwh : null,
                $suivant ? (float) $suivant->valeur_kwh : null
            );
            if ($erreur) {
                throw new ReleveIncoherent($erreur, bloquant: true);
            }
        } elseif ($precedent && ! $forcer) {
            $recharges = $compteur->recharges()
                ->whereIn('statut', [RechargeStatut::Saisi->value, RechargeStatut::Confirme->value])
                ->where('date_saisie', '>', $precedent->releve_le)
                ->where('date_saisie', '<=', $le)
                ->get(['credit_kwh']);

            $avertissement = ControleReleve::credit(
                $valeur,
                (float) $precedent->valeur_kwh,
                (float) $recharges->sum(fn ($r) => (float) $r->credit_kwh),
                $recharges->contains(fn ($r) => $r->credit_kwh === null)
            );
            if ($avertissement) {
                throw new ReleveIncoherent($avertissement);
            }
        }

        $releve = Releve::create([
            'compteur_id' => $compteur->id,
            'user_id' => $auteur->id,
            'type' => $type,
            'valeur_kwh' => $valeur,
            'source' => 'manuel',
            'releve_le' => $le,
        ]);

        $this->consommations->recalculer($compteur);

        return $releve;
    }

    public function supprimer(Releve $releve): void
    {
        $compteur = $releve->compteur;
        $releve->delete();
        $this->consommations->recalculer($compteur);
    }
}
