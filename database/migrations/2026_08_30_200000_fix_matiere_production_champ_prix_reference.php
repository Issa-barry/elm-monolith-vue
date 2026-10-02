<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige le défaut buggé livré par ProduitTypeDefaultSeeder : le type « Matière de production »
 * (non vendable) portait champ_prix_reference = 'prix_achat', ce qui fait comparer
 * ProduitService::raisonIncoherencePrix() un prix de vente jamais saisi (toujours 0, ce type
 * n'étant pas vendable) au prix d'achat — rejetant systématiquement TOUTE création de produit de
 * ce type, quel que soit le prix d'achat renseigné. Backfill ciblé : uniquement les lignes encore
 * à la valeur par défaut buggée, jamais un admin ayant explicitement reconfiguré ce champ depuis
 * (improbable ici puisque cette configuration bloquait déjà toute création, mais le filtre reste
 * la pratique du projet — ne jamais écraser une valeur potentiellement volontaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('produit_types')
            ->where('code', 'matiere_production')
            ->where('champ_prix_reference', 'prix_achat')
            ->where('vendable', false)
            ->update(['champ_prix_reference' => null]);
    }

    public function down(): void
    {
        DB::table('produit_types')
            ->where('code', 'matiere_production')
            ->whereNull('champ_prix_reference')
            ->where('vendable', false)
            ->update(['champ_prix_reference' => 'prix_achat']);
    }
};
