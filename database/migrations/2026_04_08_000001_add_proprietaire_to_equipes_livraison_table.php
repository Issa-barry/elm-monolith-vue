<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->foreignId('proprietaire_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('proprietaires')
                ->restrictOnDelete();

            $table->decimal('taux_commission_proprietaire', 5, 2)
                ->nullable()
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('equipes_livraison', function (Blueprint $table) {
            $table->dropForeign(['proprietaire_id']);
            $table->dropColumn(['proprietaire_id', 'taux_commission_proprietaire']);
        });
    }
};
