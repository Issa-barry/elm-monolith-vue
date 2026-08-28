<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot du calcul au moment de la génération (même principe que
 * CommandeVenteLigne::prix_vente_snapshot / EquipeLivraisonPartageCategorie::montant_unitaire_
 * snapshot) : le montant par pack et la quantité éligible utilisés ne doivent jamais être
 * recalculés depuis Client::cashback_montant_par_pack, qui peut changer après coup — `montant`
 * (colonne déjà existante) reste le montant total, désormais dérivé de
 * montant_unitaire_snapshot × quantite_eligible_snapshot.
 *
 * Nullable : les transactions déjà existantes (ancien modèle seuil/gain, cf. CashbackService
 * avant ce chantier) n'ont pas cette notion et le restent — jamais recalculées rétroactivement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->unsignedInteger('montant_unitaire_snapshot')->nullable()->after('montant');
            $table->unsignedInteger('quantite_eligible_snapshot')->nullable()->after('montant_unitaire_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('cashback_transactions', function (Blueprint $table) {
            $table->dropColumn(['montant_unitaire_snapshot', 'quantite_eligible_snapshot']);
        });
    }
};
