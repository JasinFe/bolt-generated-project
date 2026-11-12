<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompteurController;
use App\Http\Controllers\IotController;
use App\Http\Controllers\RechargeController;
use App\Http\Controllers\ReleveController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TableauDeBordController;
use Illuminate\Support\Facades\Route;

// --- Comptes -------------------------------------------------------------------
Route::post('inscription', [AuthController::class, 'inscription'])->middleware('throttle:10,1');
Route::post('connexion', [AuthController::class, 'connexion'])->middleware('throttle:10,1');

// --- Capteurs IoT (authentifiés par leur propre jeton, pas par un compte) --------
Route::post('iot/mesures', [IotController::class, 'mesures'])->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('moi', [AuthController::class, 'moi']);
    Route::post('deconnexion', [AuthController::class, 'deconnexion']);

    // --- Sites et compteurs ------------------------------------------------------
    Route::get('sites', [SiteController::class, 'index']);
    Route::post('sites', [SiteController::class, 'store']);
    Route::put('sites/{site}', [SiteController::class, 'update']);
    Route::delete('sites/{site}', [SiteController::class, 'destroy']);
    Route::post('sites/{site}/compteurs', [CompteurController::class, 'store']);

    Route::get('compteurs', [CompteurController::class, 'index']);
    Route::get('compteurs/{compteur}', [CompteurController::class, 'show']);
    Route::put('compteurs/{compteur}', [CompteurController::class, 'update']);
    Route::delete('compteurs/{compteur}', [CompteurController::class, 'destroy']);

    Route::get('compteurs/{compteur}/partages', [CompteurController::class, 'partages']);
    Route::post('compteurs/{compteur}/partages', [CompteurController::class, 'partager']);
    Route::delete('compteurs/{compteur}/partages/{user}', [CompteurController::class, 'retirerPartage']);

    Route::post('compteurs/{compteur}/capteur', [CompteurController::class, 'associerCapteur']);
    Route::delete('compteurs/{compteur}/capteur', [CompteurController::class, 'dissocierCapteur']);

    // --- Tableau de bord ---------------------------------------------------------
    Route::get('compteurs/{compteur}/tableau-de-bord', [TableauDeBordController::class, 'show']);
    Route::get('compteurs/{compteur}/consommations', [TableauDeBordController::class, 'consommations']);
    Route::get('compteurs/{compteur}/alertes', [TableauDeBordController::class, 'alertes']);
    Route::post('alertes/{alerte}/lue', [TableauDeBordController::class, 'marquerLue']);

    // --- Relevés -----------------------------------------------------------------
    Route::get('compteurs/{compteur}/releves', [ReleveController::class, 'index']);
    Route::post('compteurs/{compteur}/releves', [ReleveController::class, 'store']);
    Route::delete('releves/{releve}', [ReleveController::class, 'destroy']);

    // --- Recharges ---------------------------------------------------------------
    Route::get('compteurs/{compteur}/recharges', [RechargeController::class, 'index']);
    Route::post('compteurs/{compteur}/recharges', [RechargeController::class, 'store'])
        ->middleware('throttle:20,1');

    // Afficher un code en clair : limité et journalisé.
    Route::post('recharges/{recharge}/reveler', [RechargeController::class, 'reveler'])
        ->middleware('throttle:10,1');

    Route::post('recharges/{recharge}/saisi', [RechargeController::class, 'saisi']);
    Route::post('recharges/{recharge}/confirmer', [RechargeController::class, 'confirmer']);
    Route::post('recharges/{recharge}/rejeter', [RechargeController::class, 'rejeter']);
});
