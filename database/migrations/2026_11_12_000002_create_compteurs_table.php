<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('libelle', 80);                       // « Compteur salon », « Appartement 2B »…
            // Numéro STS (celui demandé à l'achat) : 11 ou 13 chiffres, clé de Luhn.
            $table->string('numero_sts', 13)->unique();
            $table->string('numero_serie', 30)->nullable();       // numéro fabricant (ex. 4074103)
            $table->string('marque', 40)->nullable();             // KAIFA
            $table->string('modele', 40)->nullable();             // MF101C
            $table->unsignedSmallInteger('impulsions_par_kwh')->default(1600);
            $table->decimal('seuil_credit_faible_kwh', 8, 2)->default(20);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Droits par compteur : un bailleur gère, un locataire consulte.
        Schema::create('compteur_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // proprietaire | gestionnaire | occupant
            $table->string('role', 20);
            $table->timestamps();

            $table->unique(['compteur_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compteur_user');
        Schema::dropIfExists('compteurs');
    }
};
