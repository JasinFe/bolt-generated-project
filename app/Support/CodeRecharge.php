<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Code de recharge STS (20 chiffres).
 *
 * Classe sans dépendance Laravel : testable seule.
 * On ne cherche JAMAIS à déchiffrer le contenu du code (il est chiffré
 * avec la clé propre au compteur, que seule la CIE détient).
 */
final class CodeRecharge
{
    public const LONGUEUR = 20;

    private function __construct(private readonly string $chiffres) {}

    /** Accepte « 1234 5678-9012… », un copier-coller de SMS, etc. */
    public static function depuisSaisie(string $saisie): self
    {
        $chiffres = self::normaliser($saisie);

        if (strlen($chiffres) !== self::LONGUEUR) {
            throw new InvalidArgumentException(
                sprintf('Le code doit contenir exactement %d chiffres (%d trouvés).', self::LONGUEUR, strlen($chiffres))
            );
        }

        return new self($chiffres);
    }

    public static function normaliser(string $saisie): string
    {
        return preg_replace('/\D+/', '', $saisie) ?? '';
    }

    /**
     * Cherche un code de 20 chiffres dans un SMS collé ou partagé.
     * Tolère les espaces ou tirets entre groupes. Renvoie null si 0 ou plusieurs codes.
     */
    public static function extraireDuSms(string $sms): ?self
    {
        preg_match_all('/(?<!\d)(?:\d[ \-]?){19}\d(?!\d)/', $sms, $m);
        $codes = array_values(array_unique(array_map([self::class, 'normaliser'], $m[0])));

        return count($codes) === 1 ? new self($codes[0]) : null;
    }

    public function valeur(): string
    {
        return $this->chiffres;
    }

    /** 1234 5678 9012 3456 7890 — pour le mode saisie au clavier. */
    public function enGroupes(int $taille = 4): string
    {
        return implode(' ', str_split($this->chiffres, $taille));
    }

    public function fin(): string
    {
        return substr($this->chiffres, -4);
    }

    public static function masquer(string $fin): string
    {
        return '**** **** **** **** '.$fin;
    }

    /**
     * Empreinte déterministe (HMAC-SHA256) : sert à l'index UNIQUE anti-doublon.
     * Le « pepper » ne doit jamais changer, sinon les doublons ne sont plus détectés.
     */
    public function empreinte(string $pepper): string
    {
        if (strlen($pepper) < 32) {
            throw new InvalidArgumentException('ENERGIE_CODE_PEPPER doit faire au moins 32 caractères.');
        }

        return hash_hmac('sha256', $this->chiffres, $pepper);
    }
}
