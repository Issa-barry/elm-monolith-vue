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
use App\Models\CommissionEnveloppePart;
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
use Inertia\Testing\AssertableInertia as Assert;
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

    /**
     * Transfert en TRANSIT dont les lignes ont déjà leur quantité reçue + type d'écart
     * renseignés (pré-condition de checkReception()) — permet d'exercer réellement
     * TransfertLogistiqueService::avancerStatut() sur la transition TRANSIT → RECEPTION,
     * contrairement à makeTransfertEnReception() qui crée directement en RECEPTION et ne
     * déclenche donc jamais le hook d'auto-approbation qui vit dans cette transition.
     */
    private function makeTransfertEnTransitPourReception(int $qteChargee = 100, int $qteRecue = 100, ?Site $siteDestination = null): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->siteSrc->id,
            'site_destination_id' => ($siteDestination ?? $this->siteDest)->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => StatutTransfert::TRANSIT,
            'date_depart_reelle' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qteChargee,
            'quantite_chargee' => $qteChargee,
            'quantite_recue' => $qteRecue,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]);

        return $transfert;
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

    // ── Approbation admin optionnelle (Parametre::isApprobationReceptionLogistiqueObligatoire) ──

    public function test_approbation_obligatoire_par_defaut_aucune_commission_a_la_seule_reception(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertEquals(StatutTransfert::RECEPTION, $transfert->statut);
        $this->assertNull($transfert->validation_reception);
        $this->assertNull($this->enveloppePour($transfert));
    }

    public function test_approbation_non_requise_genere_la_commission_directement_a_la_reception(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertEquals(StatutTransfert::RECEPTION, $transfert->statut);
        $this->assertEquals('accord', $transfert->validation_reception);
        $this->assertNull($transfert->validated_by, 'Auto-approbation : pas de décision humaine, contrairement à un accord manuel.');

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe);
        $this->assertEquals(20000.0, (float) $enveloppe->montant_total);
    }

    /** Le bouton "Approuver la réception" disparaît côté UI une fois validation_reception=accord,
     *  mais un appel direct au endpoint doit rester sans danger si jamais rejoué. */
    public function test_approbation_non_requise_reste_idempotente_si_un_accord_est_quand_meme_rejoue(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $this->assertEquals(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count(),
        );
    }

    /** Sous CHARGEMENT_VALIDE la commission naît déjà au départ — désactiver l'approbation ne
     *  doit jamais en générer une seconde à la réception (onTransfertReceptionEffectuee() reste
     *  un no-op quel que soit ce paramètre). */
    public function test_approbation_non_requise_sans_effet_sous_chargement_valide(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::CHARGEMENT_VALIDE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnChargement(qteChargee: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert); // CHARGEMENT → TRANSIT : génère

        $transfert->lignes()->first()->update(['quantite_recue' => 100, 'ecart_type' => TypeEcartLogistique::CONFORME->value]);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert->fresh()); // TRANSIT → RECEPTION

        $this->assertEquals('accord', $transfert->validation_reception);
        $this->assertEquals(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfert->id)->count(),
            "Une seule commission, née au chargement — l'auto-approbation à la réception ne doit jamais en générer une seconde.",
        );
    }

    /**
     * Le hook d'auto-approbation vit UNIQUEMENT dans la transition TRANSIT → RECEPTION
     * (avancerStatut()) — il ne s'exécute qu'une fois, au moment de cette transition, jamais de
     * façon récurrente. Changer le paramètre organisation après coup ne doit donc jamais rejouer
     * ni corriger rétroactivement un transfert déjà en RECEPTION : le paramètre gouverne le
     * comportement AU MOMENT de la réception, pas un état courant réévalué en continu.
     */
    public function test_changer_le_parametre_napprouve_jamais_retroactivement_un_transfert_deja_en_attente(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        // Approbation obligatoire (défaut) au moment de la réception du transfert A.
        $transfertA = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfertA = TransfertLogistiqueService::avancerStatut($transfertA);

        $this->assertNull($transfertA->validation_reception, 'Transfert A doit rester en attente.');
        $this->assertNull($this->enveloppePour($transfertA));

        // L'organisation désactive ensuite l'approbation obligatoire.
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);

        // Transfert A ne doit JAMAIS être rattrapé rétroactivement par ce changement — aucun
        // job ne le rejoue, son état reste celui figé au moment de sa propre réception.
        $transfertA = $transfertA->fresh();
        $this->assertNull($transfertA->validation_reception, 'Le changement de paramètre ne doit pas approuver rétroactivement un transfert déjà en attente.');
        $this->assertNull($this->enveloppePour($transfertA), 'Aucune commission ne doit apparaître pour A tant qu\'aucune décision admin explicite n\'a eu lieu.');

        // Un NOUVEAU transfert (B), réceptionné après le changement, suit lui le nouveau réglage.
        $transfertB = $this->makeTransfertEnTransitPourReception(qteRecue: 50);
        $transfertB = TransfertLogistiqueService::avancerStatut($transfertB);

        $this->assertEquals('accord', $transfertB->validation_reception);
        $this->assertNotNull($this->enveloppePour($transfertB));

        // Transfert A reste approuvable manuellement à tout moment (le paramètre ne retire
        // aucune capacité admin, il ne fait que sauter l'étape quand elle n'est plus désirée) —
        // et cette approbation manuelle tardive ne doit générer qu'UNE seule commission pour A.
        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfertA), ['decision' => 'accord'])
            ->assertRedirect();

        $this->assertEquals(
            1,
            CommissionEnveloppe::where('source_type', TransfertLogistique::class)->where('source_id', $transfertA->id)->count(),
        );
    }

    /**
     * Preuve directe côté page (pas seulement côté modèle) que le bouton "Approuver la
     * réception" de Logistique/Show.vue reste disponible pour un transfert déjà en attente
     * après un changement de paramètre : son v-if (cf. resources/js/pages/Logistique/Show.vue)
     * ne lit QUE `can_valider_reception_admin`, `transfert.statut` et
     * `transfert.validation_reception` — jamais le paramètre organisation, qui n'est même pas
     * transmis à cette page (TransfertLogistiqueController::show()).
     */
    public function test_le_bouton_approuver_reste_disponible_pour_un_transfert_deja_en_attente_apres_changement_de_parametre(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfertA = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfertA = TransfertLogistiqueService::avancerStatut($transfertA);

        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);

        $this->actingAs($this->admin)
            ->get(route('logistique.show', $transfertA))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Logistique/Show')
                ->where('can_valider_reception_admin', true)
                ->where('transfert.statut', 'reception')
                ->where('transfert.validation_reception', null)
            );
    }

    // ── Dérogation par site (Site::approbationReceptionObligatoireEffective()) ──

    public function test_derogation_site_destination_a_priorite_sur_le_parametre_organisation(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, true);
        $this->configurerBareme(montantParPack: 200);

        $this->siteDest->update(['approbation_reception_logistique_obligatoire' => false]);

        $transfert = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertEquals('accord', $transfert->validation_reception, 'La dérogation du site destination (false) doit primer sur le paramètre organisation (true).');
        $this->assertNotNull($this->enveloppePour($transfert));
    }

    public function test_derogation_site_destination_impose_lapprobation_meme_si_lorganisation_ne_lexige_pas(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);
        $this->configurerBareme(montantParPack: 200);

        $this->siteDest->update(['approbation_reception_logistique_obligatoire' => true]);

        $transfert = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertNull($transfert->validation_reception, 'La dérogation du site destination (true) doit primer sur le paramètre organisation (false).');
        $this->assertNull($this->enveloppePour($transfert));
    }

    public function test_site_sans_derogation_herite_du_parametre_organisation(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, false);
        $this->configurerBareme(montantParPack: 200);

        // approbation_reception_logistique_obligatoire du site reste null (défaut) — jamais touché.
        $this->assertNull($this->siteDest->fresh()->approbation_reception_logistique_obligatoire);

        $transfert = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfert = TransfertLogistiqueService::avancerStatut($transfert);

        $this->assertEquals('accord', $transfert->validation_reception);
        $this->assertNotNull($this->enveloppePour($transfert));
    }

    /** Deux transferts simultanés vers deux sites différents suivent chacun leur propre règle. */
    public function test_deux_sites_destination_differents_appliquent_chacun_leur_propre_regle(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, true);
        $this->configurerBareme(montantParPack: 200);

        $siteDest2 = $this->makeSite('Site Destination 2');
        $siteDest2->update(['approbation_reception_logistique_obligatoire' => false]);
        // $this->siteDest reste sur le défaut organisation (true), aucune dérogation.

        $this->actingAs($this->admin);

        $transfertVersSiteDest = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $transfertVersSiteDest = TransfertLogistiqueService::avancerStatut($transfertVersSiteDest);

        $transfertVersSiteDest2 = $this->makeTransfertEnTransitPourReception(qteRecue: 100, siteDestination: $siteDest2);
        $transfertVersSiteDest2 = TransfertLogistiqueService::avancerStatut($transfertVersSiteDest2);

        $this->assertNull($transfertVersSiteDest->validation_reception, 'Site sans dérogation : hérite du défaut organisation (obligatoire).');
        $this->assertEquals('accord', $transfertVersSiteDest2->validation_reception, 'Site dérogataire : approbation non requise.');
    }

    /**
     * Symétrique de test_changer_le_parametre_napprouve_jamais_retroactivement... mais pour une
     * dérogation de SITE plutôt que le paramètre organisation : même garantie, même raison (le
     * hook ne s'exécute qu'une fois, à la transition TRANSIT → RECEPTION).
     */
    public function test_changer_la_derogation_dun_site_napprouve_jamais_retroactivement_un_transfert_deja_en_attente(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        Parametre::setApprobationReceptionLogistiqueObligatoire($this->org->id, true);
        $this->configurerBareme(montantParPack: 200);

        $transfertA = $this->makeTransfertEnTransitPourReception(qteRecue: 100);
        $this->actingAs($this->admin);
        $transfertA = TransfertLogistiqueService::avancerStatut($transfertA);

        $this->assertNull($transfertA->validation_reception);

        // Le site destination désactive ensuite l'approbation.
        $this->siteDest->update(['approbation_reception_logistique_obligatoire' => false]);

        $transfertA = $transfertA->fresh();
        $this->assertNull($transfertA->validation_reception, 'Le changement de dérogation du site ne doit pas approuver rétroactivement un transfert déjà en attente.');
        $this->assertNull($this->enveloppePour($transfertA));

        $transfertB = $this->makeTransfertEnTransitPourReception(qteRecue: 50);
        $transfertB = TransfertLogistiqueService::avancerStatut($transfertB);

        $this->assertEquals('accord', $transfertB->validation_reception, 'Un nouveau transfert vers ce site suit la nouvelle dérogation.');
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

    // ── Détail par livreur (onglet "Commission logistique" du transfert) ────────

    /**
     * L'onglet "Commission logistique" de la page transfert (TransfertLogistiqueController::
     * mapCommissionLivreursGeneriques()) doit exposer directement le détail par livreur — plus
     * besoin de renvoyer l'utilisateur vers Comptabilité > Commissions pour le voir. Part
     * unitaire = montant_unitaire_snapshot (partage GNF fixe par catégorie, cf. configurerBareme() :
     * 60 % / 40 % de 200 = 120/80), montant = ce que chaque livreur a réellement gagné sur ce
     * transfert (100 packs reçus).
     */
    public function test_reception_effectuee_expose_le_detail_par_livreur_sur_la_page_transfert(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);
        $this->configurerBareme(montantParPack: 200);

        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $response = $this->actingAs($this->admin)
            ->get(route('logistique.show', $transfert))
            ->assertOk();

        $props = $response->viewData('page')['props']['transfert'];

        $this->assertTrue($props['commission_generique_genere']);
        $this->assertCount(2, $props['commission_generique_livreurs']);

        $parLivreur = collect($props['commission_generique_livreurs'])->keyBy('id');

        $this->assertEquals(120, $parLivreur[$this->livreur1->id]['montant_unitaire']);
        $this->assertEquals(12000.0, $parLivreur[$this->livreur1->id]['montant']); // 100 × 120
        $this->assertEquals(80, $parLivreur[$this->livreur2->id]['montant_unitaire']);
        $this->assertEquals(8000.0, $parLivreur[$this->livreur2->id]['montant']); // 100 × 80

        // Le seul barème configuré dans ce test est celui de l'équipe de livraison (livreurs) :
        // le total du détail livreurs doit donc reconstituer exactement le total global de
        // l'enveloppe, sans commission perdue ni inventée.
        $sommeLivreurs = collect($props['commission_generique_livreurs'])->sum('montant');
        $this->assertEquals($props['commission_generique_montant_total'], $sommeLivreurs);
    }

    /**
     * Une commission générique peut exister pour une autre cible (ici : site) sans qu'aucun
     * barème Livreur ne soit configuré pour l'équipe — décision AMOA #4, "absence de règle = 0,
     * jamais une erreur". Le tableau "Détail par livreur" doit alors rester vide plutôt que
     * d'inventer un bénéficiaire, et l'onglet doit l'expliquer clairement (cf. Show.vue) au lieu
     * d'afficher un tableau vide.
     */
    public function test_transfert_sans_bareme_livreur_expose_une_liste_livreurs_vide(): void
    {
        Parametre::setDeclencheurCommissionLogistique($this->org->id, DeclencheurCommissionLogistique::RECEPTION_EFFECTUEE);

        // Barème SITE uniquement — jamais EQUIPE_LIVRAISON.
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $processus->update(['statut' => CommissionActivationStatut::ACTIF->value]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Site — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 50,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $transfert = $this->makeTransfertEnReception(qteRecue: 100);

        $this->actingAs($this->admin)
            ->post($this->urlValidation($transfert), ['decision' => 'accord'])
            ->assertRedirect();

        $enveloppe = $this->enveloppePour($transfert);
        $this->assertNotNull($enveloppe, 'La cible site doit produire une commission même sans barème livreur.');
        $this->assertTrue(
            CommissionEnveloppePart::where('enveloppe_id', $enveloppe->id)
                ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
                ->doesntExist()
        );

        $props = $this->actingAs($this->admin)
            ->get(route('logistique.show', $transfert))
            ->assertOk()
            ->viewData('page')['props']['transfert'];

        $this->assertTrue($props['commission_generique_genere']);
        $this->assertSame([], $props['commission_generique_livreurs']);
    }
}
