<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 7a (ADR 0060, R1, D16) — la fiche Personne fait foi pour l'identité d'un
 * client, mais la synchronisation ne démarre, par organisation, qu'après le rapport des écarts et
 * leur arbitrage : `organizations.synchronisation_identite_client_le` reste vide (comportement
 * actuel, CLIENTPERSONNE-002) jusqu'à l'activation par `contacts:ecarts-clients --activer`, refusée
 * tant qu'un conflit reste à arbitrer.
 *
 * `contacts_reprise_valeurs` : valeurs d'avant et d'après de chaque champ modifié par une exécution
 * appliquée de l'arbitrage, pour l'annuler sans rien perdre (même journal d'exécutions que le lot 5,
 * `contacts_reprises`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('synchronisation_identite_client_le')->nullable();
        });

        Schema::create('contacts_reprise_valeurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('contacts_reprise_id')->constrained('contacts_reprises')->restrictOnDelete();
            // client | personne
            $table->string('cible_type', 20);
            $table->char('cible_id', 26);
            $table->string('champ', 30);
            $table->text('valeur_avant')->nullable();
            $table->text('valeur_apres')->nullable();
            // annulee | conflit
            $table->string('annulation_statut', 10)->nullable();
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();

            $table->index(['contacts_reprise_id', 'annulation_statut'], 'contacts_reprise_valeurs_statut_index');
            $table->index(['cible_type', 'cible_id'], 'contacts_reprise_valeurs_cible_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts_reprise_valeurs');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('synchronisation_identite_client_le');
        });
    }
};
