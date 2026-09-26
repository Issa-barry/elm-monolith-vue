<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Couvre les nouvelles actions Ajuster/Valider directement depuis Comptabilité > Commissions
 * (CommissionAjustementController::ajusterParts()/validerParts()/repartirVehicule()), sans
 * passer par une PaiementPeriode — cf. décision produit du 29/08/2026 : le comptable ne doit
 * plus avoir besoin de connaître une référence de période pour traiter une commission.
 *
 * Les scénarios "période" classiques (validation de lot, écarts, ajustement multi-livreurs)
 * restent couverts par CommissionAjustementVenteTest — non dupliqués ici.
 */
class CommissionValidationDirecteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'comptabilite.read', 'comptabilite.payer']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    /** Véhicule + équipe d'un seul livreur (100 % de l'enveloppe) + catégorie/règle associées. */
    private function makeVehiculeUnLivreur(string $nom = 'Saa Fodé'): array
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'capacite_packs' => 200]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => $nom]);

        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 1200,
            'effective_from' => now()->subDay(),
        ]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Livraison — Sachets',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 1200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        return ['vehicule' => $vehicule->fresh(), 'livreur' => $livreur, 'categorie' => $categorie];
    }

    /** Quantité 100 × 1200 GNF = 120 000 GNF, intégralement pour l'unique livreur de l'équipe. */
    private function genererCommission(Vehicule $vehicule, Categorie $categorie): CommandeVente
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test '.uniqid(), 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 200000,
        ]);

        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 100,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 100 * (float) $variante->prix_vente,
        ]);

        $this->seedVarianteStockSuffisant($variante, $this->defaultSite);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [
            ['id' => $ligne->id, 'quantite_chargee' => 100, 'type_ecart' => 'conforme'],
        ]);

        $commande = $commande->fresh();
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        return $commande;
    }

    private function seulePart(Livreur $livreur): CommissionEnveloppePart
    {
        return CommissionEnveloppePart::where('beneficiaire_id', $livreur->id)->sole();
    }

    /** @test */
    public function valider_directement_valide_la_part_sans_quaucune_periode_existe(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $this->assertSame(0, PaiementPeriode::count(), 'aucune période ne doit exister avant validation directe');

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($part->fresh()->validated_at);
        $this->assertSame(0, PaiementPeriode::count(), 'la validation directe ne doit créer aucune PaiementPeriode');

        // Le bouton "Valider" ne doit plus être proposé pour cette ligne une fois la part
        // pré-validée — validerPart() ne bascule jamais le statut CREEE lui-même (cf.
        // CommissionAjustementController::validerParts()), donc creee_parts doit filtrer sur
        // validated_at pour que l'action disparaisse malgré un statut brut inchangé.
        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.index'));
        $row = collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);
        $this->assertSame([], $row['creee_parts'], 'plus aucune action Valider/Ajuster à proposer une fois la part pré-validée');
    }

    /** @test */
    public function valider_directement_valide_toutes_les_parts_dun_meme_beneficiaire(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $this->genererCommission($vehicule, $categorie);

        $parts = CommissionEnveloppePart::where('beneficiaire_id', $livreur->id)->get();
        $this->assertCount(2, $parts, 'deux commandes doivent produire deux parts distinctes');

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => $parts->map(fn ($p) => ['type' => 'vente', 'id' => $p->id])->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '2 commission(s) validée(s).');

        foreach ($parts as $part) {
            $this->assertNotNull($part->fresh()->validated_at);
        }
    }

    /** @test */
    public function valider_en_masse_traite_plusieurs_beneficiaires_en_une_seule_requete(): void
    {
        ['vehicule' => $vehiculeA, 'livreur' => $livreurA, 'categorie' => $categorieA] = $this->makeVehiculeUnLivreur('Saa Fodé');
        $this->genererCommission($vehiculeA, $categorieA);

        ['vehicule' => $vehiculeB, 'livreur' => $livreurB, 'categorie' => $categorieB] = $this->makeVehiculeUnLivreur('Mamadou');
        $this->genererCommission($vehiculeB, $categorieB);

        $partA = $this->seulePart($livreurA);
        $partB = $this->seulePart($livreurB);

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [
                    ['type' => 'vente', 'id' => $partA->id],
                    ['type' => 'vente', 'id' => $partB->id],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '2 commission(s) validée(s).');

        $this->assertNotNull($partA->fresh()->validated_at);
        $this->assertNotNull($partB->fresh()->validated_at);
    }

    /**
     * Le comptable ne doit jamais avoir à se soucier du fait que deux commissions d'un même
     * bénéficiaire retombent sur deux quinzaines différentes : validerParts() ne groupe par
     * période que pour l'audit, jamais comme condition — cf. docblock de la méthode.
     */
    /** @test */
    public function valider_directement_fonctionne_meme_si_les_parts_couvrent_deux_periodes_differentes(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $this->genererCommission($vehicule, $categorie);

        $parts = CommissionEnveloppePart::where('beneficiaire_id', $livreur->id)->get();
        $this->assertCount(2, $parts);

        // Fait retomber la seconde part sur la quinzaine précédente, sans jamais créer la
        // PaiementPeriode correspondante — exactement le cas "commande tardive" du monde réel.
        $parts->last()->enveloppe->update(['earned_at' => now()->subDays(20)]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => $parts->map(fn ($p) => ['type' => 'vente', 'id' => $p->id])->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '2 commission(s) validée(s).');

        foreach ($parts as $part) {
            $this->assertNotNull($part->fresh()->validated_at, 'les deux parts doivent être validées malgré les deux quinzaines différentes');
        }
        $this->assertSame(0, PaiementPeriode::count(), 'aucune période ne doit être créée par la seule validation directe');
    }

    /** @test */
    public function ajuster_directement_exige_un_motif(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $this->actingAs($this->user)
            ->patch(route('comptabilite.commissions.ajustements.ajuster'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
                'montant' => 100000,
            ])
            ->assertSessionHasErrors('motif');

        $this->assertNull($part->fresh()->montant_actuel, 'aucun ajustement ne doit être appliqué sans motif');
    }

    /** @test */
    public function ajuster_directement_conserve_le_montant_theorique_et_change_le_montant_retenu(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);
        $theoriqueAvant = (float) $part->montant_net;

        $this->actingAs($this->user)
            ->patch(route('comptabilite.commissions.ajustements.ajuster'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
                'montant' => 100000,
                'motif' => 'correction',
                'commentaire' => 'Ajustement test',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $frais = $part->fresh();
        $this->assertSame($theoriqueAvant, (float) $frais->montant_net, 'le montant théorique ne doit jamais être écrasé');
        $this->assertSame(100000.0, (float) $frais->montant_actuel);
        $this->assertSame(100000.0, $frais->montant_a_payer);
    }

    /** @test */
    public function valider_directement_refuse_un_utilisateur_non_admin(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $nonAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $nonAdmin->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
        $nonAdmin->givePermissionTo('comptabilite.read');

        $this->actingAs($nonAdmin)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
            ])
            ->assertForbidden();

        $this->assertNull($part->fresh()->validated_at);
    }

    /** @test */
    public function valider_directement_refuse_une_part_dune_autre_organisation(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $autreAdmin = $this->makeAdminUser();

        $this->actingAs($autreAdmin)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
            ])
            ->assertForbidden();

        $this->assertNull($part->fresh()->validated_at);
    }

    /** @test */
    public function repartir_vehicule_cree_et_calcule_la_periode_puis_redirige_vers_lecran_ajustement(): void
    {
        ['vehicule' => $vehicule, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);

        $this->assertSame(0, PaiementPeriode::count());

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vehicules.repartir', ['vehiculeId' => $vehicule->id]));

        $periode = PaiementPeriode::sole();
        $this->assertSame(StatutPeriodePaiement::CALCULEE->value, $periode->statut->value);

        $response->assertRedirect(route('comptabilite.periodes.ajustements.vehicule', [
            'periode' => $periode->id,
            'vehicule' => $vehicule->id,
        ]));
    }
}
