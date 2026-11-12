<?php

namespace Tests\Feature;

use App\Models\Alerte;
use App\Models\Compteur;
use App\Models\Recharge;
use App\Models\Releve;
use App\Models\User;
use App\Notifications\AlerteEnergieNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParcoursEnergieTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '1234 5678 9012 3456 7890';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
    }

    /** Crée un site + un compteur par l'API ; l'utilisateur en devient propriétaire. */
    private function compteurDe(User $user, string $numero = '42071094462'): Compteur
    {
        Sanctum::actingAs($user);
        $site = $this->postJson('/api/sites', ['nom' => 'Maison Cocody', 'ville' => 'Abidjan'])->assertCreated()->json('id');
        $id = $this->postJson("/api/sites/$site/compteurs", ['libelle' => 'Compteur salon', 'numero_sts' => $numero])
            ->assertCreated()->json('id');

        return Compteur::findOrFail($id);
    }

    private function recharge(Compteur $compteur, array $plus = []): array
    {
        return $this->postJson("/api/compteurs/{$compteur->id}/recharges", $plus + [
            'code' => self::CODE,
            'montant_fcfa' => 10000,
            'credit_kwh' => 98.5,
            'date_achat' => now()->subHour()->toIso8601String(),
            'mode_paiement' => 'orange_money',
        ])->assertCreated()->json();
    }

    // ------------------------------------------------------------------ comptes

    public function test_inscription_connexion_et_acces_protege(): void
    {
        $this->getJson('/api/compteurs')->assertUnauthorized();

        $this->postJson('/api/inscription', [
            'name' => 'Awa', 'email' => 'awa@example.com', 'password' => 'motdepasse', 'password_confirmation' => 'motdepasse',
        ])->assertCreated()->assertJsonStructure(['user' => ['id', 'email'], 'token']);

        $this->postJson('/api/connexion', ['email' => 'awa@example.com', 'password' => 'mauvais'])->assertStatus(422);

        $token = $this->postJson('/api/connexion', ['email' => 'awa@example.com', 'password' => 'motdepasse'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/moi')->assertOk()->assertJsonPath('email', 'awa@example.com')
            ->assertJsonMissingPath('password');
    }

    public function test_mot_de_passe_oublie_et_reinitialisation(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'awa@example.com']);
        $ancien = $user->createToken('telephone')->plainTextToken;

        // Même réponse pour une adresse inconnue : on ne révèle pas quels comptes existent.
        $this->postJson('/api/mot-de-passe/oublie', ['email' => 'inconnu@example.com'])->assertOk();
        Notification::assertNothingSent();

        $this->postJson('/api/mot-de-passe/oublie', ['email' => 'awa@example.com'])->assertOk();
        $jeton = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$jeton, $user) {
            $jeton = $n->token;
            $mail = $n->toMail($user);

            return str_contains($mail->actionUrl, '?reinitialiser='.$jeton) && str_contains($mail->actionUrl, 'email=awa%40example.com');
        });

        $corps = ['email' => 'awa@example.com', 'password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret'];
        $this->postJson('/api/mot-de-passe/reinitialiser', $corps + ['token' => 'faux'])->assertStatus(422);
        $this->postJson('/api/mot-de-passe/reinitialiser', $corps + ['token' => $jeton])->assertOk();
        $this->postJson('/api/mot-de-passe/reinitialiser', $corps + ['token' => $jeton])->assertStatus(422);   // usage unique

        $this->postJson('/api/connexion', ['email' => 'awa@example.com', 'password' => 'nouveau-secret'])->assertOk();
        $this->assertSame(1, $user->tokens()->count());   // l'ancien appareil est déconnecté
        $this->assertNull(PersonalAccessToken::findToken($ancien));
    }

    public function test_mot_de_passe_oublie_quand_l_e_mail_ne_part_pas(): void
    {
        User::factory()->create(['email' => 'awa@example.com']);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'serveur-inexistant.invalid', 'mail.mailers.smtp.port' => 465]);

        $this->postJson('/api/mot-de-passe/oublie', ['email' => 'awa@example.com'])
            ->assertStatus(503)->assertJsonPath('message', 'L\'e-mail n\'a pas pu être envoyé pour le moment. Réessayez plus tard.');
    }

    // ------------------------------------------------------------------ compteurs

    public function test_creation_de_compteur_avec_controle_luhn(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $site = $this->postJson('/api/sites', ['nom' => 'Maison'])->assertCreated()->json('id');

        // Deux chiffres inversés : refusé par la clé de Luhn.
        $this->postJson("/api/sites/$site/compteurs", ['libelle' => 'Salon', 'numero_sts' => '42017094462'])
            ->assertStatus(422)->assertJsonValidationErrors('numero_sts');

        $this->postJson("/api/sites/$site/compteurs", ['libelle' => 'Salon', 'numero_sts' => '4207 1094 462'])
            ->assertCreated()->assertJsonPath('numero_sts', '42071094462')->assertJsonPath('role', 'proprietaire');

        // Même compteur une seconde fois.
        $this->postJson("/api/sites/$site/compteurs", ['libelle' => 'Bis', 'numero_sts' => '42071094462'])
            ->assertStatus(422)->assertJsonValidationErrors('numero_sts');

        $this->getJson('/api/compteurs')->assertOk()->assertJsonCount(1)->assertJsonPath('0.site.nom', 'Maison');

        // Le site d'un autre utilisateur est inaccessible.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/sites/$site/compteurs", ['libelle' => 'X', 'numero_sts' => '42071094462'])->assertForbidden();
        $this->getJson('/api/compteurs')->assertOk()->assertJsonCount(0);
    }

    public function test_partage_et_droits_par_role(): void
    {
        $proprio = User::factory()->create();
        $occupant = User::factory()->create();
        $etranger = User::factory()->create();
        $compteur = $this->compteurDe($proprio);

        $this->postJson("/api/compteurs/{$compteur->id}/partages", ['email' => 'inconnu@example.com', 'role' => 'occupant'])
            ->assertStatus(422);
        $this->postJson("/api/compteurs/{$compteur->id}/partages", ['email' => $occupant->email, 'role' => 'occupant'])
            ->assertCreated()->assertJsonCount(2);
        $id = $this->recharge($compteur)['id'];

        // L'occupant consulte et marque « saisi », mais n'enregistre ni ne révèle de code.
        Sanctum::actingAs($occupant);
        $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->assertOk()->assertJsonPath('role', 'occupant');
        $this->getJson("/api/compteurs/{$compteur->id}/recharges")->assertOk();
        $this->postJson("/api/compteurs/{$compteur->id}/recharges", [
            'code' => '1111 2222 3333 4444 5555', 'montant_fcfa' => 5000, 'date_achat' => now()->toIso8601String(), 'mode_paiement' => 'wave',
        ])->assertForbidden();
        $this->postJson("/api/recharges/$id/reveler")->assertForbidden();
        $this->postJson("/api/compteurs/{$compteur->id}/partages", ['email' => $etranger->email, 'role' => 'occupant'])->assertForbidden();
        $this->putJson("/api/compteurs/{$compteur->id}", ['libelle' => 'Piraté'])->assertForbidden();
        $this->postJson("/api/recharges/$id/saisi")->assertOk();

        // Un inconnu ne voit rien.
        Sanctum::actingAs($etranger);
        $this->getJson("/api/compteurs/{$compteur->id}")->assertForbidden();
        $this->getJson("/api/compteurs/{$compteur->id}/recharges")->assertForbidden();
        $this->postJson("/api/recharges/$id/confirmer", ['credit_apres_kwh' => 100])->assertForbidden();

        // Le propriétaire retire l'accès.
        Sanctum::actingAs($proprio);
        $this->deleteJson("/api/compteurs/{$compteur->id}/partages/{$occupant->id}")->assertNoContent();
        $this->deleteJson("/api/compteurs/{$compteur->id}/partages/{$proprio->id}")->assertStatus(422);
        $this->assertNull($compteur->roleDe($occupant));
    }

    // ------------------------------------------------------------------ recharges

    public function test_cycle_de_vie_complet_d_une_recharge(): void
    {
        $user = User::factory()->create();
        $compteur = $this->compteurDe($user);

        $r = $this->recharge($compteur);
        $numero = sprintf('RCH-2026-%06d', $r['id']);
        $this->assertSame($numero, $r['numero']);
        $this->assertSame('en_attente', $r['statut']);
        $this->assertSame('**** **** **** **** 7890', $r['code_masque']);
        $this->assertArrayNotHasKey('code_chiffre', $r);
        $this->assertArrayNotHasKey('code_empreinte', $r);

        // Le code n'est jamais stocké en clair.
        $brut = DB::table('recharges')->first();
        $this->assertStringNotContainsString('12345678901234567890', $brut->code_chiffre);
        $this->assertSame(64, strlen($brut->code_empreinte));

        // Même code, autre présentation : doublon.
        $this->postJson("/api/compteurs/{$compteur->id}/recharges", [
            'code' => '12345678-90123456-7890', 'montant_fcfa' => 10000,
            'date_achat' => now()->toIso8601String(), 'mode_paiement' => 'wave',
        ])->assertStatus(409)->assertJsonPath('recharge_existante.numero', $numero);

        // Confirmer avant d'avoir saisi : interdit.
        $this->postJson("/api/recharges/{$r['id']}/confirmer", ['credit_apres_kwh' => 110])->assertStatus(422);

        // Révéler : groupes de 4, journalisé, jamais mis en cache.
        $this->postJson("/api/recharges/{$r['id']}/reveler")->assertOk()
            ->assertExactJson(['groupes' => ['1234', '5678', '9012', '3456', '7890']])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseHas('journal_acces_codes', ['recharge_id' => $r['id'], 'user_id' => $user->id, 'action' => 'affichage']);

        $this->postJson("/api/recharges/{$r['id']}/saisi", ['credit_avant_kwh' => 12.3])->assertOk()->assertJsonPath('statut', 'saisi');

        // 12,3 + 98,5 = 110,8 attendus ; 110,5 constatés → écart de 0,3 kWh.
        $this->postJson("/api/recharges/{$r['id']}/confirmer", ['credit_apres_kwh' => 110.5])->assertOk()
            ->assertJsonPath('statut', 'confirme')->assertJsonPath('ecart_credit_kwh', 0.3);

        $this->assertDatabaseHas('releves', ['compteur_id' => $compteur->id, 'source' => 'confirmation', 'recharge_id' => $r['id']]);
        $this->postJson("/api/recharges/{$r['id']}/rejeter", ['message_compteur' => 'Rejeté'])->assertStatus(422);

        $this->getJson("/api/compteurs/{$compteur->id}/recharges")->assertOk()
            ->assertJsonPath('data.0.code_masque', '**** **** **** **** 7890')
            ->assertJsonMissingPath('data.0.code_chiffre');
    }

    public function test_sms_colle_en_entier_et_code_invalide(): void
    {
        $compteur = $this->compteurDe(User::factory()->create());

        $r = $this->recharge($compteur, [
            'code' => 'CIE: Paiement 10000F recu. Compteur 42071094462. Code: 1234 5678 9012 3456 7890. Energie: 98,5kWh',
        ]);
        $this->assertSame('**** **** **** **** 7890', $r['code_masque']);

        $this->postJson("/api/compteurs/{$compteur->id}/recharges", [
            'code' => '1234 5678 9012', 'montant_fcfa' => 10000, 'date_achat' => now()->toIso8601String(), 'mode_paiement' => 'wave',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_rejet_puis_nouvel_essai(): void
    {
        $compteur = $this->compteurDe(User::factory()->create());
        $id = $this->recharge($compteur)['id'];

        $this->postJson("/api/recharges/$id/saisi")->assertOk();
        $this->postJson("/api/recharges/$id/rejeter", ['message_compteur' => 'Code rejeté'])->assertOk()
            ->assertJsonPath('statut', 'rejete')->assertJsonPath('message_compteur', 'Code rejeté');
        $this->postJson("/api/recharges/$id/saisi")->assertOk()->assertJsonPath('message_compteur', null);
    }

    // ------------------------------------------------------------------ relevés et consommation

    public function test_releves_controle_de_coherence(): void
    {
        $user = User::factory()->create();
        $compteur = $this->compteurDe($user);
        $url = "/api/compteurs/{$compteur->id}/releves";

        $this->postJson($url, ['type' => 'credit', 'valeur_kwh' => 50, 'releve_le' => '2026-11-10 08:00'])->assertCreated();
        $this->postJson($url, ['type' => 'credit', 'valeur_kwh' => 20, 'releve_le' => '2026-11-13 08:00'])->assertStatus(422);   // futur

        // Le crédit remonte sans recharge : avertissement, puis accepté en forçant.
        $this->postJson($url, ['type' => 'credit', 'valeur_kwh' => 80, 'releve_le' => '2026-11-11 08:00'])
            ->assertStatus(422)->assertJsonPath('peut_forcer', true);
        $id = $this->postJson($url, ['type' => 'credit', 'valeur_kwh' => 80, 'releve_le' => '2026-11-11 08:00', 'forcer' => true])
            ->assertCreated()->json('id');
        $this->deleteJson("/api/releves/$id")->assertNoContent();

        // Avec une recharge saisie entre les deux, le même relevé est cohérent.
        $r = $this->recharge($compteur);
        Carbon::setTestNow('2026-11-12 10:05:00');
        $this->postJson("/api/recharges/{$r['id']}/saisi")->assertOk();
        Carbon::setTestNow('2026-11-12 10:10:00');
        $this->postJson($url, ['type' => 'credit', 'valeur_kwh' => 130])->assertCreated();

        // Index : ne peut pas diminuer, et ne se force pas.
        $this->postJson($url, ['type' => 'index', 'valeur_kwh' => 1500, 'releve_le' => '2026-11-10 08:00'])->assertCreated();
        $this->postJson($url, ['type' => 'index', 'valeur_kwh' => 1490, 'releve_le' => '2026-11-11 08:00', 'forcer' => true])
            ->assertStatus(422)->assertJsonPath('peut_forcer', false);

        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_consommation_autonomie_et_tableau_de_bord(): void
    {
        $user = User::factory()->create();
        $compteur = $this->compteurDe($user);
        $url = "/api/compteurs/{$compteur->id}/releves";

        // 8 kWh par jour pendant 10 jours : 200 → 120 kWh, relevés chaque matin à 8 h.
        for ($j = 10; $j >= 0; $j--) {
            $this->postJson($url, [
                'type' => 'credit',
                'valeur_kwh' => 120 + 8 * $j,
                'releve_le' => Carbon::parse('2026-11-12 08:00')->subDays($j)->toDateTimeString(),
            ])->assertCreated();
        }

        $t = $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->assertOk()->json();

        // Dernier relevé 120 kWh à 8 h, il est 10 h : 2 h à 8 kWh/jour → 119,33 kWh.
        $this->assertSame(120, (int) $t['credit']['dernier_releve_kwh']);
        $this->assertEqualsWithDelta(119.33, $t['credit']['estime_kwh'], 0.01);
        $this->assertFalse($t['credit']['faible']);
        $this->assertEqualsWithDelta(8.0, $t['autonomie']['moyenne_kwh_jour'], 0.001);
        $this->assertSame(14, $t['autonomie']['jours_estimes']);
        $this->assertTrue($t['autonomie']['fiable']);

        // 9 jours complets (du 3 au 11), le 2 et le 12 sont partiels.
        $complets = array_filter($t['consommation']['jours'], fn ($j) => $j['complet']);
        $this->assertCount(9, $complets);
        $this->assertCount(11, $t['consommation']['jours']);
        $this->assertEqualsWithDelta(8.0, $t['consommation']['moyenne_kwh_jour'], 0.001);
        $this->assertSame('releves', $t['consommation']['jours'][0]['source']);

        $this->getJson("/api/compteurs/{$compteur->id}/consommations?du=2026-11-05&au=2026-11-07")->assertOk()
            ->assertJsonCount(3, 'jours')->assertJsonPath('total_kwh', 24);

        // Une recharge tapée mais pas encore confirmée s'ajoute au crédit estimé.
        $r = $this->recharge($compteur);
        $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->assertJsonPath('recharges.en_attente', 1)
            ->assertJsonPath('recharges.mois_fcfa', 10000);
        $this->postJson("/api/recharges/{$r['id']}/saisi")->assertOk();
        $t = $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->json();
        $this->assertEqualsWithDelta(217.83, $t['credit']['estime_kwh'], 0.01);
        $this->assertSame(0, $t['recharges']['en_attente']);
    }

    // ------------------------------------------------------------------ alertes

    public function test_alertes_quotidiennes_et_anti_repetition(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $compteur = $this->compteurDe($user);

        // 8 kWh/jour, il reste 18 kWh ce matin : sous le seuil de 20 kWh.
        for ($j = 9; $j >= 0; $j--) {
            Releve::create([
                'compteur_id' => $compteur->id, 'type' => 'credit', 'valeur_kwh' => 18 + 8 * $j, 'source' => 'manuel',
                'releve_le' => Carbon::parse('2026-11-12 08:00')->subDays($j),
            ]);
        }

        // Un code enregistré il y a 5 jours et jamais tapé.
        Carbon::setTestNow('2026-11-07 10:00:00');
        $this->recharge($compteur);
        Carbon::setTestNow('2026-11-12 10:00:00');

        $this->artisan('energie:quotidien', ['--sync' => true])->assertSuccessful();

        $this->assertDatabaseHas('alertes', ['compteur_id' => $compteur->id, 'type' => 'credit_faible', 'niveau' => 'critique']);
        $this->assertDatabaseHas('alertes', ['compteur_id' => $compteur->id, 'type' => 'code_en_attente']);
        Notification::assertSentToTimes($user, AlerteEnergieNotification::class, 2);

        // Relancé dans la journée : pas de doublon.
        $this->artisan('energie:quotidien', ['compteur' => $compteur->id, '--sync' => true])->assertSuccessful();
        $this->assertSame(2, Alerte::count());

        $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->assertJsonPath('alertes_non_lues', 2)
            ->assertJsonPath('credit.faible', true);
        $id = $this->getJson("/api/compteurs/{$compteur->id}/alertes")->assertOk()->json('data.0.id');
        $this->postJson("/api/alertes/$id/lue")->assertOk();
        $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->assertJsonPath('alertes_non_lues', 1);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/alertes/$id/lue")->assertForbidden();
    }

    // ------------------------------------------------------------------ IoT

    public function test_capteur_iot_mesures_consommation_et_capteur_muet(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $compteur = $this->compteurDe($user);

        $jeton = $this->postJson("/api/compteurs/{$compteur->id}/capteur", ['identifiant' => 'ESP32-A1B2'])
            ->assertCreated()->assertJsonMissingPath('capteur.jeton_hash')->json('jeton');

        // Une mesure par heure pendant 3 jours, 0,5 kWh/h = 800 impulsions/h.
        $debut = Carbon::parse('2026-11-08 00:00:00');
        $mesures = [];
        for ($h = 0; $h <= 72; $h++) {
            $mesures[] = ['mesure_le' => $debut->copy()->addHours($h)->getTimestamp(), 'impulsions_cumul' => 10000 + 800 * $h, 'puissance_w' => 500];
        }
        $corps = ['identifiant' => 'ESP32-A1B2', 'firmware' => '1.0.3', 'mesures' => $mesures];

        $this->postJson('/api/iot/mesures', $corps)->assertUnauthorized();
        $this->withToken('mauvais-jeton')->postJson('/api/iot/mesures', $corps)->assertUnauthorized();
        $this->withToken($jeton)->postJson('/api/iot/mesures', $corps)->assertOk()->assertJsonPath('inserees', 73);
        // Rejoué : aucun doublon. Horloge non synchronisée : ignorée.
        $this->withToken($jeton)->postJson('/api/iot/mesures', $corps)->assertOk()->assertJsonPath('inserees', 0);
        $this->withToken($jeton)->postJson('/api/iot/mesures', ['identifiant' => 'ESP32-A1B2', 'mesures' => [['mesure_le' => 120, 'impulsions_cumul' => 5]]])
            ->assertOk()->assertJsonPath('ignorees', 1);
        $this->assertSame(73, DB::table('mesures_iot')->count());

        $this->artisan('energie:quotidien', ['--sync' => true])->assertSuccessful();

        Sanctum::actingAs($user);
        $jours = $this->getJson("/api/compteurs/{$compteur->id}/consommations?du=2026-11-08&au=2026-11-10")->assertOk()->json('jours');
        $this->assertCount(3, $jours);
        foreach ($jours as $j) {
            $this->assertSame('iot', $j['source']);
            $this->assertTrue($j['complet']);
            $this->assertEqualsWithDelta(12.0, $j['kwh'], 0.001);
        }

        // Silencieux depuis plus de 6 h → alerte « capteur muet ».
        $this->artisan('energie:capteurs')->assertSuccessful();
        $this->assertDatabaseMissing('alertes', ['type' => 'capteur_muet']);
        Carbon::setTestNow('2026-11-12 17:00:00');
        $this->artisan('energie:capteurs')->assertSuccessful();
        $this->assertDatabaseHas('alertes', ['compteur_id' => $compteur->id, 'type' => 'capteur_muet']);

        $this->deleteJson("/api/compteurs/{$compteur->id}/capteur")->assertNoContent();
        $this->withToken($jeton)->postJson('/api/iot/mesures', $corps)->assertUnauthorized();
    }

    public function test_reglages_suppression_et_messages_en_francais(): void
    {
        $user = User::factory()->create();
        $compteur = $this->compteurDe($user);
        $this->recharge($compteur);

        $this->putJson("/api/compteurs/{$compteur->id}", ['libelle' => 'Cuisine', 'seuil_credit_faible_kwh' => 35])
            ->assertOk()->assertJsonPath('libelle', 'Cuisine')->assertJsonPath('seuil_credit_faible_kwh', '35.00');

        $this->putJson("/api/compteurs/{$compteur->id}", ['libelle' => ''])
            ->assertStatus(422)->assertJsonPath('errors.libelle.0', 'Le champ nom du compteur est obligatoire.');
        $this->postJson("/api/compteurs/{$compteur->id}/releves", ['type' => 'credit', 'valeur_kwh' => 10, 'releve_le' => '2027-01-01 08:00'])
            ->assertStatus(422)->assertJsonPath('errors.releve_le.0', 'La date du relevé ne peut pas être dans le futur.');

        // Suppression douce : le compteur disparaît, l'historique reste en base.
        $this->deleteJson("/api/compteurs/{$compteur->id}")->assertNoContent();
        $this->getJson('/api/compteurs')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/compteurs/{$compteur->id}/tableau-de-bord")->assertNotFound();
        $this->assertSame(1, Recharge::count());
    }

    public function test_messages_d_erreur_generiques_en_francais(): void
    {
        $this->getJson('/api/compteurs')->assertUnauthorized()->assertJsonPath('message', 'Vous devez vous connecter.');

        $compteur = $this->compteurDe(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/compteurs/{$compteur->id}")->assertForbidden()
            ->assertJsonPath('message', 'Vous n\'avez pas le droit de faire cette action.');
        $this->getJson('/api/compteurs/999999')->assertNotFound()->assertJsonPath('message', 'Élément introuvable.');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/connexion', ['email' => 'x@example.com', 'password' => 'faux']);
        }
        $this->postJson('/api/connexion', ['email' => 'x@example.com', 'password' => 'faux'])
            ->assertStatus(429)->assertJsonPath('message', 'Trop de tentatives. Patientez une minute avant de réessayer.');
    }

    public function test_page_d_accueil(): void
    {
        $this->get('/')->assertOk()->assertSee('Suivi énergie');
    }
}
