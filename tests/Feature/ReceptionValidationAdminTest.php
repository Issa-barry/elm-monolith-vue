<?php

namespace Tests\Feature;

use App\Enums\BaseCalculLogistique;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistique;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReceptionValidationAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $admin;

    protected User $operateur;

    protected Site $siteSrc;

    protected Site $siteDest;

    protected Vehicule $vehicule;

    protected EquipeLivraison $equipe;

    protected Livreur $livreur1;

    protected Livreur $livreur2;

    protected Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        // Permissions
        foreach (['logistique.create', 'logistique.read', 'logistique.update', 'logistique.commission.verser'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        foreach (['super_admin', 'admin_entreprise'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->siteSrc = $this->makeSite('Site Source');
        $this->siteDest = $this->makeSite('Site Destination', 'siege');

        // Admin
        $this->admin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->admin->assignRole('admin_entreprise');
        $this->admin->givePermissionTo(['logistique.read', 'logistique.update', 'logistique.commission.verser']);
        $this->admin->sites()->attach($this->siteDest->id, ['role' => 'responsable', 'is_default' => true]);

        // Opérateur (peut saisir la réception mais pas valider admin)
        $this->operateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->operateur->givePermissionTo(['logistique.read', 'logistique.update']);
        $this->operateur->sites()->attach($this->siteDest->id, ['role' => 'employe', 'is_default' => true]);

        // Véhicule + équipe
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'categorie' => 'interne',
            'is_active' => true,
            'capacite_packs' => 500,
        ]);

        $this->livreur1 = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->livreur2 = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $this->equipe->id,
            'livreur_id' => $this->livreur1->id,
            'taux_commission' => 60,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $this->equipe->id,
            'livreur_id' => $this->livreur2->id,
            'taux_commission' => 40,
        ]);

        $this->vehicule->update(['equipe_livraison_id' => $this->equipe->id]);

        $this->produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Eau 19L',
            'prix_vente' => 5000,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSite(string $nom, string $type = 'depot'): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => $type,
            'localisation' => 'Conakry',
        ]);
    }

    private function makeTransfertEnReception(int $qteDemandee = 100, int $qteRecue = 100): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSrc->id,
            'site_destination_id' => $this->siteDest->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => StatutTransfert::RECEPTION,
            'date_arrivee_reelle' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'produit_id' => $this->produit->id,
            'quantite_demandee' => $qteDemandee,
            'quantite_chargee' => $qteDemandee,
            'quantite_recue' => $qteRecue,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]);

        return $transfert;
    }

    private function urlValidation(TransfertLogistique $t): string
    {
        return "/logistique/{$t->id}/validation-reception";
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /** Accord admin → commission créée automatiquement */
    public function test_accord_admin_genere_commission(): void
    {
        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect("/logistique/{$transfert->id}");

        $transfert->refresh();
        $this->assertEquals('accord', $transfert->validation_reception);
        $this->assertEquals($this->admin->id, $transfert->validated_by);
        $this->assertNotNull($transfert->validated_at);

        $commission = CommissionLogistique::where('transfert_logistique_id', $transfert->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(BaseCalculLogistique::PAR_PACK->value, $commission->base_calcul->value);
        $this->assertEquals(200.0, (float) $commission->valeur_base);
        $this->assertEquals(100, $commission->quantite_reference);
        $this->assertEquals(20000.0, (float) $commission->montant_total); // 100 × 200
    }

    /** Refus admin → aucune commission créée */
    public function test_refus_admin_ne_genere_pas_commission(): void
    {
        $transfert = $this->makeTransfertEnReception();

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), [
                'decision' => 'refus',
                'motif' => 'Quantités non conformes au bon de livraison',
            ])
            ->assertRedirect("/logistique/{$transfert->id}");

        $transfert->refresh();
        $this->assertEquals('refus', $transfert->validation_reception);
        $this->assertEquals('Quantités non conformes au bon de livraison', $transfert->validation_motif);

        $this->assertDatabaseMissing('commissions_logistiques', [
            'transfert_logistique_id' => $transfert->id,
        ]);
    }

    /** Double clic "D'accord" → pas de commission en doublon */
    public function test_double_accord_idempotent(): void
    {
        $transfert = $this->makeTransfertEnReception(qteRecue: 50);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);
        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $this->assertEquals(
            1,
            CommissionLogistique::where('transfert_logistique_id', $transfert->id)->count(),
            'Une seule commission doit exister même après double validation.'
        );
    }

    /** Refus puis accord → commission générée à ce moment-là */
    public function test_changement_refus_vers_accord_genere_commission(): void
    {
        $transfert = $this->makeTransfertEnReception(qteRecue: 200);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), [
            'decision' => 'refus',
            'motif' => 'Erreur de saisie',
        ]);

        $this->assertDatabaseMissing('commissions_logistiques', ['transfert_logistique_id' => $transfert->id]);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $commission = CommissionLogistique::where('transfert_logistique_id', $transfert->id)->first();
        $this->assertNotNull($commission);
        $this->assertEquals(200 * 200, (float) $commission->montant_total); // 200 packs × 200 FG
    }

    /** Calcul : 1850 packs → 370 000 FG */
    public function test_calcul_commission_1850_packs(): void
    {
        $transfert = $this->makeTransfertEnReception(qteDemandee: 1850, qteRecue: 1850);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $commission = CommissionLogistique::where('transfert_logistique_id', $transfert->id)->first();
        $this->assertEquals(370000.0, (float) $commission->montant_total);
    }

    /** Répartition selon pourcentages : livreur1 = 60 %, livreur2 = 40 % */
    public function test_repartition_parts_selon_taux(): void
    {
        $transfert = $this->makeTransfertEnReception(qteRecue: 100); // 20 000 FG total

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $commission = CommissionLogistique::where('transfert_logistique_id', $transfert->id)
            ->with('parts')
            ->first();

        $part1 = $commission->parts->firstWhere('livreur_id', $this->livreur1->id);
        $part2 = $commission->parts->firstWhere('livreur_id', $this->livreur2->id);

        $this->assertNotNull($part1);
        $this->assertNotNull($part2);
        $this->assertEquals(12000.0, (float) $part1->montant_net); // 20000 × 60 %
        $this->assertEquals(8000.0, (float) $part2->montant_net); // 20000 × 40 %
        $this->assertEquals(12000.0 + 8000.0, (float) $commission->montant_total);
    }

    /** Opérateur non-admin → interdit */
    public function test_operateur_ne_peut_pas_valider_admin(): void
    {
        $transfert = $this->makeTransfertEnReception();

        $this->actingAs($this->operateur)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertStatus(403);
    }

    /** Refus sans motif → validation échoue */
    public function test_refus_sans_motif_retourne_erreur(): void
    {
        $transfert = $this->makeTransfertEnReception();

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'refus', 'motif' => ''])
            ->assertSessionHasErrors('motif');
    }

    /** Validation impossible si transfert pas en RECEPTION */
    public function test_validation_impossible_si_pas_reception(): void
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSrc->id,
            'site_destination_id' => $this->siteDest->id,
            'vehicule_id' => $this->vehicule->id,
            'statut' => StatutTransfert::TRANSIT,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertStatus(403);
    }

    /** Activité historisée après accord */
    public function test_activite_historisee_apres_accord(): void
    {
        $transfert = $this->makeTransfertEnReception();

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $this->assertDatabaseHas('transfert_activites', [
            'transfert_logistique_id' => $transfert->id,
            'action' => 'validation_admin_accord',
            'user_id' => $this->admin->id,
        ]);
    }

    /** Activité historisée après refus */
    public function test_activite_historisee_apres_refus(): void
    {
        $transfert = $this->makeTransfertEnReception();

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), [
            'decision' => 'refus',
            'motif' => 'Test refus',
        ]);

        $this->assertDatabaseHas('transfert_activites', [
            'transfert_logistique_id' => $transfert->id,
            'action' => 'validation_admin_refus',
            'user_id' => $this->admin->id,
        ]);
    }
}
