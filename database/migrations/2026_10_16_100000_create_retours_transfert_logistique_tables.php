<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retour au site source d'une marchandise partie sur un transfert logistique (ADR 0029, cf.
 * docs/retour-transfert.md).
 *
 * - `transfert_lignes.quantite_retournee` cumule les retours de la ligne. Les quantités chargée et
 *   reçue ne sont jamais modifiées par un retour. Défaut 0 : aucune ligne existante n'est touchée.
 * - `transfert_retours` / `transfert_retour_lignes` tracent chaque retour (qui, quand, pourquoi,
 *   combien). Pas de prix ni de montant : un transfert ne porte jamais de facture (COMM-006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfert_lignes', function (Blueprint $table) {
            $table->unsignedInteger('quantite_retournee')->default(0)->after('quantite_recue');
        });

        Schema::create('transfert_retours', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('transfert_logistique_id')->constrained('transferts_logistiques')->cascadeOnDelete();
            // Site où la marchandise est réintégrée — toujours le site source du transfert, figé
            // ici pour que l'historique reste lisible même si le transfert est modifié ensuite.
            $table->foreignUlid('site_id')->constrained('sites')->restrictOnDelete();
            $table->string('motif', 40);
            $table->text('commentaire')->nullable();
            $table->unsignedInteger('quantite_totale');
            // true quand ce retour a ramené TOUTE la marchandise chargée au site source (le
            // transfert passe alors en « Retourné »), y compris s'il complète des retours précédents.
            $table->boolean('retour_total')->default(false);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transfert_logistique_id', 'created_at']);
        });

        Schema::create('transfert_retour_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('transfert_retour_id')->constrained('transfert_retours')->cascadeOnDelete();
            $table->foreignUlid('transfert_ligne_id')->constrained('transfert_lignes')->cascadeOnDelete();
            $table->foreignUlid('variante_id')->constrained('produit_variantes')->restrictOnDelete();
            $table->unsignedInteger('quantite_retournee');
            $table->string('libelle_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfert_retour_lignes');
        Schema::dropIfExists('transfert_retours');

        Schema::table('transfert_lignes', function (Blueprint $table) {
            $table->dropColumn('quantite_retournee');
        });
    }
};
