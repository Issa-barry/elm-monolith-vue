<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_enveloppe_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enveloppe_id')->constrained('commission_enveloppes')->cascadeOnDelete();
            // Polymorphe léger (CommandeVenteLigne...).
            $table->string('source_ligne_type', 100);
            $table->ulid('source_ligne_id');
            $table->foreignUlid('variante_id')->nullable()->constrained('produit_variantes')->nullOnDelete();
            // Snapshot figé au moment du calcul — peut être NULL (produit sans catégorie,
            // décision AMOA #5, ou unite_calcul = marge_operation qui ne raisonne pas par
            // catégorie en Phase 1).
            $table->foreignUlid('categorie_id_snapshot')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignUlid('commission_regle_id')->nullable()->constrained('commission_regles')->nullOnDelete();
            $table->decimal('quantite', 12, 2);
            $table->string('unite_calcul_snapshot', 30);
            $table->decimal('montant_ligne', 15, 2);
            $table->timestamps();

            $table->index('enveloppe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_enveloppe_lignes');
    }
};
