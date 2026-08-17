<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            // Identité civile portée par Personne — cf.
            // database/migrations/0001_01_01_000003_z_create_personnes_table.php.
            $table->foreignUlid('personne_id')->constrained('personnes')->restrictOnDelete();
            $table->string('matricule', 6);
            $table->string('type_employe', 20)->default('interne');
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('statut', 20)->default('actif');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'matricule'], 'employes_org_matricule_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employes');
    }
};
