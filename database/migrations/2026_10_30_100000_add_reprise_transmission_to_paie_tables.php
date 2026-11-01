<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reprise de la transmission RH par la Comptabilité (lot 5 RH, ADR 0041, docs/paie.md).
 *
 * - paie_periodes : version de transmission reprise dans la période, qui et quand. Une version
 *   n'alimente qu'une seule période (index unique).
 * - paie_lignes : avantages en nature transmis, valorisés à part, jamais dans le net à payer.
 *
 * Colonnes nullables ou à 0 : les périodes et lignes existantes restent telles quelles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paie_periodes', function (Blueprint $table) {
            $table->foreignUlid('transmission_paie_id')->nullable()->after('statut')
                ->constrained('transmissions_paie')->nullOnDelete();
            $table->foreignUlid('reprise_par')->nullable()->after('transmission_paie_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reprise_le')->nullable()->after('reprise_par');

            $table->unique('transmission_paie_id', 'paie_periodes_transmission_unique');
        });

        Schema::table('paie_lignes', function (Blueprint $table) {
            $table->decimal('total_avantages_nature', 15, 2)->default(0)->after('total_autres_gains');
        });
    }

    public function down(): void
    {
        Schema::table('paie_lignes', function (Blueprint $table) {
            $table->dropColumn('total_avantages_nature');
        });

        Schema::table('paie_periodes', function (Blueprint $table) {
            $table->dropForeign(['transmission_paie_id']);
            $table->dropForeign(['reprise_par']);
            $table->dropUnique('paie_periodes_transmission_unique');
            $table->dropColumn(['transmission_paie_id', 'reprise_par', 'reprise_le']);
        });
    }
};
