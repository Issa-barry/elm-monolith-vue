<?php

use App\Enums\StatutPropositionVehicule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propositions_vehicules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('proprietaire_id')->nullable()->constrained('proprietaires')->nullOnDelete();
            $table->foreignId('livreur_id')->nullable()->constrained('livreurs')->nullOnDelete();

            $table->string('nom_contact', 150)->nullable();
            $table->string('telephone_contact', 30)->nullable();

            $table->string('nom_vehicule', 100);
            $table->string('marque', 100)->nullable();
            $table->string('modele', 100)->nullable();
            $table->string('immatriculation', 30);
            $table->string('type_vehicule', 30)->nullable();
            $table->unsignedInteger('capacite_packs')->nullable();
            $table->text('commentaire')->nullable();

            $table->string('statut', 20)->default(StatutPropositionVehicule::PENDING->value);
            $table->text('decision_note')->nullable();
            $table->timestamp('traitee_at')->nullable();
            $table->foreignId('traitee_par')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut'], 'prop_veh_org_statut_idx');
            $table->index(['user_id', 'created_at'], 'prop_veh_user_created_idx');
            $table->index(['immatriculation', 'organization_id'], 'prop_veh_immat_org_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('propositions_vehicules');
    }
};
