<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            // Champs manquants (miroir de commission_parts commerciales)
            $table->decimal('montant_net', 12, 2)->default(0)->after('montant_brut');
            $table->decimal('frais_supplementaires', 12, 2)->default(0)->after('montant_net');
            $table->string('type_frais', 50)->nullable()->after('frais_supplementaires');
            $table->text('commentaire_frais')->nullable()->after('type_frais');
            $table->foreignId('proprietaire_id')->nullable()
                ->after('livreur_id')
                ->constrained('proprietaires')
                ->nullOnDelete();
        });

        // Initialiser montant_net = montant_brut pour les lignes existantes
        \DB::table('commission_logistique_parts')->update([
            'montant_net' => \DB::raw('montant_brut'),
        ]);
    }

    public function down(): void
    {
        Schema::table('commission_logistique_parts', function (Blueprint $table) {
            $table->dropForeign(['proprietaire_id']);
            $table->dropColumn([
                'montant_net',
                'frais_supplementaires',
                'type_frais',
                'commentaire_frais',
                'proprietaire_id',
            ]);
        });
    }
};
