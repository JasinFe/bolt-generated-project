<?php

namespace App\Enums;

enum RoleCompteur: string
{
    case Proprietaire = 'proprietaire';   // tous les droits, y compris partager le compteur
    case Gestionnaire = 'gestionnaire';   // recharges, relevés, rapports
    case Occupant = 'occupant';           // consultation + relevés manuels

    public function peutGererRecharges(): bool
    {
        return $this !== self::Occupant;
    }
}
