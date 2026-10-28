<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPeriodePaiement;
use App\Enums\StatutTransfert;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Parametre;
use App\Models\Parrain;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\PartageConformiteVehiculesService;
use App\Services\Commission\PartageLivraisonVersionService;
use App\Services\Commission\PartageParrainService;
use App\Services\Commission\PartParrainService;
use App\Services\Commission\ReconfigurationPartagesService;
use App\Services\PeriodeCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Part du parrain prise sur l'enveloppe Livreur (décisions du 20/10/2026, cf.
 * docs/parrainage-vehicule.md) :
 *  - le parrain est une ligne du partage d'équipe : sa part sort de l'enveloppe, le total reste
 *    quantité × barème ;
 *  - rémunéré sur la vente, le transfert grossiste et la distribution — jamais sur un transfert
 *    logistique entre sites ; la distribution d'un véhicule parrainé a donc son propre partage,
 *    avec le barème Logistique ;
 *  - aucune version de partage ne peut omettre la ligne du parrain (garde-fou serveur) ;
 *  - ajouter un parrain crée sa ligne à 0 GNF, remplacer ou retirer un parrain qui a une part
 *    positive est refusé ;
 *  - une équipe parrainée n'est jamais alignée automatiquement lors d'un changement de barème.
 */
class ParrainPartageCommissionTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private Produit $produit;

    private Vehicule $vehicule;

    private EquipeLivraison $equipe;

    private Livreur $chauffeur;

    private Livreur $convoyeur;

    private CommissionProcessus $vente;

    private CommissionProcessus $logistique;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');

        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'logistique.read', 'logistique.create', 'logistique.update',
            'vehicules.read', 'vehicules.update',
            'equipes-livraison.read', 'equipes-livraison.create', 'equipes-livraison.update',
        ]);
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = $this->user->sites()->firstOrFail();
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet d\'eau', 'statut' => 'actif']);
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack de 25 Sachets', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        ['vehicule' => $this->vehicule, 'equipe' => $this->equipe, 'livreurs' => [$this->chauffeur, $this->convoyeur]] = $this->vehiculeAvecEquipe(2);

        $this->vente = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $this->logistique = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);

        $this->regle($this->vente, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 1000);
        $this->regle($this->logistique, CommissionCibleType::CODE_EQUIPE_LIVRAISON, 1000);
        $this->partage($this->equipe, $this->vente, [$this->chauffeur->id => 600, $this->convoyeur->id => 400]);
        $this->partage($this->equipe, $this->logistique, [$this->chauffeur->id => 600, $this->convoyeur->id => 400]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreurs: list<Livreur>} */
    private function vehiculeAvecEquipe(int $nbLivreurs): array
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'livraison_vente' => true,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe '.$vehicule->nom_vehicule,
            'is_active' => true,
        ]);

        $livreurs = [];
        for ($i = 0; $i < $nbLivreurs; $i++) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
            EquipeLivreur::create([
                'equipe_id' => $equipe->id,
                'livreur_id' => $livreur->id,
                'role' => $i === 0 ? 'chauffeur' : 'convoyeur',
                'ordre' => $i,
            ]);
            $livreurs[] = $livreur;
        }

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreurs' => $livreurs];
    }

    private function regle(CommissionProcessus $processus, string $cible, int $montant): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => "{$cible} {$processus->code}",
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => $cible,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => '2026-10-01',
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @param  array<string, int>  $montants  livreur_id => GNF/pack */
    private function partage(EquipeLivraison $equipe, CommissionProcessus $processus, array $montants): void
    {
        foreach ($montants as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id,
                'processus_id' => $processus->id,
                'categorie_id' => $this->categorie->id,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => '2026-10-01',
            ]);
        }
    }

    /** Ajoute un parrain au véhicule par le même chemin que StoreParrainController. */
    private function ajouterParrain(Vehicule $vehicule): Parrain
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);

        DB::transaction(function () use ($vehicule, $parrain) {
            $ancien = $vehicule->parrain_id;
            $vehicule->update(['parrain_id' => $parrain->id]);
            PartageParrainService::appliquerChangementDeParrain($vehicule, $ancien);
        });

        return $parrain;
    }

    private function repartir(CommissionProcessus $processus, int $chauffeur, int $convoyeur, ?int $parrain): void
    {
        PartageLivraisonVersionService::versionner(
            $this->equipe->id,
            $processus->id,
            $this->categorie->id,
            [$this->chauffeur->id => $chauffeur, $this->convoyeur->id => $convoyeur],
            Carbon::today(),
            $parrain,
        );
    }

    private function vendre(NatureOperation $nature, int $qte): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'nature_operation' => $nature->value,
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => true,
            'total_commande' => $qte * 2000,
        ]);
        $variante = $this->produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => $qte * 2000,
        ]);
        $this->seedVarianteStockSuffisant($variante, $this->site);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_chargee' => $qte, 'type_ecart' => 'conforme'],
        ]);

        if ($nature === NatureOperation::DISTRIBUTION_CLIENT) {
            CommandeVenteService::validerReception($commande->fresh(), [
                ['id' => $ligne->id, 'quantite_livree' => $qte, 'type_ecart_reception' => 'conforme'],
            ]);
        }

        return $commande->fresh();
    }

    private function transferer(int $qte): TransfertLogistique
    {
        $destination = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt B', 'type' => 'agence', 'localisation' => 'Kindia']);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $destination->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => StatutTransfert::CHARGEMENT,
            'created_by' => $this->user->id,
        ]);
        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qte,
            'quantite_chargee' => $qte,
        ]);

        CommissionEnveloppeGenerator::genererPourTransfertLogistique($transfert->fresh(), 'quantite_chargee');

        return $transfert;
    }

    /** @return array<string, float> "type:id" => montant, parts de l'enveloppe équipe de livraison */
    private function partsEquipe(string $sourceType, string $sourceId): array
    {
        $enveloppe = CommissionEnveloppe::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->firstOrFail();

        return $enveloppe->parts()->get()
            ->mapWithKeys(fn (CommissionEnveloppePart $p) => ["{$p->beneficiaire_type}:{$p->beneficiaire_id}" => (float) $p->montant_net])
            ->all();
    }

    /** @return array<string, int> "livreur:id" ou "parrain:id" => GNF/pack, version active */
    private function partageActif(CommissionProcessus $processus, ?EquipeLivraison $equipe = null): array
    {
        return EquipeLivraisonPartageCategorie::where('equipe_id', ($equipe ?? $this->equipe)->id)
            ->where('processus_id', $processus->id)
            ->where('categorie_id', $this->categorie->id)
            ->actifA(Carbon::today())
            ->get()
            ->mapWithKeys(fn (EquipeLivraisonPartageCategorie $p) => ["{$p->beneficiaire_type}:{$p->beneficiaire_id}" => (int) $p->montant_unitaire])
            ->all();
    }

    // ── Génération ────────────────────────────────────────────────────────────

    public function test_sans_parrain_la_repartition_est_inchangee(): void
    {
        $commande = $this->vendre(NatureOperation::VENTE_STANDARD, 100);

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 60000.0,
            "livreur:{$this->convoyeur->id}" => 40000.0,
        ], $this->partsEquipe(CommandeVente::class, $commande->id));
    }

    public function test_la_part_du_parrain_est_prise_sur_lenveloppe_sans_augmenter_le_total(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);

        $commande = $this->vendre(NatureOperation::VENTE_STANDARD, 100);

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $this->partsEquipe(CommandeVente::class, $commande->id));

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->firstOrFail();
        $this->assertEquals(100000.0, (float) $enveloppe->montant_total);

        $partParrain = $enveloppe->parts()->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)->firstOrFail();
        $this->assertNotNull($partParrain->validated_at, 'La part du parrain est validée d\'office.');
        $this->assertNull($partParrain->validated_by);
        $this->assertSame($parrain->id, $partParrain->resoudreBeneficiaire()?->id);
    }

    public function test_un_transfert_logistique_remunere_le_parrain_sur_lenveloppe_livreur(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->logistique, 500, 300, 200);

        $transfert = $this->transferer(100);

        // PARRAIN-025 : part du parrain du véhicule du transfert, prise sur l'enveloppe (100 000).
        $parts = $this->partsEquipe(TransfertLogistique::class, $transfert->id);
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $parts);
        $this->assertEquals(100000.0, array_sum($parts), 'Le total de l\'enveloppe ne change pas.');
        $this->assertSame(1, CommissionEnveloppe::where('source_id', $transfert->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->count(), 'Une seule enveloppe, aucun doublon.');
    }

    public function test_un_transfert_avec_une_part_nulle_ne_paie_rien_au_parrain(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);

        $transfert = $this->transferer(100);

        // Aucune part à 0 GNF : ni ligne de fiche, ni réouverture de la période Parrains.
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 60000.0,
            "livreur:{$this->convoyeur->id}" => 40000.0,
        ], $this->partsEquipe(TransfertLogistique::class, $transfert->id));
        $this->assertCount(0, $this->fichesParrain($parrain));
    }

    public function test_une_part_nulle_du_parrain_ne_rouvre_pas_sa_periode_validee(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->vendre(NatureOperation::VENTE_STANDARD, 100);
        $periode = PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->sole();
        $avant = [$periode->fresh()->statut, $periode->fresh()->calcul_hash, $this->fichesParrain($parrain)->sole()->lignes()->count()];

        $this->transferer(100);   // part du parrain à 0 GNF sur les transferts

        $this->assertSame($avant, [$periode->fresh()->statut, $periode->fresh()->calcul_hash, $this->fichesParrain($parrain)->sole()->lignes()->count()], 'Période et fiche du parrain inchangées.');
    }

    public function test_un_transfert_sans_parrain_garde_le_partage_des_livreurs(): void
    {
        $transfert = $this->transferer(100);

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 60000.0,
            "livreur:{$this->convoyeur->id}" => 40000.0,
        ], $this->partsEquipe(TransfertLogistique::class, $transfert->id));
    }

    public function test_la_distribution_a_son_propre_partage_avec_le_parrain_et_le_bareme_logistique(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $distribution = CommissionProcessus::where('organization_id', $this->org->id)
            ->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
            ->firstOrFail();
        $this->repartir($distribution, 500, 300, 200);

        $commande = $this->vendre(NatureOperation::DISTRIBUTION_CLIENT, 100);
        $transfert = $this->transferer(100);

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $this->partsEquipe(CommandeVente::class, $commande->id));
        $this->assertSame(
            $distribution->id,
            CommissionEnveloppe::where('source_id', $commande->id)->value('processus_id'),
            'L\'enveloppe garde l\'identité distribution_client.',
        );
        // Le partage Logistique porte aussi le parrain (PARRAIN-025), ici à 0 GNF : aucune part.
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 60000.0,
            "livreur:{$this->convoyeur->id}" => 40000.0,
        ], $this->partsEquipe(TransfertLogistique::class, $transfert->id));
    }

    public function test_une_distribution_sans_parrain_garde_le_partage_logistique(): void
    {
        $commande = $this->vendre(NatureOperation::DISTRIBUTION_CLIENT, 10);

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 6000.0,
            "livreur:{$this->convoyeur->id}" => 4000.0,
        ], $this->partsEquipe(CommandeVente::class, $commande->id));
    }

    public function test_un_meme_parrain_est_remunere_sur_chacun_de_ses_vehicules(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);

        ['vehicule' => $autre, 'equipe' => $equipeAutre, 'livreurs' => [$seul]] = $this->vehiculeAvecEquipe(1);
        $this->partage($equipeAutre, $this->vente, [$seul->id => 1000]);
        DB::transaction(function () use ($autre, $parrain) {
            $autre->update(['parrain_id' => $parrain->id]);
            PartageParrainService::appliquerChangementDeParrain($autre, null);
        });
        PartageLivraisonVersionService::versionner($equipeAutre->id, $this->vente->id, $this->categorie->id, [$seul->id => 900], Carbon::today(), 100);

        $this->vendre(NatureOperation::VENTE_STANDARD, 10);
        $this->vehicule = $autre;
        $this->vendre(NatureOperation::VENTE_STANDARD, 10);

        $montants = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)
            ->where('beneficiaire_id', $parrain->id)
            ->pluck('montant_net')
            ->map(fn ($m) => (float) $m)
            ->sort()
            ->values()
            ->all();
        $this->assertSame([1000.0, 2000.0], $montants);
    }

    // ── Garde-fou serveur ─────────────────────────────────────────────────────

    public function test_une_version_de_partage_sans_ligne_parrain_est_refusee(): void
    {
        $this->ajouterParrain($this->vehicule);

        $this->expectException(ValidationException::class);
        $this->repartir($this->vente, 600, 400, null);
    }

    public function test_une_version_logistique_sans_la_part_du_parrain_est_refusee(): void
    {
        $this->ajouterParrain($this->vehicule);

        $this->expectException(ValidationException::class);
        $this->repartir($this->logistique, 600, 400, null);
    }

    public function test_une_ligne_parrain_est_refusee_sans_parrain(): void
    {
        $this->expectException(ValidationException::class);
        $this->repartir($this->vente, 500, 300, 200);
    }

    public function test_le_controle_de_commande_exige_la_ligne_du_parrain(): void
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        // Parrain posé sans passer par le service : partage resté sans sa ligne.
        $this->vehicule->update(['parrain_id' => $parrain->id]);

        $nonConformites = CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id,
            $this->equipe->fresh(),
            CommissionProcessus::CODE_VENTE,
            $this->vehicule->type_vehicule_id,
            [$this->categorie->id],
            Carbon::today(),
        );

        $this->assertCount(1, $nonConformites);
        $this->assertStringContainsString('Parrain', implode(', ', $nonConformites->first()['membres_manquants']));
    }

    public function test_une_ligne_parrain_obsolete_rend_le_partage_non_conforme(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $nouveau = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $this->vehicule->update(['parrain_id' => $nouveau->id]);

        $nonConformites = CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id,
            $this->equipe->fresh(),
            CommissionProcessus::CODE_VENTE,
            $this->vehicule->type_vehicule_id,
            [$this->categorie->id],
            Carbon::today(),
        );

        $this->assertCount(1, $nonConformites);
    }

    /** @return array<string, mixed> enregistrement de l'équipe (modale Équipe), processus Vente */
    private function payloadEquipe(?int $partParrain, int $chauffeur = 500, int $convoyeur = 300): array
    {
        $partage = [
            'categorie_id' => $this->categorie->id,
            'parts' => [
                ['membre_ordre' => 0, 'montant_unitaire' => $chauffeur],
                ['membre_ordre' => 1, 'montant_unitaire' => $convoyeur],
            ],
        ];
        if ($partParrain !== null) {
            $partage['part_parrain'] = $partParrain;
        }

        return [
            'vehicule_id' => $this->vehicule->id,
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'membres' => [
                ['livreur_id' => $this->chauffeur->id, 'nom_complet' => $this->chauffeur->nom_complet, 'telephone' => $this->chauffeur->telephone, 'role' => 'chauffeur', 'ordre' => 0],
                ['livreur_id' => $this->convoyeur->id, 'nom_complet' => $this->convoyeur->nom_complet, 'telephone' => $this->convoyeur->telephone, 'role' => 'convoyeur', 'ordre' => 1],
            ],
            'partages_categorie' => [$partage],
        ];
    }

    public function test_lenregistrement_de_lequipe_exige_la_part_du_parrain(): void
    {
        $this->ajouterParrain($this->vehicule);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $this->equipe), $this->payloadEquipe(null, 600, 400))
            ->assertStatus(422);

        $this->assertEquals(0, $this->partageActif($this->vente)['parrain:'.$this->vehicule->fresh()->parrain_id]);
    }

    public function test_lenregistrement_de_lequipe_versionne_la_part_du_parrain(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $this->equipe), $this->payloadEquipe(200))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vehicules.show', $this->vehicule));

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 500,
            "livreur:{$this->convoyeur->id}" => 300,
            "parrain:{$parrain->id}" => 200,
        ], $this->partageActif($this->vente));
    }

    public function test_lenregistrement_de_lequipe_refuse_une_part_de_parrain_sans_parrain(): void
    {
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $this->equipe), $this->payloadEquipe(200))
            ->assertStatus(422);
    }

    // ── Ajout, remplacement, retrait du parrain ───────────────────────────────

    public function test_ajouter_un_parrain_cree_ses_lignes_a_zero_sans_changer_aucun_montant(): void
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $this->vehicule), ['personne_id' => $parrain->personne_id])
            ->assertRedirect(route('vehicules.show', $this->vehicule));

        $distribution = CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)->firstOrFail();
        $attendu = [
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
            "parrain:{$parrain->id}" => 0,
        ];
        $this->assertEquals($attendu, $this->partageActif($this->vente));
        $this->assertEquals($attendu, $this->partageActif($distribution));
        $this->assertEquals($attendu, $this->partageActif($this->logistique));

        $this->assertEmpty(CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id, $this->equipe->fresh(), CommissionProcessus::CODE_VENTE, null, [$this->categorie->id], Carbon::today(),
        ), 'Une commande reste possible : la ligne à 0 GNF suffit.');
    }

    public function test_remplacer_un_parrain_sans_part_lui_transfere_sa_ligne(): void
    {
        $this->ajouterParrain($this->vehicule);
        $nouveau = Parrain::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $this->vehicule), ['personne_id' => $nouveau->personne_id])
            ->assertSessionHasNoErrors();

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
            "parrain:{$nouveau->id}" => 0,
        ], $this->partageActif($this->vente));
    }

    /** Le convoyeur quitte l'équipe : le chauffeur seul porte le partage Vente (800 + parrain 200). */
    private function unSeulLivreurAvecParrain(): void
    {
        EquipeLivreur::where('livreur_id', $this->convoyeur->id)->delete();
        PartageLivraisonVersionService::versionner(
            $this->equipe->id,
            $this->vente->id,
            $this->categorie->id,
            [$this->chauffeur->id => 800],
            Carbon::today(),
            200,
        );
    }

    private function statutVente(): string
    {
        return PartageConformiteVehiculesService::statuts($this->org->id, collect([$this->vehicule->fresh()]))[$this->vehicule->id][CommissionProcessus::CODE_VENTE];
    }

    public function test_remplacer_un_parrain_qui_a_une_part_le_nouveau_demarre_a_zero_et_le_partage_passe_a_faire(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $nouveau = Parrain::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $this->vehicule), ['personne_id' => $nouveau->personne_id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vehicules.show', $this->vehicule))
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'onglet Équipe'));

        $this->assertSame($nouveau->id, $this->vehicule->fresh()->parrain_id);
        // Jamais de transfert de la part de l'ancien : le nouveau démarre à 0, les livreurs gardent leurs montants.
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 500,
            "livreur:{$this->convoyeur->id}" => 300,
            "parrain:{$nouveau->id}" => 0,
        ], $this->partageActif($this->vente));
        $this->assertSame('a_faire', $this->statutVente());
    }

    public function test_remplacer_un_parrain_dont_la_part_attendait_la_repartition_ne_laisse_rien_a_refaire(): void
    {
        $this->ajouterParrain($this->vehicule);
        // Part saisie dans l'onglet Parrain, équipe pas encore répartie : 600 + 400 + 200 (« À faire »).
        $this->repartir($this->vente, 600, 400, 200);
        $nouveau = Parrain::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $this->vehicule), ['personne_id' => $nouveau->personne_id])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
            "parrain:{$nouveau->id}" => 0,
        ], $this->partageActif($this->vente));
        $this->assertSame('a_repartir', $this->statutVente(), 'Complet : seule la part nulle du nouveau parrain reste à décider.');
    }

    public function test_remplacer_un_parrain_avec_un_seul_livreur_lui_rend_la_part_de_lancien(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->unSeulLivreurAvecParrain();
        $nouveau = Parrain::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('vehicules.parrain.store', $this->vehicule), ['personne_id' => $nouveau->personne_id])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 1000,
            "parrain:{$nouveau->id}" => 0,
        ], $this->partageActif($this->vente));
        // Complet, mais la part nulle du nouveau parrain reste à décider (PARRAIN-022).
        $this->assertSame('a_repartir', $this->statutVente());
    }

    public function test_changer_un_parrain_qui_a_une_part_exige_le_droit_de_modifier_lequipe(): void
    {
        $ancien = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $nouveau = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $sansDroitEquipe = $this->makeUserWithPermissions($this->org, ['vehicules.read', 'vehicules.update']);
        $sansDroitEquipe->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansDroitEquipe)
            ->post(route('vehicules.parrain.store', $this->vehicule), ['personne_id' => $nouveau->personne_id])
            ->assertForbidden();
        $this->actingAs($sansDroitEquipe)
            ->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertForbidden();

        $this->assertSame($ancien->id, $this->vehicule->fresh()->parrain_id);
    }

    public function test_retirer_un_parrain_qui_a_une_part_laisse_le_partage_a_faire_avec_plusieurs_livreurs(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);

        $this->actingAs($this->user)
            ->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('vehicules.show', $this->vehicule))
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'onglet Équipe'));

        $this->assertNull($this->vehicule->fresh()->parrain_id);
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 500,
            "livreur:{$this->convoyeur->id}" => 300,
        ], $this->partageActif($this->vente));
        $this->assertSame('a_faire', $this->statutVente());
    }

    public function test_retirer_un_parrain_avec_un_seul_livreur_lui_rend_toute_lenveloppe(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->unSeulLivreurAvecParrain();

        $this->actingAs($this->user)
            ->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->assertEquals(["livreur:{$this->chauffeur->id}" => 1000], $this->partageActif($this->vente));
        $this->assertSame('fait', $this->statutVente());
    }

    public function test_les_repartitions_a_refaire_sont_exposees_pour_le_parcours_combine(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);

        $groupes = $this->actingAs($this->user)
            ->getJson(route('vehicules.parrain.repartitions', $this->vehicule))
            ->assertOk()
            ->json('groupes');

        $this->assertCount(1, $groupes);
        $this->assertSame(CommissionProcessus::CODE_VENTE, $groupes[0]['processus_code']);
        $this->assertSame(1000, $groupes[0]['bareme']);
        $this->assertSame(200, $groupes[0]['part_parrain_actuelle']);
        $this->assertSame([500, 300], array_column($groupes[0]['membres'], 'montant_actuel'));
    }

    public function test_la_fiche_vehicule_expose_la_part_du_parrain_et_longlet_distribution(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $this->vehicule))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parrain_partage.id', $parrain->id)
                ->where('parrain_remuneration', fn ($lignes) => collect($lignes)->contains(fn ($l) => $l['processus_code'] === CommissionProcessus::CODE_VENTE && $l['montant'] === 200))
                ->where('processus_options', fn ($options) => collect($options)->pluck('value')->contains(CommissionProcessus::CODE_DISTRIBUTION_CLIENT))
                ->where('equipe.partages_categorie.0.part_parrain', 200)
            );

        $this->actingAs($this->user)
            ->get(route('vehicules.show', ['vehicule' => $this->vehicule, 'processus' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('parrain_partage.id', $parrain->id)
                ->where('parrain_remuneration', fn ($lignes) => collect($lignes)->contains(fn ($l) => $l['processus_code'] === CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)));
    }

    /** Juge commun des ventes : non-conformités du processus, à la date du jour. */
    private function refusOperations(string $processusCode): int
    {
        return CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id,
            $this->equipe->fresh(),
            $processusCode,
            $this->vehicule->fresh()->type_vehicule_id,
            [$this->categorie->id],
            Carbon::today(),
        )->count();
    }

    public function test_la_premiere_commission_du_parrain_cree_sa_periode_et_sa_fiche(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->assertSame(0, PaiementPeriode::where('organization_id', $this->org->id)->where('type', TypePeriodePaiement::PARRAIN->value)->count(), 'Aucune période Parrains au départ : aucun écran de période ouvert.');

        $this->vendre(NatureOperation::VENTE_STANDARD, 100);

        // PARRAIN-017 : la fiche apparaît dans « Paiements parrains » sans action manuelle.
        $fiche = PaiementFiche::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)
            ->where('beneficiaire_id', $parrain->id)
            ->first();
        $this->assertNotNull($fiche, 'La fiche du parrain doit être créée dès la génération de sa commission.');
        $this->assertEquals(20000.0, (float) $fiche->montant_net);
    }

    public function test_les_commissions_suivantes_du_parrain_ne_creent_ni_periode_ni_fiche_en_double(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);

        $this->vendre(NatureOperation::VENTE_STANDARD, 100);
        $this->vendre(NatureOperation::VENTE_STANDARD, 50);

        $this->assertSame(1, PaiementPeriode::where('organization_id', $this->org->id)->where('type', TypePeriodePaiement::PARRAIN->value)->count());
        $fiches = PaiementFiche::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)->where('beneficiaire_id', $parrain->id)->get();
        $this->assertCount(1, $fiches, 'Une seule fiche par parrain et par période.');
        $this->assertEquals(30000.0, (float) $fiches->first()->montant_net, '20 000 + 10 000, chaque vente une seule fois.');
        $this->assertSame(2, $fiches->first()->lignes()->count());
    }

    // ── Bénéficiaire affiché sur les fiches parrain ───────────────────────────

    private function comptable()
    {
        $comptable = $this->makeUserWithPermissions($this->org, ['comptabilite.read']);
        $comptable->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $comptable;
    }

    public function test_le_calcul_ne_prend_jamais_lidentifiant_comme_nom_du_parrain(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $parrain->personne->update(['nom_complet' => '', 'prenom' => null, 'nom' => null, 'surnom' => null]);
        $this->repartir($this->vente, 500, 300, 200);

        $this->vendre(NatureOperation::VENTE_STANDARD, 100);

        $this->assertSame('Parrain sans nom', $this->fichesParrain($parrain)->sole()->beneficiaire_nom);
    }

    public function test_la_liste_des_fiches_parrain_affiche_son_nom_et_son_telephone_jamais_son_identifiant(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->vendre(NatureOperation::VENTE_STANDARD, 100);
        // Fiche calculée avant le correctif, pour une personne sans nom : l'identifiant enregistré.
        $this->fichesParrain($parrain)->sole()->update(['beneficiaire_nom' => $parrain->id]);
        $parrain->personne->update(['nom_complet' => '', 'prenom' => null, 'nom' => null, 'surnom' => null]);
        $telephone = $parrain->personne->telephone;

        $this->actingAs($this->comptable())->get(route('comptabilite.fiches.parrains'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('fiches.data.0.beneficiaire_nom', 'Parrain sans nom')
                ->where('fiches.data.0.beneficiaire_telephone', $telephone));

        // Le nom saisi ensuite sur la personne est repris, sans recalcul de la fiche.
        $parrain->personne->update(['nom_complet' => 'Ousmane SIDIBÉ']);
        $fiche = $this->fichesParrain($parrain)->sole();
        $this->actingAs($this->comptable())->get(route('comptabilite.fiches.parrains'))
            ->assertInertia(fn ($page) => $page->where('fiches.data.0.beneficiaire_nom', 'Ousmane SIDIBÉ'));
        $this->actingAs($this->comptable())->get(route('comptabilite.fiches.show', $fiche))
            ->assertInertia(fn ($page) => $page
                ->where('fiche.beneficiaire_nom', 'Ousmane SIDIBÉ')
                ->where('fiche.beneficiaire_telephone', $telephone));
    }

    // ── Rattrapage des fiches parrain manquantes (commissions:parrains-rattrapage) ──

    /** État d'avant le correctif du 25/10 : la commission existe, sa période et sa fiche non. */
    private function supprimerFichesEtPeriodesParrain(): void
    {
        foreach (PaiementFiche::withTrashed()->where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)->get() as $fiche) {
            $fiche->lignes()->delete();
            $fiche->forceDelete();
        }
        PaiementPeriode::withTrashed()->where('organization_id', $this->org->id)
            ->where('type', TypePeriodePaiement::PARRAIN->value)->forceDelete();
    }

    private function fichesParrain(Parrain $parrain)
    {
        return PaiementFiche::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)
            ->where('beneficiaire_id', $parrain->id)->get();
    }

    public function test_le_rattrapage_simule_sans_ecrire_puis_cree_la_periode_et_la_fiche_une_seule_fois(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->vendre(NatureOperation::VENTE_STANDARD, 100);
        $this->supprimerFichesEtPeriodesParrain();
        $enveloppes = CommissionEnveloppe::count();
        $partParrain = CommissionEnveloppePart::where('beneficiaire_type', CommissionEnveloppePart::TYPE_PARRAIN)->sole();

        // Simulation : rapport, aucune écriture.
        $this->artisan('commissions:parrains-rattrapage')
            ->expectsOutputToContain('Simulation')
            ->assertExitCode(0);
        $this->assertSame(0, PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->count());
        $this->assertCount(0, $this->fichesParrain($parrain));

        // Application : période et fiche créées, rien de régénéré.
        $this->artisan('commissions:parrains-rattrapage', ['--appliquer' => true])
            ->expectsOutputToContain('régularisée')
            ->assertExitCode(0);
        $this->assertSame(1, PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->count());
        $fiches = $this->fichesParrain($parrain);
        $this->assertCount(1, $fiches);
        $this->assertEquals(20000.0, (float) $fiches->first()->montant_net);
        $this->assertSame($enveloppes, CommissionEnveloppe::count(), 'Aucune commission régénérée.');
        $this->assertTrue(CommissionEnveloppePart::whereKey($partParrain->id)->exists(), 'La même part, jamais recréée.');

        // Idempotence : une seconde exécution ne trouve plus rien.
        $this->artisan('commissions:parrains-rattrapage', ['--appliquer' => true])
            ->expectsOutputToContain('rien à régulariser')
            ->assertExitCode(0);
        $this->assertCount(1, $this->fichesParrain($parrain));
        $this->assertSame(1, PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->count());
    }

    public function test_le_rattrapage_ne_touche_pas_une_fiche_deja_payee_dune_autre_periode(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->vendre(NatureOperation::VENTE_STANDARD, 100);   // P1 : fiche payée
        $payee = $this->fichesParrain($parrain)->sole();
        $payee->update(['montant_paye' => 20000]);

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->vendre(NatureOperation::VENTE_STANDARD, 50);    // P2 : période et fiche perdues
        $p2 = PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->whereDate('date_debut', '2026-10-16')->sole();
        foreach ($p2->fiches()->get() as $fiche) {
            $fiche->lignes()->delete();
            $fiche->forceDelete();
        }
        $p2->forceDelete();
        $etatPayee = fn () => [...$payee->fresh()->only(['montant_net', 'montant_paye', 'statut']), 'maj' => $payee->fresh()->updated_at->toDateTimeString()];
        $avant = $etatPayee();

        $this->artisan('commissions:parrains-rattrapage', ['--appliquer' => true])->assertExitCode(0);

        $this->assertSame($avant, $etatPayee(), 'Fiche payée intacte.');
        $this->assertSame(1, $payee->fresh()->lignes()->count());
        $nouvelle = $this->fichesParrain($parrain)->where('id', '!=', $payee->id)->sole();
        $this->assertEquals(10000.0, (float) $nouvelle->montant_net);
    }

    public function test_le_rattrapage_ignore_une_periode_cloturee(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->vendre(NatureOperation::VENTE_STANDARD, 100);
        $periode = PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->sole();
        foreach ($periode->fiches()->get() as $fiche) {
            $fiche->lignes()->delete();
            $fiche->forceDelete();
        }
        $periode->update(['statut' => StatutPeriodePaiement::CLOTUREE->value]);

        $this->artisan('commissions:parrains-rattrapage', ['--appliquer' => true])
            ->expectsOutputToContain('clôturée')
            ->assertExitCode(0);

        $this->assertSame(StatutPeriodePaiement::CLOTUREE, $periode->fresh()->statut);
        $this->assertCount(0, $this->fichesParrain($parrain));
    }

    public function test_un_echec_du_rattrapage_annule_sa_periode_et_est_rapporte(): void
    {
        $this->ajouterParrain($this->vehicule);
        $this->repartir($this->vente, 500, 300, 200);
        $this->vendre(NatureOperation::VENTE_STANDARD, 100);
        $this->supprimerFichesEtPeriodesParrain();
        $this->mock(PeriodeCalculatorService::class, fn ($m) => $m->shouldReceive('traiterPeriodesPourDates')
            ->andThrow(new \RuntimeException('période comptable verrouillée')));

        $this->artisan('commissions:parrains-rattrapage', ['--appliquer' => true])
            ->expectsOutputToContain('période comptable verrouillée')
            ->assertExitCode(1);

        $this->assertSame(0, PaiementPeriode::where('type', TypePeriodePaiement::PARRAIN->value)->count(), 'Période annulée avec le reste.');
    }

    // ── Reprise PARRAIN-025 : véhicules déjà parrainés ───────────────────────

    private function repriseLogistique(): void
    {
        (require database_path('migrations/2026_10_25_220000_backfill_parrain_partages_logistique.php'))->up();
    }

    public function test_la_reprise_ajoute_au_partage_logistique_une_part_nulle_confirmee_sans_toucher_au_reste(): void
    {
        // Véhicule parrainé avant PARRAIN-025 : son partage Logistique ne porte pas le parrain.
        $parrain = $this->ajouterParrain($this->vehicule);
        EquipeLivraisonPartageCategorie::where('processus_id', $this->logistique->id)->whereNotNull('parrain_id')->delete();
        EquipeLivraisonPartageCategorie::where('processus_id', $this->logistique->id)->update(['effective_to' => null]);
        $venteAvant = $this->partageActif($this->vente);
        $commande = $this->vendre(NatureOperation::VENTE_STANDARD, 10);
        $partsAvant = $this->partsEquipe(CommandeVente::class, $commande->id);

        $this->repriseLogistique();
        $this->repriseLogistique(); // rejouable

        $lignes = EquipeLivraisonPartageCategorie::where('equipe_id', $this->equipe->id)
            ->where('processus_id', $this->logistique->id)->whereNull('effective_to')->get();
        $this->assertCount(3, $lignes, 'Une seule nouvelle version : 2 livreurs + le parrain, sans doublon.');
        $ligneParrain = $lignes->firstWhere('parrain_id', $parrain->id);
        $this->assertSame(0, (int) $ligneParrain->montant_unitaire);
        $this->assertNotNull($ligneParrain->part_parrain_confirmee_at, 'Part nulle confirmée : aucun transfert bloqué.');
        $this->assertSame(Carbon::today()->toDateString(), $ligneParrain->effective_from->toDateString(), 'Date de début = activation de la règle.');
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
            "parrain:{$parrain->id}" => 0,
        ], $this->partageActif($this->logistique));
        $this->assertEquals($venteAvant, $this->partageActif($this->vente), 'Les autres processus ne changent pas.');
        $this->assertEquals($partsAvant, $this->partsEquipe(CommandeVente::class, $commande->id), 'Commissions historiques intactes.');
        $this->assertSame(0, $this->refusOperations(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT));
    }

    public function test_une_date_de_debut_passee_ne_recalcule_jamais_les_transferts(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $this->unSeulLivreurAvecParrain();
        $transfert = $this->transferer(100);   // 21/10 : sans part de parrain
        $partsAvant = $this->partsEquipe(TransfertLogistique::class, $transfert->id);
        Carbon::setTestNow('2026-10-23 10:00:00');
        EquipeLivreur::where('livreur_id', $this->convoyeur->id)->delete();

        PartParrainService::definirParts($this->vehicule->fresh(), $parrain->id, [
            ['processus_code' => CommissionProcessus::CODE_VENTE, 'categorie_id' => $this->categorie->id, 'part_parrain' => 200],
            ['processus_code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 'categorie_id' => $this->categorie->id, 'part_parrain' => 200],
            ['processus_code' => CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 'categorie_id' => $this->categorie->id, 'part_parrain' => 0],
        ], confirmerPartNulle: true, dateEffet: Carbon::parse('2026-10-21'));

        // La part du parrain s'applique aux transferts à partir d'aujourd'hui seulement.
        $this->assertSame('2026-10-23', EquipeLivraisonPartageCategorie::where('processus_id', $this->logistique->id)
            ->where('parrain_id', $parrain->id)->whereNull('effective_to')->value('effective_from')->toDateString());
        $this->assertEquals($partsAvant, $this->partsEquipe(TransfertLogistique::class, $transfert->id), 'Transfert passé jamais recalculé.');
    }

    public function test_retirer_un_parrain_ne_bloque_que_les_partages_desequilibres(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);
        $distribution = CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)->firstOrFail();
        $this->repartir($this->vente, 500, 300, 200);
        $this->repartir($distribution, 500, 300, 200);
        $avant = $this->vendre(NatureOperation::DISTRIBUTION_CLIENT, 100);
        $partsAvant = $this->partsEquipe(CommandeVente::class, $avant->id);
        $this->assertSame(20000.0, $partsAvant["parrain:{$parrain->id}"]);

        $this->actingAs($this->user)
            ->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'Ventes') && ! str_contains($m, 'Distribution'));

        // Seule la Vente (500 + 300 ≠ 1 000) est bloquée ; transfert et distribution restent ouverts.
        $this->assertSame('a_faire', $this->statutVente());
        $this->assertSame(1, $this->refusOperations(CommissionProcessus::CODE_VENTE));
        $this->assertSame(0, $this->refusOperations(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT));
        $this->assertSame(0, $this->refusOperations(CommissionProcessus::CODE_DISTRIBUTION_CLIENT));

        // Distribution : historique intact, versions closes jamais supprimées ; la suivante reprend
        // le partage Logistique — une seule enveloppe, sans parrain.
        $this->assertEquals($partsAvant, $this->partsEquipe(CommandeVente::class, $avant->id));
        $this->assertSame(1, EquipeLivraisonPartageCategorie::where('processus_id', $distribution->id)
            ->where('parrain_id', $parrain->id)->where('montant_unitaire', 200)->whereNotNull('effective_to')->count());
        $apres = $this->vendre(NatureOperation::DISTRIBUTION_CLIENT, 100);
        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 60000.0,
            "livreur:{$this->convoyeur->id}" => 40000.0,
        ], $this->partsEquipe(CommandeVente::class, $apres->id));
        $this->assertSame(1, CommissionEnveloppe::where('source_id', $apres->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->count());
    }

    public function test_retirer_un_parrain_dont_le_total_des_livreurs_est_deja_complet_ne_bloque_rien(): void
    {
        $this->ajouterParrain($this->vehicule);
        // Part saisie, équipe pas encore répartie : 600 + 400 + 200.
        $this->repartir($this->vente, 600, 400, 200);

        $this->actingAs($this->user)
            ->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        // Contrôle complet (somme, livreurs actifs, aucune ligne de parrain), pas seulement le total.
        $this->assertSame('fait', $this->statutVente());
        $this->assertSame(0, $this->refusOperations(CommissionProcessus::CODE_VENTE));
    }

    public function test_retirer_un_parrain_sans_part_rend_la_distribution_au_partage_logistique(): void
    {
        $this->ajouterParrain($this->vehicule);
        $distribution = CommissionProcessus::where('organization_id', $this->org->id)->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)->firstOrFail();

        DB::transaction(function () {
            $ancien = $this->vehicule->parrain_id;
            $this->vehicule->update(['parrain_id' => null]);
            PartageParrainService::appliquerChangementDeParrain($this->vehicule, $ancien);
        });

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
        ], $this->partageActif($this->vente));
        $this->assertSame([], $this->partageActif($distribution));
    }

    // ── Changement de barème ──────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function lignesBareme(int $livreur): array
    {
        return [[
            'categorie_id' => $this->categorie->id,
            'beneficiaires' => [CommissionCibleType::CODE_EQUIPE_LIVRAISON],
            'consultant_id' => null,
            'montants_standard' => [CommissionCibleType::CODE_EQUIPE_LIVRAISON => $livreur],
            'exceptions' => [],
        ]];
    }

    public function test_une_equipe_a_un_seul_livreur_avec_parrain_nest_jamais_alignee_automatiquement(): void
    {
        ['vehicule' => $autre, 'equipe' => $equipeAutre, 'livreurs' => [$seul]] = $this->vehiculeAvecEquipe(1);
        $this->partage($equipeAutre, $this->vente, [$seul->id => 1000]);
        DB::transaction(function () use ($autre) {
            $autre->update(['parrain_id' => Parrain::factory()->create(['organization_id' => $this->org->id])->id]);
            PartageParrainService::appliquerChangementDeParrain($autre, null);
        });
        // Sans parrain : alignée automatiquement, comme avant.
        ['equipe' => $equipeSansParrain] = $this->vehiculeAvecEquipe(1);
        $this->partage($equipeSansParrain, $this->vente, [$equipeSansParrain->membres()->value('livreur_id') => 1000]);

        ['groupes' => $groupes, 'automatiques' => $automatiques] = ReconfigurationPartagesService::analyser(
            $this->org->id, $this->vente, $this->lignesBareme(1200), null,
        );

        $this->assertSame([$equipeSansParrain->id], $automatiques->pluck('equipe_id')->all());
        $groupe = $groupes->firstWhere('equipe_id', $equipeAutre->id);
        $this->assertNotNull($groupe, 'L\'équipe parrainée passe par la grille.');
        $this->assertContains('parrain', collect($groupe['membres'])->pluck('type')->all());
    }

    public function test_la_publication_dun_bareme_versionne_la_part_preparee_du_parrain(): void
    {
        $parrain = $this->ajouterParrain($this->vehicule);

        $resultat = ReconfigurationPartagesService::enregistrerConfiguration($this->org->id, CommissionProcessus::CODE_VENTE, $this->lignesBareme(1200), $this->user->id);
        $brouillon = $resultat['brouillon'];
        $this->assertNotNull($brouillon);

        ReconfigurationPartagesService::enregistrerPartages($brouillon, [[
            'equipe_id' => $this->equipe->id,
            'categorie_id' => $this->categorie->id,
            'parts' => [
                ['livreur_id' => $this->chauffeur->id, 'montant_unitaire' => 600],
                ['livreur_id' => $this->convoyeur->id, 'montant_unitaire' => 400],
                ['parrain_id' => $parrain->id, 'montant_unitaire' => 200],
            ],
        ]], $this->user->id);
        ReconfigurationPartagesService::publier($brouillon->fresh(), $this->user->id);

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
            "parrain:{$parrain->id}" => 200,
        ], $this->partageActif($this->vente));
    }

    public function test_un_brouillon_logistique_fait_aussi_reconfigurer_le_partage_distribution(): void
    {
        $this->ajouterParrain($this->vehicule);

        ['groupes' => $groupes] = ReconfigurationPartagesService::analyser(
            $this->org->id, $this->logistique, $this->lignesBareme(1200), null,
        );

        $this->assertEqualsCanonicalizing(
            [CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, CommissionProcessus::CODE_DISTRIBUTION_CLIENT],
            $groupes->where('equipe_id', $this->equipe->id)->pluck('processus_code')->all(),
        );
        $groupeDistribution = $groupes->firstWhere('processus_code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $this->assertContains('parrain', collect($groupeDistribution['membres'])->pluck('type')->all());
        $groupeLogistique = $groupes->firstWhere('processus_code', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $this->assertContains('parrain', collect($groupeLogistique['membres'])->pluck('type')->all(), 'PARRAIN-025 : le parrain est membre du partage Logistique.');
    }

    // ── Liste des véhicules, reprise des données ──────────────────────────────

    public function test_la_liste_expose_le_partage_distribution_seulement_pour_un_vehicule_parraine(): void
    {
        $statuts = PartageConformiteVehiculesService::statuts($this->org->id, Vehicule::with('equipe.membres.livreur')->whereKey($this->vehicule->id)->get());
        $this->assertArrayNotHasKey(CommissionProcessus::CODE_DISTRIBUTION_CLIENT, $statuts[$this->vehicule->id]);

        $this->ajouterParrain($this->vehicule);
        $statuts = PartageConformiteVehiculesService::statuts($this->org->id, Vehicule::with('equipe.membres.livreur')->whereKey($this->vehicule->id)->get());

        // PARRAIN-022 : partages complets, mais la part du parrain à 0 GNF créée par le système
        // reste à répartir tant qu'une personne ne l'a pas définie ou confirmée.
        $this->assertSame(PartageConformiteVehiculesService::A_REPARTIR, $statuts[$this->vehicule->id][CommissionProcessus::CODE_DISTRIBUTION_CLIENT]);
        $this->assertSame(PartageConformiteVehiculesService::A_REPARTIR, $statuts[$this->vehicule->id][CommissionProcessus::CODE_VENTE]);
    }

    public function test_la_reprise_ajoute_la_ligne_du_parrain_aux_vehicules_deja_parraines(): void
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $this->vehicule->update(['parrain_id' => $parrain->id]);

        $migration = require database_path('migrations/2026_10_21_100100_backfill_parrain_partages_commission.php');
        $migration->up();
        $migration->up();

        $this->assertEquals([
            "livreur:{$this->chauffeur->id}" => 600,
            "livreur:{$this->convoyeur->id}" => 400,
            "parrain:{$parrain->id}" => 0,
        ], $this->partageActif($this->vente));
        $this->assertSame(1, EquipeLivraisonPartageCategorie::where('equipe_id', $this->equipe->id)
            ->where('processus_id', $this->vente->id)
            ->whereNotNull('parrain_id')
            ->count(), 'Rejouée, la reprise ne crée aucune ligne en double.');
    }
}
