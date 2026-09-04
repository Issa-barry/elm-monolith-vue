<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionLogistique;
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
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\TransfertLogistiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Déclencheur configurable de la commission logistique — CommissionTriggerService /
 * DeclencheurCommissionLogistique. Reprend le setup de ReceptionValidationAdminTest.
 *
 * Réécrit le 03/09/2026 : le moteur générique (CommissionEnveloppe/CommissionEnveloppeGenerator)
 * est désormais le SEUL moteur de commission logistique — l'ancien CommissionLogistiqueService et
 * la bascule par organisation (estMigreVersMoteurGenerique()) ont été retirés après vérification
 * en production qu'aucun solde `commission_logistique_parts` n'existait plus. Ce fichier
 * n'exerçait jusqu'ici QUE l'ancien moteur (aucune organisation testée n'activait le processus
 * logistique_transfert) — toutes les assertions sont portées sur CommissionEnveloppe.
 */
class CommissionTriggerLogistiqueTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    protected Organization $org;

    protected User $admin;

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

        foreach (['logistique.create', 'logistique.read', 'logistique.update', 'logistique.commission.verser'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        foreach (['super_admin', 'admin_entreprise'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->siteSrc = $this->makeSite('Site Source');
        $this->siteDest = $this->makeSite('Site Destination', 'siege');

        $this->admin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->admin->assignRole('admin_entreprise');
        $this->admin->givePermissionTo(['logistique.read', 'logistique.update', 'logistique.commission.verser']);
        $this->admin->sites()->attach($this->siteDest->id, ['role' => 'responsable', 'is_default' => true]);

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
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $this->livreur1->id, 'taux_commission' => 60]);
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $this->livreur2->id, 'taux_commission' => 40]);

        $this->vehicule->update(['equipe_livraison_id' => $this->equipe->id]);

        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Eau 19L']);
        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Eau 19L', 'categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);
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

    /** Barème équipe (montant/pack) + partage GNF fixe par catégorie — nécessaires pour que le
     *  moteur générique résolve un montant non nul sur ce transfert (cf. décision AMOA #4). */
    private function configurerBareme(int $montantParPack = 200): CommissionProcessus
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

        return $processus;
    }

    private function enveloppePour(TransfertLogistique $transfert): ?CommissionEnveloppe
    {
        return CommissionEnveloppe::where('source_type', TransfertLogistique::class)
            ->where('source_id', $transfert->id)
            ->first();
    }

    private function makeTransfertEnChargement(int $qteChargee = 100): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSrc->id,
            'site_destination_id' => $this->siteDest->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => StatutTransfert::CHARGEMENT,
            'created_by' => $this->admin->id,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qteChargee,
            'quantite_chargee' => $qteChargee,
        ]);

        $this->seedVarianteStockSuffisant($this->produit->variantePrincipale()->first(), $this->siteSrc);

        return $transfert;
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

    // ── CHARGEMENT_VALIDE ────────────────────────────────────────────────────

    public function test_chargement_valide_genere_la_commission_sur_quantite_chargee(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnChargement(qteChargee: 100);

        $this->actingAs($this->admin);
        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertEquals(StatutTransfert::TRANSIT, $transfert->fresh()->statut);

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals(20000.0, (float) $enveloppe->montant_total); // 100 × 200 FG
    }

    public function test_chargement_valide_la_reception_ulterieure_ne_duplique_pas(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnChargement(qteChargee: 100);
        $this->actingAs($this->admin);
        TransfertLogistiqueService::avancerStatut($transfert); // CHARGEMENT → TRANSIT : commission générée

        $transfert = $transfert->fresh();
        $transfert->lignes()->first()->update(['quantite_recue' => 90, 'ecart_type' => TypeEcartLogistique::MANQUANT->value]);
        $transfert->update(['statut' => StatutTransfert::RECEPTION->value, 'date_arrivee_reelle' => now()->toDateString()]);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $this->assertEquals(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count(),
            'La validation de réception ne doit pas générer une seconde commission.'
        );

        // Le montant reste figé sur la quantité chargée (100), jamais recalculé sur
        // la quantité reçue (90) constatée ensuite — cf. spec §7.
        $enveloppe = $this->enveloppePour($transfert->fresh());
        $this->assertEquals(20000.0, (float) $enveloppe->montant_total);
    }

    // ── RECEPTION_EFFECTUEE (défaut) ─────────────────────────────────────────

    public function test_reception_effectuee_le_chargement_valide_ne_genere_aucune_commission(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnChargement(qteChargee: 100);
        $this->actingAs($this->admin);
        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertNull($this->enveloppePour($transfert));
    }

    public function test_reception_effectuee_genere_la_commission_a_la_validation_admin(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals(20000.0, (float) $enveloppe->montant_total);
    }

    public function test_reception_effectuee_defaut_sans_parametre(): void
    {
        // Aucun Parametre::set... appelé : comportement historique RECEPTION_EFFECTUEE.
        $this->configurerBareme(montantParPack: 200);
        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $this->assertNotNull($this->enveloppePour($transfert));
    }

    /** Double clic "D'accord" → pas de doublon (idempotence déjà portée par la contrainte unique). */
    public function test_reception_effectuee_idempotence_double_accord(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnReception(qteRecue: 50);

        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);
        $this->actingAs($this->admin)->post($this->urlValidation($transfert), ['decision' => 'accord']);

        $this->assertEquals(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count()
        );
    }

    // ── Statut de naissance ──────────────────────────────────────────────────
    // Le déclencheur ne choisit que QUAND la commission naît, jamais son statut
    // initial : elle naît toujours CREEE, quel que soit le déclencheur — cf.
    // CommissionAdjustmentService::activerCommissionsCreees(), seul point
    // d'entrée qui la fait passer IMPAYE à la validation de la période.

    public function test_chargement_valide_cree_toujours_en_statut_creee(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnChargement(qteChargee: 100);
        $this->actingAs($this->admin);
        TransfertLogistiqueService::avancerStatut($transfert);

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals('creee', $enveloppe->statut->value);
        $this->assertTrue($enveloppe->parts()->where('statut', '!=', 'creee')->doesntExist());
    }

    public function test_reception_effectuee_cree_toujours_en_statut_creee(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals('creee', $enveloppe->statut->value);
        $this->assertTrue($enveloppe->parts()->where('statut', '!=', 'creee')->doesntExist());
    }

    // ── Multi-tenant ─────────────────────────────────────────────────────────

    public function test_parametre_organisation_est_independant(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);
        $this->configurerBareme(montantParPack: 200);

        $orgB = Organization::factory()->create();
        Parametre::setDeclencheurCommissionLogistique($orgB->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);

        $transfert = $this->makeTransfertEnChargement(qteChargee: 100);
        $this->actingAs($this->admin);
        TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertNotNull($this->enveloppePour($transfert));
        $this->assertEquals(
            DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE,
            Parametre::getDeclencheurCommissionLogistique($orgB->id),
        );
    }

    /**
     * Régression du 03/09/2026 : aucune génération legacy n'est plus possible, quel que soit
     * l'état de configuration de l'organisation (Step 5 du chantier de retrait du moteur
     * legacy) — même sans jamais avoir appelé configurerBareme(), la table `commissions_logistiques`
     * ne doit jamais recevoir de nouvelle ligne.
     */
    public function test_aucune_generation_legacy_nest_plus_possible(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        // Volontairement : pas de configurerBareme().

        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $this->assertDatabaseMissing('commissions_logistiques', ['transfert_logistique_id' => $transfert->id]);
    }
}
