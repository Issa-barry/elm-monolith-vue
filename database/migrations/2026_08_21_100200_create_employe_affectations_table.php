<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Historique d'affectation d'un employé au COUPLE (site, fonction) — source de vérité pour "qui
 * occupait quelle fonction sur quel site, à quelle date" (nécessaire dès qu'un employé change de
 * fonction après avoir été gérant d'un site, cf. Phase 2 — la fonction ACTUELLE ne suffit pas pour
 * retrouver un gérant historique). `employes.site_id`/`fonction_rh_id` restent des caches
 * dénormalisés "valeur actuelle", maintenus par EmployeAffectationService — jamais la source de
 * vérité.
 *
 * `debut_at`/`fin_at` en DATETIME (pas DATE), sémantique DÉBUT INCLUS — FIN EXCLUE : fermer
 * l'affectation A au même instant que l'ouverture de B élimine toute ambiguïté de double
 * appartenance le jour d'un transfert (contrairement à une comparaison par DATE inclusive des deux
 * côtés). `fin_at IS NULL` = affectation active.
 *
 * Pas de contrainte SQL "une seule ligne active par employé" (MySQL ne supporte pas les index
 * uniques partiels) — garantie par lockForUpdate() sur l'employé dans EmployeAffectationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employe_affectations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('employe_id')->constrained('employes')->cascadeOnDelete();
            // restrictOnDelete : un site avec un historique d'affectation ne doit jamais pouvoir
            // être supprimé silencieusement.
            $table->foreignUlid('site_id')->constrained('sites')->restrictOnDelete();
            // nullable : un employé peut être affecté à un site avant qu'une fonction lui soit
            // attribuée (ou pour un employé migré sans fonction connue, cf. backfill ci-dessous).
            $table->foreignUlid('fonction_rh_id')->nullable()->constrained('fonctions_rh')->nullOnDelete();
            $table->dateTime('debut_at');
            $table->dateTime('fin_at')->nullable();
            $table->string('motif', 30)->nullable();
            $table->foreignUlid('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employe_id', 'fin_at']);
            $table->index(['site_id', 'fonction_rh_id', 'debut_at', 'fin_at']);
        });

        // Backfill : sans cette étape, aucun employé déjà affecté à un site avant cette migration
        // n'apparaîtrait dans le nouvel historique — SiteGerantResolver le verrait comme "jamais
        // affecté à aucun site". fonction_rh_id reste null (aucune fonction RH n'existait avant
        // cette mission) ; debut_at = date de création de l'employé, la meilleure approximation
        // disponible de son affectation réelle.
        $now = now();
        $employes = DB::table('employes')->whereNotNull('site_id')->select('id', 'organization_id', 'site_id', 'created_at')->get();

        foreach ($employes as $employe) {
            DB::table('employe_affectations')->insert([
                'id' => (string) Str::ulid(),
                'organization_id' => $employe->organization_id,
                'employe_id' => $employe->id,
                'site_id' => $employe->site_id,
                'fonction_rh_id' => null,
                'debut_at' => $employe->created_at ?? $now,
                'fin_at' => null,
                'motif' => 'backfill_migration',
                'cree_par' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employe_affectations');
    }
};
