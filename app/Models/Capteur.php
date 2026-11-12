<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Capteur extends Model
{
    protected $fillable = ['compteur_id', 'identifiant', 'jeton_hash', 'firmware', 'derniere_activite', 'actif'];

    protected $hidden = ['jeton_hash'];

    protected function casts(): array
    {
        return ['derniere_activite' => 'datetime', 'actif' => 'boolean'];
    }

    public function compteur(): BelongsTo
    {
        return $this->belongsTo(Compteur::class);
    }
}
