<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partage Livraison PAR CATÉGORIE (décision AMOA post-Phase 2) : les barèmes
 * Livraison varient désormais par catégorie de produit, donc le partage entre
 * livreurs doit lui aussi être défini par catégorie — un seul pourcentage par
 * équipe (equipe_livreurs.taux_commission) ne peut plus représenter à la fois
 * un partage 50/50 sur 300 GNF (Sachet) et 60/40 sur 1 000 GNF (Bouteille).
 *
 * Absence de ligne pour un couple (équipe, catégorie) = "non configuré" —
 * jamais un partage égal ou proportionnel déduit implicitement : la
 * génération de la cible équipe_livraison pour cette catégorie reste "à
 * régulariser" tant qu'un admin n'a pas explicitement sauvegardé un partage
 * (cf. EquipeLivraisonController, CommissionEnveloppeGenerator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipe_livraison_partages_categorie', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('equipe_id')->constrained('equipes_livraison')->cascadeOnDelete();
            $table->foreignUlid('categorie_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignUlid('livreur_id')->constrained('livreurs')->cascadeOnDelete();
            $table->decimal('part_pourcentage', 5, 2);
            $table->timestamps();

            $table->unique(['equipe_id', 'categorie_id', 'livreur_id'], 'eq_liv_partage_categorie_unique');
            $table->index(['equipe_id', 'categorie_id'], 'eq_liv_partage_categorie_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipe_livraison_partages_categorie');
    }
};
