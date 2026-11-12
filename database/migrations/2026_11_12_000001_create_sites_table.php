<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proprietaire_id')->constrained('users')->cascadeOnDelete();
            $table->string('nom', 120);
            // habitation | immeuble | bureau | commerce
            $table->string('type', 20)->default('habitation');
            $table->string('adresse')->nullable();
            $table->string('ville', 80)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
