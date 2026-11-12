<?php

use Illuminate\Support\Facades\Schedule;

// Une seule tâche cron suffit, y compris sur un hébergement mutualisé :
//   * * * * * php /chemin/vers/artisan schedule:run
// Aucun worker permanent (Supervisor) n'est nécessaire : la file d'attente est vidée chaque minute ci-dessous.

Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('energie:quotidien')
    ->dailyAt('01:15')
    ->timezone('Africa/Abidjan')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('energie:capteurs')
    ->hourly()
    ->withoutOverlapping();
