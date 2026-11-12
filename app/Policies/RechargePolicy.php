<?php

namespace App\Policies;

use App\Models\Compteur;
use App\Models\Recharge;
use App\Models\User;

class RechargePolicy
{
    /** Voir l'historique (codes masqués) : toute personne liée au compteur. */
    public function viewAny(User $user, Compteur $compteur): bool
    {
        return $compteur->roleDe($user) !== null;
    }

    public function create(User $user, Compteur $compteur): bool
    {
        return (bool) $compteur->roleDe($user)?->peutGererRecharges();
    }

    /** Afficher le code en clair : propriétaire, gestionnaire, ou l'auteur de l'enregistrement. */
    public function revelerCode(User $user, Recharge $recharge): bool
    {
        return $recharge->enregistre_par === $user->id
            || (bool) $recharge->compteur->roleDe($user)?->peutGererRecharges();
    }

    /** Marquer saisi / confirmer / rejeter : l'occupant peut le faire, c'est lui qui est devant le compteur. */
    public function update(User $user, Recharge $recharge): bool
    {
        return $recharge->compteur->roleDe($user) !== null;
    }
}
