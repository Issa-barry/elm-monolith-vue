<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Factures fournisseurs (ADR 0022, lot 3 du module Achats). Une facture appartient à un bon de
 * commande validé et facture des LIGNES DE RÉCEPTION : chaque quantité reçue ne peut être facturée
 * qu'une fois (contrôle sous verrou à la validation). La dette fournisseur naît à la validation et
 * est portée par la facture (TTC, payé, reste dû) ; le paiement (montant_paye) appartient au lot 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_fournisseurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('commande_achat_id')->constrained('commandes_achats');
            $table->foreignUlid('fournisseur_id')->constrained('fournisseurs');
            $table->foreignUlid('site_id')->constrained('sites');
            $table->string('reference')->unique();
            $table->unsignedInteger('numero')->nullable();
            $table->string('numero_facture_fournisseur', 100);
            $table->date('date_facture');
            $table->date('date_echeance')->nullable();
            $table->decimal('taux_tva', 5, 2)->default(0);
            $table->decimal('montant_ht', 14, 2)->default(0);
            $table->decimal('montant_tva', 14, 2)->default(0);
            $table->decimal('montant_ttc', 14, 2)->default(0);
            $table->decimal('montant_paye', 14, 2)->default(0);
            $table->string('statut', 30)->default('brouillon');
            $table->text('note')->nullable();
            $table->foreignUlid('contenu_modifie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contenu_modifie_at')->nullable();
            $table->timestamp('validee_at')->nullable();
            $table->foreignUlid('validee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fournisseur_nom_snapshot')->nullable();
            $table->timestamp('annulee_at')->nullable();
            $table->foreignUlid('annulee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif_annulation')->nullable();
            // Dernier échec de comptabilisation (mapping manquant…) : affiché sur la fiche, effacé
            // dès que la pièce est passée (comptabilisation non bloquante, cf. ADR 0022).
            $table->text('comptabilisation_erreur')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'fournisseur_id', 'numero_facture_fournisseur'], 'factures_fournisseurs_numero_idx');
            $table->index(['organization_id', 'statut']);
        });

        Schema::create('facture_fournisseur_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('facture_fournisseur_id')->constrained('factures_fournisseurs')->cascadeOnDelete();
            $table->foreignUlid('reception_achat_ligne_id')->constrained('reception_achat_lignes');
            $table->foreignUlid('commande_achat_ligne_id')->constrained('commande_achat_lignes');
            $table->foreignUlid('variante_id')->nullable()->constrained('produit_variantes')->nullOnDelete();
            $table->string('libelle_snapshot')->nullable();
            $table->string('reference_snapshot')->nullable();
            $table->integer('qte_facturee');
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('total_ht', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facture_fournisseur_lignes');
        Schema::dropIfExists('factures_fournisseurs');
    }
};
