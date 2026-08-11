<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_identite', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();

            $table->ulidMorphs('identifiable');

            $table->string('type_piece', 30);
            $table->string('numero', 100)->nullable();

            $table->string('pays_delivrance', 2)->nullable();
            $table->date('date_delivrance')->nullable();
            $table->date('date_expiration')->nullable();

            $table->string('recto_path')->nullable();
            $table->string('verso_path')->nullable();

            $table->string('recto_nom_original')->nullable();
            $table->string('verso_nom_original')->nullable();

            $table->string('recto_mime_type', 100)->nullable();
            $table->string('verso_mime_type', 100)->nullable();

            $table->unsignedBigInteger('recto_taille')->nullable();
            $table->unsignedBigInteger('verso_taille')->nullable();

            $table->string('statut_verification', 20)->default('en_attente');
            $table->text('motif_rejet')->nullable();
            $table->boolean('est_active')->default(true);

            $table->foreignUlid('verifiee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verifiee_le')->nullable();

            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'identifiable_type', 'identifiable_id'], 'pieces_identite_org_identifiable_idx');
            $table->index(['organization_id', 'statut_verification'], 'pieces_identite_org_statut_idx');
            $table->index(['organization_id', 'date_expiration'], 'pieces_identite_org_expiration_idx');
            $table->index(['organization_id', 'est_active'], 'pieces_identite_org_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_identite');
    }
};
