<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Transition CREEE → impaye pour les commissions dont la commande
        // est déjà en livraison (livraison_en_cours, livree, facturation, cloturee).
        // Corrige les données créées en mode FACTURE_PAYEE avant le guard statut.
        $statutsEncaissables = ['livraison_en_cours', 'livree', 'facturation', 'cloturee'];
        $placeholders = implode(',', array_fill(0, count($statutsEncaissables), '?'));

        DB::statement("
            UPDATE commissions_ventes cv
            INNER JOIN commandes_ventes cmd ON cmd.id = cv.commande_vente_id
            SET cv.statut = 'impaye'
            WHERE cv.statut = 'creee'
            AND cmd.statut IN ({$placeholders})
        ", $statutsEncaissables);

        DB::statement("
            UPDATE commission_parts cp
            INNER JOIN commissions_ventes cv ON cv.id = cp.commission_vente_id
            INNER JOIN commandes_ventes cmd ON cmd.id = cv.commande_vente_id
            SET cp.statut = 'impaye'
            WHERE cp.statut = 'creee'
            AND cmd.statut IN ({$placeholders})
        ", $statutsEncaissables);
    }

    public function down(): void
    {
        // Irréversible : on ne peut pas savoir quelles lignes ont été modifiées.
    }
};
