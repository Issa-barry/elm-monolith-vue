<?php

namespace App\Services\Rh;

use App\Models\Employe;
use App\Models\EmployeAffectation;
use App\Models\FonctionRh;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée UNIQUE pour toute modification de l'affectation (site, fonction) d'un employé —
 * affectation initiale (validation de compte), transfert de site, changement de fonction : les
 * trois cas ferment la ligne d'historique active et en ouvrent une nouvelle, jamais de logique
 * dupliquée entre eux. Ne réécrit jamais une ligne déjà close (cf. employe_affectations, début
 * inclus / fin exclue).
 */
class EmployeAffectationService
{
    /**
     * @param  ?Site  $site  null = retire le site (et donc la fonction, cf. note ci-dessous) —
     *                       aucune ligne d'historique n'est ouverte sans site (employe_affectations
     *                       exige un site non nul par construction).
     * @param  ?FonctionRh  $fonction  peut être null même avec un site (fonction pas encore
     *                                 attribuée) — jamais l'inverse en pratique (site obligatoire
     *                                 dès la validation de compte).
     */
    public function definir(Employe $employe, ?Site $site, ?FonctionRh $fonction, User $acteur, ?string $motif = null): void
    {
        DB::transaction(function () use ($employe, $site, $fonction, $acteur, $motif) {
            // lockForUpdate() sérialise toute affectation concurrente sur le même employé (deux
            // transferts simultanés ne peuvent jamais tous les deux réussir en même temps).
            $employe = Employe::where('id', $employe->id)->lockForUpdate()->firstOrFail();
            $active = $employe->affectationActive()->first();

            $siteChanged = $active?->site_id !== $site?->id;
            $fonctionChanged = $active?->fonction_rh_id !== $fonction?->id;

            if ($active !== null && ! $siteChanged && ! $fonctionChanged) {
                return; // Idempotent : rien à faire, aucun bruit dans l'historique.
            }

            $now = now();

            if ($active !== null) {
                $active->update(['fin_at' => $now]);
            }

            if ($site !== null) {
                EmployeAffectation::create([
                    'organization_id' => $employe->organization_id,
                    'employe_id' => $employe->id,
                    'site_id' => $site->id,
                    'fonction_rh_id' => $fonction?->id,
                    'debut_at' => $now,
                    'fin_at' => null,
                    'motif' => $motif ?? $this->motifParDefaut($active, $siteChanged),
                    'cree_par' => $acteur->id,
                ]);
            }

            // Cache dénormalisé "valeur actuelle" — reflète toujours l'état demandé, y compris
            // pour la fonction seule sans site actif (non historisée dans ce cas précis, cf.
            // docblock du paramètre $fonction ci-dessus).
            $employe->update([
                'site_id' => $site?->id,
                'fonction_rh_id' => $fonction?->id,
            ]);

            if ($siteChanged) {
                $this->synchroniserAccesApplicatif($employe, $active?->site_id, $site);
            }
        });
    }

    private function motifParDefaut(?EmployeAffectation $active, bool $siteChanged): string
    {
        if ($active === null) {
            return 'affectation_initiale';
        }

        return $siteChanged ? 'transfert' : 'changement_fonction';
    }

    /**
     * Retire l'accès applicatif à l'ancien site, l'accorde au nouveau — via la Personne partagée
     * (Personne::user(), déjà existante), jamais un nouveau lien à construire. Un Employe sans
     * User associé (pas encore de compte applicatif) ne fait rien ici : la synchronisation aura
     * lieu plus tard, à la validation de ce compte (cf. AccountValidationService).
     */
    private function synchroniserAccesApplicatif(Employe $employe, ?string $ancienSiteId, ?Site $nouveauSite): void
    {
        $user = $employe->personne?->user;
        if ($user === null) {
            return;
        }

        if ($ancienSiteId !== null) {
            $user->sites()->detach($ancienSiteId);
        }

        if ($nouveauSite !== null) {
            $user->sites()->syncWithoutDetaching([
                $nouveauSite->id => ['role' => 'employe', 'is_default' => true],
            ]);
        }
    }
}
