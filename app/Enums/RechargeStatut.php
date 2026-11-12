<?php

namespace App\Enums;

enum RechargeStatut: string
{
    case EnAttente = 'en_attente';   // enregistré dans l'application, pas encore tapé sur le compteur
    case Saisi = 'saisi';            // l'utilisateur dit l'avoir tapé
    case Confirme = 'confirme';      // crédit ajouté, vérifié par un relevé
    case Rejete = 'rejete';          // refusé par le compteur (mauvais compteur, déjà utilisé…)

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'À saisir',
            self::Saisi => 'Saisi',
            self::Confirme => 'Confirmé',
            self::Rejete => 'Anomalie',
        };
    }

    /** Transitions autorisées. */
    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, match ($this) {
            self::EnAttente => [self::Saisi, self::Rejete],
            self::Saisi => [self::Confirme, self::Rejete],
            self::Rejete => [self::Saisi],          // nouvel essai après correction
            self::Confirme => [],
        }, true);
    }
}
