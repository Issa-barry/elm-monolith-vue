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
            UPDATE commissions_ventes
            SET statut = 'impaye'
            WHERE statut = 'creee'
            AND commande_vente_id IN (
                SELECT id FROM commandes_ventes WHERE statut IN ({$placeholders})
            )
        ", $statutsEncaissables);

        DB::statement("
            UPDATE commission_parts
            SET statut = 'impaye'
            WHERE statut = 'creee'
            AND commission_vente_id IN (
                SELECT cv.id FROM commissions_ventes cv
                INNER JOIN commandes_ventes cmd ON cmd.id = cv.commande_vente_id
                WHERE cmd.statut IN ({$placeholders})
            )
        ", $statutsEncaissables);
    }

    public function down(): void
    {
        // Irréversible : on ne peut pas savoir quelles lignes ont été modifiées.
    }
};
