<?php

namespace App\Http\Controllers;

use App\Enums\RoleCompteur;
use App\Models\Capteur;
use App\Models\Compteur;
use App\Models\Site;
use App\Models\User;
use App\Rules\NumeroCompteurSts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompteurController extends Controller
{
    // GET /compteurs — tous les compteurs auxquels l'utilisateur a accès
    public function index(Request $request): JsonResponse
    {
        $compteurs = $request->user()->compteurs()->with('site:id,nom,ville')->orderBy('libelle')->get()
            ->map(fn (Compteur $c) => $this->presenter($c, $c->pivot->role));

        return response()->json($compteurs);
    }

    // POST /sites/{site}/compteurs
    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorize('update', $site);
        $request->merge(['numero_sts' => preg_replace('/\D+/', '', (string) $request->input('numero_sts'))]);

        $data = $request->validate([
            'libelle' => ['required', 'string', 'max:80'],
            'numero_sts' => ['required', new NumeroCompteurSts, Rule::unique('compteurs', 'numero_sts')],
            ...$this->reglesCommunes(),
        ], ['numero_sts.unique' => 'Ce compteur est déjà enregistré. Demandez à son propriétaire de le partager avec vous.']);

        $compteur = DB::transaction(function () use ($site, $data, $request) {
            $compteur = $site->compteurs()->create($data);
            $compteur->utilisateurs()->attach($request->user()->id, ['role' => RoleCompteur::Proprietaire->value]);

            return $compteur;
        });

        return response()->json($this->presenter($compteur->fresh('site:id,nom,ville'), RoleCompteur::Proprietaire->value), 201);
    }

    // GET /compteurs/{compteur}
    public function show(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('view', $compteur);
        $compteur->load('site:id,nom,ville', 'capteur');

        return response()->json($this->presenter($compteur, $compteur->roleDe($request->user())->value));
    }

    // PUT /compteurs/{compteur} — le numéro STS ne se modifie pas (il identifie le compteur)
    public function update(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('update', $compteur);

        $compteur->update($request->validate([
            'libelle' => ['sometimes', 'required', 'string', 'max:80'],
            'actif' => ['sometimes', 'boolean'],
            ...$this->reglesCommunes(),
        ]));

        return response()->json($this->presenter($compteur->load('site:id,nom,ville'), RoleCompteur::Proprietaire->value));
    }

    // DELETE /compteurs/{compteur} — suppression douce : l'historique des recharges est conservé
    public function destroy(Compteur $compteur): JsonResponse
    {
        $this->authorize('delete', $compteur);
        $compteur->delete();

        return response()->json(null, 204);
    }

    // GET /compteurs/{compteur}/partages
    public function partages(Compteur $compteur): JsonResponse
    {
        $this->authorize('view', $compteur);

        return response()->json($compteur->utilisateurs()->orderBy('name')->get()->map(fn (User $u) => [
            'user_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role,
        ]));
    }

    // POST /compteurs/{compteur}/partages — donner accès à un utilisateur déjà inscrit
    public function partager(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('partager', $compteur);

        $data = $request->validate([
            'email' => ['required', 'email', Rule::exists('users', 'email')],
            'role' => ['required', Rule::in([RoleCompteur::Gestionnaire->value, RoleCompteur::Occupant->value])],
        ], ['email.exists' => 'Aucun compte avec cette adresse : la personne doit d\'abord s\'inscrire.']);

        $invite = User::where('email', $data['email'])->firstOrFail();
        if ($invite->id === $request->user()->id) {
            return response()->json(['message' => 'Vous êtes déjà propriétaire de ce compteur.'], 422);
        }

        $compteur->utilisateurs()->syncWithoutDetaching([$invite->id => ['role' => $data['role']]]);

        return $this->partages($compteur)->setStatusCode(201);
    }

    // DELETE /compteurs/{compteur}/partages/{user}
    public function retirerPartage(Request $request, Compteur $compteur, User $user): JsonResponse
    {
        $this->authorize('partager', $compteur);

        if ($compteur->roleDe($user) === RoleCompteur::Proprietaire) {
            return response()->json(['message' => 'Le propriétaire ne peut pas être retiré.'], 422);
        }

        $compteur->utilisateurs()->detach($user->id);

        return response()->json(null, 204);
    }

    // POST /compteurs/{compteur}/capteur — associe un capteur IoT ; le jeton n'est affiché qu'une fois
    public function associerCapteur(Request $request, Compteur $compteur): JsonResponse
    {
        $this->authorize('update', $compteur);

        $data = $request->validate([
            'identifiant' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9:_\-]+$/',
                Rule::unique('capteurs', 'identifiant')->ignore($compteur->capteur?->id)],
            'firmware' => ['nullable', 'string', 'max:20'],
        ]);

        $jeton = Str::random(48);
        $capteur = Capteur::updateOrCreate(
            ['compteur_id' => $compteur->id],
            $data + ['jeton_hash' => Hash::make($jeton), 'actif' => true]
        );

        return response()->json(['capteur' => $capteur, 'jeton' => $jeton], 201);
    }

    // DELETE /compteurs/{compteur}/capteur
    public function dissocierCapteur(Compteur $compteur): JsonResponse
    {
        $this->authorize('update', $compteur);
        $compteur->capteur?->delete();

        return response()->json(null, 204);
    }

    private function reglesCommunes(): array
    {
        return [
            'numero_serie' => ['nullable', 'string', 'max:30'],
            'marque' => ['nullable', 'string', 'max:40'],
            'modele' => ['nullable', 'string', 'max:40'],
            'impulsions_par_kwh' => ['sometimes', 'integer', 'min:100', 'max:20000'],
            'seuil_credit_faible_kwh' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
        ];
    }

    private function presenter(Compteur $compteur, string $role): array
    {
        return array_merge($compteur->makeHidden('pivot')->toArray(), ['role' => $role]);
    }
}
