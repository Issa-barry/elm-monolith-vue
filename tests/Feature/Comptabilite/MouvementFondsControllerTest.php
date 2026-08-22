<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class MouvementFondsControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siege;

    private Site $agence;

    private CompteTresorerie $caisseSiege;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir', 'tresorerie.annuler', 'tresorerie.rejeter']);

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

    private function storePayload(): array
    {
        return [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => 250_000,
        ];
    }

    public function test_index_refuse_non_authentifie(): void
    {
        $this->get(route('comptabilite.tresorerie.mouvements.index'))->assertRedirect(route('login'));
    }

    public function test_index_refuse_sans_permission(): void
    {
        $this->user->syncPermissions([]);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertStatus(403);
    }

    public function test_store_cree_un_brouillon(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload())
            ->assertRedirect();

        $this->assertDatabaseHas('mouvements_fonds', [
            'organization_id' => $this->org->id,
            'montant' => 250_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);
    }

    public function test_cycle_complet_envoyer_puis_recevoir(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertRedirect();
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement))
            ->assertRedirect();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->fresh()->statut);
    }

    public function test_double_confirmation_envoyer_echoue_la_deuxieme_fois(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement));

        // La policy refuse la deuxième tentative (statut n'est plus BROUILLON) : 403,
        // jamais une deuxième pièce comptable créée pour le même mouvement.
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertStatus(403);
    }

    public function test_annuler_requiert_un_motif(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.annuler', $mouvement), [])
            ->assertSessionHasErrors('motif');
    }

    public function test_isole_les_organisations(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite1 = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'S1', 'type' => 'siege', 'localisation' => 'X']);
        $autreSite2 = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'S2', 'type' => 'agence', 'localisation' => 'X']);
        $compteAutre = CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail();
        $compteTresoAutre1 = CompteTresorerie::create(['organization_id' => $autreOrg->id, 'site_id' => $autreSite1->id, 'compte_comptable_id' => $compteAutre->id, 'type' => 'caisse', 'libelle' => 'C1']);
        $compteTresoAutre2 = CompteTresorerie::create(['organization_id' => $autreOrg->id, 'site_id' => $autreSite2->id, 'compte_comptable_id' => $compteAutre->id, 'type' => 'caisse', 'libelle' => 'C2']);

        $autreUser = User::factory()->create(['organization_id' => $autreOrg->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['tresorerie.create', 'tresorerie.read'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $autreUser->assignRole('manager');
        $autreUser->givePermissionTo(['tresorerie.create', 'tresorerie.read']);
        $autreUser->sites()->attach($autreSite2->id, ['role' => 'employe', 'is_default' => true]);

        $mouvementAutreOrg = MouvementFonds::create([
            'organization_id' => $autreOrg->id,
            'site_origine_id' => $autreSite1->id,
            'site_destination_id' => $autreSite2->id,
            'compte_tresorerie_origine_id' => $compteTresoAutre1->id,
            'compte_tresorerie_destination_id' => $compteTresoAutre2->id,
            'montant' => 100_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);

        // Un utilisateur de $this->org ne peut ni voir ni envoyer un mouvement d'une
        // autre organisation, même en devinant son ID.
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvementAutreOrg))
            ->assertStatus(403);
    }

    public function test_non_admin_ne_peut_pas_envoyer_depuis_un_site_qui_n_est_pas_le_sien(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $nonAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $nonAdmin->assignRole('manager');
        $nonAdmin->givePermissionTo(['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer']);
        // Affecté uniquement à l'agence, jamais au siège.
        $nonAdmin->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);

        $mouvement = MouvementFonds::create([
            'organization_id' => $this->org->id,
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => 100_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);

        $this->actingAs($nonAdmin)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertStatus(403);
    }
}
