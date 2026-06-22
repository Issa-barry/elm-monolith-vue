<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ce fichier a été renommé depuis 2026_06_13_100001_..., déjà exécuté
        // en production sous l'ancien nom. Schema::hasTable évite l'erreur
        // "table already exists" quand Laravel le rejoue sous le nouveau nom.
        if (Schema::hasTable('type_vehicules')) {
            return;
        }

        Schema::create('type_vehicules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('nom', 100);
            $table->integer('capacite_defaut');
            $table->string('unite_capacite', 20)->default('packs');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['nom', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_vehicules');
    }
};
