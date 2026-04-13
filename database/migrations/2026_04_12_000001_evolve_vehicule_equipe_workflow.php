<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Évolution du workflow Véhicule / Équipe :
 *
 * Avant : vehicule → equipe  (vehicules.equipe_livraison_id FK)
 * Après : equipe → vehicule  (equipes_livraison.vehicule_id FK)
 *
 * Nouvelles règles :
 *  - Véhicule a une `categorie` : interne | externe
 *  - Pour externe : proprietaire_id requis
 *  - Pour interne  : proprietaire_id = NULL (propriété du site courant)
 *  - L'équipe choisit son véhicule (vehicule_id)
 *
 * Stratégie pour éviter la FK circulaire :
 *  vehicules.equipe_livraison_id → equipes_livraison (existant)
 *  equipes_livraison.vehicule_id → vehicules (nouveau)
 *
 *  On ajoute vehicule_id SANS contrainte FK d'abord,
 *  on fait les backfills, on supprime l'ancienne FK,
 *  PUIS on ajoute la contrainte FK sur vehicule_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Ajouter `categorie` aux véhicules (nullable temporairement) ────
        Schema::table('vehicules', function (Blueprint $table) {
            $table->string('categorie', 20)->nullable()->after('type_vehicule');
        });

        // ── 2. Ajouter `vehicule_id` sans FK (pour éviter la FK circulaire) ──
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicule_id')->nullable()->after('id');
        });

        // ── 3. Backfill : equipes_livraison.vehicule_id depuis vehicules ─────
        // (vehicules.equipe_livraison_id existe encore à ce stade)
        // Utilise query builder (compatible MySQL et SQLite)
        DB::table('vehicules')
            ->whereNull('deleted_at')
            ->whereNotNull('equipe_livraison_id')
            ->get(['id', 'equipe_livraison_id'])
            ->each(function ($v) {
                DB::table('equipes_livraison')
                    ->where('id', $v->equipe_livraison_id)
                    ->update(['vehicule_id' => $v->id]);
            });

        // ── 4. Backfill : categorie sur les véhicules ─────────────────────────
        DB::statement("
            UPDATE vehicules
            SET categorie = CASE
                WHEN proprietaire_id IS NOT NULL THEN 'externe'
                ELSE 'interne'
            END
        ");

        // ── 5. Rendre `categorie` NOT NULL ────────────────────────────────────
        Schema::table('vehicules', function (Blueprint $table) {
            $table->string('categorie', 20)->default('interne')->nullable(false)->change();
        });

        // ── 6. Supprimer FK + index unique + colonne equipe_livraison_id ─────
        // MySQL : la FK doit être supprimée AVANT l'index unique qu'elle utilise
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropForeign(['equipe_livraison_id']);
        });
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropUnique('vehicules_equipe_livraison_id_unique');
        });
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('equipe_livraison_id');
        });

        // ── 7. Maintenant on peut rendre proprietaire_id nullable ─────────────
        // (plus de risque de conflit avec d'anciennes FK)
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropForeign(['proprietaire_id']);
        });
        Schema::table('vehicules', function (Blueprint $table) {
            $table->unsignedBigInteger('proprietaire_id')->nullable()->change();
        });
        Schema::table('vehicules', function (Blueprint $table) {
            $table->foreign('proprietaire_id')
                ->references('id')
                ->on('proprietaires')
                ->restrictOnDelete();
        });

        // ── 8. Maintenant ajouter la FK sur vehicule_id (pas de FK circulaire) ─
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->foreign('vehicule_id')
                ->references('id')
                ->on('vehicules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Supprimer FK sur vehicule_id des équipes
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->dropForeign(['vehicule_id']);
            $table->dropColumn('vehicule_id');
        });

        // Rétablir proprietaire_id NOT NULL
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropForeign(['proprietaire_id']);
        });
        Schema::table('vehicules', function (Blueprint $table) {
            $table->unsignedBigInteger('proprietaire_id')->nullable(false)->change();
        });
        Schema::table('vehicules', function (Blueprint $table) {
            $table->foreign('proprietaire_id')
                ->references('id')
                ->on('proprietaires')
                ->restrictOnDelete();
        });

        // Rétablir equipe_livraison_id sur vehicules
        Schema::table('vehicules', function (Blueprint $table) {
            $table->foreignId('equipe_livraison_id')
                ->nullable()
                ->after('proprietaire_id')
                ->constrained('equipes_livraison')
                ->nullOnDelete();
        });

        // Supprimer categorie
        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('categorie');
        });
    }
};
