<?php

namespace Tests\Feature\Comptabilite;

use App\Models\Organization;
use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionSavedViewsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['commissions.read', 'cashback.read']);
        $this->actingAs($this->user);
    }

    public static function lists(): array
    {
        return [
            'livreurs' => ['commissions-livreurs', 'vente', 'impaye', 'filtre_statut'],
            'proprietaires' => ['commissions-proprietaires', 'proprietaires', 'impaye', 'filtre_statut'],
            'sites' => ['commissions-sites', 'sites', 'impaye', 'filtre_statut'],
            'consultants' => ['commissions-consultants', 'consultants', 'impaye', 'filtre_statut'],
            'cashback' => ['cashback', 'cashback', 'valide', 'filters.statut'],
        ];
    }

    #[DataProvider('lists')]
    public function test_saved_and_default_views_apply_and_reset(string $scope, string $path, string $status, string $prop): void
    {
        $filters = ['statut' => $status];
        if ($scope !== 'cashback') {
            $filters += ['processus' => ['vente', 'logistique_transfert'], 'periode' => '2026-08-P1'];
        }
        $id = $this->postJson(route('saved-filters.store', $scope), [
            'name' => 'Vue comptable', 'visibility' => 'personal', 'filters' => $filters, 'is_default' => true,
        ])->assertCreated()->json('id');

        $url = '/backoffice/comptabilite/commissions/'.$path;
        foreach ([$url.'?saved_view='.$id, $url] as $target) {
            $this->get($target)->assertOk()->assertInertia(function (Assert $page) use ($id, $prop, $status, $scope) {
                $page->where('saved_view.id', $id)->where($prop, $status);
                if ($scope !== 'cashback') {
                    $page->where('filtre_processus', ['vente', 'logistique_transfert'])
                        ->where('selected_periode', '2026-08-P1');
                }
            });
        }
        $this->get($url.'?all=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('saved_view', null)->where($prop, ''));
        $this->get($url.'?statut=partiel')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('saved_view', null)->where($prop, 'partiel'));
    }

    #[DataProvider('lists')]
    public function test_read_and_share_permissions_are_enforced(string $scope, string $path, string $status): void
    {
        $payload = ['name' => 'Partage', 'visibility' => 'shared', 'filters' => ['statut' => $status]];
        $this->postJson(route('saved-filters.store', $scope), $payload)->assertForbidden();
        $permission = $scope === 'cashback' ? 'cashback.update' : 'commissions.update';
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $this->user->givePermissionTo($permission);
        $this->postJson(route('saved-filters.store', $scope), $payload)->assertCreated();

        $this->user->revokePermissionTo(['commissions.read', 'cashback.read']);
        $this->getJson(route('saved-filters.index', $scope))->assertForbidden();
        $this->postJson(route('saved-filters.store', $scope), [...$payload, 'visibility' => 'personal'])->assertForbidden();
    }

    public function test_accounting_read_permission_also_grants_commission_views(): void
    {
        Permission::firstOrCreate(['name' => 'comptabilite.read', 'guard_name' => 'web']);
        $this->user->revokePermissionTo('commissions.read');
        $this->user->givePermissionTo('comptabilite.read');
        $this->getJson(route('saved-filters.index', 'commissions-livreurs'))->assertOk();
    }

    public function test_scopes_and_organizations_are_isolated(): void
    {
        $id = $this->postJson(route('saved-filters.store', 'commissions-livreurs'), [
            'name' => 'Livreurs', 'visibility' => 'personal', 'filters' => ['statut' => 'impaye'],
        ])->assertCreated()->json('id');
        $this->getJson(route('saved-filters.index', 'commissions-sites'))->assertJsonCount(0, 'views');
        $this->get('/backoffice/comptabilite/commissions/sites?saved_view='.$id)->assertNotFound();

        $org = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $view = SavedFilter::create([
            'organization_id' => $org->id, 'user_id' => $owner->id, 'scope' => 'commissions-livreurs',
            'name' => 'Externe', 'visibility' => 'shared', 'filters' => ['statut' => 'impaye'],
        ]);
        $this->get('/backoffice/comptabilite/commissions/vente?saved_view='.$view->id)->assertNotFound();
    }

    public function test_invalid_criteria_are_rejected(): void
    {
        foreach ([
            ['commissions-livreurs', ['processus' => ['inconnu']]],
            ['commissions-livreurs', ['periode' => '2026-99-P1']],
            ['commissions-sites', ['categorie_id' => $this->org->id]],
            ['commissions-consultants', ['consultant_id' => $this->org->id]],
            ['commissions-consultants', ['site_ids' => [$this->user->sites()->firstOrFail()->id]]],
            ['cashback', ['client_id' => $this->org->id]],
            ['cashback', ['date_debut' => 'invalide']],
            ['cashback', ['statut' => 'paye']],
        ] as [$scope, $filters]) {
            $this->postJson(route('saved-filters.store', $scope), [
                'name' => 'Invalide', 'visibility' => 'personal', 'filters' => $filters,
            ])->assertUnprocessable();
        }
    }
}
