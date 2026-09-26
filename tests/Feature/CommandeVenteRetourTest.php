<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\EvenementComptable;
use App\Enums\MotifRetourCommande;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommandeVenteRetour;
use App\Models\CommandeVenteRetourLigne;
use App\Models\CommissionCibleType;
use App\Models\CommissionGenerationAttempt;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteRetourService;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\MouvementStockMotifService;
use App\Services\MouvementStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Retour de livraison d'une vente standard avant encaissement (décision produit du 23/09/2026,
 * cf. docs/retour-commande.md) : quantité livrée = chargée - retournée, facture recalculée, stock
 * réintégré par une ENTRÉE distincte, commission réajustée (ou annulée en cas de retour total),
 * écriture comptable de régularisation.
 *
 * Route testée : POST /ventes/{id}/retour (Ventes\EnregistrerRetourCommandeVenteController).
 */
class CommandeVenteRetourTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private const PRIX_VENTE = 2000;

    private Site $site;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'ventes.demarrer_chargement', 'ventes.valider_chargement', 'ventes.valider_reception',
            'ventes.enregistrer_retour', 'factures.encaisser',
        ]);

        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
            'statut' => 'actif',
        ]);

        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
        // Barème global : 100/pack pour l'équipe (partagé 58/42), 50/pack pour le propriétaire.
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 100,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Propriétaire — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 50,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeVehiculeAvecEquipe(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 50,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $convoyeur->id, 'role' => 'convoyeur', 'ordre' => 1]);

        $processusId = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id;
        foreach ([[$chauffeur, 58], [$convoyeur, 42]] as [$livreur, $montant]) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id, 'categorie_id' => $this->categorie->id,
                'processus_id' => $processusId,
                'livreur_id' => $livreur->id, 'part_pourcentage' => 0,
                'montant_unitaire' => $montant, 'effective_from' => now()->subDay(),
            ]);
        }

        return $vehicule->fresh();
    }

    /**
     * Commande standard avec une ligne par quantité donnée, réellement avancée par
     * CommandeVenteService (confirmer / démarrer le chargement / valider le chargement) jusqu'à
     * LIVRAISON_EN_COURS, chaque ligne chargée en totalité. Stock initial : 100 par variante.
     *
     * @param  list<int>  $quantites
     * @return array{commande: CommandeVente, lignes: list<CommandeVenteLigne>}
     */
    private function commandeEnLivraison(array $quantites = [10], ?Vehicule $vehicule = null): array
    {
        $vehicule ??= $this->makeVehiculeAvecEquipe();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => array_sum($quantites) * self::PRIX_VENTE,
        ]);

        $lignes = [];
        foreach ($quantites as $i => $quantite) {
            $produit = $this->makeProduitAvecVariante(
                $this->org,
                ['nom' => 'Produit '.($i + 1), 'categorie_id' => $this->categorie->id],
                ['prix_vente' => self::PRIX_VENTE, 'prix_usine' => 1500],
            );
            $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $this->site, 100);

            $lignes[] = $commande->lignes()->create([
                'variante_id' => $produit->variantePrincipale()->first()->id,
                'quantite_demandee' => $quantite,
                'prix_usine_snapshot' => 1500.0,
                'prix_vente_snapshot' => (float) self::PRIX_VENTE,
                'total_ligne' => $quantite * self::PRIX_VENTE,
            ]);
        }

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, array_map(fn (CommandeVenteLigne $l) => [
            'id' => $l->id,
            'quantite_chargee' => $l->quantite_demandee,
            'type_ecart' => 'conforme',
        ], $lignes));

        return ['commande' => $commande->fresh(), 'lignes' => $lignes];
    }

    /** @param  list<array{id: string, quantite: int}>  $lignes */
    /**
     * Paiement par chèque : depuis le 24/09/2026, un moyen hors espèces n'est accepté que s'il vise
     * un support actif de l'agence de la facture (cf. MoyensEncaissementResolver) — la banque de
     * l'agence est créée à la première demande.
     *
     * @return array<string, mixed>
     */
    private function paiementCheque(FactureVente $facture, int $montant): array
    {
        $banque = CompteTresorerie::firstOrCreate(
            ['organization_id' => $facture->organization_id, 'site_id' => $facture->site_id, 'type' => 'banque'],
            [
                'compte_comptable_id' => CompteComptable::where('organization_id', $facture->organization_id)
                    ->where('numero', '521000')->firstOrFail()->id,
                'libelle' => 'Banque test',
                'actif' => true,
            ],
        );

        return ['montant' => $montant, 'mode_paiement' => 'cheque', 'compte_tresorerie_id' => $banque->id];
    }

    private function posterRetour(CommandeVente $commande, array $lignes, string $motif = 'client_absent', ?string $commentaire = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->post(route('ventes.retour.store', $commande), array_filter([
            'motif' => $motif,
            'commentaire' => $commentaire,
            'lignes' => $lignes,
        ], fn ($v) => $v !== null));
    }

    private function stock(CommandeVenteLigne $ligne): int
    {
        return (int) VarianteStock::where('produit_variante_id', $ligne->variante_id)
            ->where('site_id', $this->site->id)
            ->value('qte_stock');
    }

    private function enveloppe(CommandeVente $commande, string $cible)
    {
        return $commande->commissions()->where('cible_type', $cible)->first();
    }

    // ── Retour partiel ────────────────────────────────────────────────────────

    public function test_retour_partiel_reduit_la_quantite_livree_et_recalcule_la_facture(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->assertSame(90, $this->stock($ligne));

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'client_refus', null)
            ->assertRedirect(route('ventes.show', $commande))
            ->assertSessionHasNoErrors();

        $ligne = $ligne->fresh();
        // Les quantités d'origine ne sont jamais modifiées : commandé 10, chargé 10, retourné 3, livré 7.
        $this->assertSame(10, $ligne->quantite_demandee);
        $this->assertSame(10, $ligne->quantite_chargee);
        $this->assertSame(3, $ligne->quantite_retournee);
        $this->assertSame(7, $ligne->quantite_livree);
        $this->assertEquals(7 * self::PRIX_VENTE, (float) $ligne->total_ligne);

        $commande = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        $this->assertEquals(7 * self::PRIX_VENTE, (float) $commande->total_commande);
        $this->assertEquals(7 * self::PRIX_VENTE, (float) $commande->facture->montant_net);
        $this->assertEquals(7 * self::PRIX_VENTE, (float) $commande->facture->montant_brut);
        $this->assertEquals(StatutFactureVente::IMPAYEE, $commande->facture->statut_facture);
        $this->assertSame(7, $commande->fresh()->load('lignes')->quantite_totale);
    }

    public function test_retour_trace_qui_quand_pourquoi_et_combien(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'autre', 'Portail fermé');

        $retour = $commande->retours()->with('lignes')->firstOrFail();
        $this->assertEquals(MotifRetourCommande::AUTRE, $retour->motif);
        $this->assertSame('Portail fermé', $retour->commentaire);
        $this->assertSame($this->user->id, $retour->created_by);
        $this->assertSame($this->org->id, $retour->organization_id);
        $this->assertSame(3, $retour->quantite_totale);
        $this->assertEquals(3 * self::PRIX_VENTE, (float) $retour->montant_retourne);
        $this->assertFalse($retour->retour_total);
        $this->assertCount(1, $retour->lignes);
        $this->assertSame(3, $retour->lignes->first()->quantite_retournee);
        $this->assertEquals(self::PRIX_VENTE, (float) $retour->lignes->first()->prix_unitaire);

        $activite = $commande->activites()->where('action', 'retour_enregistre')->firstOrFail();
        $this->assertSame($this->user->id, $activite->user_id);
        $this->assertSame(3, $activite->details['quantite']);
    }

    public function test_retour_partiel_reintegre_le_stock_par_une_entree_distincte(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->assertSame(90, $this->stock($ligne));

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]]);

        $this->assertSame(93, $this->stock($ligne));

        // SORTIE du chargement intacte + ENTRÉE de retour distincte, rattachée à la ligne de retour.
        $sortie = MouvementStock::where('source_type', CommandeVenteLigne::class)->where('source_id', $ligne->id)->where('type', 'sortie')->firstOrFail();
        $this->assertSame(10, (int) $sortie->quantite);
        $this->assertNull($sortie->annule_par_id);

        $retourLigne = CommandeVenteRetourLigne::firstOrFail();
        $entree = MouvementStock::where('source_type', CommandeVenteRetourLigne::class)->firstOrFail();
        $this->assertSame($retourLigne->id, $entree->source_id);
        $this->assertSame('entree', $entree->type);
        $this->assertSame(3, (int) $entree->quantite);
        $this->assertSame(90, (int) $entree->stock_avant);
        $this->assertSame(93, (int) $entree->stock_apres);
        $this->assertSame($this->site->id, $entree->site_id);
    }

    public function test_journal_de_stock_affiche_retour_livraison_avec_la_reference_de_commande(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]]);

        $entree = MouvementStock::where('source_type', CommandeVenteRetourLigne::class)->firstOrFail();
        MouvementStockMotifService::annoter(collect([$entree]));

        $this->assertSame(MouvementStockMotifService::KEY_RETOUR_LIVRAISON, $entree->motif_type);
        $this->assertSame('Retour livraison — '.$commande->reference, $entree->motif_label);
    }

    public function test_reintegration_stock_est_idempotente_pour_une_meme_ligne_de_retour(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]]);
        $retourLigne = CommandeVenteRetourLigne::firstOrFail();

        MouvementStockService::reintegrerRetour(
            $ligne->variante_id, $this->site->id, $this->org->id, 3,
            CommandeVenteRetourLigne::class, $retourLigne->id, $this->user->id,
        );

        $this->assertSame(93, $this->stock($ligne));
        $this->assertSame(1, MouvementStock::where('source_type', CommandeVenteRetourLigne::class)->count());
    }

    public function test_retour_ligne_par_ligne_ne_touche_que_les_lignes_concernees(): void
    {
        ['commande' => $commande, 'lignes' => [$l1, $l2, $l3]] = $this->commandeEnLivraison([10, 5, 20]);

        $this->posterRetour($commande, [
            ['id' => $l1->id, 'quantite' => 2],
            ['id' => $l2->id, 'quantite' => 0],
            ['id' => $l3->id, 'quantite' => 8],
        ])->assertSessionHasNoErrors();

        $this->assertSame(8, $l1->fresh()->quantite_livree);
        $this->assertNull($l2->fresh()->quantite_livree);
        $this->assertSame(0, $l2->fresh()->quantite_retournee);
        $this->assertSame(12, $l3->fresh()->quantite_livree);

        $this->assertEquals((8 + 5 + 12) * self::PRIX_VENTE, (float) $commande->fresh()->facture->montant_net);
        $this->assertSame(92, $this->stock($l1));
        $this->assertSame(95, $this->stock($l2));
        $this->assertSame(88, $this->stock($l3));
        // Une ligne à 0 n'est ni tracée ni réintégrée.
        $this->assertSame(2, CommandeVenteRetourLigne::count());
    }

    public function test_retours_partiels_successifs_puis_depassement_refuse(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 4]])->assertSessionHasNoErrors();
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();

        $ligne = $ligne->fresh();
        $this->assertSame(7, $ligne->quantite_retournee);
        $this->assertSame(3, $ligne->quantite_livree);
        $this->assertSame(2, $commande->retours()->count());
        $this->assertSame(97, $this->stock($ligne));

        // Il ne reste que 3 packs retournables : 4 est refusé, rien ne bouge.
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 4]])->assertSessionHasErrors('lignes');
        $this->assertSame(7, $ligne->fresh()->quantite_retournee);
        $this->assertSame(2, $commande->retours()->count());
        $this->assertSame(97, $this->stock($ligne));
    }

    // ── Retour total ──────────────────────────────────────────────────────────

    public function test_retour_total_passe_la_commande_en_retournee_et_annule_la_facture(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 10]], 'client_absent')
            ->assertSessionHasNoErrors();

        $commande = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::RETOURNEE, $commande->statut);
        $this->assertTrue($commande->isRetournee());
        $this->assertSame(0, $ligne->fresh()->quantite_livree);
        $this->assertEquals(0, (float) $commande->total_commande);
        $this->assertEquals(0, (float) $commande->facture->montant_net);
        $this->assertEquals(StatutFactureVente::ANNULEE, $commande->facture->statut_facture);
        $this->assertSame(100, $this->stock($ligne));
        $this->assertTrue($commande->retours()->first()->retour_total);
        $this->assertNotNull($commande->activites()->where('action', 'retournee')->first());
    }

    public function test_retours_partiels_qui_completent_le_total_passent_la_commande_en_retournee(): void
    {
        ['commande' => $commande, 'lignes' => [$l1, $l2]] = $this->commandeEnLivraison([10, 5]);

        $this->posterRetour($commande, [['id' => $l1->id, 'quantite' => 10]])->assertSessionHasNoErrors();
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);

        $this->posterRetour($commande, [['id' => $l2->id, 'quantite' => 5]])->assertSessionHasNoErrors();

        $commande = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::RETOURNEE, $commande->statut);
        $this->assertEquals(StatutFactureVente::ANNULEE, $commande->facture->statut_facture);
        $retours = $commande->retours()->reorder('id')->get();
        $this->assertFalse($retours->first()->retour_total);
        $this->assertTrue($retours->last()->retour_total);
    }

    public function test_encaissement_impossible_sur_commande_retournee(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 10]]);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $commande->fresh()->facture), [
                'montant' => 1000,
                'mode_paiement' => 'cheque',
            ])
            ->assertStatus(422);

        $this->assertSame(0, $commande->fresh()->facture->encaissements()->count());
    }

    // ── Après un retour : la facture ne se règle que sur le livré ─────────────

    public function test_facture_encaissable_uniquement_sur_le_montant_livre_apres_retour(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]]);
        $facture = $commande->fresh()->facture;
        $this->assertEquals(14000, (float) $facture->montant_restant);

        // Au-delà du restant dû (14 000) : refusé.
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), $this->paiementCheque($facture, 20000))
            ->assertSessionHasErrors('montant');

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), $this->paiementCheque($facture, 14000))
            ->assertSessionHasNoErrors();

        $this->assertEquals(StatutFactureVente::PAYEE, $facture->fresh()->statut_facture);
    }

    // ── Refus : conditions métier ─────────────────────────────────────────────

    public function test_retour_refuse_hors_livraison_en_cours(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);
        $produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'P'], ['prix_vente' => 2000, 'prix_usine' => 1500]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 5, 'quantite_chargee' => 5,
            'prix_usine_snapshot' => 1500.0, 'prix_vente_snapshot' => 2000.0, 'total_ligne' => 10000.0,
        ]);

        $this->assertNotNull($commande->raisonRetourImpossible());
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 1]])->assertForbidden();
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_retour_refuse_apres_un_encaissement(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $commande->facture), $this->paiementCheque($commande->facture, 2000))
            ->assertSessionHasNoErrors();
        // Le premier encaissement fait passer une vente standard en LIVREE.
        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertForbidden();

        $this->assertSame(0, $commande->retours()->count());
        $this->assertSame(0, $ligne->fresh()->quantite_retournee);
        $this->assertSame(90, $this->stock($ligne));
    }

    public function test_retour_refuse_si_un_encaissement_existe_deja_malgre_le_statut(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        // Encaissement créé hors du contrôleur (import, API…) : la commande reste en livraison.
        $commande->facture->encaissements()->create([
            'montant' => 1000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'cheque',
        ]);
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertForbidden();
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_retour_refuse_pour_une_commande_a_reception_explicite(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $commande->update(['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT]);

        $this->assertTrue($commande->fresh()->requiertReceptionExplicite());
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertForbidden();
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_service_refuse_meme_quand_la_policy_est_contournee(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $commande->update(['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT]);

        $this->expectException(ValidationException::class);

        CommandeVenteRetourService::enregistrer(
            $commande->fresh(),
            [['id' => $ligne->id, 'quantite' => 3]],
            MotifRetourCommande::CLIENT_ABSENT,
        );
    }

    // ── Validation de la saisie ───────────────────────────────────────────────

    public function test_motif_obligatoire(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->actingAs($this->user)
            ->post(route('ventes.retour.store', $commande), ['lignes' => [['id' => $ligne->id, 'quantite' => 3]]])
            ->assertSessionHasErrors('motif');

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'inconnu')->assertSessionHasErrors('motif');
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_motif_autre_exige_un_commentaire(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'autre')->assertSessionHasErrors('commentaire');
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'autre', '   ')->assertSessionHasErrors('commentaire');
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_au_moins_une_quantite_est_exigee(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 0]])->assertSessionHasErrors('lignes');
        $this->assertSame(0, $commande->retours()->count());
        $this->assertSame(90, $this->stock($ligne));
    }

    public function test_quantite_superieure_au_charge_refusee_sans_effet_de_bord(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 11]])->assertSessionHasErrors('lignes');

        $this->assertSame(0, $ligne->fresh()->quantite_retournee);
        $this->assertNull($ligne->fresh()->quantite_livree);
        $this->assertEquals(10 * self::PRIX_VENTE, (float) $commande->fresh()->facture->montant_net);
        $this->assertSame(90, $this->stock($ligne));
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_ligne_d_une_autre_commande_refusee(): void
    {
        ['commande' => $commande] = $this->commandeEnLivraison([10]);
        ['lignes' => [$autreLigne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $autreLigne->id, 'quantite' => 3]])->assertSessionHasErrors('lignes');
        $this->assertSame(0, $autreLigne->fresh()->quantite_retournee);
    }

    // ── Autorisations et isolation organisationnelle ──────────────────────────

    public function test_permission_dediee_obligatoire(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $sansPermission = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.update', 'ventes.valider_reception']);
        $sansPermission->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'client_absent', null, $sansPermission)->assertForbidden();
        $this->assertSame(0, $commande->retours()->count());

        $avecPermission = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.enregistrer_retour']);
        $avecPermission->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'client_absent', null, $avecPermission)->assertSessionHasNoErrors();
        $this->assertSame(1, $commande->retours()->count());
    }

    public function test_isolation_organisationnelle(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $autreOrg = Organization::factory()->create();
        $intrus = $this->makeUserWithPermissions($autreOrg, ['ventes.read', 'ventes.enregistrer_retour']);
        $siteAutre = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre', 'type' => 'depot', 'localisation' => 'Kindia']);
        $intrus->sites()->attach($siteAutre->id, ['role' => 'employe', 'is_default' => true]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'client_absent', null, $intrus)->assertForbidden();

        $this->assertSame(0, $commande->retours()->count());
        $this->assertSame(0, $ligne->fresh()->quantite_retournee);
        $this->assertSame(90, $this->stock($ligne));
    }

    // ── Commission ────────────────────────────────────────────────────────────

    public function test_commission_generee_au_chargement_est_recalculee_sur_la_quantite_facturee(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->assertEquals(500, (float) $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE)->montant_total);
        $this->assertEquals(1000, (float) $this->enveloppe($commande, CommissionCibleType::CODE_EQUIPE_LIVRAISON)->montant_total);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();

        // Commission calculée sur 7 packs facturés, plus sur 10 chargés.
        $proprietaire = $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE);
        $equipe = $this->enveloppe($commande, CommissionCibleType::CODE_EQUIPE_LIVRAISON);
        $this->assertEquals(350, (float) $proprietaire->montant_total);
        $this->assertEquals(700, (float) $equipe->montant_total);
        $this->assertEquals(StatutCommission::CREEE, $proprietaire->statut);
        $this->assertEquals(StatutCommission::CREEE, $equipe->statut);
        $this->assertEquals(700, (float) $equipe->parts()->sum('montant_net'));
        $this->assertEquals(7, (float) $proprietaire->lignes()->first()->quantite);

        // Une seule enveloppe par cible : l'ancienne est remplacée, pas dupliquée.
        $this->assertSame(2, $commande->commissions()->count());
    }

    public function test_commission_reajustee_conserve_sa_date_de_gain_d_origine(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        // Hier : barèmes et partage d'équipe rendus effectifs dès avant-hier, donc encore applicables
        // à cette date de gain — la commission régénérée doit rester rattachée à SA date d'origine,
        // jamais à aujourd'hui.
        $hier = now()->subDay()->toDateString();
        CommissionRegle::query()->update(['effective_from' => now()->subDays(2)->toDateString()]);
        EquipeLivraisonPartageCategorie::query()->update(['effective_from' => now()->subDays(2)->toDateString()]);
        $commande->commissions()->update(['earned_at' => $hier]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]]);

        $enveloppes = $commande->commissions()->get();
        $this->assertCount(2, $enveloppes);
        foreach ($enveloppes as $enveloppe) {
            $this->assertSame($hier, $enveloppe->earned_at->toDateString());
        }
    }

    public function test_retour_total_annule_la_commission(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->assertSame(2, $commande->commissions()->count());

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 10]])->assertSessionHasNoErrors();

        $enveloppes = $commande->commissions()->with('parts')->get();
        $this->assertCount(2, $enveloppes);
        foreach ($enveloppes as $enveloppe) {
            $this->assertEquals(StatutCommission::ANNULEE, $enveloppe->statut);
            foreach ($enveloppe->parts as $part) {
                $this->assertEquals(StatutCommission::ANNULEE, $part->statut);
            }
        }
    }

    public function test_retour_refuse_si_la_commission_est_deja_validee_dans_une_periode(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        // Période de paiement validée : la part est sortie de CREEE (cf. CommissionAdjustmentService).
        $enveloppe = $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE);
        $enveloppe->update(['statut' => StatutCommission::IMPAYE->value]);
        $enveloppe->parts()->update(['statut' => StatutCommission::IMPAYE->value]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasErrors('retour');

        // Rien n'a bougé : ni quantité, ni facture, ni stock, ni commission.
        $this->assertSame(0, $ligne->fresh()->quantite_retournee);
        $this->assertEquals(10 * self::PRIX_VENTE, (float) $commande->fresh()->facture->montant_net);
        $this->assertSame(90, $this->stock($ligne));
        $this->assertEquals(500, (float) $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE)->montant_total);
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_retour_refuse_si_la_commission_a_ete_ajustee_a_la_main(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $enveloppe = $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE);
        $enveloppe->parts()->update(['montant_actuel' => 400]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasErrors('retour');
        $this->assertSame(0, $commande->retours()->count());
    }

    public function test_sous_declencheur_facture_encaissee_la_commission_naitra_sur_la_quantite_facturee(): void
    {
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::FACTURE_ENCAISSEE);
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $this->assertSame(0, $commande->commissions()->count());

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();
        $this->assertSame(0, $commande->commissions()->count());

        $facture = $commande->fresh()->facture;
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), $this->paiementCheque($facture, 14000))
            ->assertSessionHasNoErrors();

        $this->assertEquals(350, (float) $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE)->montant_total);
        $this->assertEquals(700, (float) $this->enveloppe($commande, CommissionCibleType::CODE_EQUIPE_LIVRAISON)->montant_total);
    }

    // ── Comptabilité ──────────────────────────────────────────────────────────

    public function test_retour_partiel_regularise_l_ecriture_comptable_de_la_vente(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $facture = $commande->facture;
        $this->assertNotNull(PieceComptable::where('source_id', $facture->id)->where('type_evenement', EvenementComptable::VENTE_FACTUREE->value)->first());

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();

        $retour = $commande->retours()->firstOrFail();
        $piece = PieceComptable::where('source_id', $retour->id)
            ->where('type_evenement', EvenementComptable::VENTE_RETOUR->value)
            ->with('lignes')
            ->firstOrFail();

        // Écriture inverse de la vente, sur la seule valeur retournée (3 × 2 000), équilibrée.
        $this->assertEquals(6000, (float) $piece->lignes->sum('debit'));
        $this->assertEquals(6000, (float) $piece->lignes->sum('credit'));
        $this->assertCount(2, $piece->lignes);
    }

    public function test_retours_successifs_donnent_une_piece_par_retour_dont_la_somme_annule_la_vente(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]]);
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 7]]);

        $pieces = PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->with('lignes')->get();
        $this->assertCount(2, $pieces);
        $this->assertEquals(20000, (float) $pieces->flatMap->lignes->sum('debit'));
        // Aucune contrepassation de la pièce d'origine : elle reste valide.
        $this->assertSame(0, PieceComptable::where('type_evenement', 'like', 'contrepassation_de_%')->count());
    }

    public function test_un_echec_de_comptabilisation_n_empeche_pas_le_retour(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        CompteMapping::where('organization_id', $this->org->id)->where('evenement', 'vente_retour')->delete();

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();

        $this->assertSame(1, $commande->retours()->count());
        $this->assertSame(7, $ligne->fresh()->quantite_livree);
        $this->assertSame(93, $this->stock($ligne));
    }

    // ── Échec de comptabilisation : traçabilité et reprise ────────────────────

    public function test_echec_de_comptabilisation_est_signale_trace_puis_rattrape_sans_doublon(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        CompteMapping::where('organization_id', $this->org->id)->where('evenement', 'vente_retour')->delete();

        // Le retour reste valide (quantités, facture, stock, commission)…
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            // … mais l'utilisateur est averti que la comptabilité reste à régulariser.
            ->assertSessionHas('warning');

        $this->assertSame(7, $ligne->fresh()->quantite_livree);
        $this->assertEquals(14000, (float) $commande->fresh()->facture->montant_net);
        $this->assertSame(93, $this->stock($ligne));
        $this->assertEquals(350, (float) $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE)->montant_total);
        $this->assertSame(0, PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->count());

        // Trace visible sur la fiche de la commande.
        $trace = $commande->activites()->where('action', 'comptabilisation_retour_echouee')->firstOrFail();
        $this->assertSame($commande->retours()->first()->id, $trace->details['retour_id']);
        $this->assertStringContainsString('à régulariser', $trace->action_label);

        // Retrouvable par l'audit (métier ↔ compta), qui échoue tant que la pièce manque.
        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])
            ->expectsOutputToContain('Retours de livraison')
            ->assertExitCode(1);

        // Reprise : mappings rétablis, puis rattrapage — idempotent.
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['retour']])->assertExitCode(0);
        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['retour']])->assertExitCode(0);

        $pieces = PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->with('lignes')->get();
        $this->assertCount(1, $pieces);
        $this->assertEquals(6000, (float) $pieces->first()->lignes->sum('debit'));

        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])->assertExitCode(0);
    }

    public function test_rattrapage_ne_compte_pas_deux_fois_un_retour_deja_inclus_dans_la_vente_rattrapee(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        // La comptabilisation de la vente avait échoué à l'origine : aucune pièce vente_facturee.
        $facture = $commande->facture;
        PieceComptable::where('source_id', $facture->id)->where('type_evenement', EvenementComptable::VENTE_FACTUREE->value)->delete();

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();
        // Sans pièce de vente préalable, le retour n'appelle aucune régularisation…
        $this->assertSame(0, PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->count());

        // … et le rattrapage de la vente, postée APRÈS le retour, la comptabilise directement au net.
        CommandeVenteRetour::query()->update(['created_at' => now()->subHour()]);
        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['vente', 'retour']])->assertExitCode(0);

        $vente = PieceComptable::where('source_id', $facture->id)->where('type_evenement', EvenementComptable::VENTE_FACTUREE->value)->with('lignes')->firstOrFail();
        $this->assertEquals(14000, (float) $vente->lignes->sum('credit'));
        $this->assertSame(0, PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->count());
        $this->artisan('comptabilite:auditer', ['--organization' => [$this->org->id]])->assertExitCode(0);
    }

    // ── Retours successifs : aucun double effet ───────────────────────────────

    public function test_deux_retours_successifs_de_3_puis_2_cumulent_sans_double_effet(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 2]])->assertSessionHasNoErrors();

        // Chargé 10 · retours 3 + 2 · livré 5 · facturé 5.
        $ligne = $ligne->fresh();
        $this->assertSame(10, $ligne->quantite_chargee);
        $this->assertSame(5, $ligne->quantite_retournee);
        $this->assertSame(5, $ligne->quantite_livree);
        $commande = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->statut);
        $this->assertEquals(5 * self::PRIX_VENTE, (float) $commande->facture->montant_net);
        $this->assertSame(2, $commande->retours()->count());

        // Stock : sortie du chargement -10 conservée, deux entrées +3 et +2 (jamais de double
        // réintégration) → 95.
        $this->assertSame(95, $this->stock($ligne));
        $entrees = MouvementStock::where('source_type', CommandeVenteRetourLigne::class)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $entrees);
        $this->assertEqualsCanonicalizing([3, 2], $entrees->pluck('quantite')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(1, MouvementStock::where('source_type', CommandeVenteLigne::class)->where('source_id', $ligne->id)->where('type', 'sortie')->count());

        // Commission : une seule enveloppe par cible (l'ancienne remplacée, jamais dupliquée), sur 5 packs.
        $this->assertSame(2, $commande->commissions()->count());
        $proprietaire = $this->enveloppe($commande, CommissionCibleType::CODE_PROPRIETAIRE);
        $equipe = $this->enveloppe($commande, CommissionCibleType::CODE_EQUIPE_LIVRAISON);
        $this->assertEquals(250, (float) $proprietaire->montant_total);
        $this->assertEquals(500, (float) $equipe->montant_total);
        $this->assertEquals(StatutCommission::CREEE, $proprietaire->statut);
        $this->assertEquals(5, (float) $proprietaire->lignes()->first()->quantite);
        // Historique : une tentative de génération par événement (chargement + 2 retours).
        $this->assertSame(3, CommissionGenerationAttempt::where('source_id', $commande->id)->count());

        // Comptabilité : une pièce de vente d'origine + une pièce de régularisation PAR retour.
        $this->assertSame(1, PieceComptable::where('type_evenement', EvenementComptable::VENTE_FACTUREE->value)->count());
        $regularisations = PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->with('lignes')->get();
        $this->assertCount(2, $regularisations);
        $this->assertEqualsCanonicalizing([6000.0, 4000.0], $regularisations->map(fn ($p) => (float) $p->lignes->sum('debit'))->all());
        $this->assertSame(0, PieceComptable::where('type_evenement', 'like', 'contrepassation_de_%')->count());
    }

    public function test_retour_partiel_puis_retour_du_solde_annule_la_commission_sans_double_annulation(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]])->assertSessionHasNoErrors();
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 7]])->assertSessionHasNoErrors();

        $commande = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::RETOURNEE, $commande->statut);
        $this->assertEquals(StatutFactureVente::ANNULEE, $commande->facture->statut_facture);
        $this->assertSame(100, $this->stock($ligne));

        // Deux enveloppes (régénérées au 1er retour), toutes annulées au 2e — jamais 4.
        $enveloppes = $commande->commissions()->with('parts')->get();
        $this->assertCount(2, $enveloppes);
        foreach ($enveloppes as $enveloppe) {
            $this->assertEquals(StatutCommission::ANNULEE, $enveloppe->statut);
            $this->assertTrue($enveloppe->parts->every(fn ($p) => $p->statut === StatutCommission::ANNULEE));
        }

        // Les régularisations comptables cumulées annulent exactement la vente d'origine.
        $regularisations = PieceComptable::where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->with('lignes')->get();
        $this->assertCount(2, $regularisations);
        $this->assertEquals(20000, (float) $regularisations->flatMap->lignes->sum('debit'));
    }

    // ── Affichage (fiche vente) ───────────────────────────────────────────────

    public function test_fiche_vente_expose_le_bouton_retour_les_quantites_et_l_historique(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);

        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.can_enregistrer_retour', true)
                ->where('commande.is_retournee', false)
                ->where('commande.lignes.0.quantite_retournable', 10)
                ->has('motifs_retour', 6)
                ->has('retours', 0)
            );

        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 3]], 'client_refus');

        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.can_enregistrer_retour', true)
                ->where('commande.lignes.0.quantite_retournee', 3)
                ->where('commande.lignes.0.quantite_retournable', 7)
                ->where('commande.lignes.0.quantite_livree', 7)
                ->where('retours.0.motif', 'client_refus')
                ->where('retours.0.quantite_totale', 3)
                ->where('retours.0.retour_total', false)
                ->where('retours.0.created_by', $this->user->name)
            );
    }

    public function test_fiche_vente_masque_le_bouton_sans_permission_ou_hors_livraison(): void
    {
        ['commande' => $commande, 'lignes' => [$ligne]] = $this->commandeEnLivraison([10]);
        $sansPermission = $this->makeUserWithPermissions($this->org, ['ventes.read']);
        $sansPermission->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansPermission)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_enregistrer_retour', false));

        // Retour total : la commande est terminale, plus aucun retour possible.
        $this->posterRetour($commande, [['id' => $ligne->id, 'quantite' => 10]]);
        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.can_enregistrer_retour', false)
                ->where('commande.is_retournee', true)
                ->where('commande.statut', 'retournee')
                ->where('commande.statut_label', 'Retournée')
                ->where('commande.can_encaisser', false)
            );
    }

    public function test_bouton_retour_masque_pour_super_admin_hors_livraison(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($superAdmin)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_enregistrer_retour', false));
    }

    // ── Modèle ────────────────────────────────────────────────────────────────

    public function test_quantite_nette_chargee_et_retournable(): void
    {
        $ligne = new CommandeVenteLigne(['quantite_demandee' => 10, 'quantite_chargee' => 10, 'quantite_retournee' => 4]);
        $this->assertSame(6, $ligne->quantite_nette_chargee);
        $this->assertSame(6, $ligne->quantite_retournable);

        $nonChargee = new CommandeVenteLigne(['quantite_demandee' => 10]);
        $this->assertNull($nonChargee->quantite_nette_chargee);
        $this->assertSame(0, $nonChargee->quantite_retournable);
    }

    public function test_statut_retournee_est_terminal_et_non_encaissable(): void
    {
        $this->assertTrue(StatutCommandeVente::RETOURNEE->isTerminal());
        $this->assertFalse(StatutCommandeVente::RETOURNEE->isAnnulable());
        $this->assertFalse(StatutCommandeVente::RETOURNEE->isEditable());
        $this->assertSame('Retournée', StatutCommandeVente::RETOURNEE->label());

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'statut' => StatutCommandeVente::RETOURNEE,
        ]);
        $this->assertFalse($commande->isEncaissable());
    }
}
