<?php

namespace App\Services;

use App\Enums\RechargeStatut;
use App\Models\Alerte;
use App\Models\Compteur;
use App\Notifications\AlerteEnergieNotification;
use App\Support\ReglesAlertes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class AlerteService
{
    public function __construct(private readonly AutonomieService $autonomie) {}

    /** Vérifications quotidiennes, à lancer APRÈS le recalcul des consommations. */
    public function verifierQuotidien(Compteur $compteur): void
    {
        $this->verifierCredit($compteur);
        $this->verifierSurconsommation($compteur);
        $this->verifierCodesEnAttente($compteur);
    }

    /** Crédit faible (estimé maintenant) et épuisement prévu dans 3 jours ou moins. */
    public function verifierCredit(Compteur $compteur): void
    {
        $situation = $this->autonomie->situation($compteur);
        if (! $situation || ! $situation['autonomie']) {
            return;
        }

        $creditMaintenant = $situation['credit_estime_kwh'];
        $jours = $situation['autonomie']['jours_estimes'];
        $donnees = ['credit_estime_kwh' => $creditMaintenant, 'jours_restants' => $jours];

        if ($creditMaintenant <= (float) $compteur->seuil_credit_faible_kwh) {
            $this->signaler($compteur, 'credit_faible', 'critique',
                sprintf('%s : crédit estimé à %s kWh, pensez à recharger.', $compteur->libelle, $this->nombre($creditMaintenant)),
                $donnees);
        } elseif ($jours <= 3) {
            $this->signaler($compteur, 'epuisement_prevu', 'attention',
                sprintf('%s : crédit épuisé dans environ %d jour(s) au rythme actuel.', $compteur->libelle, $jours),
                $donnees);
        }
    }

    /** Uniquement sur des jours mesurés par IoT : les relevés manuels lissent tout. */
    public function verifierSurconsommation(Compteur $compteur): void
    {
        $hier = Carbon::now(config('energie.fuseau', 'Africa/Abidjan'))->subDay()->toDateString();

        $historique = $compteur->consommationsJournalieres()
            ->where('source', 'iot')->where('complet', true)
            ->whereDate('jour', '>=', Carbon::parse($hier)->subDays(28)->toDateString())
            ->orderByDesc('jour')
            ->get(['jour', 'kwh']);

        $jour = $historique->first(fn ($c) => $c->jour->toDateString() === $hier);
        if (! $jour) {
            return;
        }

        $avant = $historique->filter(fn ($c) => $c->jour->toDateString() < $hier);
        $resultat = ReglesAlertes::surconsommation(
            (float) $jour->kwh,
            $avant->filter(fn ($c) => $c->jour->dayOfWeek === $jour->jour->dayOfWeek)->pluck('kwh')->map(fn ($v) => (float) $v)->all(),
            $avant->take(7)->pluck('kwh')->map(fn ($v) => (float) $v)->all(),
            (int) config('energie.seuil_surconsommation_pct', 30),
        );

        if ($resultat) {
            $this->signaler($compteur, 'surconsommation', 'attention',
                sprintf('%s : %s kWh consommés hier, +%d %% par rapport à d\'habitude (%s kWh).',
                    $compteur->libelle, $this->nombre((float) $jour->kwh), $resultat['ecart_pct'], $this->nombre($resultat['reference'])),
                ['jour' => $hier, 'kwh' => (float) $jour->kwh] + $resultat);
        }
    }

    /** Codes enregistrés mais pas tapés sur le compteur depuis plusieurs jours. */
    public function verifierCodesEnAttente(Compteur $compteur): void
    {
        $limite = now()->subDays((int) config('energie.alerte_code_en_attente_jours', 3));

        $nombre = $compteur->recharges()
            ->where('statut', RechargeStatut::EnAttente->value)
            ->where('created_at', '<', $limite)
            ->count();

        if ($nombre > 0) {
            $this->signaler($compteur, 'code_en_attente', 'attention',
                sprintf('%s : %d code(s) de recharge pas encore saisi(s) sur le compteur.', $compteur->libelle, $nombre),
                ['nombre' => $nombre]);
        }
    }

    /** Capteur IoT silencieux : coupure, crédit épuisé, Wi-Fi ou panne du boîtier. */
    public function verifierCapteur(Compteur $compteur): void
    {
        $capteur = $compteur->capteur;
        $heures = (int) config('energie.capteur_muet_heures', 6);

        if ($capteur?->actif && $capteur->derniere_activite && $capteur->derniere_activite->lt(now()->subHours($heures))) {
            $this->signaler($compteur, 'capteur_muet', 'info',
                sprintf('%s : aucune mesure reçue depuis %s. Coupure, crédit épuisé ou capteur hors ligne ?',
                    $compteur->libelle, $capteur->derniere_activite->diffForHumans()),
                ['derniere_activite' => $capteur->derniere_activite->toIso8601String()]);
        }
    }

    /** Crée l'alerte et notifie, sauf si la même alerte existe déjà depuis moins de 24 h. */
    private function signaler(Compteur $compteur, string $type, string $niveau, string $message, array $donnees = []): void
    {
        $recente = Alerte::where('compteur_id', $compteur->id)
            ->where('type', $type)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($recente) {
            return;
        }

        $alerte = Alerte::create([
            'compteur_id' => $compteur->id,
            'type' => $type,
            'niveau' => $niveau,
            'message' => $message,
            'donnees' => $donnees,
        ]);

        try {
            Notification::send($compteur->utilisateurs, new AlerteEnergieNotification($alerte));
        } catch (\Throwable $e) {
            report($e);   // l'alerte reste visible dans l'application même si l'e-mail ne part pas
        }
    }

    private function nombre(float $v): string
    {
        return number_format($v, 1, ',', ' ');
    }
}
