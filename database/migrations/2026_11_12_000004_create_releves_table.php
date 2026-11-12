<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un relevé = une valeur lue sur l'afficheur du compteur à un instant donné.
        Schema::create('releves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compteur_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // credit : solde restant (diminue) | index : consommation cumulée (augmente)
            $table->string('type', 10);
            $table->decimal('valeur_kwh', 12, 2);
            // manuel | iot | confirmation (relevé saisi à la confirmation d'une recharge)
            $table->string('source', 15)->default('manuel');
            $table->foreignId('recharge_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('releve_le');
            $table->timestamps();

            $table->index(['compteur_id', 'type', 'releve_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releves');
    }
};
