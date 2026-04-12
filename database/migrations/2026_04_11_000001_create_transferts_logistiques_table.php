<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferts_logistiques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->foreignId('site_source_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('site_destination_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('vehicule_id')->nullable()->constrained('vehicules')->nullOnDelete();
            $table->foreignId('equipe_livraison_id')->nullable()->constrained('equipes_livraison')->nullOnDelete();
            $table->string('statut')->default('brouillon');
            $table->date('date_depart_prevue')->nullable();
            $table->date('date_depart_reelle')->nullable();
            $table->date('date_arrivee_prevue')->nullable();
            $table->date('date_arrivee_reelle')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'statut']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferts_logistiques');
    }
};
