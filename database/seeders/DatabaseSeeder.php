<?php

namespace Database\Seeders;

use App\Enums\RoleCompteur;
use App\Models\Releve;
use App\Models\Site;
use App\Models\User;
use App\Services\ConsommationService;
use App\Services\RechargeService;
use App\Support\CodeRecharge;
use Illuminate\Database\Seeder;

/**
 * Données de démonstration : `php artisan migrate:fresh --seed`
 * Compte : demo@example.com / demo-energie
 */
class DatabaseSeeder extends Seeder
{
    public function run(RechargeService $recharges, ConsommationService $consommations): void
    {
        $user = User::factory()->create([
            'name' => 'Compte de démonstration',
            'email' => 'demo@example.com',
            'password' => 'demo-energie',
        ]);

        $site = Site::create(['proprietaire_id' => $user->id, 'nom' => 'Maison Cocody', 'type' => 'habitation', 'ville' => 'Abidjan']);
        $compteur = $site->compteurs()->create([
            'libelle' => 'Compteur salon', 'numero_sts' => '42071094462', 'marque' => 'KAIFA', 'modele' => 'MF101C',
        ]);
        $compteur->utilisateurs()->attach($user->id, ['role' => RoleCompteur::Proprietaire->value]);

        // 21 jours de relevés de crédit chaque matin (7 à 10 kWh par jour), avec une recharge il y a 9 jours.
        $credit = 150.0;
        mt_srand(2026);
        for ($j = 21; $j >= 0; $j--) {
            $le = now()->subDays($j)->setTime(7, 30);

            if ($j === 9) {
                $recharge = $recharges->enregistrer($compteur, CodeRecharge::depuisSaisie('4821 0937 5560 1284 7719'), [
                    'montant_fcfa' => 10000, 'credit_kwh' => 98.5, 'date_achat' => $le->copy()->subHours(12), 'mode_paiement' => 'orange_money',
                ], $user);
                $recharge->forceFill([
                    'statut' => 'confirme', 'date_saisie' => $le->copy()->subHours(11), 'date_confirmation' => $le->copy()->subHours(11),
                    'credit_avant_kwh' => round($credit - 4, 2), 'credit_apres_kwh' => round($credit + 94.5, 2),
                ])->save();
                $credit += 98.5;
            }

            Releve::create([
                'compteur_id' => $compteur->id, 'user_id' => $user->id, 'type' => 'credit',
                'valeur_kwh' => round($credit, 2), 'source' => 'manuel', 'releve_le' => $le,
            ]);
            $credit -= mt_rand(70, 100) / 10;
        }

        // Un code acheté hier, pas encore tapé sur le compteur.
        $recharges->enregistrer($compteur, CodeRecharge::depuisSaisie('1234 5678 9012 3456 7890'), [
            'montant_fcfa' => 5000, 'credit_kwh' => 49.2, 'date_achat' => now()->subDay(), 'mode_paiement' => 'wave',
        ], $user);

        $consommations->recalculer($compteur);
    }
}
