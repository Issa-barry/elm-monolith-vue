<?php

namespace Tests\Feature\Comptabilite;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Régression Sentry (preprod, 07/09/2026) : le rôle Commerciale avait "Commissions: Lire"
 * (`commissions.read`) coché dans /backoffice/roles, mais tous les contrôleurs Commission ne
 * vérifiaient que `comptabilite.read` — une permission différente, plus large (dépenses,
 * trésorerie, salaires, journal financier inclus), jamais accordée au rôle Commercial. Résultat :
 * 403 malgré la case cochée, quelle que soit la combinaison Créer/Modifier/Supprimer choisie.
 * Séparément, `/commissions/cashback` répondait 403 pour une TROISIÈME raison : sa policy
 * (CashbackTransactionPolicy::viewAny) ignorait totalement les permissions Spatie et vérifiait une
 * liste de noms de rôles codée en dur.
 *
 * Corrigé par User::canReadCommissions() (comptabilite.read OU commissions.read, cf. sa docblock)
 * utilisé par les 5 contrôleurs Commission (Vente, Propriétaires, Sites, Consultants, Logistique),
 * et par CashbackTransactionPolicy::viewAny() passé à can('cashback.read').
 */
class CommissionsReadPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    /** Rôle non-admin, sans comptabilite.read — seulement les permissions passées explicitement. */
    private function makeUserWithOnly(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public static function commissionIndexRoutesProvider(): array
    {
        return [
            'vente (Livreurs)' => ['comptabilite.commissions.vente.index'],
            'propriétaires' => ['comptabilite.commissions.proprietaires.index'],
            'sites' => ['comptabilite.commissions.sites.index'],
            'consultants' => ['comptabilite.commissions.consultants.index'],
            'logistique (legacy)' => ['comptabilite.commissions.logistique.index'],
        ];
    }

    #[DataProvider('commissionIndexRoutesProvider')]
    public function test_commissions_read_seul_suffit_pour_consulter(string $routeName): void
    {
        $user = $this->makeUserWithOnly(['commissions.read']);

        $this->actingAs($user)->get(route($routeName))->assertOk();
    }

    #[DataProvider('commissionIndexRoutesProvider')]
    public function test_sans_commissions_read_ni_comptabilite_read_acces_refuse(string $routeName): void
    {
        $user = $this->makeUserWithOnly([]);

        $this->actingAs($user)->get(route($routeName))->assertForbidden();
    }

    /**
     * comptabilite.read seul (sans commissions.read) doit continuer à fonctionner — c'est la
     * combinaison des rôles comptable/admin_entreprise/super_admin, jamais cassée par ce fix.
     */
    public function test_comptabilite_read_seul_continue_de_suffire(): void
    {
        $user = $this->makeUserWithOnly(['comptabilite.read']);

        $this->actingAs($user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();
    }

    /**
     * commissions.read seul ne donne aucun droit de paiement — seule l'action de LECTURE est
     * élargie, comptabilite.payer reste requis pour payerLivreur.
     */
    public function test_commissions_read_seul_ne_donne_pas_le_droit_de_payer(): void
    {
        $user = $this->makeUserWithOnly(['commissions.read']);

        $this->actingAs($user)
            ->post(route('comptabilite.commissions.logistique.livreur.paiements', ['livreurId' => (string) Str::ulid()]))
            ->assertForbidden();
    }

    public function test_cashback_read_seul_suffit_pour_consulter(): void
    {
        $user = $this->makeUserWithOnly(['cashback.read']);

        $this->actingAs($user)
            ->get(route('comptabilite.commissions.cashback.index'))
            ->assertOk();
    }

    public function test_sans_cashback_read_acces_refuse(): void
    {
        $user = $this->makeUserWithOnly([]);

        $this->actingAs($user)
            ->get(route('comptabilite.commissions.cashback.index'))
            ->assertForbidden();
    }
}
