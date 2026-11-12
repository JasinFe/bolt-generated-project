<?php

namespace App\Http\Controllers;

use App\Models\Capteur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class IotController extends Controller
{
    /**
     * POST /iot/mesures — appelé par l'ESP32 (ou par la passerelle MQTT → HTTP).
     * En-tête : Authorization: Bearer <jeton du capteur>
     * Corps   : { "identifiant": "ESP32-A1B2", "mesures": [ { "mesure_le": 1794480000, "impulsions_cumul": 123456, "puissance_w": 420 } ] }
     */
    public function mesures(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifiant' => ['required', 'string', 'max:40'],
            'firmware' => ['nullable', 'string', 'max:20'],
            'mesures' => ['required', 'array', 'min:1', 'max:500'],
            'mesures.*.mesure_le' => ['required'],                       // timestamp Unix ou date ISO 8601
            'mesures.*.impulsions_cumul' => ['required', 'integer', 'min:0'],
            'mesures.*.puissance_w' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $capteur = Capteur::where('identifiant', $data['identifiant'])->where('actif', true)->first();
        $jeton = (string) $request->bearerToken();

        // Même réponse que le capteur existe ou non.
        if (! $capteur || $jeton === '' || ! Hash::check($jeton, $capteur->jeton_hash)) {
            return response()->json(['message' => 'Capteur inconnu ou jeton invalide.'], 401);
        }

        $limite = now()->addMinutes(10);   // horloge de l'ESP32 légèrement en avance tolérée
        $lignes = [];
        $ignorees = 0;

        foreach ($data['mesures'] as $m) {
            try {
                $le = is_numeric($m['mesure_le'])
                    ? Carbon::createFromTimestampUTC((int) $m['mesure_le'])
                    : Carbon::parse((string) $m['mesure_le'])->utc();
            } catch (\Throwable) {
                $le = null;
            }

            // Horloge non synchronisée (1970) ou date dans le futur : inutilisable.
            if (! $le || $le->year < 2020 || $le->gt($limite)) {
                $ignorees++;

                continue;
            }

            $lignes[$le->getTimestamp()] = [
                'capteur_id' => $capteur->id,
                'mesure_le' => $le->format('Y-m-d H:i:s'),
                'impulsions_cumul' => (int) $m['impulsions_cumul'],
                'puissance_w' => $m['puissance_w'] ?? null,
                'recu_le' => now()->format('Y-m-d H:i:s'),
            ];
        }

        // Rejouer un envoi ne crée pas de doublon (index unique capteur + horodatage).
        $inserees = $lignes ? DB::table('mesures_iot')->insertOrIgnore(array_values($lignes)) : 0;

        $capteur->update(array_filter([
            'derniere_activite' => now(),
            'firmware' => $data['firmware'] ?? null,
        ]));

        return response()->json(['recues' => count($data['mesures']), 'inserees' => $inserees, 'ignorees' => $ignorees]);
    }
}
