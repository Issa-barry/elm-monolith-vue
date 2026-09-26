<?php

use App\Enums\NatureOperation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->string('nature_operation')->nullable()->after('commission_eligible_snapshot');
        });

        // Backfill purement informatif (badge/filtre) : n'affecte aucune commission déjà générée.
        // distribution_client seulement si le client est distributeur ET qu'un véhicule de flotte
        // est rattaché — même règle que NatureOperation::deriverParDefaut().
        DB::table('commandes_ventes')
            ->join('clients', 'clients.id', '=', 'commandes_ventes.client_id')
            ->where('clients.type', 'distributeur')
            ->whereNotNull('commandes_ventes.vehicule_id')
            ->update(['commandes_ventes.nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value]);

        DB::table('commandes_ventes')
            ->whereNull('nature_operation')
            ->update(['nature_operation' => NatureOperation::VENTE_STANDARD->value]);

        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->string('nature_operation')->nullable(false)->default(NatureOperation::VENTE_STANDARD->value)->change();
        });
    }

    public function down(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropColumn('nature_operation');
        });
    }
};
