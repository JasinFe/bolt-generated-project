<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alerte extends Model
{
    protected $fillable = ['compteur_id', 'type', 'niveau', 'message', 'donnees', 'lue_le'];

    protected function casts(): array
    {
        return ['donnees' => 'array', 'lue_le' => 'datetime'];
    }
}
