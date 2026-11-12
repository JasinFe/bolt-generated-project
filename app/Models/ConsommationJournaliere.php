<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsommationJournaliere extends Model
{
    protected $table = 'consommations_journalieres';

    protected $fillable = ['compteur_id', 'jour', 'kwh', 'source', 'complet'];

    protected function casts(): array
    {
        return ['jour' => 'date', 'kwh' => 'decimal:3', 'complet' => 'boolean'];
    }
}
