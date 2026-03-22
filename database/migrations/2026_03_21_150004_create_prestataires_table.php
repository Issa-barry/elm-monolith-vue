<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prestataires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 10)->nullable()->unique();

            // Identité (personne physique ou morale)
            $table->string('nom')->nullable();
            $table->string('prenom')->nullable();
            $table->string('raison_sociale')->nullable();

            // Contact
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('code_phone_pays', 10)->default('+224');

            // Localisation
            $table->string('code_pays', 2)->default('GN');
            $table->string('pays')->default('Guinée');
            $table->string('ville')->nullable();
            $table->text('adresse')->nullable();

            // Métier
            $table->string('type')->default('fournisseur')->index();
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prestataires');
    }
};
