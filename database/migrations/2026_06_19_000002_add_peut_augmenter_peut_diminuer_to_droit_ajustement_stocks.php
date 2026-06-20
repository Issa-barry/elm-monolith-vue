<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('droit_ajustement_stocks', function (Blueprint $table) {
            $table->boolean('peut_augmenter')->default(false)->after('is_actif');
            $table->boolean('peut_diminuer')->default(false)->after('peut_augmenter');
        });

        // Migrer les droits existants : is_actif=true → les deux directions autorisées
        DB::table('droit_ajustement_stocks')
            ->where('is_actif', true)
            ->update(['peut_augmenter' => true, 'peut_diminuer' => true]);

        Schema::table('droit_ajustement_stocks', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_actif']);
            $table->dropColumn('is_actif');
        });
    }

    public function down(): void
    {
        Schema::table('droit_ajustement_stocks', function (Blueprint $table) {
            $table->boolean('is_actif')->default(false)->after('perimetre');
            $table->index(['organization_id', 'is_actif']);
        });

        DB::table('droit_ajustement_stocks')
            ->where('peut_augmenter', true)
            ->orWhere('peut_diminuer', true)
            ->update(['is_actif' => true]);

        Schema::table('droit_ajustement_stocks', function (Blueprint $table) {
            $table->dropColumn(['peut_augmenter', 'peut_diminuer']);
        });
    }
};
