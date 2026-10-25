<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\EvenementComptable;
use App\Enums\MotifAjustementCommission;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\AuditLog;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionPartAdjustment;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Parametre;
use App\Models\Parrain;
use App\Models\PieceComptable;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\PartageLivraisonVersionService;
use App\Services\Commission\PartageParrainService;
use App\Services\Commission\PartParrainService;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use App\Services\PeriodeValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Date de début des droits du parrain (PARRAIN-023, décisions du 24/10/2026) :
 *  - future : la répartition prend effet à cette date, rien ne change avant ;
 *  - passée : répartition antidatée, commission Livreur des commandes gagnées depuis recalculée
 *    (part du parrain prise sur l'enveloppe, total inchangé), automatiquement, avec l'équipe et
 *    la répartition actuelles ;
 *  - option B : commissions non payées seulement (validées et ajustées comprises), période
 *    validée rouverte (écritures contrepassées) ; jamais une période clôturée ;
 *  - idempotent ; les autres bénéficiaires et le transfert entre sites ne sont jamais touchés.
 * Barème Livreur Vente 1 000 GNF/pack, équipe 600 / 400, puis 500 / 300 / parrain 200.
 */
class ParrainDroitsRetroactifsTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 10:00:00');

        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'vehicules.read', 'vehicules.update',
            'equipes-livraison.read', 'equipes-livraison.create', 'equipes-livraison.update',
            'comptabilite.read',
        ]);
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->site = $this->user->sites()->firstOrFail();
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet d\'eau', 'statut' => 'actif']);
        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack de 25 Sachets', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
            'is_active' => true,
        ]);
        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe',
            'is_active' => true,
        ]);
        foreach (['chauffeur', 'convoyeur'] as $ordre => $role) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
            EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $livreur->id, 'role' => $role, 'ordre' => $ordre]);
            $this->{$role} = $livreur;
        }

        $this->vente = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        foreach ([CommissionCibleType::CODE_EQUIPE_LIVRAISON => [1000, CommissionMode::A_REPARTIR], CommissionCibleType::CODE_PROPRIETAIRE => [300, CommissionMode::DIRECT]] as $cible => [$montant, $mode]) {
            CommissionRegle::create([
                'organization_id' => $this->org->id,
                'processus_id' => $this->vente->id,
                'libelle' => $cible,
                'scope_type' => CommissionScopeType::CATEGORIE->value,
                'scope_id' => $this->categorie->id,
                'cible_type' => $cible,
                'mode' => $mode->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => $montant,
                'effective_from' => '2026-09-01',
                'statut' => CommissionRegleStatut::ACTIVE->value,
            ]);
        }
        foreach ([$this->chauffeur->id => 600, $this->convoyeur->id => 400] as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $this->equipe->id,
                'processus_id' => $this->vente->id,
                'categorie_id' => $this->categorie->id,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => '2026-09-01',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function vendre(int $qte): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'statut' => StatutCommandeVente::BROUILLON,
            'commission_eligible_snapshot' => true,
            'total_commande' => $qte * 2000,
        ]);
        $ligne = $commande->lignes()->create([
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => $qte,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => $qte * 2000,
        ]);
        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        CommandeVenteService::validerChargement($commande->fresh(), [
            ['id' => $ligne->id, 'quantite_chargee' => $qte, 'type_ecart' => 'conforme'],
        ]);

        return $commande->fresh();
    }

    /** Ajout du parrain par le même chemin que StoreParrainController (ligne à 0 GNF). */
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

    /** @param  array<string, int>  $livreurs */
    private function definir(array $livreurs, int $parrain, string $date, bool $remplacer = false): ?array
    {
        return PartParrainService::definir(
            $this->vehicule->fresh(),
            $this->vehicule->fresh()->parrain_id,
            [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $this->categorie->id,
                'parts' => collect($livreurs)->map(fn (int $m, string $id) => ['livreur_id' => $id, 'montant_unitaire' => $m])->values()->all(),
                'part_parrain' => $parrain,
            ]],
            confirmerPartNulle: true,
            dateEffet: Carbon::parse($date),
            remplacerPlanification: $remplacer,
        );
    }

    /** Saisie en place de l'onglet Parrain (PARRAIN-024) : la part du parrain seule, Vente. */
    private function definirPart(int $part, string $date)
    {
        return $this->actingAs($this->user)->put(route('vehicules.parrain.part.update', $this->vehicule), [
            'parrain_id' => $this->vehicule->fresh()->parrain_id,
            'date_effet' => $date,
            'confirmer_part_nulle' => true,
            'parts' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $this->categorie->id,
                'part_parrain' => $part,
            ]],
        ]);
    }

    private function droitsEnAttente(Parrain $parrain): ?string
    {
        return PartageLivraisonVersionService::droitsEnAttente($this->equipe->id, $this->vente->id, $this->categorie->id, $parrain->id)?->toDateString();
    }

    /** Modale Équipe (processus Vente) : livreurs + part du parrain (null : véhicule sans parrain). */
    private function enregistrerEquipe(int $chauffeur, int $convoyeur, ?int $parrain, bool $confirmerDroits = false)
    {
        return $this->actingAs($this->user)->patch(route('equipes-livraison.update', $this->equipe), [
            'vehicule_id' => $this->vehicule->id,
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'membres' => array_map(fn (int $i) => [
                'livreur_id' => [$this->chauffeur, $this->convoyeur][$i]->id,
                'nom_complet' => [$this->chauffeur, $this->convoyeur][$i]->nom_complet,
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
            ] + ($parrain === null ? [] : [
                'part_parrain' => $parrain,
                'confirmer_part_parrain_nulle' => true,
            ])],
            'confirmer_droits_parrain' => $confirmerDroits,
        ]);
    }

    /** @return array<string, float> "livreur:id" | "parrain:id" => montant net, enveloppe Livreur active */
    private function partsEquipe(CommandeVente $commande): array
    {
        return CommissionEnveloppe::where('source_type', CommandeVente::class)
            ->where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->sole()
            ->parts()
            ->get()
            ->mapWithKeys(fn (CommissionEnveloppePart $p) => ["{$p->beneficiaire_type}:{$p->beneficiaire_id}" => (float) $p->montant_net])
            ->sortKeys()
            ->all();
    }

    /** @param  array<string, float>  $attendu */
    private function assertPartsEquipe(array $attendu, CommandeVente $commande, string $message = ''): void
    {
        ksort($attendu);
        $this->assertSame($attendu, $this->partsEquipe($commande), $message);
    }

    private function enveloppeProprietaire(CommandeVente $commande): CommissionEnveloppe
    {
        return CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)
            ->sole();
    }

    private function periodeLivreurs(): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, Carbon::parse('2026-10-02'));
        app(PeriodeCalculatorService::class)->calculerSiNecessaire($periode);

        return $periode->fresh();
    }

    /** Validation par une personne des parts Livreur, puis de la période (comptabilisation). */
    private function validerPeriodeLivreurs(): PaiementPeriode
    {
        CommissionEnveloppePart::where('beneficiaire_type', 'livreur')
            ->update(['validated_at' => now(), 'validated_by' => $this->user->id]);
        $periode = $this->periodeLivreurs();
        // Toutes les parts validées : la période se valide d'elle-même au calcul (ADR 0008).
        if (! $periode->isValidee()) {
            $this->assertNull(app(PeriodeValidationService::class)->valider($periode, $this->user));
        }

        return $periode->fresh();
    }

    // ── Scénarios ────────────────────────────────────────────────────────────

    public function test_une_date_future_ne_change_rien_avant_elle(): void
    {
        $parrain = $this->parrainer();

        $bilan = $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-05');

        $this->assertNull($bilan, 'Aucun recalcul pour une date future.');
        $avant = $this->vendre(10);
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 6000.0,
            "livreur:{$this->convoyeur->id}" => 4000.0,
            "parrain:{$parrain->id}" => 0.0,
        ], $avant);

        Carbon::setTestNow('2026-10-05 09:00:00');
        $apres = $this->vendre(10);
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 5000.0,
            "livreur:{$this->convoyeur->id}" => 3000.0,
            "parrain:{$parrain->id}" => 2000.0,
        ], $apres);
    }

    public function test_une_date_passee_recalcule_les_commandes_depuis_cette_date_sans_toucher_aux_autres(): void
    {
        $avantDebut = $this->vendre(10);            // 02/10 — avant la date de début choisie
        Carbon::setTestNow('2026-10-03 10:00:00');
        $commande = $this->vendre(100);             // 03/10
        $proprietaire = $this->enveloppeProprietaire($commande);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();
        $bilan = $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-03');

        $this->assertSame([$commande->reference], $bilan['recalculees']);
        $this->assertSame([], $bilan['exclues']);
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $commande);
        $this->assertPartsEquipe(["livreur:{$this->chauffeur->id}" => 6000.0, "livreur:{$this->convoyeur->id}" => 4000.0], $avantDebut);
        $this->assertSame($proprietaire->id, $this->enveloppeProprietaire($commande)->id, 'Propriétaire jamais touché.');
        $this->assertSame('2026-10-03', Carbon::parse(CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->sole()->earned_at)->toDateString());
    }

    public function test_le_recalcul_est_idempotent(): void
    {
        $commande = $this->vendre(100);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();

        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->count());
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $commande);
    }

    public function test_une_periode_validee_est_rouverte_et_ses_ecritures_contrepassees(): void
    {
        $this->vendre(100);
        $periode = $this->validerPeriodeLivreurs();
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->statut);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->parrainer();
        $bilan = $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $this->assertCount(1, $bilan['recalculees']);
        $periode = $periode->fresh();
        $this->assertNotSame(StatutPeriodePaiement::VALIDEE, $periode->statut, 'Rouverte : les nouvelles parts Livreur sont à revalider.');
        $this->assertEquals(80000.0, (float) PaiementFiche::where('periode_id', $periode->id)->sum('montant_net'));
    }

    public function test_une_fiche_deja_payee_est_exclue_du_recalcul(): void
    {
        $commande = $this->vendre(100);
        $periode = $this->validerPeriodeLivreurs();
        $fiche = PaiementFiche::where('periode_id', $periode->id)->where('beneficiaire_id', $this->chauffeur->id)->sole();
        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => 1000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-10-04',
        ]);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->parrainer();
        $bilan = $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $this->assertSame([], $bilan['recalculees']);
        $this->assertSame([['reference' => $commande->reference, 'motif' => 'fiche de paiement déjà payée']], $bilan['exclues']);
        $this->assertPartsEquipe(["livreur:{$this->chauffeur->id}" => 60000.0, "livreur:{$this->convoyeur->id}" => 40000.0], $commande);
    }

    public function test_une_date_dans_une_periode_cloturee_est_refusee(): void
    {
        $this->vendre(100);
        $periode = $this->periodeLivreurs();
        $periode->update(['statut' => StatutPeriodePaiement::CLOTUREE->value]);

        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->parrainer();

        try {
            $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-10');
            $this->fail('Une date dans une période clôturée doit être refusée.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('au plus tôt le 16/10/2026', $e->errors()['date_effet'][0]);
        }
        $this->assertNotNull($this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-16'));
    }

    public function test_un_ajustement_manuel_est_recalcule(): void
    {
        $commande = $this->vendre(100);
        CommissionEnveloppePart::where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $this->chauffeur->id)
            ->update(['montant_actuel' => 55000]);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();
        $bilan = $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $this->assertSame([$commande->reference], $bilan['recalculees']);
        $this->assertSame(0, CommissionEnveloppePart::whereNotNull('montant_actuel')->count(), 'Ajustement manuel remplacé par le recalcul.');
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $commande);
    }

    public function test_lequipe_actuelle_sapplique_a_toute_la_periode(): void
    {
        $commande = $this->vendre(100);

        // 04/10 : le convoyeur quitte l'équipe, le chauffeur reprend tout le barème.
        Carbon::setTestNow('2026-10-04 10:00:00');
        EquipeLivreur::where('livreur_id', $this->convoyeur->id)->delete();
        $parrain = $this->parrainer();
        $bilan = $this->definir([$this->chauffeur->id => 800], 200, '2026-10-01');

        $this->assertSame([$commande->reference], $bilan['recalculees']);
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 80000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $commande);
    }

    public function test_lecran_enregistre_une_date_passee_et_affiche_le_bilan(): void
    {
        $commande = $this->vendre(100);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();

        $this->actingAs($this->user)->getJson(route('vehicules.parrain.part', $this->vehicule))
            ->assertOk()
            ->assertJsonPath('date_effet_min', null);

        // Saisie en place (PARRAIN-024) : une date passée exige un partage complet — un seul
        // livreur, qui reçoit le reste du barème.
        EquipeLivreur::where('livreur_id', $this->convoyeur->id)->delete();

        $this->actingAs($this->user)->put(route('vehicules.parrain.part.update', $this->vehicule), [
            'parrain_id' => $parrain->id,
            'date_effet' => '2026-10-01',
            'confirmer_part_nulle' => false,
            'parts' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $this->categorie->id,
                'part_parrain' => 200,
            ]],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 commande(s) recalculée(s) depuis le 01/10/2026'));

        $this->assertSame(
            ["livreur:{$this->chauffeur->id}" => 80000.0, "parrain:{$parrain->id}" => 20000.0],
            $this->partsEquipe($commande),
        );
    }

    // ── Plusieurs livreurs : date de début en attente de la répartition (PARRAIN-024) ──

    public function test_plusieurs_livreurs_la_date_passee_attend_la_repartition_de_lequipe_puis_recalcule(): void
    {
        $commande = $this->vendre(100);           // 02/10
        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();

        $this->definirPart(200, '2026-10-01')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'onglet Équipe'));

        // Partage déséquilibré : jamais de recalcul, la date reste en attente.
        $this->assertSame('2026-10-01', $this->droitsEnAttente($parrain));
        $partsAvant = ["livreur:{$this->chauffeur->id}" => 60000.0, "livreur:{$this->convoyeur->id}" => 40000.0];
        $this->assertPartsEquipe($partsAvant, $commande);

        // Sans confirmation explicite : refusé, rien ne change.
        $this->enregistrerEquipe(500, 300, 200)->assertSessionHasErrors('confirmer_droits_parrain');
        $this->assertPartsEquipe($partsAvant, $commande);
        $this->assertSame('2026-10-01', $this->droitsEnAttente($parrain));

        $this->enregistrerEquipe(500, 300, 200, confirmerDroits: true)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, '1 commande(s) recalculée(s) depuis le 01/10/2026'));

        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $commande);
        $this->assertNull($this->droitsEnAttente($parrain), 'Date appliquée : plus rien en attente.');
        $this->assertSame('2026-10-01', EquipeLivraisonPartageCategorie::where('equipe_id', $this->equipe->id)
            ->where('parrain_id', $parrain->id)->whereNull('effective_to')->value('effective_from')?->toDateString());
    }

    public function test_des_droits_en_attente_ne_sont_jamais_perdus_par_une_autre_ecriture(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();
        $this->definirPart(200, '2026-10-01')->assertSessionHasNoErrors();

        // Transfert de livreur, reconfiguration… : refusés tant que la répartition n'est pas faite.
        try {
            PartageLivraisonVersionService::versionner(
                $this->equipe->id,
                $this->vente->id,
                $this->categorie->id,
                [$this->chauffeur->id => 500, $this->convoyeur->id => 300],
                Carbon::today(),
                200,
            );
            $this->fail('Une écriture qui ignore la date en attente doit être refusée.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('01/10/2026', $e->errors()['partages'][0]);
        }

        $this->assertSame('2026-10-01', $this->droitsEnAttente($parrain));
    }

    public function test_un_nouveau_parrain_ne_reprend_jamais_les_commissions_de_lancien(): void
    {
        $ancien = $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-02');
        $commande = $this->vendre(100);           // 02/10 : 20 000 GNF pour l'ancien parrain

        Carbon::setTestNow('2026-10-05 10:00:00');
        $nouveau = $this->parrainer();

        // Ses droits ne peuvent pas commencer avant son arrivée : l'historique de l'ancien est acquis.
        $this->definirPart(150, '2026-10-01')->assertSessionHasErrors('date_effet');
        $this->actingAs($this->user)->getJson(route('vehicules.parrain.part', $this->vehicule))
            ->assertJsonPath('date_effet_min', '2026-10-05');

        $this->assertSame(20000.0, $this->partsEquipe($commande)["parrain:{$ancien->id}"]);
        $this->assertArrayNotHasKey("parrain:{$nouveau->id}", $this->partsEquipe($commande));

        // Sa fiche de la quinzaine reste la sienne.
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::PARRAIN, Carbon::parse('2026-10-02'));
        app(PeriodeCalculatorService::class)->calculerSiNecessaire($periode);
        $fiche = PaiementFiche::where('periode_id', $periode->id)->sole();
        $this->assertSame($ancien->id, $fiche->beneficiaire_id);
        $this->assertEquals(20000.0, (float) $fiche->montant_net);
    }

    // ── Retrait du parrain (PARRAIN-012 révisé, PARRAIN-024) ──────────────────

    public function test_retirer_le_parrain_lui_laisse_ses_commissions_et_sa_fiche_et_arrete_ses_droits(): void
    {
        $parrain = $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-02');
        $commande = $this->vendre(100);           // 02/10 : 20 000 GNF pour le parrain
        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->actingAs($this->user)->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn (string $m) => str_contains($m, 'onglet Équipe'));

        // Commissions acquises intactes, à son nom ; le parrain n'est jamais supprimé.
        $this->assertNull($this->vehicule->fresh()->parrain_id);
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 50000.0,
            "livreur:{$this->convoyeur->id}" => 30000.0,
            "parrain:{$parrain->id}" => 20000.0,
        ], $commande);
        $this->assertTrue(Parrain::whereKey($parrain->id)->exists());

        // Sa fiche de la quinzaine reste la sienne, calculée après le retrait.
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::PARRAIN, Carbon::parse('2026-10-02'));
        app(PeriodeCalculatorService::class)->calculerSiNecessaire($periode);
        $fiche = PaiementFiche::where('periode_id', $periode->id)->sole();
        $this->assertSame($parrain->id, $fiche->beneficiaire_id);
        $this->assertEquals(20000.0, (float) $fiche->montant_net);

        // Droits arrêtés : l'équipe répartie à nouveau, plus aucune part de parrain.
        $this->enregistrerEquipe(600, 400, null)->assertSessionHasNoErrors();
        $apres = $this->vendre(10);
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 6000.0,
            "livreur:{$this->convoyeur->id}" => 4000.0,
        ], $apres);
    }

    public function test_retirer_le_parrain_abandonne_sa_date_en_attente_sans_aucun_recalcul(): void
    {
        $commande = $this->vendre(100);           // 02/10, avant le parrain
        Carbon::setTestNow('2026-10-05 10:00:00');
        $parrain = $this->parrainer();
        $this->definirPart(200, '2026-10-01')->assertSessionHasNoErrors();
        $this->assertSame('2026-10-01', $this->droitsEnAttente($parrain));

        // 600 + 400 = barème : le partage redevient complet, rien à refaire.
        $this->actingAs($this->user)->delete(route('vehicules.parrain.destroy', $this->vehicule))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('warning');

        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 60000.0,
            "livreur:{$this->convoyeur->id}" => 40000.0,
        ], $commande, 'Aucun recalcul : la date en attente est abandonnée.');
        $this->assertNull($this->droitsEnAttente($parrain));
        $this->assertSame(1, EquipeLivraisonPartageCategorie::where('parrain_id', $parrain->id)
            ->whereNotNull('droits_parrain_depuis')->whereNotNull('effective_to')->count(), 'Historique conservé, jamais supprimé.');

        // Plus rien ne retient les autres écritures du partage (transfert, reconfiguration).
        PartageLivraisonVersionService::versionner(
            $this->equipe->id,
            $this->vente->id,
            $this->categorie->id,
            [$this->chauffeur->id => 700, $this->convoyeur->id => 300],
            Carbon::today(),
        );
        $this->assertSame(700, (int) EquipeLivraisonPartageCategorie::where('livreur_id', $this->chauffeur->id)
            ->where('processus_id', $this->vente->id)->whereNull('effective_to')->value('montant_unitaire'));
    }

    // ── Corrections de la revue (A1 à A4) ────────────────────────────────────

    public function test_a1_une_periode_impossible_a_rouvrir_annule_tout_le_recalcul(): void
    {
        $commande = $this->vendre(100);
        $periode = $this->validerPeriodeLivreurs();
        $fiche = PaiementFiche::where('periode_id', $periode->id)->where('beneficiaire_id', $this->chauffeur->id)->sole();
        $this->assertEquals(60000.0, (float) $fiche->montant_net);
        $this->assertTrue(PieceComptable::where('type_evenement', EvenementComptable::FICHE_LIVREUR_VALIDEE->value)->exists());

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->parrainer();
        $versionsAvant = EquipeLivraisonPartageCategorie::count();
        $piecesAvant = PieceComptable::count();
        $lignesAvant = DB::table('compta_ecritures')->count();
        $auditAvant = AuditLog::count();
        // La contrepassation échoue (ex. période comptable verrouillée).
        $this->partialMock(EcritureComptableService::class, fn ($m) => $m->shouldReceive('contrepasser')
            ->andThrow(new \RuntimeException('période comptable verrouillée')));

        try {
            $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');
            $this->fail('Le recalcul doit être refusé.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('rien n\'a été modifié', $e->errors()['repartitions'][0]);
            $this->assertStringContainsString('période comptable verrouillée', $e->errors()['repartitions'][0]);
        }

        // Rien n'a bougé : jamais une fiche à 60 000 face à une enveloppe recalculée.
        $this->assertPartsEquipe(["livreur:{$this->chauffeur->id}" => 60000.0, "livreur:{$this->convoyeur->id}" => 40000.0], $commande);
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);
        $this->assertEquals(60000.0, (float) $fiche->fresh()->montant_net);
        $this->assertSame($versionsAvant, EquipeLivraisonPartageCategorie::count(), 'Répartition antidatée annulée aussi.');
        $this->assertSame(0, CommissionEnveloppePart::where('beneficiaire_type', 'parrain')->where('montant_net', '>', 0)->count());
        $this->assertSame($piecesAvant, PieceComptable::count(), 'Aucune pièce comptable (ni contrepassation) ne subsiste.');
        $this->assertSame($lignesAvant, DB::table('compta_ecritures')->count());
        $this->assertSame($auditAvant, AuditLog::count(), 'Aucune trace d\'audit d\'un recalcul annulé.');
    }

    public function test_a2_une_repartition_planifiee_nest_jamais_ecrasee_par_la_modale_equipe(): void
    {
        $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-16');

        $this->enregistrerEquipe(700, 300, 0)->assertSessionHasErrors('partages');

        $this->assertStringContainsString('planifiée prend effet le 16/10/2026', session('errors')->first('partages'));
        $this->assertSame('2026-10-16', PartParrainService::planification($this->vehicule->fresh()));
    }

    public function test_a2_le_transfert_dun_livreur_et_les_autres_ecritures_respectent_la_planification(): void
    {
        $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-16');

        $autre = Vehicule::factory()->create(['organization_id' => $this->org->id, 'livraison_vente' => true, 'is_active' => true]);
        $reponse = $this->actingAs($this->user)->post(route('equipes-livraison.transfert.store', $this->convoyeur->id), [
            'nouveau_vehicule_id' => $autre->id,
            'role' => 'chauffeur',
            'partages_depart' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $this->categorie->id,
                'parts' => [['livreur_id' => $this->chauffeur->id, 'montant_unitaire' => 1000]],
                'part_parrain' => 0,
            ]],
        ]);
        $reponse->assertSessionHasErrors('partages');
        $this->assertDatabaseHas('equipe_livreurs', ['equipe_id' => $this->equipe->id, 'livreur_id' => $this->convoyeur->id]);

        // Reconfiguration après changement de barème, changement de parrain… : même garde-fou.
        $this->expectException(ValidationException::class);
        PartageLivraisonVersionService::versionner(
            $this->equipe->id, $this->vente->id, $this->categorie->id,
            [$this->chauffeur->id => 600, $this->convoyeur->id => 400], Carbon::today(), 0,
        );
    }

    public function test_a2_seule_la_grille_remplace_une_planification_avec_confirmation(): void
    {
        $parrain = $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-16');

        try {
            $this->definir([$this->chauffeur->id => 400, $this->convoyeur->id => 300], 300, '2026-10-20');
            $this->fail('Le remplacement doit être confirmé.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('remplacer_planification', $e->errors());
        }

        $this->definir([$this->chauffeur->id => 400, $this->convoyeur->id => 300], 300, '2026-10-20', remplacer: true);

        $this->assertSame('2026-10-20', PartParrainService::planification($this->vehicule->fresh()));
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->assertPartsEquipe([
            "livreur:{$this->chauffeur->id}" => 4000.0,
            "livreur:{$this->convoyeur->id}" => 3000.0,
            "parrain:{$parrain->id}" => 3000.0,
        ], $this->vendre(10));
    }

    public function test_a3_le_remplacement_dun_ajustement_manuel_est_trace(): void
    {
        $commande = $this->vendre(100);
        $part = CommissionEnveloppePart::where('beneficiaire_type', 'livreur')->where('beneficiaire_id', $this->chauffeur->id)->sole();
        $part->update(['montant_actuel' => 55000]);
        CommissionPartAdjustment::create([
            'commission_part_type' => $part->getMorphClass(),
            'commission_part_id' => $part->id,
            'ancien_montant' => 60000,
            'nouveau_montant' => 55000,
            'motif' => MotifAjustementCommission::CORRECTION->value,
            'commentaire' => 'Casse constatée',
            'created_by' => $this->user->id,
        ]);

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $trace = AuditLog::where('auditable_id', $commande->id)->where('event_code', AuditEvent::AUTO_RECALCULATED->value)->sole();
        $meta = $trace->meta;
        $this->assertStringContainsString('depuis le 01/10/2026', $meta['description']);
        $this->assertEquals(55000, $meta['decisions_remplacees'][0]['montant_ajuste']);
        $this->assertSame('correction', $meta['decisions_remplacees'][0]['ajustements'][0]['motif']);
        $this->assertSame('Casse constatée', $meta['decisions_remplacees'][0]['ajustements'][0]['commentaire']);
        $this->assertSame($this->user->id, $meta['decisions_remplacees'][0]['ajustements'][0]['par']);
        $this->assertSame('55000.00', $trace->old_values['parts_livreur']["livreur:{$this->chauffeur->id}"]);
        $this->assertSame('50000.00', $trace->new_values['parts_livreur']["livreur:{$this->chauffeur->id}"]);
    }

    public function test_a4_une_seconde_execution_sans_changement_na_aucun_effet(): void
    {
        $commande = $this->vendre(100);
        $this->validerPeriodeLivreurs();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->parrainer();
        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        // Revalidation humaine de la période rouverte.
        $periode = $this->validerPeriodeLivreurs();
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->statut);
        $enveloppe = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->sole();
        $pieces = PieceComptable::count();
        $traces = AuditLog::where('event', AuditEvent::AUTO_RECALCULATED->value)->count();

        $bilan = $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $this->assertSame([], $bilan['recalculees']);
        $this->assertSame([$commande->reference], $bilan['inchangees']);
        $this->assertSame($enveloppe->id, CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->sole()->id);
        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut, 'Aucune réouverture.');
        $this->assertSame($pieces, PieceComptable::count(), 'Aucune écriture comptable.');
        $this->assertSame($traces, AuditLog::where('event', AuditEvent::AUTO_RECALCULATED->value)->count());
    }

    public function test_une_commande_sans_part_du_parrain_avant_la_date_garde_le_total(): void
    {
        $commande = $this->vendre(100);
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->parrainer();

        $this->definir([$this->chauffeur->id => 500, $this->convoyeur->id => 300], 200, '2026-10-01');

        $total = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->sole();
        $this->assertEquals(100000.0, (float) $total->montant_total, 'Jamais plus que l\'enveloppe : la part du parrain est prise sur celle des livreurs.');
    }
}
