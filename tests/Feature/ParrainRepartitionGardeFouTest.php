<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parametre;
use App\Models\Parrain;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\PartageConformiteVehiculesService;
use App\Services\Commission\PartageParrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Garde-fou PARRAIN-022 : une part du parrain à 0 GNF créée par le système (ajout, remplacement,
 * reprise) n'est pas une décision. À partir de la date d'activation de l'organisation, une vente
 * est refusée — à la création, à la modification, dans l'aperçu du formulaire et au chargement —
 * sur le partage (processus × catégorie) concerné, avant toute écriture. Une part positive ou une
 * part nulle confirmée par une personne lève le blocage ; le transfert entre sites, les véhicules
 * non parrainés et les encaissements ne sont jamais concernés.
 */
class ParrainRepartitionGardeFouTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Categorie $categorie;

    private Produit $produit;

    private Vehicule $vehicule;

    private EquipeLivraison $equipe;

    /** @var list<Livreur> */
    private array $livreurs = [];

    private CommissionProcessus $vente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');

        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'ventes.demarrer_chargement', 'ventes.valider_chargement',
            'vehicules.read', 'vehicules.update',
            'equipes-livraison.read', 'equipes-livraison.create', 'equipes-livraison.update',
        ]);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = $this->user->sites()->firstOrFail();
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack 500 ml', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 5000, 'prix_usine' => 3500],
        );
        $this->vente = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $this->regle($this->vente, 1000);

        ['vehicule' => $this->vehicule, 'equipe' => $this->equipe, 'livreurs' => $this->livreurs] = $this->vehiculeAvecEquipe();
        $this->partage($this->equipe, $this->vente, [$this->livreurs[0]->id => 600, $this->livreurs[1]->id => 400]);
        $this->parrainer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function regle(CommissionProcessus $processus, int $montant): void
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => "Livreur {$processus->code}",
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => '2026-10-01',
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreurs: list<Livreur>} */
    private function vehiculeAvecEquipe(): array
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
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
        foreach (['chauffeur', 'convoyeur'] as $ordre => $role) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true, 'nom_complet' => ucfirst($role).' Test']);
            EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => $role, 'ordre' => $ordre]);
            $livreurs[] = $livreur;
        }

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreurs' => $livreurs];
    }

    /** @param  array<string, int>  $montants */
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

    /** Même chemin que StoreParrainController : ligne du parrain à 0 GNF, créée par le système. */
    private function parrainer(): Parrain
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $this->vehicule = $this->vehicule->fresh();
        DB::transaction(function () use ($parrain) {
            $ancien = $this->vehicule->parrain_id;
            $this->vehicule->update(['parrain_id' => $parrain->id]);
            PartageParrainService::appliquerChangementDeParrain($this->vehicule, $ancien);
        });

        return $parrain;
    }

    private function activer(string $date = '2026-10-23'): void
    {
        Parametre::setRepartitionParrainObligatoireDu($this->org->id, $date);
    }

    private function postVente(?Vehicule $vehicule = null)
    {
        return $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => ($vehicule ?? $this->vehicule)->id,
            'lignes' => [['produit_id' => $this->produit->id, 'qte' => 5, 'prix_vente' => 5000]],
        ]);
    }

    /** Modale Équipe : livreurs + part du parrain, confirmation explicite d'une part nulle. */
    private function enregistrerEquipe(int $chauffeur, int $convoyeur, int $parrain, bool $confirmer = false)
    {
        return $this->actingAs($this->user)->patch(route('equipes-livraison.update', $this->equipe), [
            'vehicule_id' => $this->vehicule->id,
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'membres' => array_map(fn (int $i) => [
                'livreur_id' => $this->livreurs[$i]->id,
                'nom_complet' => $this->livreurs[$i]->nom_complet,
                'telephone' => '+2246200000'.($i + 10),
                'role' => $i === 0 ? 'chauffeur' : 'convoyeur',
                'ordre' => $i,
            ], [0, 1]),
            'partages_categorie' => [[
                'categorie_id' => $this->categorie->id,
                'parts' => [
                    ['membre_ordre' => 0, 'montant_unitaire' => $chauffeur],
                    ['membre_ordre' => 1, 'montant_unitaire' => $convoyeur],
                ],
                'part_parrain' => $parrain,
                'confirmer_part_parrain_nulle' => $confirmer,
            ]],
        ]);
    }

    private function statutVente(): string
    {
        $vehicule = Vehicule::with('equipe.membres.livreur')->find($this->vehicule->id);

        return PartageConformiteVehiculesService::statuts($this->org->id, collect([$vehicule]))[$vehicule->id][CommissionProcessus::CODE_VENTE];
    }

    /** Aperçu du formulaire de vente : rejoue exactement le contrôle de création. */
    private function venteBloquee(): bool
    {
        return (bool) $this->actingAs($this->user)
            ->getJson(route('ventes.check-partage-commission', ['vehicule_id' => $this->vehicule->id, 'produit_ids' => [$this->produit->id]]))
            ->assertOk()
            ->json('bloquant');
    }

    private function brouillon(): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'client_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 25000,
            'commission_eligible_snapshot' => true,
        ]);
        $commande->lignes()->create([
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 5,
            'prix_usine_snapshot' => 3500,
            'prix_vente_snapshot' => 5000,
            'total_ligne' => 25000,
        ]);

        return $commande;
    }

    private function commandeEnChargement(): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'client_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 50000,
            'commission_eligible_snapshot' => true,
        ]);
        $commande->lignes()->create([
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => 3500,
            'prix_vente_snapshot' => 5000,
            'total_ligne' => 50000,
        ]);
        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());

        return $commande->fresh('lignes');
    }

    private function charger(CommandeVente $commande)
    {
        return $this->actingAs($this->user)->post(route('ventes.statut.avancer', $commande), [
            'lignes' => $commande->lignes->map(fn ($l) => [
                'id' => $l->id,
                'quantite_chargee' => $l->quantite_demandee,
                'type_ecart' => 'conforme',
            ])->all(),
        ]);
    }

    // ── Scénarios ────────────────────────────────────────────────────────────

    public function test_avant_activation_la_vente_reste_autorisee_et_le_partage_est_a_repartir(): void
    {
        $this->assertSame('a_repartir', $this->statutVente());

        $this->postVente()->assertSessionHasNoErrors();

        $this->assertSame(1, CommandeVente::count());
    }

    public function test_une_date_dactivation_future_ne_bloque_pas_encore(): void
    {
        $this->activer('2026-10-24');

        $this->postVente()->assertSessionHasNoErrors();
    }

    public function test_apres_activation_une_part_non_repartie_refuse_la_vente_sans_aucune_trace(): void
    {
        $this->activer();

        $this->postVente()->assertSessionHasErrors('vehicule_id');

        $this->assertStringContainsString('la part du parrain n\'a pas été répartie', session('errors')->first('vehicule_id'));
        $this->assertSame(0, CommandeVente::count());
        $this->assertDatabaseCount('commande_vente_lignes', 0);
        $this->assertDatabaseCount('mouvements_stock', 0);
        $this->assertSame(0, CommissionEnveloppe::count());
        $this->assertDatabaseCount('compta_pieces', 0);
    }

    public function test_laperçu_du_formulaire_rejoue_le_garde_fou(): void
    {
        $url = route('ventes.check-partage-commission', ['vehicule_id' => $this->vehicule->id, 'produit_ids' => [$this->produit->id]]);

        $this->actingAs($this->user)->getJson($url)->assertOk()->assertJsonPath('bloquant', false);
        $this->activer();
        $this->actingAs($this->user)->getJson($url)->assertOk()->assertJsonPath('bloquant', true);
    }

    public function test_la_modification_dune_commande_est_refusee_apres_activation(): void
    {
        $commande = $this->brouillon();
        $this->activer();

        $this->actingAs($this->user)->put(route('ventes.update', $commande), [
            'vehicule_id' => $this->vehicule->id,
            'lignes' => [['produit_id' => $this->produit->id, 'qte' => 6, 'prix_vente' => 5000]],
        ])->assertSessionHasErrors('vehicule_id');

        $this->assertSame(5, (int) $commande->fresh('lignes')->lignes->first()->quantite_demandee);
    }

    public function test_une_commande_creee_avant_lactivation_est_refusee_au_chargement(): void
    {
        $commande = $this->commandeEnChargement();
        $this->activer();

        $this->charger($commande)->assertSessionHasErrors('partage_commission');

        $this->assertSame(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
        $this->assertSame(0, CommissionEnveloppe::where('source_id', $commande->id)->count());

        $this->enregistrerEquipe(500, 300, 200)->assertSessionHasNoErrors();
        $this->charger($commande->fresh('lignes'))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
    }

    public function test_enregistrer_lequipe_sans_confirmer_la_part_nulle_laisse_le_partage_a_repartir(): void
    {
        $this->activer();

        $this->enregistrerEquipe(600, 400, 0)->assertSessionHasNoErrors();

        $this->assertSame('a_repartir', $this->statutVente());
        $this->postVente()->assertSessionHasErrors('vehicule_id');
    }

    public function test_confirmer_la_part_nulle_dans_la_modale_equipe_autorise_la_vente(): void
    {
        $this->activer();

        $this->enregistrerEquipe(600, 400, 0, confirmer: true)->assertSessionHasNoErrors();

        $this->assertSame('fait', $this->statutVente());
        $this->postVente()->assertSessionHasNoErrors();
    }

    public function test_une_part_positive_autorise_la_vente_et_la_modifier_ensuite_ne_rebloque_pas(): void
    {
        $this->activer();
        $this->enregistrerEquipe(500, 300, 200)->assertSessionHasNoErrors();
        $this->assertFalse($this->venteBloquee());
        $this->postVente()->assertSessionHasNoErrors();

        $this->enregistrerEquipe(450, 300, 250)->assertSessionHasNoErrors();
        $this->assertFalse($this->venteBloquee(), 'Modifier une part déjà décidée ne demande pas de revalidation.');

        // Remise à 0 sans confirmation : ce n'est plus une décision, la vente est refusée.
        $this->enregistrerEquipe(600, 400, 0)->assertSessionHasNoErrors();
        $this->assertTrue($this->venteBloquee());
    }

    public function test_une_part_nulle_confirmee_reste_confirmee_quand_seuls_les_livreurs_changent(): void
    {
        $this->activer();
        $this->enregistrerEquipe(600, 400, 0, confirmer: true)->assertSessionHasNoErrors();

        $this->enregistrerEquipe(700, 300, 0)->assertSessionHasNoErrors();

        $this->assertSame('fait', $this->statutVente());
        $this->postVente()->assertSessionHasNoErrors();
    }

    public function test_changer_de_parrain_remet_le_partage_a_repartir(): void
    {
        $this->activer();
        $this->enregistrerEquipe(600, 400, 0, confirmer: true)->assertSessionHasNoErrors();

        $this->parrainer();

        $this->assertSame('a_repartir', $this->statutVente());
        $this->postVente()->assertSessionHasErrors('vehicule_id');
    }

    public function test_un_vehicule_non_parraine_nest_jamais_concerne(): void
    {
        $this->activer();
        ['vehicule' => $autre, 'equipe' => $equipe, 'livreurs' => $l] = $this->vehiculeAvecEquipe();
        $this->partage($equipe, $this->vente, [$l[0]->id => 600, $l[1]->id => 400]);

        $this->postVente($autre)->assertSessionHasNoErrors();
    }

    public function test_le_blocage_porte_sur_le_seul_partage_non_reparti(): void
    {
        $logistique = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $this->regle($logistique, 1000);
        $this->partage($this->equipe, $logistique, [$this->livreurs[0]->id => 600, $this->livreurs[1]->id => 400]);
        $this->vehicule->update(['livraison_logistique' => true]);
        $this->parrainer(); // nouveau parrain : Vente et Distribution « à répartir »
        $this->activer();

        $this->enregistrerEquipe(600, 400, 0, confirmer: true)->assertSessionHasNoErrors();

        $juge = fn (string $code) => CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id,
            $this->equipe->fresh(),
            $code,
            $this->vehicule->type_vehicule_id,
            [$this->categorie->id],
            Carbon::today(),
        );
        $this->assertCount(0, $juge(CommissionProcessus::CODE_VENTE), 'Vente répartie : autorisée.');
        $distribution = $juge(CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
        $this->assertCount(1, $distribution, 'Distribution non répartie : refusée.');
        $this->assertSame(CommissionPartageLivraisonCategorieChecker::CODE_PARRAIN_A_REPARTIR, $distribution->first()['code']);
        $this->assertCount(0, $juge(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT), 'Transfert entre sites : jamais concerné.');
    }

    public function test_le_transfert_entre_sites_nest_jamais_bloque_au_titre_du_parrain(): void
    {
        $this->activer();
        $logistique = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $this->regle($logistique, 1000);
        $this->partage($this->equipe, $logistique, [$this->livreurs[0]->id => 600, $this->livreurs[1]->id => 400]);

        $nonConformites = CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id,
            $this->equipe->fresh(),
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            $this->vehicule->type_vehicule_id,
            [$this->categorie->id],
            Carbon::today(),
        );

        $this->assertCount(0, $nonConformites);
    }

    /** @return array<string, mixed> */
    private function parametresVente(?string $date): array
    {
        return [
            'autoriser_saisie_dessous_qte_max' => false,
            'controle_impayes_actif' => false,
            'seuil_impayes_max' => 0,
            'declencheur_commission_vente' => DeclencheurCommissionVente::CHARGEMENT_VALIDE->value,
            'precommande_acompte_min_pct' => 0,
            'repartition_parrain_obligatoire_du' => $date,
        ];
    }

    public function test_regler_la_date_dactivation_exige_le_droit_de_modifier_les_parametres(): void
    {
        $user = $this->makeUserWithPermissions($this->org, ['ventes.read']);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->put(route('settings.ventes.update'), $this->parametresVente('2026-10-23'))->assertForbidden();

        $this->assertNull(Parametre::getRepartitionParrainObligatoireDu($this->org->id));
    }

    public function test_une_date_dactivation_invalide_est_refusee_et_la_vider_desactive_le_blocage(): void
    {
        $user = $this->makeUserWithPermissions($this->org, ['parametres.update']);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->put(route('settings.ventes.update'), $this->parametresVente('23/10/2026'))
            ->assertSessionHasErrors('repartition_parrain_obligatoire_du');
        $this->assertNull(Parametre::getRepartitionParrainObligatoireDu($this->org->id));

        $this->activer();
        $this->assertTrue(Parametre::repartitionParrainObligatoire($this->org->id, Carbon::today()));

        $this->actingAs($user)->put(route('settings.ventes.update'), $this->parametresVente(''))->assertSessionHasNoErrors();
        $this->assertNull(Parametre::getRepartitionParrainObligatoireDu($this->org->id));
        $this->postVente()->assertSessionHasNoErrors();
    }

    public function test_le_blocage_commence_a_minuit_le_jour_de_lactivation(): void
    {
        $this->activer('2026-10-24');
        Carbon::setTestNow('2026-10-23 23:59:00');
        $this->assertFalse(Parametre::repartitionParrainObligatoire($this->org->id, Carbon::today()));

        Carbon::setTestNow('2026-10-24 00:00:00');
        $this->assertTrue(Parametre::repartitionParrainObligatoire($this->org->id, Carbon::today()));
    }

    public function test_la_date_dactivation_se_regle_dans_les_parametres_de_vente(): void
    {
        $user = $this->makeUserWithPermissions($this->org, ['parametres.update']);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->put(route('settings.ventes.update'), [
            'autoriser_saisie_dessous_qte_max' => false,
            'controle_impayes_actif' => false,
            'seuil_impayes_max' => 0,
            'declencheur_commission_vente' => DeclencheurCommissionVente::CHARGEMENT_VALIDE->value,
            'precommande_acompte_min_pct' => 0,
            'repartition_parrain_obligatoire_du' => '2026-11-02',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-11-02', Parametre::getRepartitionParrainObligatoireDu($this->org->id));
    }
}
