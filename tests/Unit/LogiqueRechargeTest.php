<?php

namespace Tests\Unit;

use App\Enums\RechargeStatut;
use App\Services\AutonomieService;
use App\Support\CodeRecharge;
use App\Support\Luhn;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LogiqueRechargeTest extends TestCase
{
    private const PEPPER = 'pepper-de-test-de-32-caracteres-min';

    public function test_numero_du_compteur_photo_est_valide(): void
    {
        $this->assertTrue(Luhn::estValide('42071094462'));
        $this->assertFalse(Luhn::estValide('42071094463'));   // dernier chiffre faux
        $this->assertFalse(Luhn::estValide('42017094462'));   // deux chiffres inversés
    }

    public function test_normalisation_et_groupes(): void
    {
        $code = CodeRecharge::depuisSaisie('1234 5678-9012 3456 7890');
        $this->assertSame('12345678901234567890', $code->valeur());
        $this->assertSame('1234 5678 9012 3456 7890', $code->enGroupes());
        $this->assertSame('**** **** **** **** 7890', CodeRecharge::masquer($code->fin()));
    }

    public function test_code_de_mauvaise_longueur_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CodeRecharge::depuisSaisie('1234 5678 9012 3456 789');
    }

    public function test_meme_code_meme_empreinte_quelle_que_soit_la_saisie(): void
    {
        $a = CodeRecharge::depuisSaisie('12345678901234567890')->empreinte(self::PEPPER);
        $b = CodeRecharge::depuisSaisie('1234-5678-9012-3456-7890')->empreinte(self::PEPPER);
        $c = CodeRecharge::depuisSaisie('12345678901234567891')->empreinte(self::PEPPER);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame(64, strlen($a));
    }

    public function test_extraction_depuis_sms(): void
    {
        $sms = 'CIE: Paiement 10000F recu. Compteur 42071094462. Code: 1234 5678 9012 3456 7890. Energie: 98,5kWh';
        $this->assertSame('12345678901234567890', CodeRecharge::extraireDuSms($sms)?->valeur());

        // Le numéro de compteur (11 chiffres) ne doit pas être pris pour un code.
        $this->assertNull(CodeRecharge::extraireDuSms('Compteur 42071094462, montant 10000'));
    }

    public function test_transitions_de_statut(): void
    {
        $this->assertTrue(RechargeStatut::EnAttente->peutPasserA(RechargeStatut::Saisi));
        $this->assertTrue(RechargeStatut::Saisi->peutPasserA(RechargeStatut::Confirme));
        $this->assertFalse(RechargeStatut::EnAttente->peutPasserA(RechargeStatut::Confirme));
        $this->assertFalse(RechargeStatut::Confirme->peutPasserA(RechargeStatut::Rejete));
    }

    public function test_autonomie_cas_de_la_maquette(): void
    {
        // 210,2 kWh à 8,4 kWh/jour : 25 jours, pas 30.
        $r = AutonomieService::calculer(210.2, array_fill(0, 7, 8.4));
        $this->assertSame(25, $r['jours_estimes']);
        $this->assertTrue($r['fiable']);

        // Historique court : estimation provisoire.
        $r = AutonomieService::calculer(210.2, [7.0, 9.8]);
        $this->assertFalse($r['fiable']);
        $this->assertSame(21, $r['jours_min']);
        $this->assertSame(30, $r['jours_max']);
    }
}
