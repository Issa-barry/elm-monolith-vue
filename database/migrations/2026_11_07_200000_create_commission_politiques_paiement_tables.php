<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agences payeuses des commissions (ADR 0061) : la politique de l'organisation (agence centralisée
 * et règle par catégorie de bénéficiaire) et l'historique de chaque changement. Sans ligne, les
 * règles par défaut de AgencePayeuseCommissionService s'appliquent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_politiques_paiement')) {
            Schema::create('commission_politiques_paiement', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
                // Vide = la trésorerie principale de l'organisation (ADR 0017).
                $table->foreignUlid('agence_centralisee_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->json('regles');
                $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('commission_politique_paiement_historique')) {
            Schema::create('commission_politique_paiement_historique', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('avant');
                $table->json('apres');
                $table->unsignedInteger('fiches_reaffectees')->default(0);
                $table->timestamps();

                $table->index(['organization_id', 'created_at'], 'comm_pol_paie_hist_org_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_politique_paiement_historique');
        Schema::dropIfExists('commission_politiques_paiement');
    }
};
