<?php

use App\Enums\OperateurMobileMoney;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque Mobile Money est un compte à part (décision du 24/09/2026, cf. docs/encaissements.md) : un
 * support Mobile Money porte désormais l'opérateur qu'il représente, et c'est lui qui rend cet
 * opérateur proposable à l'encaissement dans son agence.
 *
 * - Les comptes des opérateurs sans wallet dédié (Kulu, PayCard, Soutra Money) sont créés pour les
 *   organisations existantes via le bootstrap du plan comptable (idempotent, ne crée que le manquant).
 * - L'opérateur des supports existants est déduit de leur compte (compta_mappings
 *   « mobile_money:<detail> ») : une donnée déjà en base, jamais une valeur supposée. Un support sur
 *   un compte sans opérateur connu (ex: 561000 générique) reste sans opérateur — il n'est proposé à
 *   l'encaissement qu'une fois son opérateur renseigné dans Trésorerie > Supports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('compta_supports_tresorerie', 'operateur_mobile_money')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->string('operateur_mobile_money', 30)->nullable()->after('type');
            });
        }

        $bootstrap = app(PlanComptableBootstrapService::class);
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            $bootstrap->bootstrap($organizationId);
        }

        $supports = DB::table('compta_supports_tresorerie')
            ->where('type', 'mobile_money')
            ->whereNull('operateur_mobile_money')
            ->get(['id', 'organization_id', 'compte_comptable_id']);

        foreach ($supports as $support) {
            $details = DB::table('compta_mappings')
                ->where('organization_id', $support->organization_id)
                ->where('compte_comptable_id', $support->compte_comptable_id)
                ->where('moyen_paiement', 'like', 'mobile_money:%')
                ->pluck('moyen_paiement')
                ->map(fn (string $moyen) => substr($moyen, strlen('mobile_money:')))
                ->unique()
                ->values();

            $operateurs = $details
                ->map(fn (string $detail) => OperateurMobileMoney::fromDetailComptable($detail))
                ->filter()
                ->unique()
                ->values();

            // Un compte rattaché à plusieurs opérateurs serait ambigu : on ne devine jamais.
            if ($operateurs->count() === 1) {
                DB::table('compta_supports_tresorerie')
                    ->where('id', $support->id)
                    ->update(['operateur_mobile_money' => $operateurs->first()->value]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('compta_supports_tresorerie', 'operateur_mobile_money')) {
            Schema::table('compta_supports_tresorerie', function (Blueprint $table) {
                $table->dropColumn('operateur_mobile_money');
            });
        }
    }
};
