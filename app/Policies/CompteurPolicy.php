<?php

namespace App\Policies;

use App\Enums\RoleCompteur;
use App\Models\Compteur;
use App\Models\User;

class CompteurPolicy
{
    /** Consulter (tableau de bord, relevés, alertes) : toute personne liée au compteur. */
    public function view(User $user, Compteur $compteur): bool
    {
        return $compteur->roleDe($user) !== null;
    }

    /** Ajouter un relevé : l'occupant aussi, c'est lui qui est devant le compteur. */
    public function ajouterReleve(User $user, Compteur $compteur): bool
    {
        return $compteur->roleDe($user) !== null;
    }

    /** Modifier, supprimer, partager, gérer le capteur : propriétaire uniquement. */
    public function update(User $user, Compteur $compteur): bool
    {
        return $compteur->roleDe($user) === RoleCompteur::Proprietaire;
    }

    public function delete(User $user, Compteur $compteur): bool
    {
        return $this->update($user, $compteur);
    }

    public function partager(User $user, Compteur $compteur): bool
    {
        return $this->update($user, $compteur);
    }
}
