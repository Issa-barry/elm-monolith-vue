<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
