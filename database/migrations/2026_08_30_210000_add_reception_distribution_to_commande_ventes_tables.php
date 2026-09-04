<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distribution client devient un hybride vente/logistique (décision produit du 30/08/2026,
 * révisant COMM-004) : commercialement une vente (CommandeVente/FactureVente inchangés), mais
 * logistiquement soumise à une validation de réception avant de passer LIVREE — miroir de
 * l'écart de chargement déjà existant (quantite_chargee/type_ecart/commentaire_ecart), jamais un
 * nouveau modèle. vente_standard n'utilise jamais ces champs (LIVREE reste déclenché par le
 * premier encaissement, cf. CommandeVenteService::passerEnLivree()).
 *
 * Réutilise `quantite_livree`, déjà présente sur commande_vente_lignes depuis l'origine mais
 * jamais écrite nulle part (colonne dormante) — CashbackService::quantiteEligible() la préfère
 * déjà à quantite_demandee quand elle est renseignée : la peupler ici est la complétion naturelle
 * de son intention d'origine, pas une redéfinition. Seuls les champs de traçabilité de l'écart de
 * réception (type/commentaire) sont réellement nouveaux, par symétrie avec type_ecart/
 * commentaire_ecart (écart de chargement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            $table->string('type_ecart_reception', 30)->nullable()->after('quantite_livree');
            $table->text('commentaire_ecart_reception')->nullable()->after('type_ecart_reception');
        });

        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->timestamp('reception_validee_at')->nullable()->after('livree_at');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_ventes', function (Blueprint $table) {
            $table->dropColumn('reception_validee_at');
        });

        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            $table->dropColumn(['type_ecart_reception', 'commentaire_ecart_reception']);
        });
    }
};
