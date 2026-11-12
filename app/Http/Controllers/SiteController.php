<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteController extends Controller
{
    // GET /sites
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->sites()->withCount('compteurs')->orderBy('nom')->get());
    }

    // POST /sites
    public function store(Request $request): JsonResponse
    {
        $site = $request->user()->sites()->create($this->valider($request));

        return response()->json($site, 201);
    }

    // PUT /sites/{site}
    public function update(Request $request, Site $site): JsonResponse
    {
        $this->authorize('update', $site);
        $site->update($this->valider($request));

        return response()->json($site);
    }

    // DELETE /sites/{site}
    public function destroy(Site $site): JsonResponse
    {
        $this->authorize('delete', $site);

        if ($site->compteurs()->exists()) {
            return response()->json(['message' => 'Supprimez d\'abord les compteurs de ce site.'], 409);
        }

        $site->delete();

        return response()->json(null, 204);
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(['habitation', 'immeuble', 'bureau', 'commerce'])],
            'adresse' => ['nullable', 'string', 'max:255'],
            'ville' => ['nullable', 'string', 'max:80'],
        ]);
    }
}
