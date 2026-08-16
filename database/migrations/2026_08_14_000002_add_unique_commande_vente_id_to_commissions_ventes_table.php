<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Filet de sécurité BDD pour l'idempotence de la génération de commission de
     * vente (une commande n'a jamais plus d'une CommissionVente, cf.
     * CommissionGenerator::generateForCommandeIfMissing()) — jusqu'ici garantie
     * uniquement par un `where(...)->exists()` applicatif, non protégé contre les
     * appels concurrents (double clic, retry, job rejoué). Aligné sur
     * commissions_logistiques.transfert_logistique_id, déjà unique.
     */
    public function up(): void
    {
        Schema::table('commissions_ventes', function (Blueprint $table) {
            $table->unique('commande_vente_id');
        });
    }

    public function down(): void
    {
        Schema::table('commissions_ventes', function (Blueprint $table) {
            $table->dropUnique(['commande_vente_id']);
        });
    }
};
