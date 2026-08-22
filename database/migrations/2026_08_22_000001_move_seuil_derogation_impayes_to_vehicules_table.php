<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le plafond dérogatoire d'impayés redevient individuel PAR VÉHICULE (décision produit du
 * 22/08/2026, en correction de la migration 2026_08_19_000001/000002 qui l'avait porté sur le
 * type de véhicule) : deux véhicules du même type peuvent avoir des performances de paiement
 * différentes, donc des plafonds différents — cf. SolvabiliteService::seuilApplicableVehicule().
 * Le type de véhicule redevient une classification pure, sans aucune notion d'impayés.
 *
 * Étapes, dans cet ordre (chacune dépend de la précédente) :
 * 1. Ajoute `vehicules.seuil_derogation_impayes` (nullable, comme l'ancien champ du type).
 * 2. Reprend automatiquement le plafond du type sur chaque véhicule qui a ACTUELLEMENT la
 *    dérogation active (`derogation_impayes_autorisee = true`) — sur un `migrate:fresh`, la
 *    table `vehicules` est vide à ce stade et cette étape n'affecte donc aucune ligne. Mise à
 *    jour ligne par ligne en PHP plutôt qu'un UPDATE...JOIN SQL : cette syntaxe n'existe pas
 *    sur SQLite (moteur des tests automatisés, cf. phpunit.xml), seulement sur MySQL — cette
 *    migration doit rester exécutable identiquement sur les deux.
 * 3. Supprime `type_vehicules.seuil_derogation_impayes` : plus aucun fallback caché vers le
 *    type après cette migration (cf. SolvabiliteService).
 *
 * down() ne fait que restaurer le schéma (comme les migrations précédentes de cette même
 * fonctionnalité) — aucune tentative de reconstituer les plafonds par type après coup, la
 * dérogation étant désormais définie individuellement par véhicule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicules', function (Blueprint $table) {
            $table->unsignedInteger('seuil_derogation_impayes')->nullable()->after('derogation_impayes_autorisee');
        });

        DB::table('vehicules as v')
            ->join('type_vehicules as t', 'v.type_vehicule_id', '=', 't.id')
            ->where('v.derogation_impayes_autorisee', true)
            ->whereNotNull('t.seuil_derogation_impayes')
            ->select('v.id', 't.seuil_derogation_impayes')
            ->get()
            ->each(fn ($row) => DB::table('vehicules')->where('id', $row->id)->update([
                'seuil_derogation_impayes' => $row->seuil_derogation_impayes,
            ]));

        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->dropColumn('seuil_derogation_impayes');
        });
    }

    public function down(): void
    {
        Schema::table('type_vehicules', function (Blueprint $table) {
            $table->unsignedInteger('seuil_derogation_impayes')->nullable()->after('categorie_tarifaire');
        });

        Schema::table('vehicules', function (Blueprint $table) {
            $table->dropColumn('seuil_derogation_impayes');
        });
    }
};
