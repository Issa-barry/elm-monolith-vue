<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Réparation des données héritées de l'ancienne implémentation du cashback.
 *
 * Problème : avant l'ajout de la table cashback_versements (migration 144804),
 * la méthode CashbackService::verser() marquait directement statut='verse' sur
 * la transaction sans créer de versement ni renseigner montant_verse.
 * Lors de l'ajout de la colonne montant_verse (migration 144815), ces
 * transactions ont reçu la valeur par défaut 0, créant l'incohérence :
 *   statut='verse' + montant_verse=0 + 0 versements → restant affiché = 100
 *
 * Correction : pour toute transaction marquée 'verse' sans aucun versement dans
 * cashback_versements, on fixe montant_verse = montant (entièrement versée).
 */
return new class extends Migration
{
    public function up(): void
    {
        $stale = DB::table('cashback_transactions as ct')
            ->leftJoin('cashback_versements as cv', 'cv.cashback_transaction_id', '=', 'ct.id')
            ->whereNull('cv.id')
            ->where('ct.statut', 'verse')
            ->where('ct.montant_verse', 0)
            ->select('ct.id', 'ct.montant')
            ->get();

        foreach ($stale as $row) {
            DB::table('cashback_transactions')->where('id', $row->id)->update([
                'montant_verse' => $row->montant,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Pas de rollback sûr sans audit manuel.
    }
};
