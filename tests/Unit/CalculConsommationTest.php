<?php

namespace Tests\Unit;

use App\Support\CalculConsommation as C;
use App\Support\ReglesAlertes;
use PHPUnit\Framework\TestCase;

class CalculConsommationTest extends TestCase
{
    private static function ts(string $date): int
    {
        return (new \DateTimeImmutable($date, new \DateTimeZone('Africa/Abidjan')))->getTimestamp();
    }

    public function test_credit_sans_recharge(): void
    {
        $s = C::segmentsDepuisCredits(
            [['ts' => self::ts('2026-11-01 08:00'), 'kwh' => 100.0], ['ts' => self::ts('2026-11-03 08:00'), 'kwh' => 83.2]],
            []
        );
        $this->assertTrue($s[0]['valide']);
        $this->assertSame(16.8, round($s[0]['kwh'], 2));
    }

    public function test_credit_avec_recharge_entre_deux_releves(): void
    {
        // 50 kWh → recharge de 98,5 kWh → 140 kWh : 8,5 kWh consommés.
        $s = C::segmentsDepuisCredits(
            [['ts' => self::ts('2026-11-01 08:00'), 'kwh' => 50.0], ['ts' => self::ts('2026-11-02 08:00'), 'kwh' => 140.0]],
            [['ts' => self::ts('2026-11-01 19:00'), 'kwh' => 98.5]]
        );
        $this->assertSame(8.5, round($s[0]['kwh'], 2));
    }

    public function test_recharge_confirmee_au_moment_du_releve_est_incluse(): void
    {
        $t = self::ts('2026-11-02 08:00');
        $s = C::segmentsDepuisCredits(
            [['ts' => self::ts('2026-11-01 08:00'), 'kwh' => 50.0], ['ts' => $t, 'kwh' => 140.0]],
            [['ts' => $t, 'kwh' => 98.5]]
        );
        $this->assertSame(8.5, round($s[0]['kwh'], 2));
    }

    public function test_recharge_de_montant_inconnu_rend_le_segment_invalide(): void
    {
        $s = C::segmentsDepuisCredits(
            [['ts' => self::ts('2026-11-01 08:00'), 'kwh' => 50.0], ['ts' => self::ts('2026-11-02 08:00'), 'kwh' => 140.0]],
            [['ts' => self::ts('2026-11-01 19:00'), 'kwh' => null]]
        );
        $this->assertFalse($s[0]['valide']);
    }

    public function test_credit_qui_remonte_sans_recharge_est_incoherent(): void
    {
        $s = C::segmentsDepuisCredits(
            [['ts' => self::ts('2026-11-01 08:00'), 'kwh' => 50.0], ['ts' => self::ts('2026-11-02 08:00'), 'kwh' => 60.0]],
            []
        );
        $this->assertFalse($s[0]['valide']);
    }

    public function test_repartition_au_prorata_et_jours_complets(): void
    {
        // 48 h de 2026-11-01 12:00 à 2026-11-03 12:00, 24 kWh → 6 / 12 / 6.
        $jours = C::repartirParJour([[
            'debut' => self::ts('2026-11-01 12:00'), 'fin' => self::ts('2026-11-03 12:00'), 'kwh' => 24.0, 'valide' => true,
        ]]);

        $this->assertSame(6.0, $jours['2026-11-01']['kwh']);
        $this->assertSame(12.0, $jours['2026-11-02']['kwh']);
        $this->assertSame(6.0, $jours['2026-11-03']['kwh']);
        $this->assertFalse($jours['2026-11-01']['complet']);
        $this->assertTrue($jours['2026-11-02']['complet']);
    }

    public function test_impulsions_1600_par_kwh_et_redemarrage_esp32(): void
    {
        $s = C::segmentsDepuisImpulsions([
            ['ts' => 1000, 'cumul' => 16000],
            ['ts' => 2000, 'cumul' => 17600],   // +1600 → 1 kWh
            ['ts' => 3000, 'cumul' => 800],     // redémarrage : 800 impulsions depuis 0
        ], 1600);

        $this->assertSame(1.0, $s[0]['kwh']);
        $this->assertTrue($s[0]['valide']);
        $this->assertSame(0.5, $s[1]['kwh']);
        $this->assertFalse($s[1]['valide']);
    }

    public function test_fusion_prefere_iot_complet(): void
    {
        $iot = ['2026-11-02' => ['kwh' => 9.1, 'couvert_s' => 86400, 'complet' => true]];
        $rel = ['2026-11-02' => ['kwh' => 8.0, 'couvert_s' => 86400, 'complet' => true],
            '2026-11-03' => ['kwh' => 7.5, 'couvert_s' => 86400, 'complet' => true]];

        $f = C::fusionner($iot, $rel);
        $this->assertSame('iot', $f['2026-11-02']['source']);
        $this->assertSame('releves', $f['2026-11-03']['source']);
    }

    public function test_credit_estime_et_surconsommation(): void
    {
        // 30 kWh relevés il y a 36 h, 8 kWh/jour → 18 kWh.
        $this->assertSame(18.0, ReglesAlertes::creditEstime(30.0, 36, 8.0));
        $this->assertSame(0.0, ReglesAlertes::creditEstime(5.0, 48, 8.0));

        // Mardi habituel ~8 kWh, hier 12 kWh → +50 %.
        $r = ReglesAlertes::surconsommation(12.0, [8.0, 7.8, 8.2], [8, 8, 8, 8, 8, 8, 8]);
        $this->assertSame(50, $r['ecart_pct']);

        // +20 % : sous le seuil de 30 %.
        $this->assertNull(ReglesAlertes::surconsommation(9.6, [8.0, 8.0], []));
        // Écart de 0,6 kWh : trop petit pour alerter, même à +60 %.
        $this->assertNull(ReglesAlertes::surconsommation(1.6, [1.0, 1.0], []));
    }
}
