<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle des encaissements (docs/controle-encaissements.md) : anomalies persistantes, suivi de
 * chaque notification émise et journal des contrôles exécutés. Création de tables uniquement :
 * aucune donnée existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anomalies_encaissement')) {
            Schema::create('anomalies_encaissement', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')->constrained('organizations')->cascadeOnDelete();
                // Identité stable du problème (type + facture / encaissement / pièce / référence) :
                // un même problème n'est jamais enregistré deux fois.
                $table->string('cle', 191);
                $table->string('type', 50);
                $table->string('gravite', 20);
                $table->string('statut', 20)->default('nouvelle');
                $table->foreignUlid('facture_vente_id')->nullable()->constrained('factures_ventes')->nullOnDelete();
                $table->foreignUlid('commande_vente_id')->nullable()->constrained('commandes_ventes')->nullOnDelete();
                $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->decimal('montant_facture', 15, 2)->nullable();
                $table->decimal('montant_encaisse_net', 15, 2)->nullable();
                $table->decimal('surplus', 15, 2)->default(0);
                $table->json('agent_ids')->nullable();
                $table->json('details')->nullable();
                $table->unsignedInteger('occurrence')->default(1);
                $table->timestamp('detectee_le');
                $table->timestamp('derniere_detection_le');
                $table->timestamp('ecart_disparu_le')->nullable();
                $table->foreignUlid('pris_en_charge_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('pris_en_charge_le')->nullable();
                $table->foreignUlid('cloture_par')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cloture_le')->nullable();
                $table->text('commentaire_cloture')->nullable();
                $table->unsignedInteger('nb_relances')->default(0);
                $table->timestamp('derniere_relance_le')->nullable();
                $table->timestamps();

                $table->unique(['organization_id', 'cle'], 'anomalies_encaissement_org_cle_unique');
                $table->index(['organization_id', 'statut']);
                $table->index('facture_vente_id');
            });
        }

        if (! Schema::hasTable('anomalie_encaissement_notifications')) {
            Schema::create('anomalie_encaissement_notifications', function (Blueprint $table) {
                $table->ulid('id')->primary();
                // Nom explicite : le nom généré dépasse les 64 caractères admis par MySQL.
                $table->foreignUlid('anomalie_encaissement_id')->constrained('anomalies_encaissement', 'id', 'anomalie_notif_anomalie_fk')->cascadeOnDelete();
                $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('canal', 20);
                // « detection:1 », « relance:1:2 » : un envoi par destinataire, canal et vague.
                $table->string('envoi', 40);
                $table->string('statut', 20)->default('en_attente');
                $table->unsignedInteger('tentatives')->default(0);
                $table->text('derniere_erreur')->nullable();
                $table->timestamp('envoyee_le')->nullable();
                $table->timestamps();

                $table->unique(['anomalie_encaissement_id', 'user_id', 'canal', 'envoi'], 'anomalie_notif_envoi_unique');
                $table->index(['canal', 'statut']);
            });
        }

        if (! Schema::hasTable('controles_encaissements')) {
            Schema::create('controles_encaissements', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignUlid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
                $table->string('declencheur', 20);
                $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('debut');
                $table->timestamp('fin')->nullable();
                $table->unsignedInteger('nb_constats')->default(0);
                $table->unsignedInteger('nb_nouvelles')->default(0);
                $table->unsignedInteger('nb_disparues')->default(0);
                $table->unsignedInteger('nb_relances')->default(0);
                $table->text('erreur')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'debut']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('controles_encaissements');
        Schema::dropIfExists('anomalie_encaissement_notifications');
        Schema::dropIfExists('anomalies_encaissement');
    }
};
