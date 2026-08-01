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
            // Identité civile : conservée pour d'éventuels autres projets/usages,
            // mais jamais affichée ni demandée dans l'interface Eau La Maman —
            // voir Livreur::$fillable et EquipeLivraisonController. Le libellé
            // utilisateur ("Membre") repose uniquement sur nom_complet, facultatif.
            $table->string('nom', 100)->nullable();
            $table->string('prenom', 100)->nullable();
            $table->string('nom_complet', 150)->nullable();
            $table->string('telephone', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['telephone', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livreurs');
    }
};
