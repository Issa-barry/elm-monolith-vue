<?php

namespace Tests\Unit;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Policies\CommandeVentePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Verrou de la refonte rôles/permissions (2026-09-06, § "commande de vente") :
 * `modifierContenu()` est la SEULE ability qui combine permission + organisation +
 * isEditable() (BROUILLON uniquement) — `update()`, elle, reste volontairement permission +
 * organisation seule, car `can_encaisser`/relancerCommissions() (cf. CommandeVenteController)
 * s'exercent sciemment sur des commandes déjà sorties de BROUILLON. Avant modifierContenu(),
 * rien dans la Policy elle-même n'empêchait un futur appelant d'autoriser une modification de
 * contenu via `update()` seule, sans jamais vérifier le statut.
 */
class CommandeVentePolicyTest extends TestCase
{
    use RefreshDatabase;

    private CommandeVentePolicy $policy;

    private Organization $org;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommandeVentePolicy;
        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);
    }

    private function userWithPermission(): User
    {
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo('ventes.update');

        return $user;
    }

    private function makeCommande(StatutCommandeVente $statut): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'statut' => $statut,
        ]);
    }

    public function test_modifier_contenu_vrai_pour_brouillon_avec_permission(): void
    {
        $user = $this->userWithPermission();
        $commande = $this->makeCommande(StatutCommandeVente::BROUILLON);

        $this->assertTrue($this->policy->modifierContenu($user, $commande));
    }

    public function test_modifier_contenu_faux_pour_a_charger_meme_avec_permission(): void
    {
        $user = $this->userWithPermission();
        $commande = $this->makeCommande(StatutCommandeVente::A_CHARGER);

        $this->assertFalse($this->policy->modifierContenu($user, $commande));
    }

    public function test_modifier_contenu_faux_sans_permission_meme_en_brouillon(): void
    {
        $org = $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $commande = $this->makeCommande(StatutCommandeVente::BROUILLON);

        $this->assertFalse($this->policy->modifierContenu($user, $commande));
    }

    /**
     * Régression : update() (générique) doit rester vraie hors BROUILLON dès lors que
     * permission + organisation sont réunies — sinon can_encaisser/relancerCommissions
     * (qui s'appuient sur cette même ability, volontairement distincte de
     * modifierContenu()) casseraient pour toute commande déjà sortie de BROUILLON.
     */
    public function test_update_generique_reste_vraie_hors_brouillon_avec_permission(): void
    {
        $user = $this->userWithPermission();
        $commande = $this->makeCommande(StatutCommandeVente::CHARGEMENT_EN_COURS);

        $this->assertTrue($this->policy->update($user, $commande));
    }
}
