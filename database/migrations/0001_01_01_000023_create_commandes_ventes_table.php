<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes_ventes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('numero')->nullable()->index();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // Véhicule de partenaire éventuel (Client::type = PARTENAIRE), toujours facultatif,
            // jamais un substitut à `vehicule_id` (flotte gérée) — cf. ClientVehicle.
            $table->foreignUlid('client_vehicule_id')->nullable()->constrained('client_vehicules')->nullOnDelete();
            $table->string('reference', 20)->unique();
            $table->decimal('total_commande', 12, 2)->default(0);
            // Fige le mode de tarification (prix_vente/prix_usine) applicable à la commande au
            // moment de sa création : un véhicule de flotte gérée facture toujours au prix de
            // vente plein ; un client PARTENAIRE (avec ou sans véhicule) facture à prix usine.
            // Le type du client peut changer plus tard sans recalculer rétroactivement les
            // commandes déjà passées — voir VehiculeCommandeContextResolver/CommandeVenteService.
            $table->string('mode_tarification_snapshot', 20)->default('prix_vente');
            // Fige l'éligibilité aux commissions au moment de la création de la commande,
            // indépendamment du mode de tarification ci-dessus — dérivée de
            // Vehicule::livraison_vente (jamais applicable à une vente partenaire, qui n'a pas
            // de véhicule de flotte) — voir VehiculeCommandeContextResolver et CommissionGenerator.
            $table->boolean('commission_eligible_snapshot')->default(true);
            $table->string('statut', 30)->default('brouillon');
            $table->timestamp('validated_at')->nullable();

            // Timestamps de transition de statut
            $table->timestamp('a_charger_at')->nullable();         // BROUILLON → A_CHARGER
            $table->timestamp('chargement_demarre_at')->nullable(); // A_CHARGER → CHARGEMENT_EN_COURS
            $table->timestamp('chargement_valide_at')->nullable();  // CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS
            $table->timestamp('livree_at')->nullable();             // LIVRAISON_EN_COURS → LIVREE (1er encaissement)
            $table->timestamp('closed_at')->nullable();             // → CLOTUREE

            // Annulation
            $table->text('motif_annulation')->nullable();
            $table->timestamp('annulee_at')->nullable();
            $table->foreignUlid('annulee_par')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes_ventes');
    }
};
