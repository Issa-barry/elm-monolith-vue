<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colonne manquante côté V2 pour porter le paiement des commissions —
     * miroir de `commissions_ventes.montant_verse` / `commission_parts.montant_verse`
     * (cf. CommissionEnveloppe::recalculStatutGlobal() / CommissionEnveloppePart::recalculStatut()).
     */
    public function up(): void
    {
        Schema::table('commission_enveloppes', function (Blueprint $table) {
            $table->decimal('montant_verse', 15, 2)->default(0)->after('montant_total');
        });

        Schema::table('commission_enveloppe_parts', function (Blueprint $table) {
            $table->decimal('montant_verse', 15, 2)->default(0)->after('montant_net');
        });
    }

    public function down(): void
    {
        Schema::table('commission_enveloppe_parts', function (Blueprint $table) {
            $table->dropColumn('montant_verse');
        });

        Schema::table('commission_enveloppes', function (Blueprint $table) {
            $table->dropColumn('montant_verse');
        });
    }
};
