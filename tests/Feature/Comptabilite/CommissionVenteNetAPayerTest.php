<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
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
use App\Models\Site;
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
 * Couvre la décision produit du 29/08/2026 : sur Comptabilité > Commissions > Ventes, « Net à
 * payer »/« Reste à payer » doivent toujours refléter le montant réellement retenu courant
 * (montant_actuel ?? montant_net, dépenses déduites), indépendamment du fait que la période
 * couvrante soit validée ou non — seule la VALIDATION conditionne le droit au paiement, jamais
 * l'affichage du montant. Le cas « aucun ajustement, tout est encore CREEE » reste couvert par
 * CommissionVenteStatutCreeeTest ; ce fichier couvre l'ajustement, le paiement partiel/total,
 * l'agrégation KPI et la réactualisation après action.
 */
class CommissionVenteNetAPayerTest extends TestCase
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

    private function indexRow(Livreur $livreur): array
    {
        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        return collect($response->viewData('page')['props']['beneficiaires'])
            ->firstWhere('beneficiaire_id', $livreur->id);
    }

    /** @test */
    public function ajustement_positif_est_immediatement_reflete_dans_net_a_payer(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);
        $this->assertSame(120000.0, (float) $part->montant_net);

        $this->actingAs($this->user)
            ->patch(route('comptabilite.commissions.ajustements.ajuster'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
                'montant' => 135000,
                'motif' => 'travail_supplementaire',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = $this->indexRow($livreur);
        $this->assertSame(135000.0, (float) $row['total_net_cumule'], 'net à payer doit refléter le montant ajusté, pas le théorique');
        $this->assertSame(135000.0, (float) $row['solde_restant']);
        $this->assertSame('creee', $row['statut_global'], 'le statut reste "creee" — un ajustement de montant ne rend rien payable');
    }

    /** @test */
    public function ajustement_negatif_est_immediatement_reflete_dans_net_a_payer(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $this->actingAs($this->user)
            ->patch(route('comptabilite.commissions.ajustements.ajuster'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
                'montant' => 107000,
                'motif' => 'absence',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = $this->indexRow($livreur);
        $this->assertSame(107000.0, (float) $row['total_net_cumule']);
        $this->assertSame(107000.0, (float) $row['solde_restant']);
    }

    /** @test */
    public function paiement_partiel_diminue_le_reste_a_payer_sans_changer_le_net(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        // Simule une commission déjà rendue payable (période validée) puis partiellement
        // versée — le montant retenu (net) ne doit jamais bouger avec le paiement, seul
        // le reste diminue. Le statut PARTIEL est posé explicitement : recalculStatut()
        // (déclenché en pratique par CommissionPaymentService lors d'un vrai paiement) n'est
        // pas invoqué par un simple update() de test.
        $part->update(['statut' => StatutCommission::PARTIEL->value, 'montant_verse' => 50000]);

        $row = $this->indexRow($livreur);
        $this->assertSame(120000.0, (float) $row['total_net_cumule'], 'le net à payer ne doit pas changer avec un paiement partiel');
        $this->assertSame(70000.0, (float) $row['solde_restant']);
        $this->assertSame(50000.0, (float) $row['total_verse']);
    }

    /** @test */
    public function paiement_total_ramene_le_reste_a_payer_a_zero(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $part->update(['statut' => StatutCommission::PAYE->value, 'montant_verse' => 120000]);

        $row = $this->indexRow($livreur);
        $this->assertSame(120000.0, (float) $row['total_net_cumule'], 'le net à payer reste le montant retenu, même intégralement payé');
        $this->assertSame(0.0, (float) $row['solde_restant']);
        $this->assertSame(120000.0, (float) $row['total_verse']);
    }

    /** @test */
    public function les_kpis_agreges_ne_double_comptent_pas_les_parts_dun_meme_beneficiaire(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $this->genererCommission($vehicule, $categorie);

        $parts = CommissionEnveloppePart::where('beneficiaire_id', $livreur->id)->get();
        $this->assertCount(2, $parts, 'deux commandes doivent produire deux parts distinctes');
        $totalTheorique = (float) $parts->sum('montant_net');
        $this->assertSame(240000.0, $totalTheorique);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk();

        $props = $response->viewData('page')['props'];
        $row = collect($props['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id);

        // Une seule ligne agrégée pour ce bénéficiaire, avec la somme exacte des deux parts —
        // ni double comptage, ni perte d'une des deux commandes.
        $this->assertCount(1, collect($props['beneficiaires'])->where('beneficiaire_id', $livreur->id));
        $this->assertSame(2, $row['nb_commandes']);
        $this->assertSame($totalTheorique, (float) $row['total_net_cumule']);
        $this->assertSame($totalTheorique, (float) $props['kpis']['total_net']);
        $this->assertSame($totalTheorique, (float) $props['kpis']['solde_total']);
    }

    /** @test */
    public function la_liste_est_immediatement_a_jour_apres_validation_directe_sans_rechargement_manuel(): void
    {
        ['vehicule' => $vehicule, 'livreur' => $livreur, 'categorie' => $categorie] = $this->makeVehiculeUnLivreur();
        $this->genererCommission($vehicule, $categorie);
        $part = $this->seulePart($livreur);

        $avant = $this->indexRow($livreur);
        $this->assertSame('creee', $avant['statut_global']);

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $part->id]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        // Chaque visite Inertia recharge intégralement les props côté serveur (aucun `only`
        // restrictif utilisé par le front) : la prochaine ouverture de la page reflète donc
        // toujours l'état frais, sans action de rechargement manuel dédiée à prévoir côté UI.
        $apres = $this->indexRow($livreur);
        $this->assertNotNull($part->fresh()->validated_at);
        $this->assertSame(120000.0, (float) $apres['total_net_cumule'], 'le montant retenu ne change pas avec la seule validation (pas encore payable)');
    }
}
