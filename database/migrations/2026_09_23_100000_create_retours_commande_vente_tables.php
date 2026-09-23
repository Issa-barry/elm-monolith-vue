<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retour de livraison d'une vente standard, avant tout encaissement (décision produit du
 * 23/09/2026, cf. docs/retour-commande.md et docs/adr/) : le livreur revient avec tout ou partie
 * de la marchandise chargée.
 *
 * - `commande_vente_lignes.quantite_retournee` cumule les retours de la ligne ; la quantité
 *   livrée (`quantite_livree`) est alors recalculée en `quantite_chargee - quantite_retournee`,
 *   jamais saisie à la main pour une vente standard. Les quantités demandée/chargée d'origine ne
 *   sont jamais modifiées. Défaut 0 : aucune ligne existante n'est touchée.
 * - `commande_vente_retours` / `commande_vente_retour_lignes` tracent chaque retour (qui, quand,
 *   pourquoi, combien) — un mouvement métier, pas une simple correction de quantité. Une même
 *   commande peut avoir plusieurs retours successifs jusqu'à épuisement des quantités chargées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            $table->unsignedInteger('quantite_retournee')->default(0)->after('quantite_livree');
        });

        Schema::create('commande_vente_retours', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('commande_vente_id')->constrained('commandes_ventes')->cascadeOnDelete();
            $table->string('motif', 40);
            $table->text('commentaire')->nullable();
            $table->unsignedInteger('quantite_totale');
            $table->decimal('montant_retourne', 12, 2);
            // true quand ce retour a ramené la quantité livrée de TOUTES les lignes à 0 (la
            // commande passe alors en « Retournée »), y compris s'il complète des retours partiels
            // précédents.
            $table->boolean('retour_total')->default(false);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['commande_vente_id', 'created_at']);
        });

        Schema::create('commande_vente_retour_lignes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('commande_vente_retour_id')->constrained('commande_vente_retours')->cascadeOnDelete();
            $table->foreignUlid('commande_vente_ligne_id')->constrained('commande_vente_lignes')->cascadeOnDelete();
            $table->foreignUlid('variante_id')->constrained('produit_variantes')->restrictOnDelete();
            $table->unsignedInteger('quantite_retournee');
            // Prix unitaire facturé au moment du retour (mode de tarification de la commande) et
            // montant correspondant — figés, pour que l'écriture comptable de régularisation et
            // l'historique restent stables même si la ligne évolue ensuite.
            $table->decimal('prix_unitaire', 12, 2);
            $table->decimal('montant_retourne', 12, 2);
            $table->string('libelle_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commande_vente_retour_lignes');
        Schema::dropIfExists('commande_vente_retours');

        Schema::table('commande_vente_lignes', function (Blueprint $table) {
            $table->dropColumn('quantite_retournee');
        });
    }
};
