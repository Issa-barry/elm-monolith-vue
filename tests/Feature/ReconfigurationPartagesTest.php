<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionBaremeBrouillon;
use App\Models\CommissionBaremeBrouillonPartage;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\ReconfigurationPartagesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Lot 2 (ADR 0006) — changement de barème Livreur sur de nombreuses équipes :
 * brouillon → reconfiguration groupée des partages → publication atomique.
 */
class ReconfigurationPartagesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private CommissionProcessus $processus;

    private Categorie $bouteille;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');
        // Page neuve absente du manifeste Vite compilé : le rendu des assets n'est pas testé ici.
        $this->withoutVite();

        $this->initOrgAndUser([
            'parametres.read', 'parametres.update',
            'equipes-livraison.read', 'equipes-livraison.update',
            'ventes.read', 'ventes.create', 'ventes.update',
        ]);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $this->bouteille = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);

        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950);
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function regle(string $cible, int $montant, ?string $typeVehiculeId = null): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => "{$cible} — Bouteille",
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->bouteille->id,
            'type_vehicule_id' => $typeVehiculeId,
            'cible_type' => $cible,
            'mode' => $cible === CommissionCibleType::CODE_EQUIPE_LIVRAISON ? CommissionMode::A_REPARTIR->value : CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => '2026-08-01',
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreurs: list<Livreur>} */
    private function equipe(array $montants, ?string $typeVehiculeId = null, string $nom = 'V'): array
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'nom_vehicule' => $nom.'-'.uniqid(),
            'type_vehicule_id' => $typeVehiculeId,
            'livraison_vente' => true,
            'livraison_logistique' => false,
            'is_active' => true,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe',
            'is_active' => true,
        ]);

        $livreurs = [];
        foreach (array_values($montants) as $i => $montant) {
            $livreur = Livreur::factory()->create([
                'organization_id' => $this->org->id,
                'is_active' => true,
                'nom_complet' => $i === 0 ? "Chauffeur {$nom}" : "Convoyeur {$nom}",
            ]);
            EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => $i === 0 ? 'chauffeur' : 'convoyeur', 'ordre' => $i]);
            if ($montant !== null) {
                EquipeLivraisonPartageCategorie::create([
                    'equipe_id' => $equipe->id,
                    'processus_id' => $this->processus->id,
                    'categorie_id' => $this->bouteille->id,
                    'livreur_id' => $livreur->id,
                    'part_pourcentage' => 0,
                    'montant_unitaire' => $montant,
                    'effective_from' => '2026-08-01',
                ]);
            }
            $livreurs[] = $livreur;
        }

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreurs' => $livreurs];
    }

    private function lignes(int $livreur, int $proprietaire = 950, array $exceptions = []): array
    {
        return [[
            'categorie_id' => $this->bouteille->id,
            'beneficiaires' => [CommissionCibleType::CODE_PROPRIETAIRE, CommissionCibleType::CODE_EQUIPE_LIVRAISON],
            'consultant_id' => null,
            'montants_standard' => [
                CommissionCibleType::CODE_PROPRIETAIRE => $proprietaire,
                CommissionCibleType::CODE_EQUIPE_LIVRAISON => $livreur,
            ],
            'exceptions' => $exceptions,
        ]];
    }

    private function enregistrerBareme(array $lignes)
    {
        return $this->actingAs($this->user)->post(route('settings.commissions.configuration.store'), [
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'lignes' => $lignes,
        ]);
    }

    private function baremeLivreurEnVigueur(?string $typeVehiculeId = null): int
    {
        return CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe($this->org->id, $this->processus->id, $this->bouteille->id, $typeVehiculeId, Carbon::today());
    }

    private function brouillon(): CommissionBaremeBrouillon
    {
        return CommissionBaremeBrouillon::where('organization_id', $this->org->id)->latest()->firstOrFail();
    }

    private function saisie(array $e, array $montants): array
    {
        return [
            'equipe_id' => $e['equipe']->id,
            'categorie_id' => $this->bouteille->id,
            'parts' => array_map(fn (Livreur $l, int $m) => ['livreur_id' => $l->id, 'montant_unitaire' => $m], $e['livreurs'], $montants),
        ];
    }

    private function enregistrerPartages(CommissionBaremeBrouillon $b, array $saisies)
    {
        return $this->actingAs($this->user)->put(route('settings.commissions.brouillons.partages', $b), ['saisies' => $saisies]);
    }

    // ── Enregistrement de la configuration ───────────────────────────────────

    public function test_sans_equipe_concernee_le_bareme_est_applique_immediatement(): void
    {
        $this->equipe([500, 300]);

        // Seul le Propriétaire change : aucun partage Livreur concerné.
        $this->enregistrerBareme($this->lignes(800, 1200))
            ->assertRedirect(route('settings.commissions.index', ['processus' => 'vente']));

        $this->assertSame(0, CommissionBaremeBrouillon::count());
        $this->assertSame(1200, (int) CommissionRegle::where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->where('statut', 'active')->value('montant'));
    }

    public function test_un_bareme_livreur_qui_rend_des_partages_non_conformes_est_prepare_dans_un_brouillon(): void
    {
        $this->equipe([500, 300], nom: 'A');
        $this->equipe([400, 400], nom: 'B');

        $this->enregistrerBareme($this->lignes(1000))
            ->assertRedirect(route('settings.commissions.brouillons.show', $this->brouillon()))
            ->assertSessionHas('success');

        $this->assertSame(800, $this->baremeLivreurEnVigueur(), 'Rien n\'est appliqué avant publication.');
        $this->assertSame(CommissionBaremeBrouillon::STATUT_EN_COURS, $this->brouillon()->statut);

        $groupes = ReconfigurationPartagesService::groupes($this->org->id, $this->processus, $this->brouillon()->lignes, $this->brouillon());
        $this->assertCount(2, $groupes);
        $this->assertSame(['a_corriger', 'a_corriger'], $groupes->pluck('statut')->all());
    }

    public function test_seules_les_equipes_dont_le_bareme_change_sont_concernees(): void
    {
        $tricycle = TypeVehicule::where('organization_id', $this->org->id)->where('nom', 'Tricycle')->firstOrFail();
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 600, $tricycle->id);
        $camion = $this->equipe([500, 300], nom: 'Camion');
        $this->equipe([400, 200], $tricycle->id, 'Tricycle');

        // Général 800 → 1 000, exception Tricycle inchangée (600).
        $lignes = $this->lignes(1000, 950, [[
            'type_vehicule_id' => $tricycle->id,
            'montants' => [CommissionCibleType::CODE_PROPRIETAIRE => 950, CommissionCibleType::CODE_EQUIPE_LIVRAISON => 600],
        ]]);
        $groupes = ReconfigurationPartagesService::groupes($this->org->id, $this->processus, $lignes, null);

        $this->assertSame([$camion['equipe']->id], $groupes->pluck('equipe_id')->all());
    }

    public function test_apercu_compte_les_partages_concernes_sans_rien_ecrire(): void
    {
        $this->equipe([500, 300]);

        $this->actingAs($this->user)
            ->postJson(route('settings.commissions.impact'), ['processus_code' => 'vente', 'lignes' => $this->lignes(1000)])
            ->assertOk()
            ->assertJson(['nb_groupes' => 1, 'nb_equipes' => 1, 'par_categorie' => [['categorie' => 'Bouteille', 'nb' => 1]]]);

        $this->assertSame(0, CommissionBaremeBrouillon::count());
    }

    public function test_une_nouvelle_saisie_pendant_un_brouillon_met_a_jour_le_brouillon(): void
    {
        $this->equipe([500, 300]);
        $this->enregistrerBareme($this->lignes(1000));
        $this->enregistrerBareme($this->lignes(1200));

        $this->assertSame(1, CommissionBaremeBrouillon::count());
        $this->assertSame(1200, $this->brouillon()->lignes[0]['montants_standard'][CommissionCibleType::CODE_EQUIPE_LIVRAISON]);
        $this->assertSame(800, $this->baremeLivreurEnVigueur());

        $this->actingAs($this->user)->get(route('settings.commissions.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('brouillon.total', 1)
                ->where('brouillon.conformes', 0)
                ->where('lignes.0.montants_standard.equipe_livraison.montant', 1200)
            );
    }

    // ── Proposition ───────────────────────────────────────────────────────────

    public function test_proposition_proportionnelle_avec_reliquat_au_chauffeur(): void
    {
        $membres = [
            ['livreur_id' => 'c', 'role' => 'convoyeur', 'actuel' => 300],
            ['livreur_id' => 'a', 'role' => 'chauffeur', 'actuel' => 500],
        ];

        $this->assertSame([375, 625], array_column(ReconfigurationPartagesService::avecProposition($membres, 1000), 'proposition'));
        $this->assertSame([375, 626], array_column(ReconfigurationPartagesService::avecProposition($membres, 1001), 'proposition'), 'Reliquat d\'arrondi au chauffeur.');
        $this->assertSame([null, null], array_column(ReconfigurationPartagesService::avecProposition([
            ['livreur_id' => 'a', 'role' => 'chauffeur', 'actuel' => null],
            ['livreur_id' => 'b', 'role' => 'convoyeur', 'actuel' => 0],
        ], 1000), 'proposition'), 'Aucune proposition sans partage réel.');
    }

    // ── Enregistrement groupé (atomique) ─────────────────────────────────────

    public function test_enregistrement_groupe_atomique_refuse_tout_si_une_saisie_est_non_conforme(): void
    {
        $a = $this->equipe([500, 300], nom: 'A');
        $b = $this->equipe([400, 400], nom: 'B');
        $this->enregistrerBareme($this->lignes(1000));

        $this->enregistrerPartages($this->brouillon(), [
            $this->saisie($a, [600, 400]),
            $this->saisie($b, [500, 400]), // 900 ≠ 1 000
        ])->assertSessionHasErrors('groupes.'.$b['equipe']->id.'|'.$this->bouteille->id);

        $this->assertSame(0, CommissionBaremeBrouillonPartage::count(), 'Rien n\'est enregistré.');

        $this->enregistrerPartages($this->brouillon(), [
            $this->saisie($a, [600, 400]),
            $this->saisie($b, [500, 500]),
        ])->assertSessionHasNoErrors();

        $groupes = ReconfigurationPartagesService::groupes($this->org->id, $this->processus, $this->brouillon()->lignes, $this->brouillon());
        $this->assertSame(['conforme', 'conforme'], $groupes->pluck('statut')->all());
        $this->assertSame(800, $this->baremeLivreurEnVigueur(), 'Toujours rien d\'appliqué.');
        $this->assertSame(800, (int) EquipeLivraisonPartageCategorie::where('equipe_id', $a['equipe']->id)->whereNull('effective_to')->sum('montant_unitaire'));
    }

    public function test_une_saisie_sans_part_pour_un_membre_actif_est_refusee(): void
    {
        $a = $this->equipe([500, 300]);
        $this->enregistrerBareme($this->lignes(1000));

        $this->enregistrerPartages($this->brouillon(), [[
            'equipe_id' => $a['equipe']->id,
            'categorie_id' => $this->bouteille->id,
            'parts' => [['livreur_id' => $a['livreurs'][0]->id, 'montant_unitaire' => 1000]],
        ]])->assertSessionHasErrors();

        $this->assertStringContainsString('sans part : Convoyeur V', collect(session('errors')->all())->implode(' '));
    }

    // ── Publication ───────────────────────────────────────────────────────────

    public function test_publication_refusee_tant_quun_partage_nest_pas_conforme(): void
    {
        $a = $this->equipe([500, 300], nom: 'A');
        $this->equipe([400, 400], nom: 'B');
        $this->enregistrerBareme($this->lignes(1000));
        $this->enregistrerPartages($this->brouillon(), [$this->saisie($a, [600, 400])]);

        $this->actingAs($this->user)->post(route('settings.commissions.brouillons.publier', $this->brouillon()))
            ->assertSessionHasErrors('publication');

        $this->assertStringContainsString('1 partage(s) ne sont pas encore conformes', session('errors')->first('publication'));
        $this->assertSame(800, $this->baremeLivreurEnVigueur());
        $this->assertSame(CommissionBaremeBrouillon::STATUT_EN_COURS, $this->brouillon()->statut);
    }

    public function test_publication_applique_bareme_et_partages_ensemble_a_la_meme_date(): void
    {
        $a = $this->equipe([500, 300], nom: 'A');
        $b = $this->equipe([400, 400], nom: 'B');
        $this->enregistrerBareme($this->lignes(1000));
        $this->enregistrerPartages($this->brouillon(), [$this->saisie($a, [625, 375]), $this->saisie($b, [500, 500])]);

        $this->actingAs($this->user)->post(route('settings.commissions.brouillons.publier', $this->brouillon()))
            ->assertRedirect(route('settings.commissions.index', ['processus' => 'vente']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1000, $this->baremeLivreurEnVigueur());
        $this->assertSame(CommissionBaremeBrouillon::STATUT_PUBLIE, $this->brouillon()->statut);

        foreach ([[$a, 1000, [625, 375]], [$b, 1000, [500, 500]]] as [$e, $total, $parts]) {
            $actifs = CommissionPartageLivraisonCategorieChecker::partagesActifs($this->processus->id, $e['equipe']->id, $this->bouteille->id, Carbon::today());
            $this->assertSame($total, (int) $actifs->sum('montant_unitaire'));
            $this->assertSame($parts, $actifs->sortBy(fn ($p) => $p->livreur_id === $e['livreurs'][0]->id ? 0 : 1)->pluck('montant_unitaire')->values()->all());
            $this->assertSame(['2026-09-25'], $actifs->pluck('effective_from')->map->toDateString()->unique()->values()->all());
            // Historique intact : la veille, l'ancien partage (800) reste la vérité.
            $hier = CommissionPartageLivraisonCategorieChecker::partagesActifs($this->processus->id, $e['equipe']->id, $this->bouteille->id, Carbon::parse('2026-09-24'));
            $this->assertSame(800, (int) $hier->sum('montant_unitaire'));
        }
        $this->assertSame(800, CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe($this->org->id, $this->processus->id, $this->bouteille->id, null, Carbon::parse('2026-09-24')));

        // Les commandes sont de nouveau acceptées avec le nouveau barème.
        $this->assertTrue(CommissionPartageLivraisonCategorieChecker::nonConformites(
            $this->org->id, $a['equipe'], 'vente', null, [$this->bouteille->id], Carbon::today(),
        )->isEmpty());
    }

    public function test_publication_refusee_si_lequipe_a_change_depuis_la_preparation(): void
    {
        $a = $this->equipe([500, 300]);
        $this->enregistrerBareme($this->lignes(1000));
        $this->enregistrerPartages($this->brouillon(), [$this->saisie($a, [600, 400])]);

        // Modification concurrente par le formulaire normal de l'équipe (barème en vigueur : 800).
        $this->actingAs($this->user)->patch(route('equipes-livraison.update', $a['equipe']), [
            'vehicule_id' => $a['vehicule']->id,
            'processus_code' => 'vente',
            'membres' => [
                ['livreur_id' => $a['livreurs'][0]->id, 'nom_complet' => 'Chauffeur V', 'telephone' => '+224620000071', 'role' => 'chauffeur', 'ordre' => 0],
                ['livreur_id' => $a['livreurs'][1]->id, 'nom_complet' => 'Convoyeur V', 'role' => 'convoyeur', 'ordre' => 1],
            ],
            'partages_categorie' => [[
                'categorie_id' => $this->bouteille->id,
                'parts' => [['membre_ordre' => 0, 'montant_unitaire' => 450], ['membre_ordre' => 1, 'montant_unitaire' => 350]],
            ]],
        ])->assertSessionHasNoErrors();

        $groupes = ReconfigurationPartagesService::groupes($this->org->id, $this->processus, $this->brouillon()->lignes, $this->brouillon());
        $this->assertSame('a_revalider', $groupes->first()['statut']);

        $this->actingAs($this->user)->post(route('settings.commissions.brouillons.publier', $this->brouillon()))
            ->assertSessionHasErrors('publication');
        $this->assertSame(800, $this->baremeLivreurEnVigueur());

        // Revalidation : nouvel enregistrement → conforme → publiable.
        $this->enregistrerPartages($this->brouillon(), [$this->saisie($a, [600, 400])])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('settings.commissions.brouillons.publier', $this->brouillon()))->assertSessionHasNoErrors();
        $this->assertSame(1000, $this->baremeLivreurEnVigueur());
    }

    public function test_publication_refusee_si_la_configuration_a_change_par_un_autre_chemin(): void
    {
        $a = $this->equipe([500, 300]);
        $this->enregistrerBareme($this->lignes(1000));
        $this->enregistrerPartages($this->brouillon(), [$this->saisie($a, [600, 400])]);

        CommissionRegle::where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->update(['montant' => 999]);

        $this->actingAs($this->user)->post(route('settings.commissions.brouillons.publier', $this->brouillon()))
            ->assertSessionHasErrors('publication');
        $this->assertStringContainsString('modifiée depuis la préparation', session('errors')->first('publication'));
    }

    public function test_abandon_laisse_le_bareme_en_vigueur_intact(): void
    {
        $this->equipe([500, 300]);
        $this->enregistrerBareme($this->lignes(1000));

        $this->actingAs($this->user)->delete(route('settings.commissions.brouillons.abandonner', $this->brouillon()))
            ->assertRedirect(route('settings.commissions.index', ['processus' => 'vente']));

        $this->assertSame(CommissionBaremeBrouillon::STATUT_ABANDONNE, $this->brouillon()->statut);
        $this->assertSame(800, $this->baremeLivreurEnVigueur());
    }

    public function test_page_de_reconfiguration(): void
    {
        $this->equipe([500, 300], nom: 'A');
        $this->enregistrerBareme($this->lignes(1000));

        $this->actingAs($this->user)->get(route('settings.commissions.brouillons.show', $this->brouillon()))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/CommissionRegles/Reconfiguration')
                ->where('resume.total', 1)
                ->where('groupes.0.bareme_actuel', 800)
                ->where('groupes.0.bareme_cible', 1000)
                ->where('groupes.0.membres.0.proposition', 625)
                ->where('groupes.0.membres.1.proposition', 375)
                ->where('permissions.publier', true)
            );
    }

    // ── Autorisations et isolation ────────────────────────────────────────────

    public function test_autorisations_et_isolation_organisationnelle(): void
    {
        $a = $this->equipe([500, 300]);
        $this->enregistrerBareme($this->lignes(1000));
        $brouillon = $this->brouillon();

        $lecteur = $this->makeUserWithPermissions($this->org, ['parametres.read']);
        $this->actingAs($lecteur)->get(route('settings.commissions.brouillons.show', $brouillon))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permissions.modifier', false)
                ->where('permissions.publier', false)
                ->where('permissions.abandonner', false));
        $this->actingAs($lecteur)->put(route('settings.commissions.brouillons.partages', $brouillon), ['saisies' => [$this->saisie($a, [600, 400])]])->assertForbidden();
        $this->actingAs($lecteur)->post(route('settings.commissions.brouillons.publier', $brouillon))->assertForbidden();
        $this->actingAs($lecteur)->delete(route('settings.commissions.brouillons.abandonner', $brouillon))->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $etranger = $this->makeUserWithPermissions($autreOrg, ['parametres.read', 'parametres.update', 'equipes-livraison.update']);
        $site = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre', 'type' => 'depot', 'localisation' => 'Kindia']);
        $etranger->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($etranger)->get(route('settings.commissions.brouillons.show', $brouillon))->assertNotFound();
        $this->actingAs($etranger)->post(route('settings.commissions.brouillons.publier', $brouillon))->assertNotFound();
    }
}
