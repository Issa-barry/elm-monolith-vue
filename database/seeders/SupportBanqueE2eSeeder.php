<?php

namespace Database\Seeders;

use App\Enums\TypeSupportTresorerie;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\UserAuthIdentity;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Illuminate\Database\Seeder;

/**
 * E2E uniquement : appelé par DatabaseSeeder seulement quand APP_ENV=e2e (`--env=e2e`, CI et
 * `npm run e2e:db:reset`), jamais en local ni en production.
 *
 * Payer une fiche est un décaissement réel (ADR 0009) : l'argent sort d'un support approvisionné de
 * l'agence de la fiche. Les fiches logistiques du préchargement E2E (tests/e2e/global-setup.ts) sont
 * rattachées à CBA, site source de leurs transferts, où le compte admin E2E n'a pas de caisse dédiée.
 * Ce seeder y met en service un support Banque doté d'un solde d'ouverture, en passant par les
 * services de production (création en brouillon, validation, solde d'ouverture validé).
 *
 * Idempotent : ne recrée rien si le support existe déjà.
 */
class SupportBanqueE2eSeeder extends Seeder
{
    public const LIBELLE = 'Banque E2E';

    private const SITE = 'CBA';

    private const TELEPHONE_ADMIN = '+33758855039';

    private const SOLDE_OUVERTURE = 50_000_000;

    public function run(SupportTresorerieValidationService $validation, SoldeOuvertureTresorerieService $soldes): void
    {
        $org = Organization::where('slug', 'elm')->first();
        $admin = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone(self::TELEPHONE_ADMIN));
        if (! $org || ! $admin) {
            return;
        }

        $site = Site::where('organization_id', $org->id)->where('nom', self::SITE)->firstOrFail();

        $existe = CompteTresorerie::forOrg($org->id)->where('site_id', $site->id)->where('libelle', self::LIBELLE)->exists();
        if ($existe) {
            return;
        }

        $support = CompteTresorerie::create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $org->id)->where('numero', '521000')->firstOrFail()->id,
            'type' => TypeSupportTresorerie::BANQUE->value,
            'libelle' => self::LIBELLE,
            'actif' => false,
        ]);
        $support = $validation->valider($support, $admin);

        $solde = $soldes->enregistrer($org->id, $support, [
            'date_situation' => now()->toDateString(),
            'montant' => self::SOLDE_OUVERTURE,
        ], $admin->id);
        $soldes->valider($solde, $admin->id);
    }
}
