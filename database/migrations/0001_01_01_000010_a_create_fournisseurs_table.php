<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 10)->nullable()->unique();
            // Identité (physique ou morale) jamais dupliquée ici — même principe que Prestataire,
            // cf. ..._create_prestataires_table.php. Entité séparée de Prestataire (cf. décision
            // produit : un fournisseur n'est pas un prestataire) — pas de colonne 'type' ici.
            $table->foreignUlid('personne_id')->nullable()->constrained('personnes')->nullOnDelete();
            $table->foreignUlid('entreprise_tierce_id')->nullable()->constrained('entreprises_tierces')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fournisseurs');
    }
};
