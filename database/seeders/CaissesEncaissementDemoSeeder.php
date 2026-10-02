<?php

namespace Database\Seeders;

use App\Models\CompteTresorerie;
use App\Models\Personne;
use App\Models\UserAuthIdentity;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Database\Seeders\Organizations\ElmV2Demo\ElmV2DemoUsersSeeder;
use Illuminate\Database\Seeder;

/**
 * Caisse dédiée active, sur chacun de ses sites, pour les comptes de démo qui encaissent.
 *
 * Depuis le 23/09/2026 un encaissement en espèces exige une caisse dédiée active de son auteur sur
 * le site de la facture (CaisseAgentResolver::garantirCaissePourEspeces()). Sans elle, les comptes
 * de démo — ceux des specs E2E qui encaissent (`login()`/`loginAsElmV2Demo()` de tests/e2e/helpers.ts)
 * comme ceux de l'installation locale — ne pourraient plus payer en espèces.
 *
 * Volontairement limité à ces comptes (pas à tout le personnel) : le spec E2E de création d'une
 * caisse dédiée choisit lui-même un agent parmi ceux qui n'en ont pas encore.
 *
 * Passe par les services de production (création en brouillon puis validation) et reste idempotent :
 * appelé à la fois par DatabaseSeeder et par ElmV2DemoSeeder, il ignore ce qui existe déjà et les
 * comptes pas encore créés au moment de l'appel.
 */
class CaissesEncaissementDemoSeeder extends Seeder
{
    /** Mêmes numéros que E2E_FALLBACK_PHONES (tests/e2e/helpers.ts) + le compte « V2 Demo ». */
    private const TELEPHONES = [
        '+33758855039',
        '+224656555520',
        '+33769442565',
        '+224622176056',
        ElmV2DemoUsersSeeder::TELEPHONE,
    ];

    public function run(CaisseAgentService $caisses, SupportTresorerieValidationService $validation): void
    {
        foreach (self::TELEPHONES as $telephone) {
            $agent = UserAuthIdentity::resoudre(UserAuthIdentity::TYPE_TELEPHONE, Personne::normaliserTelephone($telephone));
            if (! $agent) {
                continue;
            }

            foreach ($agent->sites()->where('sites.organization_id', $agent->organization_id)->get() as $site) {
                $existe = CompteTresorerie::forOrg($agent->organization_id)
                    ->dediees()->actifs()
                    ->where('agent_id', $agent->id)->where('site_id', $site->id)
                    ->exists();
                if ($existe) {
                    continue;
                }

                $validation->valider($caisses->creer($agent->organization_id, $site->id, $agent->id), $agent);
            }
        }
    }
}
