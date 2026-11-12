<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
