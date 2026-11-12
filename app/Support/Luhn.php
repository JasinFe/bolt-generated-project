<?php

namespace App\Support;

final class Luhn
{
    /** Vrai si la chaîne de chiffres porte une clé de Luhn valide (dernier chiffre). */
    public static function estValide(string $chiffres): bool
    {
        if ($chiffres === '' || ! ctype_digit($chiffres)) {
            return false;
        }

        $somme = 0;
        $doubler = false;

        for ($i = strlen($chiffres) - 1; $i >= 0; $i--) {
            $n = (int) $chiffres[$i];
            if ($doubler) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $somme += $n;
            $doubler = ! $doubler;
        }

        return $somme % 10 === 0;
    }
}
