<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le générateur de référence (App\Services\ReferenceNumeroService) scope désormais son
     * compteur par organisation (décision produit du 31/08/2026, cf. docs/references-metier.md)
     * — deux organisations peuvent donc légitimement produire la même chaîne de référence
     * (ex: VTE-310826-001 pour l'une ET pour l'autre le même jour). L'ancienne contrainte unique
     * globale sur `reference` seule, héritée du compteur historique partagé entre toutes les
     * organisations, l'en empêchait. Remplacée par une contrainte composite
     * (organization_id, reference) : l'unicité PAR organisation reste garantie à 100%, jamais
     * retirée — seule l'unicité inter-organisations, qui n'a plus lieu d'être, disparaît.
     *
     * Inclut aussi `factures_ventes` : FactureVente::booted() recopie telle quelle la référence
     * de sa CommandeVente (jamais générée indépendamment) — même contrainte globale héritée,
     * même correctif nécessaire, sous peine de bloquer la création de la facture juste après
     * une commande dont la référence coïncide avec celle d'une autre organisation.
     *
     * Sûr sur les données existantes : une colonne déjà unique globalement satisfait
     * automatiquement toute contrainte composite qui l'inclut — aucune ligne ne peut violer la
     * nouvelle contrainte au moment de la migration.
     */
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropUnique('commandes_ventes_reference_unique');
            $table->unique(['organization_id', 'reference']);
        });

        Schema::table('factures_ventes', function (Blueprint $table) {
            $table->dropUnique('factures_ventes_reference_unique');
            $table->unique(['organization_id', 'reference']);
        });

        Schema::table('transferts_logistiques', function (Blueprint $table) {
            $table->dropUnique('transferts_logistiques_reference_unique');
            $table->unique(['organization_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
            $table->unique('reference');
        });

        Schema::table('factures_ventes', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
            $table->unique('reference');
        });

        Schema::table('transferts_logistiques', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'reference']);
            $table->unique('reference');
        });
    }
};
