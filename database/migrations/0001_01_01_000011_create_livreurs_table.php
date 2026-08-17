<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livreurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            // Identité civile (nom/prenom/telephone) portée par Personne — cf.
            // database/migrations/0001_01_01_000003_z_create_personnes_table.php. nom_complet
            // reste ici : désignation d'affichage propre à ce rôle (ex: "Chauffeur-1 Camion X"
            // généré automatiquement quand aucun nom n'est connu), pas l'état civil de la
            // personne — jamais déplacé vers Personne.
            $table->foreignUlid('personne_id')->constrained('personnes')->restrictOnDelete();
            $table->string('nom_complet', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livreurs');
    }
};
