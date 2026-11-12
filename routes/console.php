<?php

use Illuminate\Support\Facades\Schedule;

// Nécessite la tâche cron système : * * * * * php /chemin/artisan schedule:run
// et un worker de file d'attente : php artisan queue:work (Supervisor).

Schedule::command('energie:quotidien')
    ->dailyAt('01:15')
    ->timezone('Africa/Abidjan')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('energie:capteurs')
    ->hourly()
    ->withoutOverlapping();
