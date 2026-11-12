<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            // credit_faible | epuisement_prevu | surconsommation | code_en_attente | capteur_muet
            $table->string('type', 30);
            $table->string('niveau', 10)->default('info');        // info | attention | critique
            $table->string('message', 255);
            $table->json('donnees')->nullable();                  // valeurs ayant déclenché l'alerte
            $table->timestamp('lue_le')->nullable();
            $table->timestamps();

            $table->index(['compteur_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes');
    }
};
