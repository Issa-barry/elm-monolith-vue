<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lot 2 (décision du 24/09/2026, ADR 0006) : un changement de barème qui rend non conformes des
 * partages d'équipe n'est plus appliqué tout de suite — il est préparé dans un brouillon, les
 * partages concernés y sont reconfigurés (grille groupée), puis tout est publié ensemble.
 *
 *  - commission_bareme_brouillons : un brouillon EN COURS au plus par (organisation, processus),
 *    garanti par le service (verrou). `lignes` = exactement la configuration que
 *    Paramètres → Commissions enverrait à la publication ; `regles_signature` = empreinte des
 *    règles actives au moment où le brouillon a été (re)préparé, pour refuser une publication
 *    sur une configuration modifiée entre-temps.
 *  - commission_bareme_brouillon_partages : nouveau partage PRÉPARÉ par (équipe, catégorie, livreur),
 *    jamais appliqué avant la publication ; `signature_equipe` = empreinte de la composition de
 *    l'équipe et de son partage réel au moment de la préparation (modification concurrente).
 *
 * Tables neuves, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_bareme_brouillons')) {
            Schema::create('commission_bareme_brouillons', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignUlid('processus_id')->constrained('commission_processus')->cascadeOnDelete();
                $table->json('lignes');
                $table->string('regles_signature', 64);
                $table->string('statut', 20)->default('en_cours');
                $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('publie_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('publie_le')->nullable();
                $table->timestamp('abandonne_le')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'processus_id', 'statut'], 'comm_bareme_brouillon_org_proc_statut_idx');
            });
        }

        if (! Schema::hasTable('commission_bareme_brouillon_partages')) {
            Schema::create('commission_bareme_brouillon_partages', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('brouillon_id')->constrained('commission_bareme_brouillons')->cascadeOnDelete();
                $table->foreignUlid('equipe_id')->constrained('equipes_livraison')->cascadeOnDelete();
                $table->foreignUlid('categorie_id')->constrained('categories')->cascadeOnDelete();
                $table->foreignUlid('livreur_id')->constrained('livreurs')->cascadeOnDelete();
                $table->unsignedInteger('montant_unitaire');
                $table->string('signature_equipe', 64);
                $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['brouillon_id', 'equipe_id', 'categorie_id', 'livreur_id'], 'comm_bareme_brouillon_partage_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_bareme_brouillon_partages');
        Schema::dropIfExists('commission_bareme_brouillons');
    }
};
