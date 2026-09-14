<?php

namespace Tests\Feature\Comptabilite;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\MouvementFondsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SituationTresorerieControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siege;

    private Site $agence;

    private CompteTresorerie $caisseSiege;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir']);

        $this->siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $this->agence = $this->user->sites()->first();

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisseSiege = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->siege->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Siège',
        ]);
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->agence->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Agence',
        ]);

        $this->user->sites()->attach($this->siege->id, ['role' => 'employe', 'is_default' => false]);
    }

    public function test_index_refuse_non_authentifie(): void
    {
        $this->get(route('comptabilite.tresorerie.situation.index'))->assertRedirect(route('login'));
    }

    public function test_index_affiche_le_solde_d_un_mouvement_recu(): void
    {
        $mvtService = app(MouvementFondsService::class);
        $mouvement = $mvtService->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'montant' => 361_000,
        ], $this->user->id);
        $mouvement = $mvtService->envoyer($mouvement, $this->user->id);
        $mvtService->recevoir($mouvement, $this->user->id, $this->caisseAgence->id);

        $response = $this->actingAs($this->user)->get(route('comptabilite.tresorerie.situation.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Comptabilite/Tresorerie/Situation/Index')
            // Matoto -361 000 + Kouria +361 000 = 0 au global (PHP encode un float
            // 0.0 sans fraction par défaut, d'où l'entier ici).
            ->where('total_general.total', 0)
        );

        $rows = $response->viewData('page')['props']['rows'];
        $ligneAgence = collect($rows)->firstWhere('site_id', $this->agence->id);
        $this->assertSame(361_000.0, $ligneAgence['total']);
    }

    public function test_show_refuse_un_site_hors_perimetre_non_admin(): void
    {
        $autreAgence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'agence', 'localisation' => 'Conakry']);

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tresorerie.read', 'guard_name' => 'web']);
        $nonAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $nonAdmin->assignRole('manager');
        $nonAdmin->givePermissionTo(['tresorerie.read']);
        // Affecté uniquement à l'agence de $this->user, jamais à $autreAgence.
        $nonAdmin->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($nonAdmin)
            ->get(route('comptabilite.tresorerie.situation.show', $autreAgence))
            ->assertStatus(403);
    }

    public function test_show_affiche_le_detail_par_support(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.situation.show', $this->agence));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Comptabilite/Tresorerie/Situation/Show')
            ->where('site.id', $this->agence->id)
        );
    }
}
