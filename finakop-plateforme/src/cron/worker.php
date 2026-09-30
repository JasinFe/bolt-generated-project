<?php
/**
 * FinaKop Plateforme — tâche cron UNIQUE, toutes les 5 minutes :
 *
 *     php /home/<compte>/finakop/current/cron/worker.php
 *
 * Parcourt les clients actifs (un sous-processus par client) : file différée,
 * temporisation comptable, sessions expirées, sauvegarde quotidienne.
 * Journal : <données>/plateforme/logs/cron.log
 */
if ( 'cli' !== PHP_SAPI ) { http_response_code( 404 ); exit; }
require dirname( __DIR__ ) . '/app/Plateforme/Amorcage.php';
require dirname( __DIR__ ) . '/app/Plateforme/Console.php';
$argv = array( 'finakop', 'cron', '--quiet' );
exit( (int) FKC_Plateforme_Console::executer( $argv ) );
