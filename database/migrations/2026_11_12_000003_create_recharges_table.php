<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recharges', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->nullable()->unique();   // RCH-2026-000123, attribué après insertion
            $table->foreignId('compteur_id')->constrained()->restrictOnDelete();
            $table->foreignId('enregistre_par')->constrained('users')->restrictOnDelete();

            // --- Code de recharge -------------------------------------------------
            // Valeur chiffrée (Crypt / APP_KEY) : lisible uniquement via Recharge::revelerCode().
            $table->text('code_chiffre');
            // HMAC-SHA256 du code normalisé : déterministe, donc indexable. Bloque les doublons.
            $table->char('code_empreinte', 64)->unique();
            // 4 derniers chiffres pour l'affichage masqué « **** **** **** **** 1234 ».
            $table->char('code_fin', 4);

            // --- Achat ------------------------------------------------------------
            $table->unsignedInteger('montant_fcfa');
            $table->decimal('credit_kwh', 10, 2)->nullable();     // kWh indiqués par le SMS / reçu CIE
            $table->timestamp('date_achat');
            // orange_money | mtn_money | moov_money | wave | agence | autre
            $table->string('mode_paiement', 20);
            $table->string('reference_paiement', 64)->nullable();

            // --- Cycle de vie -----------------------------------------------------
            // en_attente -> saisi -> confirme | rejete
            $table->string('statut', 20)->default('en_attente')->index();
            $table->timestamp('date_saisie')->nullable();
            $table->timestamp('date_confirmation')->nullable();
            $table->decimal('credit_avant_kwh', 10, 2)->nullable();  // relevé juste avant la saisie (facultatif)
            $table->decimal('credit_apres_kwh', 10, 2)->nullable();  // relevé après la saisie
            $table->string('message_compteur', 120)->nullable();     // ce que le compteur a affiché en cas de refus
            $table->text('observation')->nullable();

            $table->timestamps();

            $table->index(['compteur_id', 'date_achat']);
        });

        // Traçabilité : qui a affiché un code en clair, et quand.
        Schema::create('journal_acces_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recharge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 20);                          // affichage | copie
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_acces_codes');
        Schema::dropIfExists('recharges');
    }
};
