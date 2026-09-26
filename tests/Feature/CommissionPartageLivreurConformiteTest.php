<?php

namespace Tests\Feature;

use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementPeriode;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Partage Livreur et changement de barème (décisions du 24/09/2026, lot 1) :
 *  - R4 : une commande (création, modification) et son chargement sont REFUSÉS dès que le
 *    partage de l'équipe du véhicule n'est pas conforme pour une catégorie réellement vendue —
 *    somme ≠ barème Livreur, ou membre actif sans part (0 GNF accepté) ; même règle à
 *    l'enregistrement de l'équipe ; jamais à l'encaissement.
 *  - R1 : barème résolu par date, même depuis remplacé.
 *  - R3 + option A : une génération PARTIELLE se complète par « Relancer » (cibles manquantes
 *    seulement, date de gain d'origine), la correction de partage prenant effet à la date
 *    d'effet du barème qui l'a rendue nécessaire.
 */
class CommissionPartageLivreurConformiteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private CommissionProcessus $processus;

    private Categorie $bouteille;

    private Categorie $sachet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 10:00:00');

        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'ventes.demarrer_chargement', 'ventes.valider_chargement',
            'equipes-livraison.read', 'equipes-livraison.create', 'equipes-livraison.update',
        ]);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = $this->user->sites()->firstOrFail();
        $this->processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $this->bouteille = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);
        $this->sachet = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet', 'statut' => 'actif']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function regle(string $cible, int $montant, Categorie $categorie, string $du = '2026-08-01'): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => "{$cible} — {$categorie->nom}",
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => $cible,
            'mode' => $cible === CommissionCibleType::CODE_EQUIPE_LIVRAISON ? CommissionMode::A_REPARTIR->value : CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => $du,
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** Même versionnement que CommissionRegleController::enregistrerRegleCategorie(). */
    private function changerBaremeLivreur(Categorie $categorie, int $montant, string $du): CommissionRegle
    {
        $ancienne = CommissionRegle::where('organization_id', $this->org->id)
            ->where('processus_id', $this->processus->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->where('scope_id', $categorie->id)
            ->where('statut', CommissionRegleStatut::ACTIVE->value)
            ->firstOrFail();
        $nouvelle = $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, $montant, $categorie, $du);
        $ancienne->update([
            'effective_to' => Carbon::parse($du)->subDay()->toDateString(),
            'statut' => CommissionRegleStatut::REMPLACEE->value,
        ]);

        return $nouvelle;
    }

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreurs: list<Livreur>} */
    private function vehiculeAvecEquipe(int $nbLivreurs = 2): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
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
            $livreur = Livreur::factory()->create([
                'organization_id' => $this->org->id,
                'is_active' => true,
                'nom_complet' => $i === 0 ? 'Chauffeur Alpha' : "Convoyeur {$i}",
            ]);
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

    /** @param  array<string, int>  $montants  livreur_id => GNF/pack */
    private function partage(EquipeLivraison $equipe, Categorie $categorie, array $montants, string $du = '2026-08-01'): void
    {
        foreach ($montants as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id,
                'processus_id' => $this->processus->id,
                'categorie_id' => $categorie->id,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => $du,
            ]);
        }
    }

    private function produit(Categorie $categorie): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $categorie->id],
            ['prix_vente' => 5000, 'prix_usine' => 3500],
        );
    }

    /** @param  list<array{0: Produit, 1: int}>  $lignes */
    private function postVente(Vehicule $vehicule, array $lignes)
    {
        return $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $vehicule->id,
            'lignes' => array_map(fn (array $l) => [
                'produit_id' => $l[0]->id,
                'qte' => $l[1],
                'prix_vente' => 5000,
            ], $lignes),
        ]);
    }

    /** Commande en CHARGEMENT_EN_COURS, construite sans passer par le contrôle de création. */
    private function commandeEnChargement(Vehicule $vehicule, Produit $produit, int $quantite): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000 * $quantite,
            'commission_eligible_snapshot' => true,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $quantite,
            'prix_usine_snapshot' => 3500,
            'prix_vente_snapshot' => 5000,
            'total_ligne' => 5000 * $quantite,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());

        return $commande->fresh('lignes');
    }

    private function chargerParHttp(CommandeVente $commande)
    {
        return $this->actingAs($this->user)->post(route('ventes.statut.avancer', $commande), [
            'lignes' => $commande->lignes->map(fn ($l) => [
                'id' => $l->id,
                'quantite_chargee' => $l->quantite_demandee,
                'type_ecart' => 'conforme',
            ])->all(),
        ]);
    }

    // ── R4 : blocage à la création ────────────────────────────────────────────

    public function test_creation_autorisee_quand_le_partage_est_exact(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_creation_refusee_quand_le_partage_est_inferieur_au_bareme_avec_message_detaille(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 200]);

        $response = $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]]);

        $response->assertSessionHasErrors('vehicule_id');
        $message = session('errors')->first('vehicule_id');
        $this->assertStringContainsString('Impossible de créer ou modifier cette commande', $message);
        $this->assertStringContainsString($vehicule->nom_vehicule, $message);
        $this->assertStringContainsString('Bouteille : barème Livreur 800 GNF/pack, partage configuré 700 GNF/pack, écart 100 GNF', $message);
        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_creation_refusee_quand_le_partage_depasse_le_bareme(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 400]);

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasErrors('vehicule_id');

        $this->assertStringContainsString('écart −100 GNF', session('errors')->first('vehicule_id'));
        $this->assertDatabaseMissing('commandes_ventes', ['vehicule_id' => $vehicule->id]);
    }

    public function test_creation_refusee_quand_la_categorie_a_un_bareme_mais_aucun_partage(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule] = $this->vehiculeAvecEquipe();

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasErrors('vehicule_id');

        $this->assertStringContainsString('aucun partage configuré', session('errors')->first('vehicule_id'));
    }

    public function test_creation_refusee_quand_un_membre_actif_na_aucune_part(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe(3);
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasErrors('vehicule_id');

        $this->assertStringContainsString('sans part : Convoyeur 2', session('errors')->first('vehicule_id'));
    }

    public function test_une_part_a_zero_et_un_livreur_desactive_ne_bloquent_pas(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe(3);
        $l[2]->update(['is_active' => false]);
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 800, $l[1]->id => 0]);

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasNoErrors();
    }

    public function test_bareme_livreur_a_zero_ne_bloque_jamais(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0, $this->bouteille);
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950, $this->bouteille);
        ['vehicule' => $vehicule] = $this->vehiculeAvecEquipe();

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasNoErrors();
    }

    public function test_seules_les_categories_presentes_dans_la_commande_sont_controlees(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 500, $this->sachet);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);
        $this->partage($equipe, $this->sachet, [$l[0]->id => 200, $l[1]->id => 100]); // 300 ≠ 500

        $produitBouteille = $this->produit($this->bouteille);
        $produitSachet = $this->produit($this->sachet);

        $this->postVente($vehicule, [[$produitSachet, 3]])->assertSessionHasErrors('vehicule_id');
        $this->postVente($vehicule, [[$produitBouteille, 3], [$produitSachet, 3]])->assertSessionHasErrors('vehicule_id');
        $message = session('errors')->first('vehicule_id');
        $this->assertStringContainsString('Sachet', $message);
        $this->assertStringNotContainsString('Bouteille :', $message);
        $this->assertSame(0, CommandeVente::where('vehicule_id', $vehicule->id)->count());

        $this->postVente($vehicule, [[$produitBouteille, 3]])->assertSessionHasNoErrors();
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_un_refus_ne_laisse_aucune_trace(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500]);

        $this->postVente($vehicule, [[$this->produit($this->bouteille), 5]])->assertSessionHasErrors('vehicule_id');

        $this->assertSame(0, CommandeVente::count());
        $this->assertDatabaseCount('commande_vente_lignes', 0);
        $this->assertDatabaseCount('mouvements_stock', 0);
        $this->assertSame(0, CommissionEnveloppe::count());
    }

    public function test_un_changement_de_bareme_bloque_puis_la_correction_du_partage_debloque(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);
        $produit = $this->produit($this->bouteille);
        $apercu = fn () => $this->actingAs($this->user)
            ->getJson(route('ventes.check-partage-commission', ['vehicule_id' => $vehicule->id, 'produit_ids' => [$produit->id]]))
            ->json('bloquant');

        $this->assertFalse($apercu());

        $this->changerBaremeLivreur($this->bouteille, 1000, '2026-09-01');
        $this->assertTrue($apercu());
        $this->postVente($vehicule, [[$produit, 2]])->assertSessionHasErrors('vehicule_id');
        $this->assertSame(0, CommandeVente::where('vehicule_id', $vehicule->id)->count());

        $this->patchEquipe($equipe, $vehicule, $l, [600, 400])->assertSessionHasNoErrors();
        $this->assertFalse($apercu());
        $this->postVente($vehicule, [[$produit, 2]])->assertSessionHasNoErrors();
        $this->assertSame(1, CommandeVente::where('vehicule_id', $vehicule->id)->count());
    }

    public function test_apercu_formulaire_rejoue_le_controle_de_creation(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 200]);
        $produit = $this->produit($this->bouteille);

        $this->actingAs($this->user)
            ->getJson(route('ventes.check-partage-commission', ['vehicule_id' => $vehicule->id, 'produit_ids' => [$produit->id]]))
            ->assertOk()
            ->assertJson(['bloquant' => true, 'message' => $this->messageAttendu($vehicule)])
            ->assertJsonPath('details.vehicule_nom', $vehicule->nom_vehicule)
            ->assertJsonPath('details.processus_code', $this->processus->code)
            ->assertJsonPath('details.processus_libelle', $this->processus->libelle)
            ->assertJsonPath('details.categories.0.categorie_id', $this->bouteille->id)
            ->assertJsonPath('details.categories.0.bareme', 800)
            ->assertJsonPath('details.categories.0.total_configure', 700)
            ->assertJsonPath('details.categories.0.ecart', 100)
            ->assertJsonPath('details.categories.0.membres_manquants', []);

        $this->actingAs($this->user)
            ->getJson(route('ventes.check-partage-commission', ['vehicule_id' => $vehicule->id, 'produit_ids' => []]))
            ->assertOk()
            ->assertJson(['bloquant' => false, 'message' => null]);
    }

    private function messageAttendu(Vehicule $vehicule): string
    {
        return "Impossible de créer ou modifier cette commande : le partage de commission du véhicule {$vehicule->nom_vehicule} n'est pas conforme pour le processus « {$this->processus->libelle} ». Bouteille : barème Livreur 800 GNF/pack, partage configuré 700 GNF/pack, écart 100 GNF. Corrigez la répartition de l'équipe avant de continuer.";
    }

    // ── R4 : filet de sécurité au chargement ──────────────────────────────────

    public function test_chargement_refuse_si_le_partage_est_devenu_non_conforme_depuis_la_creation(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);
        $commande = $this->commandeEnChargement($vehicule, $this->produit($this->bouteille), 10);

        $this->changerBaremeLivreur($this->bouteille, 1000, '2026-09-01');

        $this->chargerParHttp($commande)->assertSessionHasErrors('partage_commission');
        $this->assertStringContainsString('Impossible de valider ce chargement', session('errors')->first('partage_commission'));
        $this->assertSame(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
        $this->assertSame(0, CommissionEnveloppe::where('source_id', $commande->id)->count());

        $this->patchEquipe($equipe, $vehicule, $l, [600, 400])->assertSessionHasNoErrors();

        $this->chargerParHttp($commande->fresh('lignes'))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        $livreur = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->firstOrFail();
        $this->assertSame(10000.0, (float) $livreur->montant_total);
    }

    // ── R4 : même règle à l'enregistrement de l'équipe ───────────────────────

    /** @param  list<Livreur>  $livreurs */
    private function patchEquipe(EquipeLivraison $equipe, Vehicule $vehicule, array $livreurs, array $montants)
    {
        return $this->actingAs($this->user)->patch(route('equipes-livraison.update', $equipe), [
            'vehicule_id' => $vehicule->id,
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'membres' => array_map(fn (int $i) => [
                'livreur_id' => $livreurs[$i]->id,
                'nom_complet' => $livreurs[$i]->nom_complet,
                'telephone' => '+2246200000'.str_pad((string) ($i + 10), 2, '0', STR_PAD_LEFT),
                'role' => $i === 0 ? 'chauffeur' : 'convoyeur',
                'ordre' => $i,
            ], array_keys($livreurs)),
            'partages_categorie' => [[
                'categorie_id' => $this->bouteille->id,
                'parts' => array_values(array_filter(array_map(
                    fn (int $i) => array_key_exists($i, $montants) ? ['membre_ordre' => $i, 'montant_unitaire' => $montants[$i]] : null,
                    array_keys($livreurs),
                ))),
            ]],
        ]);
    }

    public function test_enregistrement_equipe_refuse_un_membre_sans_part_et_accepte_zero(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe(2);

        $response = $this->patchEquipe($equipe, $vehicule, $l, [0 => 800]);
        $response->assertStatus(422);
        $this->assertStringContainsString('chaque membre de l\'équipe doit avoir une part', $response->exception->getMessage());
        $this->assertStringContainsString('Convoyeur 1', $response->exception->getMessage());

        $this->patchEquipe($equipe, $vehicule, $l, [800, 0])->assertSessionHasNoErrors()->assertRedirect();
    }

    // ── R1 : régénération à une date passée ──────────────────────────────────

    public function test_une_regeneration_a_une_date_passee_utilise_le_bareme_alors_en_vigueur(): void
    {
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950, $this->bouteille);
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0, $this->bouteille);
        ['vehicule' => $vehicule] = $this->vehiculeAvecEquipe(1);
        $commande = $this->commandeEnChargement($vehicule, $this->produit($this->bouteille), 4);
        CommandeVenteService::validerChargement($commande, $commande->lignes->map(fn ($l) => ['id' => $l->id, 'quantite_chargee' => 4])->all());

        // Le barème Propriétaire change ensuite (950 → 1200 au 10/09).
        Carbon::setTestNow('2026-09-12 09:00:00');
        $ancienne = CommissionRegle::where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->firstOrFail();
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 1200, $this->bouteille, '2026-09-10');
        $ancienne->update(['effective_to' => '2026-09-09', 'statut' => CommissionRegleStatut::REMPLACEE->value]);

        // Même mécanique qu'un retour partiel (CommissionTriggerService::onRetourEnregistre()).
        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)->firstOrFail();
        $earnedAt = $enveloppe->earned_at;
        $enveloppe->lignes()->delete();
        $enveloppe->parts()->delete();
        $enveloppe->delete();
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh(), earnedAt: Carbon::parse($earnedAt));

        $regeneree = CommissionEnveloppe::where('source_id', $commande->id)->firstOrFail();
        $this->assertSame(3800.0, (float) $regeneree->montant_total, '950 × 4 — le barème du 01/09, jamais 0 ni 1 200.');
        $this->assertSame('2026-09-01', $regeneree->earned_at->toDateString());
    }

    // ── R3 + option A : régularisation d'une génération partielle ─────────────

    /**
     * Crée une commande PARTIELLE comme en production avant ce chantier : barème Livreur passé à
     * 1 000 au 10/09, partage d'équipe resté à 800, chargement au 12/09 (génération directe,
     * sans le contrôle HTTP qui la bloquerait aujourd'hui).
     *
     * @return array{commande: CommandeVente, equipe: EquipeLivraison, vehicule: Vehicule, livreurs: list<Livreur>}
     */
    private function commandePartielle(): array
    {
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950, $this->bouteille);
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);

        Carbon::setTestNow('2026-09-10 08:00:00');
        $this->changerBaremeLivreur($this->bouteille, 1000, '2026-09-10');

        Carbon::setTestNow('2026-09-12 09:00:00');
        $commande = $this->commandeEnChargement($vehicule, $this->produit($this->bouteille), 10);
        CommandeVenteService::validerChargement($commande, $commande->lignes->map(fn ($x) => ['id' => $x->id, 'quantite_chargee' => 10])->all());

        return ['commande' => $commande->fresh(), 'equipe' => $equipe, 'vehicule' => $vehicule, 'livreurs' => $l];
    }

    private function derniereTentative(CommandeVente $commande): CommissionGenerationAttempt
    {
        return CommissionGenerationAttempt::where('source_id', $commande->id)->latest('created_at')->latest('id')->firstOrFail();
    }

    public function test_relancer_une_generation_partielle_genere_uniquement_la_cible_manquante_a_la_date_dorigine(): void
    {
        ['commande' => $commande, 'equipe' => $equipe, 'vehicule' => $vehicule, 'livreurs' => $l] = $this->commandePartielle();

        $this->assertSame(CommissionGenerationStatut::PARTIEL, $this->derniereTentative($commande)->statut);
        $proprietaire = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->firstOrFail();
        $this->assertFalse(CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->exists());

        // Relance AVANT correction : rien de nouveau, toujours à régulariser.
        Carbon::setTestNow('2026-09-14 09:00:00');
        $this->actingAs($this->user)->post(route('ventes.commissions.relancer', $commande))->assertSessionHasErrors('commissions');
        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commande->id)->count());

        // Correction du partage le 15/09 → option A : effet au 10/09 (date du barème).
        Carbon::setTestNow('2026-09-15 09:00:00');
        $this->patchEquipe($equipe, $vehicule, $l, [600, 400])->assertSessionHasNoErrors();
        $this->assertSame(
            ['2026-09-10'],
            EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)->whereNull('effective_to')->pluck('effective_from')->map->toDateString()->unique()->values()->all(),
        );

        Carbon::setTestNow('2026-09-16 09:00:00');
        $this->actingAs($this->user)
            ->post(route('ventes.commissions.relancer', $commande))
            ->assertRedirect(route('ventes.show', $commande))
            ->assertSessionHasNoErrors();

        $enveloppes = CommissionEnveloppe::where('source_id', $commande->id)->get();
        $this->assertCount(2, $enveloppes, 'Propriétaire conservé + Livreur ajouté, aucun doublon.');
        $this->assertSame($proprietaire->id, $enveloppes->firstWhere('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->id);
        $this->assertSame(9500.0, (float) $enveloppes->firstWhere('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->montant_total);

        $livreur = $enveloppes->firstWhere('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON);
        $this->assertSame(10000.0, (float) $livreur->montant_total);
        $this->assertSame('2026-09-12', $livreur->earned_at->toDateString(), 'Date de gain d\'origine, même période.');
        $parts = CommissionEnveloppePart::where('enveloppe_id', $livreur->id)->pluck('montant_brut', 'beneficiaire_id')->map(fn ($m) => (float) $m);
        $this->assertSame(6000.0, $parts[$l[0]->id]);
        $this->assertSame(4000.0, $parts[$l[1]->id]);

        $this->assertSame(CommissionGenerationStatut::SUCCES, $this->derniereTentative($commande)->statut);

        // Nouvelle relance : no-op (génération complète).
        $this->actingAs($this->user)->post(route('ventes.commissions.relancer', $commande))->assertSessionHasNoErrors();
        $this->assertSame(2, CommissionEnveloppe::where('source_id', $commande->id)->count());
    }

    public function test_relance_refusee_si_la_periode_livreur_est_deja_validee(): void
    {
        ['commande' => $commande, 'equipe' => $equipe, 'vehicule' => $vehicule, 'livreurs' => $l] = $this->commandePartielle();

        PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202609-Q1-LIV',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-09-01',
            'date_fin' => '2026-09-15',
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);

        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->patchEquipe($equipe, $vehicule, $l, [600, 400])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('ventes.commissions.relancer', $commande))->assertSessionHasErrors('commissions');

        $this->assertStringContainsString('PAY-202609-Q1-LIV', session('errors')->first('commissions'));
        $this->assertFalse(CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->exists());
        $this->assertSame(CommissionGenerationStatut::PARTIEL, $this->derniereTentative($commande)->statut);
    }

    public function test_relance_sans_effet_sur_une_commande_dont_la_commission_a_ete_annulee(): void
    {
        ['commande' => $commande, 'equipe' => $equipe, 'vehicule' => $vehicule, 'livreurs' => $l] = $this->commandePartielle();
        CommissionEnveloppe::where('source_id', $commande->id)->update(['statut' => 'annulee']);

        $this->patchEquipe($equipe, $vehicule, $l, [600, 400])->assertSessionHasNoErrors();
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande->fresh());

        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commande->id)->count());
    }

    // ── Option A : date d'effet d'une nouvelle version de partage ────────────

    public function test_une_modification_dun_partage_deja_conforme_prend_effet_immediatement(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800, $this->bouteille);
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->bouteille, [$l[0]->id => 500, $l[1]->id => 300]);

        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->patchEquipe($equipe, $vehicule, $l, [400, 400])->assertSessionHasNoErrors();

        $this->assertSame(
            ['2026-09-20'],
            EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)->whereNull('effective_to')->pluck('effective_from')->map->toDateString()->unique()->values()->all(),
            'Un partage conforme remplacé par choix n\'est jamais rétrodaté.',
        );
        $partagesAu10 = CommissionPartageLivraisonCategorieChecker::partagesActifs($this->processus->id, $equipe->id, $this->bouteille->id, Carbon::parse('2026-09-10'));
        $this->assertSame(800, (int) $partagesAu10->sum('montant_unitaire'), 'L\'historique conforme reste intact.');
    }

    // ── Diagnostic pré-déploiement ────────────────────────────────────────────

    public function test_diagnostic_liste_les_equipes_non_conformes(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 1000, $this->bouteille);
        ['vehicule' => $nonConforme, 'equipe' => $e1, 'livreurs' => $l1] = $this->vehiculeAvecEquipe();
        $this->partage($e1, $this->bouteille, [$l1[0]->id => 500, $l1[1]->id => 300]);
        ['vehicule' => $conforme, 'equipe' => $e2, 'livreurs' => $l2] = $this->vehiculeAvecEquipe();
        $this->partage($e2, $this->bouteille, [$l2[0]->id => 600, $l2[1]->id => 400]);

        $csv = tempnam(sys_get_temp_dir(), 'diag');
        $this->artisan('commissions:diagnostiquer-partages', ['--organization' => [$this->org->id], '--csv' => $csv])
            ->expectsOutputToContain('1 non-conformité(s)')
            ->assertSuccessful();

        $contenu = file_get_contents($csv);
        $this->assertStringContainsString($nonConforme->nom_vehicule, $contenu);
        $this->assertStringNotContainsString($conforme->nom_vehicule, $contenu);
        $this->assertStringContainsString('écart 200 GNF', $contenu);
        @unlink($csv);
    }
}
