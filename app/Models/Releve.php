<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Releve extends Model
{
    protected $fillable = [
        'compteur_id', 'user_id', 'type', 'valeur_kwh', 'source', 'recharge_id', 'releve_le',
    ];

    protected function casts(): array
    {
        return [
            'valeur_kwh' => 'decimal:2',
            'releve_le' => 'datetime',
        ];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }
}
