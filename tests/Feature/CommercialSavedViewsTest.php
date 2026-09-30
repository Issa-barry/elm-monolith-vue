<?php

namespace Tests\Feature;

use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\SavedFilter;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommercialSavedViewsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'vehicules.read']);
        $this->actingAs($this->user);
    }

    private function commande(string $reference, string $statut = 'brouillon'): CommandeVente
    {
        return CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->firstOrFail()->id,
            'reference' => $reference, 'numero' => random_int(1, 999999),
            'statut' => $statut, 'total_commande' => 5000,
        ]);
    }

    private function save(string $scope, array $filters, bool $default = false): string
    {
        return $this->postJson(route('saved-filters.store', $scope), [
            'name' => 'Ma vue', 'visibility' => 'personal', 'filters' => $filters, 'is_default' => $default,
        ])->assertCreated()->json('id');
    }

    public function test_ventes_view_filters_rows_totals_and_defaults_without_affecting_distribution(): void
    {
        $this->commande('VTE-VISIBLE', 'livree');
        $this->commande('VTE-MASQUEE');
        $distribution = $this->commande('DST-VISIBLE');
        $distribution->update(['nature_operation' => 'distribution_client']);
        $id = $this->save('ventes', ['statuts' => ['livree'], 'numero_commande' => 'VISIBLE'], true);

        foreach (['', '?saved_view='.$id] as $query) {
            $this->get('/backoffice/ventes'.$query)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('saved_view.id', $id)->has('commandes', 1)
                ->where('commandes.0.reference', 'VTE-VISIBLE')->where('totaux.nb_total', 1));
        }
        $this->get('/backoffice/ventes?all=1')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('commandes', 2));
        $this->get('/backoffice/distributions')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('commandes', 1)->where('commandes.0.reference', 'DST-VISIBLE'));
    }

    public function test_facture_view_without_status_keeps_all_statuses_and_the_month_default(): void
    {
        foreach (['FAC-RECENTE', 'FAC-ANCIENNE'] as $reference) {
            $commande = $this->commande('VTE-'.$reference);
            FactureVente::create([
                'organization_id' => $this->org->id, 'commande_vente_id' => $commande->id,
                'reference' => $reference, 'montant_brut' => 5000, 'montant_net' => 5000,
                'statut_facture' => 'impayee',
            ])->forceFill([
                'created_at' => $reference === 'FAC-ANCIENNE' ? now()->subMonths(2) : now(),
            ])->saveQuietly();
        }
        $id = $this->save('factures', ['reference' => 'FAC-'], true);
        $this->get('/backoffice/factures?saved_view='.$id)->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('saved_view.id', $id)->where('statut', 'tous')->where('periode', 'month')->has('factures', 1));
        $this->get('/backoffice/factures')->assertInertia(fn (Assert $p) => $p->where('saved_view.id', $id));
        $this->get('/backoffice/factures?all=1')->assertInertia(fn (Assert $p) => $p->where('saved_view', null));
        $all = $this->postJson(route('saved-filters.store', 'factures'), [
            'name' => 'Toutes les dates', 'visibility' => 'personal', 'filters' => ['periode' => 'tout'],
        ])->assertCreated()->json('id');
        $this->get('/backoffice/factures?saved_view='.$all)->assertInertia(fn (Assert $p) => $p
            ->where('periode', 'tout')->has('factures', 2));
    }

    public function test_vehicle_view_applies_server_filters_keeps_global_stats_and_stable_type_ids(): void
    {
        $type = TypeVehicule::where('organization_id', $this->org->id)->firstOrFail();
        $owner = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        foreach (['Alpha', 'Beta'] as $name) {
            Vehicule::factory()->create([
                'organization_id' => $this->org->id, 'proprietaire_id' => $owner->id,
                'type_vehicule_id' => $type->id, 'nom_vehicule' => $name,
                'site_id' => $this->user->sites()->firstOrFail()->id,
                'is_active' => $name === 'Alpha', 'livraison_vente' => true,
            ]);
        }
        $id = $this->save('vehicules', [
            'nom' => 'Alpha', 'statut' => 'actif', 'type_vehicule_id' => $type->id,
            'usage' => 'vente', 'partage' => 'fait',
            'site_ids' => [$this->user->sites()->firstOrFail()->id],
            'agence_proprietaire_id' => '__none__',
        ], true);
        $type->update(['nom' => 'Type renomme']);
        foreach (['', '?saved_view='.$id] as $query) {
            $this->get('/backoffice/vehicules'.$query)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('saved_view.id', $id)->has('vehicules', 1)
                ->where('vehicules.0.nom_vehicule', 'Alpha')->where('vehicules.0.type_label', 'Type renomme')
                ->where('vehicule_stats.total', 2)->where('vehicule_stats.actifs', 1));
        }
        $this->get('/backoffice/vehicules?all=1')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('vehicules', 2));
        $this->get('/backoffice/vehicules?nom=introuvable')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('vehicules', 0));
    }

    public static function scopes(): array
    {
        return [
            ['ventes', 'ventes.read', 'ventes.update', ['statuts' => ['livree']]],
            ['factures', 'ventes.read', 'ventes.update', ['periode' => 'tout']],
            ['vehicules', 'vehicules.read', 'vehicules.update', ['statut' => 'actif']],
        ];
    }

    #[DataProvider('scopes')]
    public function test_views_enforce_permissions_and_organization_isolation(string $scope, string $read, string $share, array $filters): void
    {
        $payload = ['name' => 'Partagee', 'visibility' => 'shared', 'filters' => $filters];
        $this->postJson(route('saved-filters.store', $scope), $payload)->assertForbidden();
        Permission::firstOrCreate(['name' => $share, 'guard_name' => 'web']);
        $this->user->givePermissionTo($share);
        $id = $this->postJson(route('saved-filters.store', $scope), $payload)->assertCreated()->json('id');
        SavedFilter::findOrFail($id)->update(['organization_id' => Organization::factory()->create()->id]);
        $this->get('/backoffice/'.$scope.'?saved_view='.$id)->assertNotFound();
        $this->user->revokePermissionTo($read);
        $this->getJson(route('saved-filters.index', $scope))->assertForbidden();
    }

    public function test_criteria_and_scopes_are_isolated(): void
    {
        $id = $this->save('ventes', ['statuts' => ['livree']]);
        $this->getJson(route('saved-filters.index', 'factures'))->assertJsonCount(0, 'views');
        $this->get('/backoffice/factures?saved_view='.$id)->assertNotFound();
        $foreignSite = Site::factory()->create();
        foreach ([
            ['ventes', ['statuts' => ['inconnu']]],
            ['ventes', ['date_debut' => 'invalide']],
            ['factures', ['livreur_id' => $this->org->id]],
            ['vehicules', ['type_vehicule_id' => $this->org->id]],
            ['vehicules', ['agence_proprietaire_id' => $foreignSite->id]],
            ['vehicules', ['site_ids' => [$foreignSite->id]]],
        ] as [$scope, $filters]) {
            $this->postJson(route('saved-filters.store', $scope), [
                'name' => 'Invalide', 'visibility' => 'personal', 'filters' => $filters,
            ])->assertUnprocessable();
        }
    }
}
