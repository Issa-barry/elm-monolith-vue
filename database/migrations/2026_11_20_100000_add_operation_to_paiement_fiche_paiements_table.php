<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotence des paiements de fiche (ADR 0069) : clé de l'opération qui a créé le paiement
 * (en-tête Idempotency-Key) et empreinte de ses paramètres. Une même clé ne crée jamais deux
 * paiements sur une fiche (contrainte unique) et ne sert jamais pour d'autres paramètres.
 * Nullable : les paiements antérieurs n'ont pas de clé et restent tels quels (plusieurs NULL ne
 * se heurtent pas à la contrainte unique, sous MySQL comme sous SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            $table->string('operation_id', 64)->nullable()->after('note');
            $table->char('operation_empreinte', 64)->nullable()->after('operation_id');

            $table->unique(['fiche_id', 'operation_id'], 'pfp_fiche_operation_unique');
            $table->index(['organization_id', 'operation_id'], 'pfp_org_operation_index');
        });
    }

    public function down(): void
    {
        Schema::table('paiement_fiche_paiements', function (Blueprint $table) {
            $table->dropUnique('pfp_fiche_operation_unique');
            $table->dropIndex('pfp_org_operation_index');
            $table->dropColumn(['operation_id', 'operation_empreinte']);
        });
    }
};
