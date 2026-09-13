<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garde-fou métier : un site peut être explicitement exclu de la génération de commission
 * (cible `CommissionCibleType::CODE_SITE` uniquement — cf. CommissionEnveloppeGenerator::
 * genererDepuisContexte()), indépendamment de son `statut` opérationnel (active/inactive/
 * suspendue) et des autres cibles (équipe de livraison, propriétaire, consultant), qui ne sont
 * jamais affectées par ce réglage. Décision produit du 13/09/2026, documentée dans
 * docs/commissions.md (COMM-013).
 *
 * Booléen NON nullable, défaut `true` : tous les sites existants continuent de générer leur
 * commission SITE exactement comme avant cette migration — aucun changement de comportement
 * silencieux. Contrairement à `approbation_reception_logistique_obligatoire` (nullable, hérite
 * d'un réglage organisation), ce flag n'a pas de notion d'héritage — c'est un interrupteur direct
 * par site, au même titre que `Vehicule::is_active`/`Client::is_active`/`Prestataire::is_active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('commissions_active')->default(true)->after('approbation_reception_logistique_obligatoire');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('commissions_active');
        });
    }
};
