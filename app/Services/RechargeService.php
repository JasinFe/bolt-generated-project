<?php

namespace App\Services;

use App\Enums\RechargeStatut;
use App\Exceptions\CodeDejaEnregistre;
use App\Models\Compteur;
use App\Models\Recharge;
use App\Models\User;
use App\Support\CodeRecharge;
use Illuminate\Database\UniqueConstraintViolationException;

class RechargeService
{
    /**
     * Enregistre un code reçu après paiement CIE.
     * Ne crédite PAS le compteur : l'utilisateur devra taper le code sur le clavier.
     *
     * @param  array{montant_fcfa:int, credit_kwh?:float|null, date_achat:string, mode_paiement:string,
     *               reference_paiement?:string|null, observation?:string|null}  $donnees
     *
     * @throws CodeDejaEnregistre
     */
    public function enregistrer(Compteur $compteur, CodeRecharge $code, array $donnees, User $auteur): Recharge
    {
        $recharge = new Recharge($donnees);
        $recharge->compteur_id = $compteur->id;
        $recharge->enregistre_par = $auteur->id;
        $recharge->statut = RechargeStatut::EnAttente;
        $recharge->definirCode($code);

        try {
            // L'index UNIQUE sur code_empreinte est la vraie garantie :
            // il tient même si deux requêtes arrivent en même temps.
            $recharge->save();
        } catch (UniqueConstraintViolationException) {
            throw new CodeDejaEnregistre(
                Recharge::where('code_empreinte', $recharge->code_empreinte)->first()
            );
        }

        return $recharge;
    }
}
