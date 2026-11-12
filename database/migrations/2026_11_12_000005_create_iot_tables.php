<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ESP32 + photodiode posée sur le voyant 1600 imp/kWh (aucune ouverture du compteur).
        Schema::create('capteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('identifiant', 40)->unique();          // ex. adresse MAC ou ID ESP32
            $table->string('jeton_hash', 255);                    // Hash::make() du secret MQTT du capteur
            $table->string('firmware', 20)->nullable();
            $table->timestamp('derniere_activite')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Mesures brutes envoyées toutes les 1 à 5 minutes.
        Schema::create('mesures_iot', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capteur_id')->constrained()->cascadeOnDelete();
            $table->timestamp('mesure_le');                       // horodatage fourni par l'ESP32
            $table->unsignedBigInteger('impulsions_cumul');       // compteur conservé en flash côté ESP32
            $table->unsignedInteger('puissance_w')->nullable();   // moyenne sur l'intervalle
            $table->timestamp('recu_le')->useCurrent();

            // Rejouer un message MQTT ne crée pas de doublon.
            $table->unique(['capteur_id', 'mesure_le']);
        });

        // Agrégats lus par les tableaux de bord (recalculés chaque nuit).
        Schema::create('consommations_journalieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->date('jour');
            $table->decimal('kwh', 10, 3);
            // iot | releves (interpolation entre relevés manuels)
            $table->string('source', 10);
            $table->boolean('complet')->default(true);            // false si coupure / trou de données
            $table->timestamps();

            $table->unique(['compteur_id', 'jour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consommations_journalieres');
        Schema::dropIfExists('mesures_iot');
        Schema::dropIfExists('capteurs');
    }
};
