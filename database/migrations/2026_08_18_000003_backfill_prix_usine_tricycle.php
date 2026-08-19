<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migration de données (pas de schéma) : le tarif tricycle devient obligatoire dès qu'un type
 * utilise le prix usine (cf. ProduitType::requiredPrices(), PrixUsineResolver) — sans repli
 * implicite vers `prix_usine` à l'exécution. Pour ne rendre aucune variante existante invalide
 * au déploiement, on copie une fois `prix_usine_tricycle = prix_usine` pour les variantes dont
 * le type l'exige et qui n'ont pas encore de tarif tricycle propre — l'organisation reste ensuite
 * libre de différencier les deux valeurs.
 *
 * Non réversible proprement (on ne sait plus, après coup, quelles lignes étaient réellement
 * NULL avant la migration) — down() ne fait rien, cohérent avec une migration de rattrapage de
 * données à sens unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('produit_variantes as pv')
            ->join('produits as p', 'p.id', '=', 'pv.produit_id')
            ->join('produit_types as pt', 'pt.id', '=', 'p.produit_type_id')
            ->where('pt.prix_usine_requis', true)
            ->whereNull('pv.prix_usine_tricycle')
            ->whereNotNull('pv.prix_usine')
            ->update(['pv.prix_usine_tricycle' => DB::raw('pv.prix_usine')]);
    }

    public function down(): void
    {
        // Non réversible — voir docblock ci-dessus.
    }
};
