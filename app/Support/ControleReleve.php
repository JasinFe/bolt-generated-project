<?php

namespace App\Support;

/** Contrôle de cohérence d'un relevé au moment de la saisie, sans dépendance Laravel. */
final class ControleReleve
{
    /**
     * Index cumulé : ne peut ni être inférieur au relevé précédent, ni supérieur au suivant.
     *
     * @return string|null message d'erreur, null si cohérent
     */
    public static function index(float $valeur, ?float $precedent, ?float $suivant): ?string
    {
        if ($precedent !== null && $valeur < $precedent) {
            return sprintf('L\'index ne peut pas diminuer : le relevé précédent indique %s kWh.', self::n($precedent));
        }
        if ($suivant !== null && $valeur > $suivant) {
            return sprintf('L\'index dépasse le relevé suivant (%s kWh).', self::n($suivant));
        }

        return null;
    }

    /**
     * Crédit restant : ne peut pas remonter sans recharge saisie entre les deux relevés.
     *
     * @param  float|null  $precedent  crédit du relevé précédent
     * @param  float  $rechargesKwh  kWh des recharges saisies depuis le relevé précédent
     * @param  bool  $rechargeInconnue  une recharge sans kWh connus a été saisie : on ne peut rien conclure
     * @return string|null message d'avertissement, null si cohérent
     */
    public static function credit(float $valeur, ?float $precedent, float $rechargesKwh = 0.0, bool $rechargeInconnue = false): ?string
    {
        if ($precedent === null || $rechargeInconnue) {
            return null;
        }

        $maximum = $precedent + $rechargesKwh;
        if ($valeur > $maximum + CalculConsommation::TOLERANCE_KWH) {
            return sprintf(
                'Le crédit dépasse de %s kWh ce qui est attendu (%s kWh au plus). Une recharge n\'a peut-être pas été enregistrée.',
                self::n($valeur - $maximum),
                self::n($maximum)
            );
        }

        return null;
    }

    private static function n(float $v): string
    {
        return number_format($v, 1, ',', ' ');
    }
}
