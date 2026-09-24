<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace d'audit dédiée des annulations exceptionnelles de commandes de vente saisies par erreur
 * (cf. AnnulationExceptionnelleService, docs/adr/0004). Une ligne par annulation confirmée : qui,
 * quand, pourquoi, les montants figés au moment de la confirmation et la liste des régularisations
 * effectuées. Le code de confirmation envoyé par e-mail n'y figure jamais — seuls la méthode de
 * confirmation, l'adresse masquée et les horodatages de demande/confirmation sont conservés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annulations_exceptionnelles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('commande_vente_id')->unique()->constrained('commandes_ventes')->restrictOnDelete();
            $table->foreignUlid('facture_vente_id')->nullable()->constrained('factures_ventes')->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motif');
            $table->string('statut_avant', 40);
            // Empreinte (sha256) des données présentées dans le récapitulatif et confirmées.
            $table->string('empreinte', 64);
            $table->string('methode_confirmation', 30);
            $table->string('code_envoye_a')->nullable();
            $table->timestamp('code_demande_at')->nullable();
            $table->timestamp('confirmee_at');
            $table->decimal('montant_commande', 14, 2)->default(0);
            $table->decimal('montant_facture', 14, 2)->default(0);
            $table->decimal('montant_encaisse', 14, 2)->default(0);
            $table->decimal('montant_commissions', 14, 2)->default(0);
            $table->decimal('montant_cashback', 14, 2)->default(0);
            $table->json('snapshot');
            $table->json('regularisations');
            $table->timestamps();

            $table->index(['organization_id', 'confirmee_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annulations_exceptionnelles');
    }
};
