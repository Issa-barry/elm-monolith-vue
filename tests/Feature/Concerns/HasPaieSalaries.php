<?php

namespace Tests\Feature\Concerns;

use App\Enums\StatutPeriodePaie;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePeriode;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Salariés sous contrat et périodes de paie calculées, pour les tests de Comptabilité > Paiement
 * salaire. Suppose `$this->org` (cf. HasOrgAndUser).
 */
trait HasPaieSalaries
{
    use PrepareLaPaieDepuisLesRh;

    private function creerSalarie(?Site $site, float $salaire = 1_000_000, ?Organization $org = null): Employe
    {
        $org ??= $this->org;

        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => 'CAMARA',
            'prenom' => fake()->firstName(),
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $employe = Employe::create([
            'organization_id' => $org->id,
            'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne',
            'site_id' => $site?->id,
            'statut' => 'actif',
        ]);

        Contrat::create([
            'organization_id' => $org->id,
            'employe_id' => $employe->id,
            'type_contrat' => 'cdi',
            'date_debut' => '2025-01-01',
            'salaire_base' => $salaire,
            'statut_contrat' => 'actif',
        ]);

        return $employe;
    }

    /** Période préparée comme en production (transmission RH reprise), au statut voulu. */
    private function creerPeriodeCalculee(int $mois, int $annee, StatutPeriodePaie $statut, ?Organization $org = null): PaiePeriode
    {
        return $this->preparerPaieDepuisRh($org ?? $this->org, $mois, $annee, $statut);
    }

    private function ligneDe(PaiePeriode $periode, Employe $employe): PaieLigne
    {
        return PaieLigne::where('paie_periode_id', $periode->id)
            ->where('employe_id', $employe->id)
            ->firstOrFail();
    }

    /** Utilisateur non administrateur, limité aux agences données (rôle comptable). */
    private function creerComptable(array $sites, array $permissions = ['comptabilite.read', 'comptabilite.payer']): User
    {
        Role::firstOrCreate(['name' => 'comptable', 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('comptable');
        $user->givePermissionTo($permissions);
        foreach ($sites as $i => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function creerSuperAdmin(Organization $org): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');

        $site = Site::where('organization_id', $org->id)->first()
            ?? Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }
}
