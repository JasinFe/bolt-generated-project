<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as Courtier;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // POST /inscription
    public function inscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($data);   // mot de passe haché par le cast du modèle

        return response()->json($this->session($user, $request), 201);
    }

    // POST /connexion
    public function connexion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Adresse e-mail ou mot de passe incorrect.']);
        }

        return response()->json($this->session($user, $request));
    }

    // POST /mot-de-passe/oublie — même réponse que le compte existe ou non
    public function motDePasseOublie(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        try {
            Courtier::sendResetLink($data);
        } catch (\Throwable $e) {
            // Serveur d'e-mail injoignable ou réglages MAIL_* erronés : erreur claire plutôt qu'une page 500.
            report($e);

            return response()->json([
                'message' => 'L\'e-mail n\'a pas pu être envoyé pour le moment. Réessayez plus tard.',
            ], 503);
        }

        return response()->json([
            'message' => 'Si un compte existe avec cette adresse, un e-mail contenant un lien de réinitialisation vient d\'être envoyé.',
        ]);
    }

    // POST /mot-de-passe/reinitialiser
    public function reinitialiser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $statut = Courtier::reset($data, function (User $user, string $motDePasse) {
            $user->forceFill(['password' => $motDePasse])->save();   // haché par le cast du modèle
            $user->tokens()->delete();                                // déconnecte tous les appareils
        });

        if ($statut !== Courtier::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Ce lien est invalide ou a expiré. Refaites une demande.']);
        }

        return response()->json(['message' => 'Mot de passe modifié. Vous pouvez vous connecter.']);
    }

    // POST /deconnexion
    public function deconnexion(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    // GET /moi
    public function moi(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    private function session(User $user, Request $request): array
    {
        $appareil = substr((string) ($request->input('appareil') ?: $request->userAgent() ?: 'inconnu'), 0, 100);

        return ['user' => $user, 'token' => $user->createToken($appareil)->plainTextToken];
    }
}
