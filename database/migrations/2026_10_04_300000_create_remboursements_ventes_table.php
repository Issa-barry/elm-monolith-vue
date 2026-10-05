<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remboursements à un client (ADR 0019, lot 2) : trop-perçu d'une précommande remise pour moins que
 * ses acomptes, ou acomptes d'une précommande annulée. Sortie réelle de trésorerie depuis un support
 * de l'agence de la commande (même règle que le paiement d'une fiche, ADR 0009). Jamais supprimée :
 * c'est la trace du décaissement.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Total remboursé, dénormalisé sur la facture (0 pour tout l'historique) : le statut et le reste
        // à payer se calculent sur l'encaissé NET de remboursements sans requête supplémentaire par
        // facture dans les listes (cf. FactureVente::encaisseNet()).
        if (! Schema::hasColumn('factures_ventes', 'montant_rembourse')) {
            Schema::table('factures_ventes', function (Blueprint $table) {
                $table->decimal('montant_rembourse', 15, 2)->default(0);
            });
        }

        if (Schema::hasTable('remboursements_ventes')) {
            return;
        }

        Schema::create('remboursements_ventes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->restrictOnDelete();
            $table->foreignUlid('facture_vente_id')->constrained('factures_ventes')->restrictOnDelete();
            $table->string('motif', 20);
            $table->decimal('montant', 15, 2);
            $table->string('mode_paiement', 30);
            $table->string('operateur_mobile_money', 30)->nullable();
            $table->foreignUlid('compte_tresorerie_id')->nullable()->constrained('compta_supports_tresorerie')->nullOnDelete();
            $table->string('reference_paiement', 190)->nullable();
            $table->date('date_remboursement');
            $table->text('note')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'commande_vente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remboursements_ventes');

        if (Schema::hasColumn('factures_ventes', 'montant_rembourse')) {
            Schema::table('factures_ventes', function (Blueprint $table) {
                $table->dropColumn('montant_rembourse');
            });
        }
    }
};
