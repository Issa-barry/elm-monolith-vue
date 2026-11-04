<?php

namespace Tests\Feature\Api\Client;

use App\Enums\CategorieDepense;
use App\Enums\CommissionActivationStatut;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Filtre période comptable (annee + mois + quinzaine) des écrans Commissions, Ventes et Dépenses
 * de l'espace client : mêmes bornes partout, calculées par le serveur, chaque module gardant sa
 * propre date métier (ADR 0053).
 */
class PeriodeComptableFiltreTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private const ROUTES = ['client.dashboard.mine', 'client.activite.mine', 'client.depenses.mine'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-20 10:00:00');
    }

    private function makeVehicule(Organization $org, string $proprietaireId, string $nom = 'Vehicule Test'): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireId,
            'nom_vehicule' => $nom,
        ]);
    }

    private function makeDepense(Organization $org, Vehicule $vehicule, string $date, float $montant = 1000, array $overrides = []): Depense
    {
        $type = DepenseType::firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'carburant'],
            ['libelle' => 'Carburant', 'categorie' => CategorieDepense::VEHICULE->value]
        );

        return Depense::factory()->create([
            'organization_id' => $org->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $type->id,
            'statut' => StatutDepense::VALIDE->value,
            'montant' => $montant,
            'date_depense' => $date,
            ...$overrides,
        ]);
    }

    private function makeCommande(Organization $org, Vehicule $vehicule, string $reference, ?string $validatedAt, ?string $createdAt = null): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'reference' => $reference,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => $validatedAt,
        ]);

        if ($createdAt !== null) {
            $commande->forceFill(['created_at' => $createdAt])->saveQuietly();
        }

        return $commande;
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule, string $reference, ?string $depart): TransfertLogistique
    {
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'reference' => $reference,
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutTransfert::CLOTURE->value,
            'date_depart_reelle' => $depart,
            'created_by' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);
    }

    private function makeCommissionVente(Organization $org, Vehicule $vehicule, string $proprietaireId, string $gagneLe, float $montant, float $verse = 0): void
    {
        $commande = $this->makeCommande($org, $vehicule, 'CMD-'.uniqid(), $gagneLe);
        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => CommissionActivationStatut::ACTIF->value,
            ]
        );

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $gagneLe,
            'statut' => StatutCommission::IMPAYE,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_PROPRIETAIRE,
            'beneficiaire_id' => $proprietaireId,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => $verse,
            'statut' => $verse >= $montant ? StatutCommission::PAYE : StatutCommission::IMPAYE,
        ]);
    }

    /** La part est créée « maintenant » : seule sa date de gain la rattache à une période. */
    private function makeCommissionLogistique(Organization $org, Vehicule $vehicule, string $proprietaireId, string $gagneLe, float $montant): void
    {
        $commission = CommissionLogistique::create([
            'organization_id' => $org->id,
            'transfert_logistique_id' => $this->makeTransfert($org, $vehicule, 'TRF-'.uniqid(), $gagneLe)->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'montant_total' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprietaireId,
            'beneficiaire_nom' => 'Proprietaire Test',
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
            'earned_at' => $gagneLe,
        ]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: string, 3: string}> */
    public static function periodes(): array
    {
        return [
            'novembre P1' => [['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P1'], 'P1', '2026-11-01', '2026-11-15'],
            'novembre P2' => [['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P2'], 'P2', '2026-11-16', '2026-11-30'],
            'novembre entier' => [['annee' => 2026, 'mois' => 11, 'quinzaine' => 'TOUT'], 'TOUT', '2026-11-01', '2026-11-30'],
            'quinzaine omise = mois' => [['annee' => 2026, 'mois' => 11], 'TOUT', '2026-11-01', '2026-11-30'],
            'février bissextile P2' => [['annee' => 2024, 'mois' => 2, 'quinzaine' => 'P2'], 'P2', '2024-02-16', '2024-02-29'],
            'février non bissextile P2' => [['annee' => 2025, 'mois' => 2, 'quinzaine' => 'P2'], 'P2', '2025-02-16', '2025-02-28'],
            'mois de 31 jours P2' => [['annee' => 2026, 'mois' => 1, 'quinzaine' => 'P2'], 'P2', '2026-01-16', '2026-01-31'],
            'année précédente' => [['annee' => 2025, 'mois' => 12, 'quinzaine' => 'TOUT'], 'TOUT', '2025-12-01', '2025-12-31'],
        ];
    }

    #[DataProvider('periodes')]
    public function test_les_trois_ecrans_recoivent_les_memes_bornes_du_serveur(array $query, string $quinzaine, string $debut, string $fin): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs($this->makeProprietaireUser($org), ['*']);

        foreach (self::ROUTES as $route) {
            $this->getJson(route($route, $query))
                ->assertOk()
                ->assertJsonPath('filters.annee', $query['annee'])
                ->assertJsonPath('filters.mois', $query['mois'])
                ->assertJsonPath('filters.quinzaine', $quinzaine)
                ->assertJsonPath('filters.date_debut', $debut)
                ->assertJsonPath('filters.date_fin', $fin);
        }
    }

    public function test_une_annee_sans_mois_couvre_l_annee_entiere_partout(): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs($this->makeProprietaireUser($org), ['*']);

        foreach (self::ROUTES as $route) {
            $this->getJson(route($route, ['annee' => 2024]))
                ->assertOk()
                ->assertJsonPath('filters.annee', 2024)
                ->assertJsonPath('filters.mois', null)
                ->assertJsonPath('filters.quinzaine', null)
                ->assertJsonPath('filters.date_debut', '2024-01-01')
                ->assertJsonPath('filters.date_fin', '2024-12-31');
        }
    }

    /** @return array<string, array{0: string, 1: ?string, 2: ?string, 3: float}> */
    public static function periodesRapides(): array
    {
        // 20/11/2026 est un vendredi : semaine du 16 au 22, voir setUp() et le jeu de données du test.
        return [
            'aujourd\'hui' => ['aujourd_hui', '2026-11-20', '2026-11-20', 1],
            'hier' => ['hier', '2026-11-19', '2026-11-19', 10],
            'cette semaine' => ['cette_semaine', '2026-11-16', '2026-11-22', 11],
            'semaine dernière' => ['semaine_precedente', '2026-11-09', '2026-11-15', 100],
            'ce mois' => ['ce_mois', '2026-11-01', '2026-11-30', 111],
            'mois dernier' => ['mois_precedent', '2026-10-01', '2026-10-31', 1000],
            'cette année' => ['cette_annee', '2026-01-01', '2026-12-31', 1111],
            'année dernière' => ['annee_precedente', '2025-01-01', '2025-12-31', 10000],
            'toutes les périodes' => ['tout', null, null, 11111],
        ];
    }

    #[DataProvider('periodesRapides')]
    public function test_accueil_periodes_rapides_calculees_par_le_serveur(string $cle, ?string $debut, ?string $fin, float $gains): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        foreach (['2026-11-20' => 1, '2026-11-19' => 10, '2026-11-10' => 100, '2026-10-05' => 1000, '2025-06-15' => 10000] as $date => $montant) {
            $this->makeCommissionVente($org, $vehicule, $proprietaireId, $date, $montant);
            $this->makeDepense($org, $vehicule, $date, $montant);
        }

        Sanctum::actingAs($user, ['*']);

        $reponse = $this->getJson(route('client.dashboard.mine', ['periode' => $cle]))
            ->assertOk()
            ->assertJsonPath('filters.periode', $cle)
            ->assertJsonPath('filters.period', 'custom')
            ->assertJsonPath('filters.date_debut', $debut)
            ->assertJsonPath('filters.date_fin', $fin);

        $this->assertEquals($gains, $reponse->json('summary.total_earned'));
        $this->assertEquals($gains, $reponse->json('summary.frais_depenses_total'));
        $this->assertEquals($gains, $reponse->json('depenses_total'));
        $this->assertEquals($gains, collect($reponse->json('par_vehicule'))->sum('total_earned'));
    }

    #[DataProvider('periodesRapides')]
    public function test_depenses_periodes_rapides_calculees_par_le_serveur(string $cle, ?string $debut, ?string $fin, float $montant): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        foreach (['2026-11-20' => 1, '2026-11-19' => 10, '2026-11-10' => 100, '2026-10-05' => 1000, '2025-06-15' => 10000] as $date => $valeur) {
            $this->makeDepense($org, $vehicule, $date, $valeur);
        }

        Sanctum::actingAs($user, ['*']);

        $reponse = $this->getJson(route('client.depenses.mine', ['periode' => $cle, 'per_page' => 100]))
            ->assertOk()
            ->assertJsonPath('filters.periode', $cle)
            ->assertJsonPath('filters.date_debut', $debut)
            ->assertJsonPath('filters.date_fin', $fin);

        $this->assertEquals($montant, $reponse->json('total_valide'));
        $this->assertEquals($montant, collect($reponse->json('data'))->sum('montant'));

        // Même montant que la carte Dépenses de l'accueil sur la même période.
        $this->assertEquals($montant, $this->getJson(route('client.dashboard.mine', ['periode' => $cle]))->json('depenses_total'));
    }

    public function test_depenses_periode_rapide_refusee_avec_un_autre_filtre_de_periode(): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs($this->makeProprietaireUser($org), ['*']);

        foreach ([
            ['periode' => 'demain'],
            ['periode' => ['hier']],
            ['periode' => 'hier', 'date_debut' => '2026-11-01'],
            ['periode' => 'hier', 'date_fin' => '2026-11-30'],
        ] as $query) {
            $this->getJson(route('client.depenses.mine', $query))->assertStatus(422)->assertJsonValidationErrors('periode');
        }
        $this->getJson(route('client.depenses.mine', ['periode' => 'hier', 'annee' => 2026, 'mois' => 11]))
            ->assertStatus(422)->assertJsonValidationErrors('annee');
    }

    public function test_accueil_mois_de_trente_et_trente_et_un_jours_sans_chevauchement(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        // Dernier jour de septembre (30) puis d'octobre (31), et 1er du mois suivant.
        foreach (['2026-09-30' => 1, '2026-10-01' => 10, '2026-10-31' => 100, '2026-11-01' => 1000] as $date => $montant) {
            $this->makeCommissionVente($org, $vehicule, $proprietaireId, $date, $montant);
        }

        Sanctum::actingAs($user, ['*']);

        $gains = fn (array $query) => $this->getJson(route('client.dashboard.mine', $query))->assertOk()->json('summary.total_earned');

        $this->assertEquals(1, $gains(['annee' => 2026, 'mois' => 9, 'quinzaine' => 'P2']));
        $this->assertEquals(100, $gains(['annee' => 2026, 'mois' => 10, 'quinzaine' => 'P2']));
        $this->assertEquals(110, $gains(['annee' => 2026, 'mois' => 10]));
        $this->assertEquals(1111, $gains(['annee' => 2026]));
        $this->assertEquals(1111, $gains(['periode' => 'tout']));
    }

    public function test_accueil_commissions_partiellement_payees_restent_coherentes(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-20', 10000, 4000);
        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-19', 5000);
        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-10-05', 90000, 90000);

        Sanctum::actingAs($user, ['*']);

        $summary = $this->getJson(route('client.dashboard.mine', ['periode' => 'cette_semaine']))->assertOk()->json('summary');

        $this->assertEquals(15000, $summary['total_earned']);
        $this->assertEquals(4000, $summary['total_paid']);
        $this->assertEquals(11000, $summary['balance']);
        $this->assertSame(2, $summary['operations_count']);
        // Une commission n'est comptée qu'une fois : le total du mois inclut celles de la semaine.
        $this->assertEquals(15000, $this->getJson(route('client.dashboard.mine', ['periode' => 'ce_mois']))->json('summary.total_earned'));
        $this->assertEquals(105000, $this->getJson(route('client.dashboard.mine', ['periode' => 'tout']))->json('summary.total_earned'));
    }

    public function test_accueil_periode_sans_commission_et_periode_inconnue(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeCommissionVente($org, $vehicule, $user->proprietaire->id, '2026-11-20', 1000);

        Sanctum::actingAs($user, ['*']);

        $vide = $this->getJson(route('client.dashboard.mine', ['periode' => 'hier']))->assertOk();
        $this->assertEquals(0, $vide->json('summary.total_earned'));
        $this->assertEquals(0, $vide->json('summary.balance'));

        foreach ([
            ['periode' => 'demain'],
            ['periode' => ['hier']],
            ['periode' => 'hier', 'period' => 'ce_mois'],
            ['periode' => 'hier', 'date_debut' => '2026-11-01'],
        ] as $query) {
            $this->getJson(route('client.dashboard.mine', $query))->assertStatus(422)->assertJsonValidationErrors('periode');
        }
        $this->getJson(route('client.dashboard.mine', ['periode' => 'hier', 'annee' => 2026]))->assertStatus(422)->assertJsonValidationErrors('annee');
    }

    public function test_accueil_isolation_entre_utilisateurs_sur_une_periode_rapide(): void
    {
        $org = Organization::factory()->create();
        $alice = $this->makeProprietaireUser($org);
        $bob = $this->makeProprietaireUser($org);

        foreach ([[$alice, 1000], [$bob, 2000]] as [$user, $montant]) {
            $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
            $this->makeCommissionVente($org, $vehicule, $user->proprietaire->id, '2026-11-20', $montant);
            $this->makeDepense($org, $vehicule, '2026-11-20', $montant);
        }

        Sanctum::actingAs($alice, ['*']);

        foreach (['aujourd_hui', 'tout'] as $cle) {
            $reponse = $this->getJson(route('client.dashboard.mine', ['periode' => $cle]))->assertOk();
            $this->assertEquals(1000, $reponse->json('summary.total_earned'), $cle);
            $this->assertEquals(1000, $reponse->json('depenses_total'), $cle);
            $this->assertCount(1, $reponse->json('par_vehicule'), $cle);
        }

        $this->app['auth']->forgetGuards();
        $this->getJson(route('client.dashboard.mine', ['periode' => 'tout']))->assertUnauthorized();
    }

    public function test_depenses_filtrees_sur_date_depense_avec_bornes_incluses(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        foreach (['2026-10-31' => 1, '2026-11-01' => 10, '2026-11-15' => 100, '2026-11-16' => 1000, '2026-11-30' => 10000, '2026-12-01' => 100000] as $date => $montant) {
            $this->makeDepense($org, $vehicule, $date, $montant);
        }

        Sanctum::actingAs($user, ['*']);

        $total = fn (string $quinzaine) => $this->getJson(route('client.depenses.mine', ['annee' => 2026, 'mois' => 11, 'quinzaine' => $quinzaine]))
            ->assertOk()->json('meta.total');

        $this->assertSame(2, $total('P1'));
        $this->assertSame(2, $total('P2'));
        $this->assertSame(4, $total('TOUT'));

        $dates = collect($this->getJson(route('client.depenses.mine', ['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P1']))->json('data'))
            ->pluck('date')->sort()->values()->all();
        $this->assertSame(['2026-11-01', '2026-11-15'], $dates);
    }

    public function test_ventes_et_transferts_gardent_leur_date_de_reference(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, 'V-P1-FIN', '2026-11-15 23:59:59');
        $this->makeCommande($org, $vehicule, 'V-P2-DEBUT', '2026-11-16 00:00:00');
        // Validée en P2, créée en octobre : la date de validation prime sur la création.
        $this->makeCommande($org, $vehicule, 'V-VALIDEE-P2', '2026-11-20 08:00:00', '2026-10-05 08:00:00');
        // Sans validation : repli sur la date de création, comme sans filtre.
        $this->makeCommande($org, $vehicule, 'V-CREEE-P1', null, '2026-11-02 08:00:00');
        $this->makeCommande($org, $vehicule, 'V-OCTOBRE', '2026-10-31 23:59:59');
        $this->makeTransfert($org, $vehicule, 'T-P1', '2026-11-03');
        $this->makeTransfert($org, $vehicule, 'T-P2', '2026-11-30');
        $this->makeTransfert($org, $vehicule, 'T-DECEMBRE-AVANT', '2026-10-30');

        Sanctum::actingAs($user, ['*']);

        $references = fn (string $quinzaine) => collect($this->getJson(route('client.activite.mine', ['annee' => 2026, 'mois' => 11, 'quinzaine' => $quinzaine]))
            ->assertOk()->json('data'))->pluck('reference')->sort()->values()->all();

        $this->assertSame(['T-P1', 'V-CREEE-P1', 'V-P1-FIN'], $references('P1'));
        $this->assertSame(['T-P2', 'V-P2-DEBUT', 'V-VALIDEE-P2'], $references('P2'));
        $this->assertCount(6, $references('TOUT'));
    }

    public function test_commissions_filtrees_sur_la_date_de_gain_comme_les_fiches_de_paiement(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-15', 100);
        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-16', 1000);
        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-10-31', 10000);
        // Créée le 20/11 (voir setUp) mais gagnée le 15/11 puis le 31/10 : la création ne compte pas.
        $this->makeCommissionLogistique($org, $vehicule, $proprietaireId, '2026-11-15', 20);
        $this->makeCommissionLogistique($org, $vehicule, $proprietaireId, '2026-10-31', 20000);

        Sanctum::actingAs($user, ['*']);

        $gains = fn (array $query) => $this->getJson(route('client.dashboard.mine', $query))->assertOk()->json('summary.total_earned');

        $this->assertEquals(120, $gains(['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P1']));
        $this->assertEquals(1000, $gains(['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P2']));
        $this->assertEquals(1120, $gains(['annee' => 2026, 'mois' => 11]));
        $this->assertEquals(30000, $gains(['annee' => 2026, 'mois' => 10, 'quinzaine' => 'P2']));

        // Sans période comptable, le filtre historique reste celui de la création pour la logistique.
        $this->assertEquals(1000 + 20 + 20000 + 100, $gains(['period' => 'custom', 'date_debut' => '2026-11-01', 'date_fin' => '2026-11-30']));
    }

    public function test_indicateurs_du_dashboard_recalcules_sur_la_seule_periode(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-05', 10000, 10000);
        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-06', 5000);
        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-20', 7000);
        $this->makeDepense($org, $vehicule, '2026-11-10', 2000);
        $this->makeDepense($org, $vehicule, '2026-11-25', 3000);

        Sanctum::actingAs($user, ['*']);

        $reponse = $this->getJson(route('client.dashboard.mine', ['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P1']))->assertOk();

        $this->assertEquals(15000, $reponse->json('summary.total_earned'));
        $this->assertEquals(10000, $reponse->json('summary.total_paid'));
        $this->assertEquals(2000, $reponse->json('summary.frais_depenses_total'));
        $this->assertEquals(3000, $reponse->json('summary.balance'));
        $this->assertSame(2, $reponse->json('summary.operations_count'));
        $this->assertSame('custom', $reponse->json('filters.period'));
        $this->assertEquals(15000, collect($reponse->json('par_vehicule'))->sum('total_earned'));
        $this->assertEquals(2000, $reponse->json('depenses_total'));
    }

    public function test_p1_plus_p2_donne_le_mois_sur_les_trois_ecrans(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        foreach (['2026-11-01', '2026-11-15', '2026-11-16', '2026-11-30'] as $i => $date) {
            $this->makeCommissionVente($org, $vehicule, $proprietaireId, $date, 1000 * ($i + 1));
            $this->makeDepense($org, $vehicule, $date, 100 * ($i + 1));
        }

        Sanctum::actingAs($user, ['*']);

        $mesures = [
            'client.dashboard.mine' => 'summary.total_earned',
            'client.activite.mine' => 'meta.total',
            'client.depenses.mine' => 'meta.total',
        ];

        foreach ($mesures as $route => $chemin) {
            $valeur = fn (string $quinzaine) => $this->getJson(route($route, ['annee' => 2026, 'mois' => 11, 'quinzaine' => $quinzaine]))->assertOk()->json($chemin);

            $this->assertGreaterThan(0, $valeur('P1'), $route);
            $this->assertGreaterThan(0, $valeur('P2'), $route);
            $this->assertEquals($valeur('TOUT'), $valeur('P1') + $valeur('P2'), $route);
        }
    }

    public function test_mois_sans_donnees_renvoie_des_resultats_vides(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehicule = $this->makeVehicule($org, $proprietaireId);

        $this->makeCommissionVente($org, $vehicule, $proprietaireId, '2026-11-05', 1000);
        $this->makeDepense($org, $vehicule, '2026-11-05');

        Sanctum::actingAs($user, ['*']);

        $query = ['annee' => 2026, 'mois' => 3, 'quinzaine' => 'P1'];

        $dashboard = $this->getJson(route('client.dashboard.mine', $query))->assertOk();
        $this->assertEquals(0, $dashboard->json('summary.total_earned'));
        $this->assertSame(0, $dashboard->json('summary.operations_count'));
        $this->getJson(route('client.activite.mine', $query))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->getJson(route('client.depenses.mine', $query))->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function parametresInvalides(): array
    {
        return [
            'mois 13' => [['annee' => 2026, 'mois' => 13], 'mois'],
            'mois 0' => [['annee' => 2026, 'mois' => 0], 'mois'],
            'mois non entier' => [['annee' => 2026, 'mois' => 'novembre'], 'mois'],
            'année future' => [['annee' => 2027, 'mois' => 1], 'annee'],
            'année trop ancienne' => [['annee' => 1999, 'mois' => 1], 'annee'],
            'année non entière' => [['annee' => 'abc', 'mois' => 1], 'annee'],
            'quinzaine inconnue' => [['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P3'], 'quinzaine'],
            'quinzaine en minuscules' => [['annee' => 2026, 'mois' => 11, 'quinzaine' => 'p1'], 'quinzaine'],
            'quinzaine en tableau' => [['annee' => 2026, 'mois' => 11, 'quinzaine' => ['P1']], 'quinzaine'],
            'mois sans année' => [['mois' => 11], 'annee'],
            'quinzaine seule' => [['quinzaine' => 'P1'], 'annee'],
            'quinzaine sans mois' => [['annee' => 2026, 'quinzaine' => 'P1'], 'mois'],
            'combinée à date_debut' => [['annee' => 2026, 'mois' => 11, 'date_debut' => '2026-11-01'], 'annee'],
            'combinée à date_fin' => [['annee' => 2026, 'mois' => 11, 'date_fin' => '2026-11-30'], 'annee'],
        ];
    }

    #[DataProvider('parametresInvalides')]
    public function test_parametres_invalides_refuses_en_422_partout(array $query, string $champ): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs($this->makeProprietaireUser($org), ['*']);

        foreach (self::ROUTES as $route) {
            $this->getJson(route($route, $query))->assertStatus(422)->assertJsonValidationErrors($champ);
        }
    }

    public function test_periode_comptable_exclut_les_autres_filtres_de_periode_propres_a_chaque_ecran(): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs($this->makeProprietaireUser($org), ['*']);

        $this->getJson(route('client.activite.mine', ['annee' => 2026, 'mois' => 11, 'periode' => 'hier']))
            ->assertStatus(422)->assertJsonValidationErrors('annee');
        $this->getJson(route('client.dashboard.mine', ['annee' => 2026, 'mois' => 11, 'period' => 'ce_mois']))
            ->assertStatus(422)->assertJsonValidationErrors('annee');
    }

    public function test_acces_refuse_sans_authentification_et_sans_role_client(): void
    {
        foreach (self::ROUTES as $route) {
            $this->getJson(route($route, ['annee' => 2026, 'mois' => 11]))->assertUnauthorized();
        }

        $org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id]), ['*']);

        foreach (self::ROUTES as $route) {
            $this->getJson(route($route, ['annee' => 2026, 'mois' => 11]))->assertForbidden();
        }
    }

    public function test_deux_utilisateurs_ne_voient_que_leurs_donnees_sur_la_meme_periode(): void
    {
        $org = Organization::factory()->create();
        $autreOrg = Organization::factory()->create();
        $alice = $this->makeProprietaireUser($org);
        $bob = $this->makeProprietaireUser($org);
        $carol = $this->makeProprietaireUser($autreOrg);

        foreach ([[$org, $alice, 'A', 1000], [$org, $bob, 'B', 2000], [$autreOrg, $carol, 'C', 3000]] as [$organisation, $user, $suffixe, $montant]) {
            $vehicule = $this->makeVehicule($organisation, $user->proprietaire->id);
            $this->makeCommissionVente($organisation, $vehicule, $user->proprietaire->id, '2026-11-05', $montant);
            $this->makeDepense($organisation, $vehicule, '2026-11-05', $montant);
            $this->makeTransfert($organisation, $vehicule, 'T-'.$suffixe, '2026-11-05');
        }

        $query = ['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P1'];
        Sanctum::actingAs($alice, ['*']);

        $this->assertEquals(1000, $this->getJson(route('client.dashboard.mine', $query))->assertOk()->json('summary.total_earned'));
        $this->assertEquals(1000, $this->getJson(route('client.depenses.mine', $query))->assertOk()->json('data.0.montant'));
        $this->getJson(route('client.depenses.mine', $query))->assertJsonPath('meta.total', 1);

        $references = collect($this->getJson(route('client.activite.mine', [...$query, 'type' => 'logistique']))->assertOk()->json('data'))->pluck('reference');
        $this->assertSame(['T-A'], $references->all());
    }

    public function test_compatible_avec_les_filtres_vehicule_statut_et_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $proprietaireId = $user->proprietaire->id;
        $vehiculeA = $this->makeVehicule($org, $proprietaireId, 'A');
        $vehiculeB = $this->makeVehicule($org, $proprietaireId, 'B');

        $this->makeDepense($org, $vehiculeA, '2026-11-05', 1000);
        $this->makeDepense($org, $vehiculeB, '2026-11-05', 2000);
        $this->makeDepense($org, $vehiculeA, '2026-11-06', 4000, ['statut' => StatutDepense::SOUMIS->value]);
        $this->makeDepense($org, $vehiculeA, '2026-11-20', 8000);
        $this->makeCommissionVente($org, $vehiculeA, $proprietaireId, '2026-11-05', 100, 100);
        $this->makeCommissionVente($org, $vehiculeA, $proprietaireId, '2026-11-06', 200);
        $this->makeCommissionVente($org, $vehiculeB, $proprietaireId, '2026-11-07', 400);
        $this->makeCommissionVente($org, $vehiculeA, $proprietaireId, '2026-11-20', 800);
        $this->makeTransfert($org, $vehiculeA, 'T-A', '2026-11-05');
        $this->makeTransfert($org, $vehiculeB, 'T-B', '2026-11-05');

        Sanctum::actingAs($user, ['*']);
        $p1 = ['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P1'];

        $this->getJson(route('client.depenses.mine', [...$p1, 'vehicule_id' => $vehiculeA->id]))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(route('client.depenses.mine', [...$p1, 'statut' => StatutDepense::VALIDE->value]))->assertOk()->assertJsonPath('meta.total', 2);

        $gains = fn (array $filtres) => $this->getJson(route('client.dashboard.mine', [...$p1, ...$filtres]))->assertOk()->json('summary.total_earned');
        $this->assertEquals(300, $gains(['vehicule_id' => $vehiculeA->id]));
        $this->assertEquals(100, $gains(['statut' => StatutCommission::PAYE->value]));

        $this->getJson(route('client.activite.mine', [...$p1, 'type' => 'logistique']))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(route('client.activite.mine', [...$p1, 'type' => 'logistique', 'vehicule_id' => $vehiculeB->id]))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.reference', 'T-B');
    }

    public function test_livreur_retrouve_ses_depenses_personnelles_sur_la_periode(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $type = DepenseType::firstOrCreate(
            ['organization_id' => $org->id, 'code' => 'avance'],
            ['libelle' => 'Avance', 'categorie' => CategorieDepense::LIVREUR->value]
        );

        foreach (['2026-11-10' => 500, '2026-11-20' => 700] as $date => $montant) {
            Depense::factory()->create([
                'organization_id' => $org->id,
                'beneficiaire_type' => 'livreur',
                'beneficiaire_id' => $user->livreur->id,
                'depense_type_id' => $type->id,
                'statut' => StatutDepense::VALIDE->value,
                'montant' => $montant,
                'date_depense' => $date,
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        $query = ['annee' => 2026, 'mois' => 11, 'quinzaine' => 'P2'];
        $this->getJson(route('client.depenses.mine', $query))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.montant', 700);
        $this->assertEquals(700, $this->getJson(route('client.dashboard.mine', $query))->assertOk()->json('depenses_total'));
    }

    public function test_sans_periode_comptable_aucun_comportement_ne_change(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeDepense($org, $vehicule, '2025-01-10');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('filters.annee', null)
            ->assertJsonPath('filters.date_debut', null);
        $this->getJson(route('client.dashboard.mine'))
            ->assertOk()
            ->assertJsonPath('filters.period', 'ce_mois')
            ->assertJsonPath('filters.quinzaine', null)
            ->assertJsonPath('filters.date_fin', '2026-11-20');
        $this->getJson(route('client.activite.mine', ['periode' => 'hier']))
            ->assertOk()
            ->assertJsonPath('filters.periode', 'hier')
            ->assertJsonPath('filters.annee', null);
    }
}
