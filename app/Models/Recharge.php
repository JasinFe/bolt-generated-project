<?php

namespace App\Models;

use App\Enums\RechargeStatut;
use App\Exceptions\TransitionRechargeInterdite;
use App\Support\CodeRecharge;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * @property RechargeStatut $statut
 * @property-read string $code_masque
 */
class Recharge extends Model
{
    protected $fillable = [
        'compteur_id', 'montant_fcfa', 'credit_kwh', 'date_achat',
        'mode_paiement', 'reference_paiement', 'observation',
    ];

    // Le code (même chiffré) ne sort jamais dans une réponse JSON.
    protected $hidden = ['code_chiffre', 'code_empreinte'];

    protected $appends = ['code_masque'];

    protected function casts(): array
    {
        return [
            'statut' => RechargeStatut::class,
            'montant_fcfa' => 'integer',
            'credit_kwh' => 'decimal:2',
            'credit_avant_kwh' => 'decimal:2',
            'credit_apres_kwh' => 'decimal:2',
            'date_achat' => 'datetime',
            'date_saisie' => 'datetime',
            'date_confirmation' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Numéro lisible RCH-2026-000123, attribué une fois l'id connu.
        static::created(function (Recharge $r) {
            $r->numero = sprintf('RCH-%s-%06d', $r->created_at->format('Y'), $r->id);
            $r->saveQuietly();
        });
    }

    // ---------------------------------------------------------------- relations

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enregistre_par');
    }

    public function accesCode(): HasMany
    {
        return $this->hasMany(JournalAccesCode::class);
    }

    // ---------------------------------------------------------------- code

    /** Remplit les trois colonnes à partir du code saisi. */
    public function definirCode(CodeRecharge $code): void
    {
        $this->code_chiffre = Crypt::encryptString($code->valeur());
        $this->code_empreinte = $code->empreinte((string) config('energie.code_pepper'));
        $this->code_fin = $code->fin();
    }

    /** Affiche le code en clair ET journalise l'accès. Vérifier la policy avant d'appeler. */
    public function revelerCode(User $user, string $action = 'affichage'): CodeRecharge
    {
        $this->accesCode()->create([
            'user_id' => $user->id,
            'action' => $action,
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255),
        ]);

        return CodeRecharge::depuisSaisie(Crypt::decryptString($this->code_chiffre));
    }

    protected function codeMasque(): Attribute
    {
        return Attribute::get(fn () => CodeRecharge::masquer((string) $this->code_fin));
    }

    // ---------------------------------------------------------------- cycle de vie

    public function marquerSaisi(?float $creditAvantKwh = null): void
    {
        $this->passerA(RechargeStatut::Saisi);
        $this->date_saisie = now();
        $this->credit_avant_kwh = $creditAvantKwh;
        $this->message_compteur = null;
        $this->save();
    }

    /**
     * Confirme avec le crédit lu après saisie. Crée aussi un relevé « confirmation »,
     * qui recale le suivi de consommation.
     */
    public function confirmer(float $creditApresKwh, User $user): void
    {
        DB::transaction(function () use ($creditApresKwh, $user) {
            $this->passerA(RechargeStatut::Confirme);
            $this->credit_apres_kwh = $creditApresKwh;
            $this->date_confirmation = now();
            $this->save();

            Releve::create([
                'compteur_id' => $this->compteur_id,
                'user_id' => $user->id,
                'type' => 'credit',
                'valeur_kwh' => $creditApresKwh,
                'source' => 'confirmation',
                'recharge_id' => $this->id,
                'releve_le' => now(),
            ]);
        });
    }

    public function rejeter(string $messageCompteur): void
    {
        $this->passerA(RechargeStatut::Rejete);
        $this->message_compteur = substr($messageCompteur, 0, 120);
        $this->save();
    }

    /**
     * Écart entre le crédit attendu et le crédit constaté (kWh), si calculable.
     * Positif = moins de crédit que prévu.
     */
    public function ecartCreditKwh(): ?float
    {
        if ($this->credit_kwh === null || $this->credit_avant_kwh === null || $this->credit_apres_kwh === null) {
            return null;
        }

        $attendu = (float) $this->credit_avant_kwh + (float) $this->credit_kwh;

        return round($attendu - (float) $this->credit_apres_kwh, 2);
    }

    private function passerA(RechargeStatut $cible): void
    {
        if (! $this->statut->peutPasserA($cible)) {
            throw new TransitionRechargeInterdite($this->statut, $cible);
        }
        $this->statut = $cible;
    }
}
