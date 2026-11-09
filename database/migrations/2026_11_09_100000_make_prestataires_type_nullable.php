<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel Contacts, lot 6b (ADR 0060, décision B) — `prestataires.type` devient facultatif : les
 * qualifications (table `prestataire_sous_categories`) font foi, `type` reste une copie de
 * compatibilité. Aucune valeur existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prestataires', function (Blueprint $table) {
            $table->string('type', 30)->nullable()->change();
        });
    }

    /** Retour arrière refusé s'il existe des prestataires sans type : aucune valeur n'est inventée. */
    public function down(): void
    {
        $sansType = DB::table('prestataires')->whereNull('type')->count();
        if ($sansType > 0) {
            throw new RuntimeException("{$sansType} prestataire(s) sans type : retour arrière impossible sans inventer de valeur.");
        }

        Schema::table('prestataires', function (Blueprint $table) {
            $table->string('type', 30)->nullable(false)->change();
        });
    }
};
