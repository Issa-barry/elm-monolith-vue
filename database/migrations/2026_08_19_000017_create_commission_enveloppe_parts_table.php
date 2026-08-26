<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nommée `commission_enveloppe_parts` (et non `commission_parts`) pour ne pas entrer en
     * collision avec la table `commission_parts` de l'ancien schéma — les deux coexistent
     * pendant toute la période de transition (cf. conception cible §B, §F).
     */
    public function up(): void
    {
        Schema::create('commission_enveloppe_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enveloppe_id')->constrained('commission_enveloppes')->cascadeOnDelete();
            $table->string('beneficiaire_type', 20);
            // Polymorphe léger (livreurs / proprietaires / employes selon beneficiaire_type).
            $table->ulid('beneficiaire_id');
            // NULL pour une part issue d'une commission directe (pas de répartition).
            $table->decimal('taux_repartition_snapshot', 5, 2)->nullable();
            $table->decimal('montant_brut', 15, 2);
            $table->decimal('montant_net', 15, 2);
            $table->decimal('montant_actuel', 15, 2)->nullable();
            $table->string('statut', 20)->default('creee');
            $table->string('origine', 20)->default('theorique');
            $table->foreignUlid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->index(['enveloppe_id', 'beneficiaire_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_enveloppe_parts');
    }
};
