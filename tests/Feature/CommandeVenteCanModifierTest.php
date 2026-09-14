<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Le flag `can_modifier` exposé au frontend (page détail + liste des ventes) est calculé
 * uniquement côté backend via CommandeVente::isEditable() (délègue à
 * StatutCommandeVente::isEditable(), vrai en BROUILLON seul) combiné à la permission Spatie
 * ventes.update — jamais recalculé côté Vue. Avant ce test, isEditable() était du code mort :
 * le contrôleur dupliquait isBrouillon() à 4 endroits (show/edit/update/index) sans centraliser
 * la règle.
 */
class CommandeVenteCanModifierTest extends TestCase
{
    use HasAdminSetup, RefreshDatabase;

    private Organization $org;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);
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

    private function userWith(array $permissions): User
    {
        $user = $this->makeUserWithPermissions($this->org, $permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /**
     * Gate::before (AuthServiceProvider) bypasse toutes les Policies pour super_admin — donc
     * $user->can('modifierContenu', ...) seul renvoie systématiquement true pour ce rôle,
     * indépendamment de isEditable(). Un super_admin n'est jamais couvert par
     * makeUserWithPermissions() ci-dessus (qui assigne admin_entreprise) ; il faut ce rôle précis
     * pour reproduire le bypass.
     */
    private function superAdminUser(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('super_admin');
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_can_modifier_vrai_pour_brouillon_avec_permission(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::BROUILLON);
        $user = $this->userWith(['ventes.read', 'ventes.update']);

        $this->actingAs($user)
            ->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn ($page) => $page->where('commande.can_modifier', true));
    }

    public function test_can_modifier_faux_si_chargement_deja_demarre_meme_avec_permission(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::CHARGEMENT_EN_COURS);
        $user = $this->userWith(['ventes.read', 'ventes.update']);

        $this->actingAs($user)
            ->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn ($page) => $page->where('commande.can_modifier', false));
    }

    public function test_can_modifier_faux_si_a_charger_meme_avec_permission(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::A_CHARGER);
        $user = $this->userWith(['ventes.read', 'ventes.update']);

        $this->actingAs($user)
            ->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn ($page) => $page->where('commande.can_modifier', false));
    }

    public function test_can_modifier_faux_pour_brouillon_sans_permission_update(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::BROUILLON);
        $user = $this->userWith(['ventes.read']);

        $this->actingAs($user)
            ->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn ($page) => $page->where('commande.can_modifier', false));
    }

    public function test_edit_refuse_hors_brouillon_meme_avec_permission(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::CHARGEMENT_EN_COURS);
        $user = $this->userWith(['ventes.read', 'ventes.update']);

        $this->actingAs($user)
            ->get("/backoffice/ventes/{$commande->id}/edit")
            ->assertForbidden();
    }

    // ── Régression : bypass Gate::before pour super_admin ───────────────────────

    public function test_can_modifier_faux_pour_super_admin_en_livraison_en_cours(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::LIVRAISON_EN_COURS);
        $superAdmin = $this->superAdminUser();

        $this->actingAs($superAdmin)
            ->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn ($page) => $page->where('commande.can_modifier', false));
    }

    public function test_can_modifier_vrai_pour_super_admin_en_brouillon(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::BROUILLON);
        $superAdmin = $this->superAdminUser();

        $this->actingAs($superAdmin)
            ->get("/backoffice/ventes/{$commande->id}")
            ->assertInertia(fn ($page) => $page->where('commande.can_modifier', true));
    }

    public function test_edit_refuse_hors_brouillon_pour_super_admin(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::LIVRAISON_EN_COURS);
        $superAdmin = $this->superAdminUser();

        $this->actingAs($superAdmin)
            ->get("/backoffice/ventes/{$commande->id}/edit")
            ->assertForbidden();
    }

    /**
     * Le cas le plus sérieux : sans le garde-fou explicite dans update() (au-delà de
     * authorize()), un super_admin pouvait réécrire lignes/total/véhicule d'une commande déjà
     * sortie de brouillon via un simple appel API, malgré isEditable() = false — authorize()
     * seul ne le bloque jamais pour ce rôle (Gate::before).
     */
    public function test_update_refuse_hors_brouillon_pour_super_admin(): void
    {
        $commande = $this->makeCommande(StatutCommandeVente::LIVRAISON_EN_COURS);
        $totalAvant = $commande->total_commande;
        $superAdmin = $this->superAdminUser();

        $this->actingAs($superAdmin)
            ->put("/backoffice/ventes/{$commande->id}", [
                'client_id' => $this->client->id,
                'lignes' => [],
            ])
            ->assertForbidden();

        $this->assertEquals($totalAvant, $commande->fresh()->total_commande);
    }
}
