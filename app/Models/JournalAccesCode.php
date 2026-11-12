<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalAccesCode extends Model
{
    protected $table = 'journal_acces_codes';

    public const UPDATED_AT = null;

    protected $fillable = ['recharge_id', 'user_id', 'action', 'ip', 'user_agent'];
}
