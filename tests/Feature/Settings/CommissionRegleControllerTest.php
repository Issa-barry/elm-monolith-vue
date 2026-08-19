<?php

namespace Tests\Feature\Settings;

use App\Enums\CommissionRegleStatut;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Paramètres → Commissions (Phase 2) : CRUD des barèmes fixes, versionné —
 * "modifier" un montant crée toujours une nouvelle ligne et clôture
 * l'ancienne, ne l'écrase jamais (décision AMOA "aucune modification rétroactive").
 */
class CommissionRegleControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['parametres.read', 'parametres.update']);
    }

    /** @test */
    public function affiche_la_page_avec_une_ligne_globale_et_une_ligne_par_categorie(): void
    {
        Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $this->actingAs($this->user)
            ->get('/settings/commissions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/CommissionRegles/Index')
                ->has('lignes', 2) // globale + 1 catégorie
                ->has('cibles', 2) // propriétaire + livraison
            );
    }

    /** @test */
    public function cree_une_regle_globale_pour_le_proprietaire(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => 600,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commission_regles', [
            'organization_id' => $this->org->id,
            'cible_type' => 'proprietaire',
            'scope_type' => 'global',
            'montant' => 600.00,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);

        // Le processus vente est créé automatiquement mais reste inactif :
        // aucun changement de comportement observable.
        $processus = CommissionProcessus::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertFalse($processus->isActif());
    }

    /** @test */
    public function modifier_un_montant_cree_une_nouvelle_version_et_cloture_lancienne(): void
    {
        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 600,
        ]);

        $ancienne = CommissionRegle::where('montant', 600)->firstOrFail();

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 650,
            'effective_from' => now()->addDay()->toDateString(),
        ]);

        $ancienne->refresh();
        $this->assertEquals(CommissionRegleStatut::REMPLACEE, $ancienne->statut);
        $this->assertNotNull($ancienne->effective_to);

        $nouvelle = CommissionRegle::where('montant', 650)->firstOrFail();
        $this->assertEquals(CommissionRegleStatut::ACTIVE, $nouvelle->statut);
        $this->assertEquals($ancienne->id, $nouvelle->remplace_regle_id);

        // Toujours une seule règle ACTIVE pour ce (organisation, cible, scope).
        $this->assertEquals(
            1,
            CommissionRegle::where('organization_id', $this->org->id)
                ->where('cible_type', 'proprietaire')
                ->where('scope_type', 'global')
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->count(),
        );
    }

    /** @test */
    public function une_regle_categorie_et_une_regle_globale_coexistent_sans_se_remplacer(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'global',
            'montant' => 600,
        ]);

        $this->actingAs($this->user)->post('/settings/commissions', [
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'scope_type' => 'categorie',
            'categorie_id' => $categorie->id,
            'montant' => 800,
        ]);

        $this->assertEquals(
            2,
            CommissionRegle::where('organization_id', $this->org->id)
                ->where('statut', CommissionRegleStatut::ACTIVE->value)
                ->count(),
        );
    }

    /** @test */
    public function refuse_la_creation_sans_permission_parametres_update(): void
    {
        $this->initOrgAndUser(['parametres.read']); // pas .update

        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'global',
                'montant' => 600,
            ])
            ->assertForbidden();
    }

    /** @test */
    public function categorie_id_obligatoire_si_scope_type_categorie(): void
    {
        $this->actingAs($this->user)
            ->post('/settings/commissions', [
                'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
                'scope_type' => 'categorie',
                'montant' => 600,
            ])
            ->assertSessionHasErrors('categorie_id');
    }
}
