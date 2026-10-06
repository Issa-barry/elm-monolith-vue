<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caisse dédiée à un agent (chantier Trésorerie > Supports, phase 1) : un support
 * de trésorerie peut désormais avoir un responsable. `agent_id` NULL = support de
 * l'agence (comportement historique, toutes les lignes existantes), renseigné =
 * caisse dédiée à cet agent — la « nature » de la caisse est donc dérivée de cette
 * colonne, jamais stockée séparément.
 *
 * Aucune valeur n'est initialisée : tous les supports existants restent des
 * supports d'agence. nullOnDelete pour rester cohérent avec les autres FK vers
 * users du schéma (created_by...) et ne pas bloquer une suppression en cascade
 * d'organisation ; la suppression d'un utilisateur détenteur d'une caisse est
 * refusée applicativement (cf. DestroyUserController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
            $table->foreignUlid('agent_id')->nullable()->after('site_id')->constrained('users')->nullOnDelete();
            $table->index(['organization_id', 'agent_id', 'site_id'], 'compta_supports_agent_site_index');
        });
    }

    public function down(): void
    {
        Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
            $table->dropIndex('compta_supports_agent_site_index');
            $table->dropConstrainedForeignId('agent_id');
        });
    }
};
