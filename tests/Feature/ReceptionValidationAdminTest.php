<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Réécrit le 03/09/2026 : le moteur générique (CommissionEnveloppe) est désormais le SEUL moteur
 * de commission logistique — le champ `montant_par_pack` (saisie manuelle admin) a été retiré de
 * la requête, le montant est toujours résolu par CommissionRegle (Paramètres > Commissions >
 * Transferts logistiques). Toutes les assertions sont portées sur CommissionEnveloppe /
 * CommissionEnveloppePart, plus sur l'ancien CommissionLogistique.
 */
class ReceptionValidationAdminTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

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

    protected Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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
            'livraison_vente' => false,
            'livraison_logistique' => true,
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

        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Eau 19L']);
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Eau 19L', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 5000],
        );
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

    /** Barème équipe (montant/pack) + partage GNF fixe par catégorie, réparti 60/40 entre
     *  livreur1 et livreur2 — nécessaires pour que le moteur générique résolve un montant. */
    private function configurerBareme(int $montantParPack = 200): void
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $processus->update(['statut' => CommissionActivationStatut::ACTIF->value]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montantParPack,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $this->equipe->id,
            'categorie_id' => $this->categorie->id,
            'processus_id' => $processus->id,
            'livreur_id' => $this->livreur1->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => (int) round($montantParPack * 0.6),
            'effective_from' => now()->subDay(),
        ]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $this->equipe->id,
            'categorie_id' => $this->categorie->id,
            'processus_id' => $processus->id,
            'livreur_id' => $this->livreur2->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => (int) round($montantParPack * 0.4),
            'effective_from' => now()->subDay(),
        ]);
    }

    private function enveloppePour(TransfertLogistique $transfert): ?CommissionEnveloppe
    {
        return CommissionEnveloppe::where('source_type', TransfertLogistique::class)
            ->where('source_id', $transfert->id)
            ->first();
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
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qteDemandee,
            'quantite_chargee' => $qteDemandee,
            'quantite_recue' => $qteRecue,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]);

        return $transfert;
    }

    private function urlValidation(TransfertLogistique $t): string
    {
        return "/backoffice/logistique/{$t->id}/validation-reception";
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    /** Accord admin → commission créée automatiquement */
    public function test_accord_admin_genere_commission(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $transfert->refresh();
        $this->assertEquals('accord', $transfert->validation_reception);
        $this->assertEquals($this->admin->id, $transfert->validated_by);
        $this->assertNotNull($transfert->validated_at);

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals(20000.0, (float) $enveloppe->montant_total); // 100 × 200
    }

    /** Refus admin → aucune commission créée */
    public function test_refus_admin_ne_genere_pas_commission(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception();

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), [
                'decision' => 'refus',
                'motif' => 'Quantités non conformes au bon de livraison',
            ])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $transfert->refresh();
        $this->assertEquals('refus', $transfert->validation_reception);
        $this->assertEquals('Quantités non conformes au bon de livraison', $transfert->validation_motif);

        $this->assertNull($this->enveloppePour($transfert));
    }

    /** Double clic "D'accord" → pas de commission en doublon */
    public function test_double_accord_idempotent(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteRecue: 50);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);
        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $this->assertEquals(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count(),
            'Une seule commission doit exister même après double validation.'
        );
    }

    /** Refus puis accord → commission générée à ce moment-là */
    public function test_changement_refus_vers_accord_genere_commission(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteRecue: 200);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), [
            'decision' => 'refus',
            'motif' => 'Erreur de saisie',
        ]);

        $this->assertNull($this->enveloppePour($transfert));

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals(200 * 200, (float) $enveloppe->montant_total); // 200 packs × 200 FG
    }

    /** Calcul : 1850 packs → 370 000 FG */
    public function test_calcul_commission_1850_packs(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteDemandee: 1850, qteRecue: 1850);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertEquals(370000.0, (float) $enveloppe->montant_total);
    }

    /** Répartition selon le partage GNF fixe par catégorie : livreur1 = 60 %, livreur2 = 40 % */
    public function test_repartition_parts_selon_partage_categorie(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteRecue: 100); // 20 000 FG total

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $enveloppe = $this->enveloppePour($transfert)->load('parts');

        $part1 = $enveloppe->parts->firstWhere('beneficiaire_id', $this->livreur1->id);
        $part2 = $enveloppe->parts->firstWhere('beneficiaire_id', $this->livreur2->id);

        $this->assertNotNull($part1);
        $this->assertNotNull($part2);
        $this->assertEquals(12000.0, (float) $part1->montant_net); // 20000 × 60 %
        $this->assertEquals(8000.0, (float) $part2->montant_net); // 20000 × 40 %
        $this->assertEquals(12000.0 + 8000.0, (float) $enveloppe->montant_total);
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

    /**
     * Régression du 03/09/2026 : `montant_par_pack` n'est plus un champ accepté par ce
     * point d'entrée (retiré avec le moteur legacy) — l'envoyer n'a plus aucun effet, le montant
     * reste entièrement résolu par CommissionRegle.
     */
    public function test_montant_par_pack_envoye_est_ignore(): void
    {
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord', 'montant_par_pack' => 999999])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals(20000.0, (float) $enveloppe->montant_total); // toujours 100 × 200, jamais 999999
    }

    /** Aucune génération legacy n'est plus possible, même sans barème configuré. */
    public function test_aucune_commission_legacy_nest_creee(): void
    {
        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $this->assertDatabaseMissing('commissions_logistiques', ['transfert_logistique_id' => $transfert->id]);
    }
}
