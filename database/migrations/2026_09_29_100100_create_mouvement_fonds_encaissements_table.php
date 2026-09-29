<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes d'un règlement inter-agences (ADR 0012) : les encaissements précis qu'un mouvement de
 * fonds de nature `reglement_agences` reverse à l'agence de la commande.
 *
 * `encaissement_actif_id` porte l'identifiant de l'encaissement tant que le règlement est actif
 * (brouillon, envoyé, contesté, reçu) et repasse à NULL quand il est annulé ou retourné. Son index
 * UNIQUE interdit, au niveau de la base, qu'un même encaissement soit engagé dans deux règlements
 * actifs — MySQL n'ayant pas d'index unique partiel, c'est cette colonne qui en tient lieu.
 * L'historique des règlements annulés/retournés reste lisible par `encaissement_vente_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mouvement_fonds_encaissements')) {
            return;
        }

        Schema::create('mouvement_fonds_encaissements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('mouvement_fonds_id')->constrained('mouvements_fonds')->cascadeOnDelete();
            $table->foreignUlid('encaissement_vente_id')->nullable()->constrained('encaissements_ventes')->nullOnDelete();
            $table->ulid('encaissement_actif_id')->nullable()->unique('mvt_fonds_enc_actif_unique');
            $table->decimal('montant', 15, 2);
            $table->timestamps();

            $table->index(['organization_id', 'encaissement_vente_id'], 'mvt_fonds_enc_org_enc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvement_fonds_encaissements');
    }
};
