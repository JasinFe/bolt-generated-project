<?php

namespace App\Rules;

use App\Support\Luhn;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Numéro STS du compteur (celui demandé lors de l'achat CIE) :
 * 11 ou 13 chiffres, dernier chiffre = clé de Luhn.
 * Exemple valide : 42071094462.
 */
class NumeroCompteurSts implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $chiffres = preg_replace('/\D+/', '', (string) $value);

        if (! in_array(strlen($chiffres), [11, 13], true)) {
            $fail('Le numéro de compteur doit contenir 11 ou 13 chiffres.');

            return;
        }

        if (! Luhn::estValide($chiffres)) {
            $fail('Ce numéro de compteur semble erroné : vérifiez les chiffres sur l\'étiquette du compteur.');
        }
    }
}
