<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipes_livraison', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            // vehicule_id ajouté après create_vehicules (migration suivante)
            $table->string('nom', 100);
            $table->decimal('commission_unitaire_par_pack', 10, 2)->default(0);
            $table->decimal('montant_par_pack_proprietaire', 10, 2)->nullable();
            // Taux dérivés (calculés depuis les montants à la sauvegarde)
            $table->decimal('taux_commission_proprietaire', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipes_livraison');
    }
};
