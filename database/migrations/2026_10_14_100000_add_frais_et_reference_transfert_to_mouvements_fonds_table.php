<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfert Mobile Money d'un agent vers son agence (ADR 0027, lot 2) :
 * - `frais` : frais facturés par l'opérateur, en sus du montant transféré. NULL pour tous les
 *   mouvements existants et pour les autres natures (aucun frais saisi) ;
 * - `cle_reference_transfert` : clé d'unicité de la référence de transaction (organisation|RÉFÉRENCE),
 *   renseignée pour cette seule nature — NULL partout ailleurs, donc sans effet sur l'existant.
 *
 * Non destructive : deux colonnes facultatives, aucune donnée modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            if (! Schema::hasColumn('mouvements_fonds', 'frais')) {
                $table->decimal('frais', 15, 2)->nullable()->after('montant');
            }
            if (! Schema::hasColumn('mouvements_fonds', 'cle_reference_transfert')) {
                $table->string('cle_reference_transfert', 220)->nullable()->after('reference_externe');
                $table->unique('cle_reference_transfert', 'mouvements_fonds_cle_reference_transfert_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mouvements_fonds', function (Blueprint $table) {
            if (Schema::hasColumn('mouvements_fonds', 'cle_reference_transfert')) {
                $table->dropUnique('mouvements_fonds_cle_reference_transfert_unique');
                $table->dropColumn('cle_reference_transfert');
            }
            if (Schema::hasColumn('mouvements_fonds', 'frais')) {
                $table->dropColumn('frais');
            }
        });
    }
};
