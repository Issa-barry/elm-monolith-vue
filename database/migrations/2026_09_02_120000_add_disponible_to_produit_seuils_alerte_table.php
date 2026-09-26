<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sépare DISPONIBILITÉ et ALERTE — deux notions distinctes fusionnées par erreur le 02/09/2026
 * matin (un site sans alerte active se voyait artificiellement afficher "Disponible" quel que
 * soit son stock réel, y compris en rupture). Décision produit corrigée le 02/09/2026
 * après-midi :
 *   - DISPONIBILITÉ ("ce produit est-il vendu sur ce site ?") — nouvelle colonne `disponible`,
 *     défaut TRUE : un produit est disponible PARTOUT tant qu'aucune restriction explicite n'a
 *     été enregistrée (mode "Tous les sites"), à l'inverse de `actif` (alerte) qui défaut à
 *     FALSE. Un site non disponible ne peut jamais afficher de rupture "métier" ni générer
 *     d'alerte, quel que soit son stock physique.
 *   - ALERTE ("faut-il surveiller et notifier ?") — colonne `actif` existante (01/09/2026),
 *     inchangée, reste défaut FALSE.
 * Ces deux colonnes cohabitent sur la même ligne (produit, site) sans interférer : chacune se
 * lit et s'écrit indépendamment (cf. ProduitSeuilAlerteService::definir()/definirDisponibilite()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produit_seuils_alerte', function (Blueprint $table) {
            $table->boolean('disponible')->default(true)->after('site_id');
        });

        // Bascule non destructive : le défaut TRUE de la colonne s'applique déjà à toute ligne
        // existante (créée jusqu'ici uniquement pour l'alerte, jamais pour restreindre la
        // disponibilité) — aucun produit ne se retrouve "non disponible" quelque part à cause de
        // cette migration, conformément au mode "Tous les sites" par défaut.
    }

    public function down(): void
    {
        Schema::table('produit_seuils_alerte', function (Blueprint $table) {
            $table->dropColumn('disponible');
        });
    }
};
