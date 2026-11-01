<?php

namespace Tests\Feature\Concerns;

use App\Enums\StatutPeriodePaie;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\Site;
use App\Models\TransmissionPaie;
use App\Models\User;
use App\Services\Comptabilite\ReprisePaieService;
use App\Services\PaieCalculService;
use App\Services\Rh\Paie\TransmissionPaieService;
use Spatie\Permission\Models\Role;

/**
 * Prépare la paie d'un mois comme en production (ADR 0041) : RH prépare, valide et transmet la
 * version du mois (contrats et primes du moment), puis la Comptabilité la reprend. Les lignes de
 * paie ne viennent jamais d'ailleurs.
 */
trait PrepareLaPaieDepuisLesRh
{
    /** @var array<string, User> */
    private array $acteursPaie = [];

    private function transmettreLeMois(Organization $org, int $mois, int $annee): TransmissionPaie
    {
        $acteur = $this->acteurPaie($org);
        $service = app(TransmissionPaieService::class);

        $existante = TransmissionPaie::where('organization_id', $org->id)->where('annee', $annee)->where('mois', $mois)
            ->whereIn('statut', TransmissionPaie::STATUTS_FIGES)->exists();
        $transmission = $service->preparer($acteur, $annee, $mois, $existante ? 'Correction de test' : null);

        // ADR 0047 : la validation RH met la version à disposition de la Comptabilité.
        return $service->valider($transmission, $acteur)->fresh();
    }

    /**
     * Transmet puis reprend le mois ; la période prend ensuite le statut demandé (par défaut celui
     * laissé par la reprise : « calculé »).
     */
    private function preparerPaieDepuisRh(Organization $org, int $mois, int $annee, ?StatutPeriodePaie $statut = null): PaiePeriode
    {
        $transmission = $this->transmettreLeMois($org, $mois, $annee);
        $periode = app(PaieCalculService::class)->getOrCreerPeriode($org->id, $mois, $annee);
        $periode = app(ReprisePaieService::class)->reprendre($periode, $transmission->id, $this->acteurPaie($org));

        if ($statut !== null && $periode->statut !== $statut) {
            $periode->update(['statut' => $statut]);
        }

        return $periode->fresh();
    }

    private function acteurPaie(Organization $org): User
    {
        if (isset($this->acteursPaie[$org->id])) {
            return $this->acteursPaie[$org->id];
        }

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');
        $site = Site::where('organization_id', $org->id)->first()
            ?? Site::create(['organization_id' => $org->id, 'nom' => 'Siège paie', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $this->acteursPaie[$org->id] = $user;
    }
}
