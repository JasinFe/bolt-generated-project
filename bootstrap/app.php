<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Messages d'erreur génériques en français (l'interface les affiche tels quels).
        $exceptions->respond(function (Response $reponse, Throwable $e, Request $request) {
            $messages = [
                401 => 'Vous devez vous connecter.',
                403 => 'Vous n\'avez pas le droit de faire cette action.',
                404 => 'Élément introuvable.',
                405 => 'Action non autorisée sur cette adresse.',
                413 => 'Les données envoyées sont trop volumineuses.',
                429 => 'Trop de tentatives. Patientez une minute avant de réessayer.',
                500 => 'Erreur du serveur. Réessayez plus tard.',
                503 => 'Service momentanément indisponible. Réessayez plus tard.',
            ];

            if ($reponse instanceof JsonResponse && isset($messages[$reponse->getStatusCode()])) {
                $donnees = $reponse->getData(true);
                $donnees['message'] = $messages[$reponse->getStatusCode()];
                $reponse->setData($donnees);
            }

            return $reponse;
        });
    })->create();
