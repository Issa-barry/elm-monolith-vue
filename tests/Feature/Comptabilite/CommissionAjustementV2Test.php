<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Enums\OrigineCommissionPart;
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
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Équivalent V2 des scénarios période/ajustement/validation véhicule de
 * CommissionAjustementTest.php et PaiementPeriodeTest.php (écrits sur
 * CommissionPart, la vente Legacy) — cette même couverture pour
 * CommissionEnveloppePart, seule source de vérité désormais pour la
 * commission de vente (cf. décision AMOA du 20/08/2026 : V2 unique moteur).
 *
 * Vérifie aussi la combinaison vente V2 + logistique dans
 * CommissionAjustementController::vehicule()/validerVehicule() et
 * PaiementPeriodeController::show()/valider() — un véhicule peut porter les
 * deux natures de commission sur la même période, jamais l'une masquant
 * l'autre selon que l'organisation est V2 ou non.
 */
class CommissionAjustementV2Test extends TestCase
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

    /**
     * Véhicule + équipe de 3 livreurs partageant l'enveloppe Livraison à
     * 50 % / 37,5 % / 12,5 % — mêmes proportions que l'équivalent Legacy
     * (Oumar 60000 / Abdoulaye 45000 / Kadiatou 15000 sur un total de 120000),
     * pour rester directement comparable.
     *
     * @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreurs: array<string, Livreur>, categorie: Categorie}
     */
    private function makeVehiculeTroisLivreurs(string $suffixNoms = ''): array
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'capacite_packs' => 200]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $livreurs = [];
        $parts = ['Oumar' => 50, 'Abdoulaye' => 37.5, 'Kadiatou' => 12.5];
        foreach ($parts as $nom => $pourcentage) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => $nom.$suffixNoms]);
            EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id,
                'categorie_id' => $categorie->id,
                'livreur_id' => $livreur->id,
                'part_pourcentage' => $pourcentage,
            ]);
            $livreurs[$nom] = $livreur;
        }

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

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreurs' => $livreurs, 'categorie' => $categorie];
    }

    /** Quantité 100 × 1200 GNF = 120 000 GNF répartis 50/37,5/12,5 % → 60000/45000/15000. */
    private function creerCommandeEtGenererCommission(Vehicule $vehicule, Categorie $categorie): CommandeVente
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $categorie->id],
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

    private function periodeCouvrantAujourdhui(): PaiementPeriode
    {
        return app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::LIVREUR,
            now(),
            $this->user->id,
        );
    }

    /** @test */
    public function periode_v2_ne_peut_pas_etre_validee_si_des_parts_ne_sont_pas_validees(): void
    {
        ['vehicule' => $vehicule, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        // Aucune part validée : la validation de la période doit être refusée.
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(StatutPeriodePaiement::CALCULEE->value, $periode->fresh()->statut->value);
    }

    /** @test */
    public function ecart_non_redistribue_bloque_la_validation_de_la_periode_v2(): void
    {
        ['vehicule' => $vehicule, 'livreurs' => $livreurs, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $partAbdoulaye = CommissionAdjustmentService::partsPourPeriodeV2($periode)
            ->first(fn ($p) => $p->beneficiaire_id === $livreurs['Abdoulaye']->id);

        // Abdoulaye absent, mis à 0, mais SANS redistribution aux deux autres.
        $this->actingAs($this->user)->post(
            route('comptabilite.ajustements.absence', ['type' => 'vente_v2', 'partId' => $partAbdoulaye->id]),
        );

        foreach (CommissionAdjustmentService::partsPourPeriodeV2($periode) as $part) {
            $this->actingAs($this->user)->post(route('comptabilite.ajustements.valider', ['type' => 'vente_v2', 'partId' => $part->id]));
        }

        $response = $this->actingAs($this->user)->post(route('comptabilite.periodes.valider', $periode));
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame(
            StatutPeriodePaiement::CALCULEE->value,
            $periode->fresh()->statut->value,
            'la période ne doit pas être validée tant que les 45 000 GNF ne sont pas redistribués',
        );
    }

    /** @test */
    public function valider_vehicule_v2_valide_toutes_les_parts_si_ecart_nul(): void
    {
        ['vehicule' => $vehicule, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.vehicule.valider', ['periode' => $periode, 'vehicule' => $vehicule->id]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $parts = CommissionAdjustmentService::partsPourPeriodeV2($periode);
        $this->assertCount(3, $parts);
        foreach ($parts as $part) {
            $this->assertNotNull($part->fresh()->validated_at, "la part de {$part->beneficiaire_id} doit être validée");
        }
    }

    /** @test */
    public function valider_vehicule_v2_bloque_si_ecart_non_nul(): void
    {
        ['vehicule' => $vehicule, 'livreurs' => $livreurs, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $partAbdoulaye = CommissionAdjustmentService::partsPourPeriodeV2($periode)
            ->first(fn ($p) => $p->beneficiaire_id === $livreurs['Abdoulaye']->id);

        // Absent, mis à 0, sans redistribution : le véhicule n'est plus à l'équilibre.
        $this->actingAs($this->user)->post(
            route('comptabilite.ajustements.absence', ['type' => 'vente_v2', 'partId' => $partAbdoulaye->id]),
        );

        $response = $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.vehicule.valider', ['periode' => $periode, 'vehicule' => $vehicule->id]));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        foreach (CommissionAdjustmentService::partsPourPeriodeV2($periode) as $part) {
            $this->assertNull($part->fresh()->validated_at, "aucune part ne doit être validée tant que l'écart n'est pas résorbé");
        }
    }

    /** @test */
    public function detail_vehicule_v2_affiche_les_trois_beneficiaires(): void
    {
        ['vehicule' => $vehicule, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.ajustements.vehicule', ['periode' => $periode, 'vehicule' => $vehicule->id]));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/Ajustements/Vehicule')
            ->where('vehicule.id', $vehicule->id)
            ->where('vehicule.theorique', 120000)
            ->has('beneficiaires', 3)
        );
    }

    /** @test */
    public function periode_show_v2_liste_le_vehicule_avec_les_montants_attendus(): void
    {
        ['vehicule' => $vehicule, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/Periodes/Show')
            ->has('vehicules', 1)
            ->where('vehicules.0.vehicule_id', $vehicule->id)
            ->where('vehicules.0.nb_membres', 3)
            ->where('vehicules.0.nb_commandes', 1)
            ->where('vehicules.0.theorique', 120000)
            ->where('vehicules.0.ajuste', 120000)
            ->where('vehicules.0.equilibre', true)
        );
    }

    /** @test */
    public function ajouter_remplacant_v2_cree_une_part_origine_remplacement(): void
    {
        ['vehicule' => $vehicule, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $commande = $this->creerCommandeEtGenererCommission($vehicule, $categorie);
        $remplacant = Livreur::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Camara']);

        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', 'equipe_livraison')
            ->firstOrFail();

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        // 'vente' et non 'vente_v2' : le contrôleur résout dynamiquement CommissionVente
        // puis CommissionEnveloppe à partir du seul commission_id (jamais les deux
        // moteurs avec des données réelles simultanées pour une même organisation).
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.remplacant', $periode), [
                'commission_type' => 'vente',
                'commission_id' => $enveloppe->id,
                'type_beneficiaire' => 'livreur',
                'livreur_id' => $remplacant->id,
                'beneficiaire_nom' => $remplacant->nom_complet,
                'montant' => 20000,
                'commentaire' => 'Remplaçant du jour',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $nouvellePart = CommissionEnveloppePart::where('enveloppe_id', $enveloppe->id)
            ->where('beneficiaire_id', $remplacant->id)
            ->first();

        $this->assertNotNull($nouvellePart);
        $this->assertSame(OrigineCommissionPart::REMPLACEMENT, $nouvellePart->origine);
        $this->assertSame(20000.0, (float) $nouvellePart->montant_actuel);
        $this->assertSame(
            0.0,
            (float) $nouvellePart->montant_net,
            'un remplaçant n\'a aucune allocation théorique : tout son montant est de l\'écart à compenser',
        );
        // L'enveloppe théorique du véhicule ne bouge jamais : le remplaçant crée un
        // écart de +20 000 GNF à résorber ailleurs, jamais une commission supplémentaire.
        $this->assertSame(120000.0, (float) $enveloppe->fresh()->montant_total);
    }

    /** @test */
    public function periode_show_v2_marque_le_vehicule_a_ajuster_si_ecart_non_nul(): void
    {
        ['vehicule' => $vehicule, 'livreurs' => $livreurs, 'categorie' => $categorie] = $this->makeVehiculeTroisLivreurs();
        $this->creerCommandeEtGenererCommission($vehicule, $categorie);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $partOumar = CommissionAdjustmentService::partsPourPeriodeV2($periode)
            ->first(fn ($p) => $p->beneficiaire_id === $livreurs['Oumar']->id);

        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente_v2', 'partId' => $partOumar->id]),
            ['montant' => 40000, 'motif' => 'correction'],
        );

        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('vehicules.0.equilibre', false)
            ->where('vehicules.0.ecart', -20000)
        );
    }

    /** @test */
    public function periode_show_v2_filtre_les_vehicules_par_nom_et_par_livreur(): void
    {
        ['vehicule' => $vehiculeA, 'categorie' => $categorieA] = $this->makeVehiculeTroisLivreurs(' A');
        $this->creerCommandeEtGenererCommission($vehiculeA, $categorieA);

        // Suffixe distinct : sans lui, les deux véhicules auraient des livreurs de même
        // nom (Oumar/Abdoulaye/Kadiatou), rendant le filtre par nom de livreur ambigu.
        ['vehicule' => $vehiculeB, 'categorie' => $categorieB] = $this->makeVehiculeTroisLivreurs(' B');
        $this->creerCommandeEtGenererCommission($vehiculeB, $categorieB);

        $periode = $this->periodeCouvrantAujourdhui();
        app(PeriodeCalculatorService::class)->calculer($periode);

        $parVehicule = $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.show', $periode).'?vehicule='.$vehiculeA->nom_vehicule);
        $parVehicule->assertInertia(fn (Assert $page) => $page
            ->has('vehicules', 1)
            ->where('vehicules.0.vehicule_id', $vehiculeA->id)
        );

        $parLivreur = $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.show', $periode).'?livreur='.urlencode('Kadiatou B'));
        $parLivreur->assertInertia(fn (Assert $page) => $page
            ->has('vehicules', 1)
            ->where('vehicules.0.vehicule_id', $vehiculeB->id)
        );
    }
}
