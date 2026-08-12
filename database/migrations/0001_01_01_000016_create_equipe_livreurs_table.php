<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipe_livreurs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('equipe_id')->constrained('equipes_livraison')->cascadeOnDelete();
            $table->foreignUlid('livreur_id')->constrained('livreurs')->restrictOnDelete();
            $table->string('role', 20)->default('chauffeur');
            $table->decimal('montant_par_pack', 10, 2)->default(0);
            // Taux dérivé (calculé depuis montant_par_pack à la sauvegarde) — barème VENTE.
            $table->decimal('taux_commission', 5, 2)->default(0);
            // Barème LOGISTIQUE distinct, optionnel — nul tant que non configuré explicitement,
            // auquel cas CommissionLogistiqueService retombe sur taux_commission (barème vente)
            // pour ne rien casser sur les équipes existantes qui n'ont jamais eu besoin de le
            // distinguer. Cf. analyse "véhicules/partenaires/commissions" : vente et logistique
            // sont deux opérations distinctes qui peuvent légitimement partager des taux
            // différents pour un même livreur.
            $table->decimal('taux_commission_logistique', 5, 2)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['equipe_id', 'livreur_id']);
            $table->unique('livreur_id');
            $table->index(['equipe_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipe_livreurs');
    }
};
