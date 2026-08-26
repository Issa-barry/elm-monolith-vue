<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_regles', function (Blueprint $table) {
            // NULL = barème standard (tous véhicules) ; renseigné = exception pour ce
            // type de véhicule. Indépendant de scope_type (portée produit), cf.
            // CommissionRegleResolver.
            $table->foreignUlid('type_vehicule_id')
                ->nullable()
                ->after('scope_id')
                ->constrained('type_vehicules')
                ->nullOnDelete();

            $table->index(
                ['organization_id', 'processus_id', 'cible_type', 'scope_type', 'scope_id', 'type_vehicule_id'],
                'comm_regles_resolution_vehicule_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('commission_regles', function (Blueprint $table) {
            $table->dropIndex('comm_regles_resolution_vehicule_idx');
            $table->dropConstrainedForeignId('type_vehicule_id');
        });
    }
};
