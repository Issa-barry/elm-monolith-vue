<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomme la valeur persistée 'validee' → 'en_cours' dans commandes_ventes.statut.
 *
 * L'ancien statut "Validée" correspondait à une commande active avec facture créée.
 * Le nouveau nom "en_cours" reflète mieux le cycle de vie métier.
 *
 * Mapping :
 *   up   : validee  → en_cours
 *   down : en_cours → validee
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('commandes_ventes')
            ->where('statut', 'validee')
            ->update(['statut' => 'en_cours']);
    }

    public function down(): void
    {
        DB::table('commandes_ventes')
            ->where('statut', 'en_cours')
            ->update(['statut' => 'validee']);
    }
};
