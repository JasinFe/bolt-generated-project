<?php

namespace App\Models;

use App\Enums\RoleCompteur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Compteur extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'site_id', 'libelle', 'numero_sts', 'numero_serie', 'marque', 'modele',
        'impulsions_par_kwh', 'seuil_credit_faible_kwh', 'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'impulsions_par_kwh' => 'integer',
            'seuil_credit_faible_kwh' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function recharges(): HasMany
    {
        return $this->hasMany(Recharge::class);
    }

    public function releves(): HasMany
    {
        return $this->hasMany(Releve::class);
    }

    public function capteur(): HasOne
    {
        return $this->hasOne(Capteur::class);
    }

    public function consommationsJournalieres(): HasMany
    {
        return $this->hasMany(ConsommationJournaliere::class);
    }

    public function roleDe(User $user): ?RoleCompteur
    {
        $pivot = $this->utilisateurs()->whereKey($user->id)->first()?->pivot;

        return $pivot ? RoleCompteur::from($pivot->role) : null;
    }

    public function dernierCredit(): ?Releve
    {
        return $this->releves()->where('type', 'credit')->latest('releve_le')->latest('id')->first();
    }
}
