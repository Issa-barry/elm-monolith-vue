<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Features\TableauDeBordFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Support\Periodes\Periode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tableau de bord des commissions (docs/tableau-de-bord-commissions.md) : quatre indicateurs qui
 * partitionnent le total généré (CommissionKpiBuckets), évolution comparée à la même durée écoulée,
 * graphique par jour ou par mois, répartition par bénéficiaire, périmètres d'agence de l'ADR 0061.
 */
class DashboardCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    private Site $sonfonia;

    private ?CommissionProcessus $processus = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Dimanche 22 novembre 2026.
        Carbon::setTestNow('2026-11-22 10:00:00');

        $this->org = Organization::factory()->create();
        // Désactivé par défaut (ADR 0071) : refus couvert par TableauxDeBordActivationTest.
        Feature::for($this->org)->activate(TableauDeBordFeature::COMMISSIONS);
        $this->matoto = $this->site('Matoto', ['is_central_tresorerie' => true]);
        $this->cba = $this->site('Cba');
        $this->sonfonia = $this->site('Sonfonia');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function site(string $nom, array $attributs = [], ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
            ...$attributs,
        ]);
    }

    private function staff(array $permissions, ?Site $site = null, string $role = 'manager'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        $user->sites()->attach(($site ?? $this->matoto)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function admin(): User
    {
        return $this->staff(['commissions.read'], $this->matoto, 'admin_entreprise');
    }

    private function vehicule(Site $site, ?Organization $org = null): Vehicule
    {
        $org ??= $this->org;

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $org->id])->id,
        ]);
    }

    /**
     * Une part de commission sur une vente du véhicule. `montant_actuel` = montant ajusté par un
     * responsable, prioritaire sur le net.
     */
    private function part(
        string $earnedAt,
        float $net,
        string $statut = 'impaye',
        float $verse = 0,
        ?float $actuel = null,
        string $type = CommissionEnveloppePart::TYPE_LIVREUR,
        ?Vehicule $vehicule = null,
        ?string $beneficiaireId = null,
        ?Organization $org = null,
    ): CommissionEnveloppePart {
        $org ??= $this->org;
        $vehicule ??= $this->vehicule($this->cba, $org);

        $client = Client::create([
            'organization_id' => $org->id,
            'nom' => 'Client', 'prenom' => 'Test',
            'is_active' => true, 'cashback_eligible' => false,
        ]);
        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'site_id' => $vehicule->site_id,
            'vehicule_id' => $vehicule->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.Str::ulid(),
            'statut' => 'livree',
            'total_commande' => 1_000_000,
        ]);

        $processus = $org->is($this->org) ? ($this->processus ??= $this->processus($org)) : $this->processus($org);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => $type === CommissionEnveloppePart::TYPE_SITE ? CommissionCibleType::CODE_SITE : CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => $type === CommissionEnveloppePart::TYPE_SITE ? $beneficiaireId : (string) Str::ulid(),
            'montant_total' => $net,
            'earned_at' => $earnedAt,
            'statut' => $statut,
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaireId ?? (string) Str::ulid(),
            'montant_brut' => $net,
            'montant_net' => $net,
            'montant_actuel' => $actuel,
            'montant_verse' => $verse,
            'statut' => $statut,
        ]);
    }

    private function processus(Organization $org): CommissionProcessus
    {
        return CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    /** @return array<string, mixed> props Inertia de la page */
    private function props(User $user, array $query = []): array
    {
        $response = $this->actingAs($user)->get(route('dashboard.commissions', $query))->assertOk();

        return $response->viewData('page')['props'];
    }

    /**
     * Les six éléments de la page portent sur la même période : la somme des tranches du graphique
     * redonne chaque carte, la répartition redonne le total généré.
     *
     * @param  array<string, mixed>  $props
     */
    private function assertCoherent(array $props): void
    {
        foreach (['total_genere', 'en_attente_periode', 'payable', 'deja_paye'] as $c) {
            $this->assertEqualsWithDelta(
                $props['indicateurs'][$c]['montant'],
                array_sum(array_column($props['evolution']['tranches'], $c)),
                0.01,
                "Le graphique et la carte « {$c} » divergent.",
            );
        }
        $this->assertEqualsWithDelta(
            $props['indicateurs']['total_genere']['montant'],
            array_sum(array_column($props['repartition'], 'montant')),
            0.01,
            'La répartition et la carte « Commissions générées » divergent.',
        );
    }

    // ── Accès ─────────────────────────────────────────────────────────────────

    public function test_les_invites_sont_rediriges_vers_la_connexion(): void
    {
        $this->get(route('dashboard.commissions'))->assertRedirect(route('login'));
    }

    public function test_accessible_avec_la_lecture_comptabilite(): void
    {
        $this->actingAs($this->staff(['comptabilite.read']))
            ->get(route('dashboard.commissions'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('DashboardCommissions'));
    }

    public function test_accessible_avec_la_lecture_commissions(): void
    {
        $this->actingAs($this->staff(['commissions.read']))
            ->get(route('dashboard.commissions'))
            ->assertOk();
    }

    public function test_refuse_sans_droit_de_lecture_des_commissions(): void
    {
        $this->actingAs($this->staff(['ventes.read']))
            ->get(route('dashboard.commissions'))
            ->assertForbidden();
    }

    // ── Indicateurs ───────────────────────────────────────────────────────────

    public function test_les_quatre_indicateurs_partitionnent_le_total_genere_hors_annulees(): void
    {
        $this->part('2026-11-03', 100_000, 'creee');
        // Montant ajusté à 150 000 par un responsable : c'est lui qui est dû.
        $this->part('2026-11-10', 200_000, 'impaye', actuel: 150_000);
        $this->part('2026-11-15', 300_000, 'partiel', verse: 100_000);
        $this->part('2026-11-20', 50_000, 'paye', verse: 50_000);
        $this->part('2026-11-05', 999_000, 'annulee');
        // Hors période (« Ce mois » par défaut) et hors période de comparaison.
        $this->part('2026-09-12', 400_000, 'impaye');

        $props = $this->props($this->admin());

        $this->assertSame('ce_mois', $props['periode']['cle']);
        $i = $props['indicateurs'];
        $this->assertEquals(600_000, $i['total_genere']['montant']);
        $this->assertEquals(100_000, $i['en_attente_periode']['montant']);
        $this->assertEquals(350_000, $i['payable']['montant']);
        $this->assertEquals(150_000, $i['deja_paye']['montant']);
        $this->assertEquals(
            $i['total_genere']['montant'],
            $i['en_attente_periode']['montant'] + $i['payable']['montant'] + $i['deja_paye']['montant'],
        );
        // 150 000 payés sur 600 000 générés.
        $this->assertSame(25.0, $props['taux_paiement']);
    }

    public function test_sans_commission_sur_la_periode_le_taux_de_paiement_est_vide(): void
    {
        $props = $this->props($this->admin());

        $this->assertNull($props['taux_paiement']);
        $this->assertSame([], $props['repartition']);
        $this->assertEquals(0, $props['indicateurs']['total_genere']['montant']);
        $this->assertCoherent($props);
    }

    public function test_l_evolution_compare_a_la_meme_duree_ecoulee_de_la_periode_precedente(): void
    {
        $this->part('2026-11-10', 600_000, 'impaye');
        // Du 1er au 22 octobre : comparé.
        $this->part('2026-10-10', 300_000, 'impaye');
        // Après le 22 octobre : hors de la durée écoulée, jamais comparé.
        $this->part('2026-10-25', 1_000_000, 'impaye');

        $props = $this->props($this->admin());

        $this->assertSame('du 01/10/2026 au 22/10/2026', mb_strtolower($props['libelle_comparaison']));
        $this->assertSame(100.0, $props['indicateurs']['total_genere']['evolution']);
        $this->assertSame(100.0, $props['indicateurs']['payable']['evolution']);
        // Rien de payé ni en attente en octobre : aucune base, aucune tendance inventée.
        $this->assertNull($props['indicateurs']['deja_paye']['evolution']);
        $this->assertNull($props['indicateurs']['en_attente_periode']['evolution']);
    }

    public function test_toutes_les_periodes_n_ont_pas_de_comparaison(): void
    {
        $this->part('2026-09-12', 400_000, 'impaye');
        $this->part('2026-11-10', 600_000, 'impaye');

        $props = $this->props($this->admin(), ['periode' => 'tout']);

        $this->assertNull($props['libelle_comparaison']);
        $this->assertEquals(1_000_000, $props['indicateurs']['total_genere']['montant']);
        $this->assertNull($props['indicateurs']['total_genere']['evolution']);
        // Du premier au dernier mois qui a des commissions, sans trou.
        $this->assertSame(['Sep', 'Oct', 'Nov'], array_column($props['evolution']['tranches'], 'label'));
        $this->assertEquals([400_000, 0, 600_000], array_column($props['evolution']['tranches'], 'total_genere'));
    }

    // ── Filtre de période (unique pour toute la page) ────────────────────────

    public function test_le_filtre_propose_les_periodes_de_stat_ventes_et_les_dates_libres(): void
    {
        $props = $this->props($this->admin());

        $this->assertSame(
            ['aujourd_hui', 'hier', 'cette_semaine', 'semaine_precedente', 'ce_mois', 'mois_precedent',
                't1', 't2', 't3', 't4', 's1', 's2', 'cette_annee', 'annee_precedente', 'tout', 'personnalisee'],
            array_column($props['periode']['options'], 'value'),
        );
        $this->assertSame('ce_mois', $props['periode']['cle']);
        $this->assertSame('2026-11-01', $props['periode']['date_debut']);
        $this->assertSame('2026-11-30', $props['periode']['date_fin']);
        $this->assertSame('Ce mois (du 01/11/2026 au 30/11/2026)', $props['periode']['libelle']);
    }

    public function test_une_periode_personnalisee_pilote_les_cartes_et_les_graphiques_bornes_incluses(): void
    {
        // Hors période, juste avant et juste après.
        $this->part('2026-11-01', 7_000, 'impaye');
        $this->part('2026-11-06', 9_000_000, 'impaye');
        // Bornes incluses.
        $this->part('2026-11-02', 100_000, 'creee');
        $this->part('2026-11-05', 300_000, 'partiel', verse: 100_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE);

        $props = $this->props($this->admin(), ['date_debut' => '2026-11-02', 'date_fin' => '2026-11-05']);

        $this->assertSame('personnalisee', $props['periode']['cle']);
        $this->assertSame('2026-11-02', $props['periode']['date_debut']);
        $this->assertSame('2026-11-05', $props['periode']['date_fin']);
        $this->assertSame('Du 02/11/2026 au 05/11/2026', $props['periode']['libelle']);

        $i = $props['indicateurs'];
        $this->assertEquals(400_000, $i['total_genere']['montant']);
        $this->assertEquals(100_000, $i['en_attente_periode']['montant']);
        $this->assertEquals(200_000, $i['payable']['montant']);
        $this->assertEquals(100_000, $i['deja_paye']['montant']);
        $this->assertSame(25.0, $props['taux_paiement']);

        $this->assertSame('jour', $props['evolution']['granularite']);
        $this->assertSame(['02/11', '03/11', '04/11', '05/11'], array_column($props['evolution']['tranches'], 'label'));
        $this->assertSame(['Propriétaires', 'Livreurs'], array_column($props['repartition'], 'label'));
        $this->assertCoherent($props);

        // Comparée aux quatre jours qui précèdent : seule la part du 1er novembre y figure.
        $this->assertSame('Du 29/10/2026 au 01/11/2026', $props['libelle_comparaison']);
        $this->assertSame(round((400_000 - 7_000) / 7_000 * 100, 1), $i['total_genere']['evolution']);
    }

    public function test_une_periode_personnalisee_de_plus_de_31_jours_est_mois_par_mois_jusqu_a_aujourd_hui(): void
    {
        $this->part('2026-10-01', 100_000, 'impaye');
        $this->part('2026-11-22', 50_000, 'paye', verse: 50_000);
        $this->part('2026-08-20', 80_000, 'impaye');

        $props = $this->props($this->admin(), ['date_debut' => '2026-10-01', 'date_fin' => '2026-11-23']);

        $this->assertSame('mois', $props['evolution']['granularite']);
        $this->assertSame(['Oct', 'Nov'], array_column($props['evolution']['tranches'], 'label'));
        $this->assertEquals(150_000, $props['indicateurs']['total_genere']['montant']);
        $this->assertCoherent($props);
        // 54 jours choisis, 53 écoulés au 22/11 : comparés aux 53 jours qui précèdent le 1er octobre.
        $this->assertSame('Du 08/08/2026 au 29/09/2026', $props['libelle_comparaison']);
        $this->assertSame(round((150_000 - 80_000) / 80_000 * 100, 1), $props['indicateurs']['total_genere']['evolution']);
    }

    /** @return array<string, array{0: string}> */
    public static function periodesPredefinies(): array
    {
        $cles = array_values(array_diff(Periode::TABLEAU_DE_BORD, [Periode::PERSONNALISEE]));

        return array_combine($cles, array_map(fn (string $cle) => [$cle], $cles));
    }

    #[DataProvider('periodesPredefinies')]
    public function test_chaque_periode_predefinie_pilote_toute_la_page(string $cle): void
    {
        $this->part('2026-01-15', 10_000, 'impaye');
        $this->part('2026-11-16', 20_000, 'creee');
        $this->part('2026-11-20', 40_000, 'partiel', verse: 10_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE);
        $this->part('2026-11-22', 80_000, 'paye', verse: 80_000);
        $this->part('2025-12-31', 160_000, 'impaye');

        $props = $this->props($this->admin(), ['periode' => $cle]);

        $this->assertSame($cle, $props['periode']['cle']);
        $this->assertCoherent($props);
    }

    public function test_une_periode_invalide_est_refusee_avec_un_message(): void
    {
        $admin = $this->admin();
        $depuis = route('dashboard.commissions');

        $this->actingAs($admin)->from($depuis)
            ->get(route('dashboard.commissions', ['periode' => 'trimestre_dernier']))
            ->assertRedirect($depuis)
            ->assertSessionHasErrors(['periode' => 'Période inconnue.']);

        $this->actingAs($admin)->from($depuis)
            ->get(route('dashboard.commissions', ['date_debut' => '2026-11-05', 'date_fin' => '2026-11-02']))
            ->assertSessionHasErrors(['date_fin' => 'La date de fin doit être postérieure ou égale à la date de début.']);

        $this->actingAs($admin)->from($depuis)
            ->get(route('dashboard.commissions', ['date_debut' => '2026-02-31', 'date_fin' => '2026-03-02']))
            ->assertSessionHasErrors(['date_debut' => 'La date de début est invalide.']);

        $this->actingAs($admin)->from($depuis)
            ->get(route('dashboard.commissions', ['periode' => 'ce_mois', 'date_debut' => '2026-11-02', 'date_fin' => '2026-11-05']))
            ->assertSessionHasErrors(['periode' => 'Choisissez une période ou des dates, pas les deux.']);
    }

    public function test_une_periode_personnalisee_respecte_le_perimetre_d_agence(): void
    {
        $this->part('2026-10-15', 100_000, vehicule: $this->vehicule($this->cba));
        $this->part('2026-10-15', 900_000, vehicule: $this->vehicule($this->sonfonia));

        $props = $this->props($this->staff(['commissions.read'], $this->cba), ['date_debut' => '2026-10-01', 'date_fin' => '2026-10-31']);

        $this->assertEquals(100_000, $props['indicateurs']['total_genere']['montant']);
        $this->assertCoherent($props);
    }

    // ── Graphique et répartition ──────────────────────────────────────────────

    public function test_le_graphique_d_un_mois_est_jour_par_jour_jusqu_a_aujourd_hui(): void
    {
        $this->part('2026-11-03', 100_000, 'creee');
        $this->part('2026-11-03', 40_000, 'paye', verse: 40_000);

        $evolution = $this->props($this->admin())['evolution'];

        $this->assertSame('jour', $evolution['granularite']);
        $this->assertCount(22, $evolution['tranches']);
        $this->assertSame('01/11', $evolution['tranches'][0]['label']);
        $this->assertSame('22/11', $evolution['tranches'][21]['label']);
        $troisNovembre = $evolution['tranches'][2];
        $this->assertSame('2026-11-03', $troisNovembre['cle']);
        $this->assertEquals(140_000, $troisNovembre['total_genere']);
        $this->assertEquals(40_000, $troisNovembre['deja_paye']);
        $this->assertEquals(100_000, $troisNovembre['en_attente_periode']);
    }

    public function test_le_graphique_d_une_annee_est_mois_par_mois(): void
    {
        $this->part('2026-02-14', 70_000, 'impaye');
        $this->part('2026-11-03', 100_000, 'impaye');

        $evolution = $this->props($this->admin(), ['periode' => 'cette_annee'])['evolution'];

        $this->assertSame('mois', $evolution['granularite']);
        $this->assertSame(['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov'], array_column($evolution['tranches'], 'label'));
        $this->assertEquals(70_000, $evolution['tranches'][1]['total_genere']);
        $this->assertEquals(100_000, $evolution['tranches'][10]['total_genere']);
    }

    public function test_la_repartition_suit_le_type_de_beneficiaire_du_plus_gros_au_plus_petit(): void
    {
        $this->part('2026-11-03', 100_000);
        $this->part('2026-11-04', 50_000);
        $this->part('2026-11-05', 300_000, type: CommissionEnveloppePart::TYPE_SITE, beneficiaireId: $this->cba->id);
        $this->part('2026-11-06', 80_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE);
        $this->part('2026-11-07', 500_000, 'annulee', type: CommissionEnveloppePart::TYPE_PRESTATAIRE);

        $repartition = $this->props($this->admin())['repartition'];

        $this->assertSame([
            ['type' => 'site', 'label' => 'Sites', 'montant' => 300000],
            ['type' => 'livreur', 'label' => 'Livreurs', 'montant' => 150000],
            ['type' => 'proprietaire', 'label' => 'Propriétaires', 'montant' => 80000],
        ], array_map(fn ($r) => [...$r, 'montant' => (int) $r['montant']], $repartition));
    }

    // ── Périmètres ────────────────────────────────────────────────────────────

    public function test_un_responsable_d_agence_ne_voit_que_les_commissions_de_ses_agences(): void
    {
        $vehiculeCba = $this->vehicule($this->cba);
        $vehiculeSonfonia = $this->vehicule($this->sonfonia);

        $this->part('2026-11-03', 100_000, vehicule: $vehiculeCba);
        $this->part('2026-11-03', 900_000, vehicule: $vehiculeSonfonia);
        $this->part('2026-11-04', 20_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE, vehicule: $vehiculeCba);
        $this->part('2026-11-04', 800_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE, vehicule: $vehiculeSonfonia);
        $this->part('2026-11-05', 30_000, type: CommissionEnveloppePart::TYPE_SITE, vehicule: $vehiculeSonfonia, beneficiaireId: $this->cba->id);
        $this->part('2026-11-05', 700_000, type: CommissionEnveloppePart::TYPE_SITE, vehicule: $vehiculeCba, beneficiaireId: $this->sonfonia->id);

        $props = $this->props($this->staff(['commissions.read'], $this->cba));

        $this->assertSame([['value' => $this->cba->id, 'label' => 'Cba']], $props['agences']);
        $this->assertTrue($props['perimetre_limite']);
        $this->assertEquals(150_000, $props['indicateurs']['total_genere']['montant']);

        $admin = $this->props($this->admin());
        $this->assertFalse($admin['perimetre_limite']);
        $this->assertEquals(2_550_000, $admin['indicateurs']['total_genere']['montant']);
    }

    // ── Filtre agence (avec la période) ───────────────────────────────────────

    /** Commissions de trois agences : livreur (agence du véhicule) et site bénéficiaire. */
    private function commissionsDeTroisAgences(): void
    {
        foreach ([[$this->matoto, 100_000, 1_000], [$this->cba, 200_000, 2_000], [$this->sonfonia, 400_000, 4_000]] as [$site, $livreur, $commissionSite]) {
            $vehicule = $this->vehicule($site);
            $this->part('2026-11-03', $livreur, 'partiel', verse: $livreur / 2, vehicule: $vehicule);
            $this->part('2026-11-04', $commissionSite, 'creee', type: CommissionEnveloppePart::TYPE_SITE, vehicule: $vehicule, beneficiaireId: $site->id);
            // Période de comparaison du 1er au 22 octobre.
            $this->part('2026-10-05', $livreur / 4, 'impaye', vehicule: $vehicule);
        }
    }

    public function test_par_defaut_toutes_les_agences_de_l_organisation_pour_un_administrateur(): void
    {
        $this->commissionsDeTroisAgences();

        $props = $this->props($this->admin());

        $this->assertNull($props['agence']);
        $this->assertSame(['Cba', 'Matoto', 'Sonfonia'], array_column($props['agences'], 'label'));
        $this->assertEquals(707_000, $props['indicateurs']['total_genere']['montant']);
        $this->assertCoherent($props);
    }

    public function test_une_agence_precise_pilote_les_cartes_les_graphiques_et_la_comparaison(): void
    {
        $this->commissionsDeTroisAgences();

        $props = $this->props($this->admin(), ['site_id' => $this->cba->id]);

        $this->assertSame($this->cba->id, $props['agence']);
        $i = $props['indicateurs'];
        $this->assertEquals(202_000, $i['total_genere']['montant']);
        $this->assertEquals(2_000, $i['en_attente_periode']['montant']);
        $this->assertEquals(100_000, $i['payable']['montant']);
        $this->assertEquals(100_000, $i['deja_paye']['montant']);
        $this->assertSame(['Livreurs', 'Sites'], array_column($props['repartition'], 'label'));
        $this->assertCoherent($props);
        // Comparaison sur la même agence : 50 000 générés à Cba en octobre (pas 175 000 pour tout).
        $this->assertSame(round((202_000 - 50_000) / 50_000 * 100, 1), $i['total_genere']['evolution']);
    }

    public function test_periode_personnalisee_et_agence_se_combinent(): void
    {
        $this->commissionsDeTroisAgences();
        $this->part('2026-11-10', 9_000_000, vehicule: $this->vehicule($this->matoto));

        $props = $this->props($this->admin(), ['site_id' => $this->matoto->id, 'date_debut' => '2026-11-03', 'date_fin' => '2026-11-04']);

        $this->assertSame('personnalisee', $props['periode']['cle']);
        $this->assertSame($this->matoto->id, $props['agence']);
        $this->assertEquals(101_000, $props['indicateurs']['total_genere']['montant']);
        $this->assertSame(['03/11', '04/11'], array_column($props['evolution']['tranches'], 'label'));
        $this->assertCoherent($props);
    }

    public function test_toutes_les_agences_d_un_responsable_sont_les_siennes_seulement(): void
    {
        $this->commissionsDeTroisAgences();
        $responsable = $this->staff(['commissions.read'], $this->cba);
        $responsable->sites()->attach($this->sonfonia->id, ['role' => 'employe', 'is_default' => false]);

        $toutes = $this->props($responsable);
        $this->assertSame(['Cba', 'Sonfonia'], array_column($toutes['agences'], 'label'));
        $this->assertEquals(606_000, $toutes['indicateurs']['total_genere']['montant']);
        $this->assertCoherent($toutes);

        $sonfonia = $this->props($responsable, ['site_id' => $this->sonfonia->id]);
        $this->assertEquals(404_000, $sonfonia['indicateurs']['total_genere']['montant']);
        $this->assertCoherent($sonfonia);
    }

    public function test_une_agence_hors_du_perimetre_est_refusee(): void
    {
        $this->commissionsDeTroisAgences();
        $responsable = $this->staff(['commissions.read'], $this->cba);
        $autreOrganisation = $this->site('Ailleurs', org: Organization::factory()->create());
        $depuis = route('dashboard.commissions');

        // Refus explicite (redirection avec message), jamais les chiffres d'une autre agence.
        foreach ([$this->matoto->id, $autreOrganisation->id, 'inconnue'] as $siteId) {
            $this->actingAs($responsable)->from($depuis)
                ->get(route('dashboard.commissions', ['site_id' => $siteId]))
                ->assertRedirect($depuis)
                ->assertSessionHasErrors(['site_id' => 'Agence inconnue ou hors de votre périmètre.']);
        }

        // Une agence d'une autre organisation est refusée même pour un administrateur.
        $this->actingAs($this->admin())->from($depuis)
            ->get(route('dashboard.commissions', ['site_id' => $autreOrganisation->id]))
            ->assertSessionHasErrors(['site_id']);
    }

    public function test_l_agence_qui_paie_toutes_les_fiches_d_un_type_voit_tout_ce_type(): void
    {
        // Par défaut, Matoto (trésorerie principale) paie les propriétaires de toutes les agences ;
        // les livreurs restent payés par l'agence de leur véhicule (ADR 0061).
        $vehiculeCba = $this->vehicule($this->cba);
        $this->part('2026-11-03', 100_000, vehicule: $vehiculeCba);
        $this->part('2026-11-04', 20_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE, vehicule: $vehiculeCba);

        $props = $this->props($this->staff(['commissions.read'], $this->matoto));

        $this->assertEquals(20_000, $props['indicateurs']['total_genere']['montant']);
    }

    public function test_les_commissions_d_une_autre_organisation_ne_sont_jamais_comptees(): void
    {
        $autre = Organization::factory()->create();
        $siteAutre = $this->site('Autre', org: $autre);
        $this->part('2026-11-03', 5_000_000, vehicule: $this->vehicule($siteAutre, $autre), org: $autre);
        $this->part('2026-11-03', 100_000);

        $props = $this->props($this->admin());

        $this->assertEquals(100_000, $props['indicateurs']['total_genere']['montant']);
    }

    // ── Performance ───────────────────────────────────────────────────────────

    public function test_le_nombre_de_requetes_ne_depend_pas_du_volume_de_commissions(): void
    {
        $admin = $this->admin();
        $responsable = $this->staff(['commissions.read'], $this->cba);
        $this->part('2026-11-03', 100_000);

        $compter = function (User $user, array $query = []): int {
            $this->props($user, $query);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->props($user, $query);
            $nombre = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $nombre;
        };

        $adminAvant = $compter($admin);
        $responsableAvant = $compter($responsable);

        foreach (range(1, 15) as $i) {
            $this->part('2026-11-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 10_000 * $i);
            $this->part('2026-10-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 5_000, type: CommissionEnveloppePart::TYPE_PROPRIETAIRE);
        }

        $this->assertSame($adminAvant, $compter($admin));
        $this->assertSame($responsableAvant, $compter($responsable));
        // Les filtres agence et période (dates libres) n'ajoutent aucune requête.
        $this->assertSame($adminAvant, $compter($admin, ['site_id' => $this->cba->id]));
        $this->assertSame($adminAvant, $compter($admin, ['site_id' => $this->cba->id, 'date_debut' => '2026-10-01', 'date_fin' => '2026-11-22']));
    }
}
