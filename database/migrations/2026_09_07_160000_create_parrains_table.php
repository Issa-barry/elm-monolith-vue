<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parrains', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Identité civile (nom/telephone/ville/pays...) portée par Personne — cf.
            // database/migrations/0001_01_01_000003_z_create_personnes_table.php. Pas d'unicité
            // sur personne_id : une même Personne peut parrainer plusieurs véhicules, ce Parrain
            // (rôle) est alors réutilisé par chacun via vehicules.parrain_id (cf.
            // ParrainController::store() — Parrain::firstOrCreate() applicatif).
            $table->foreignUlid('personne_id')->constrained('personnes')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parrains');
    }
};
