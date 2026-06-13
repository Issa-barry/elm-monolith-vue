<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes_ventes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('numero')->nullable()->index();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->decimal('total_commande', 12, 2)->default(0);
            $table->string('statut', 30)->default('brouillon');

            // Timestamps de transition de statut
            $table->timestamp('a_charger_at')->nullable();         // BROUILLON → A_CHARGER
            $table->timestamp('chargement_demarre_at')->nullable(); // A_CHARGER → CHARGEMENT_EN_COURS
            $table->timestamp('chargement_valide_at')->nullable();  // CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS
            $table->timestamp('livree_at')->nullable();             // LIVRAISON_EN_COURS → LIVREE (1er encaissement)
            $table->timestamp('closed_at')->nullable();             // → CLOTUREE

            // Annulation
            $table->text('motif_annulation')->nullable();
            $table->timestamp('annulee_at')->nullable();
            $table->foreignUlid('annulee_par')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes_ventes');
    }
};
