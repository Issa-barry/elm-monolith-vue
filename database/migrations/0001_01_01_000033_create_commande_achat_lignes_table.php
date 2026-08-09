<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commande_achat_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commande_achat_id')->constrained('commandes_achats')->cascadeOnDelete();
            $table->foreignUlid('variante_id')->nullable()->constrained('produit_variantes')->nullOnDelete();
            $table->integer('qte')->default(0);
            $table->integer('qte_recue')->default(0);
            $table->decimal('prix_achat_snapshot', 12, 2)->default(0);
            $table->decimal('total_ligne', 12, 2)->default(0);
            // Nom produit + libellé variante figés à la création — safe de garder nullOnDelete
            // maintenant que le libellé ne dépend plus de la relation live.
            $table->string('libelle_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_achat_lignes');
    }
};
