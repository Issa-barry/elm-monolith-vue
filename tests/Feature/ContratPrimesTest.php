<?php

namespace Tests\Feature;

use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutDocumentContrat;
use App\Enums\TypeContrat;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\DocumentContrat;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\Personne;
use App\Models\Site;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\Rh\AffectationRemunerationService;
use App\Services\Rh\DocumentContratService;
use App\Services\Rh\ModeleContratService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DesigneUnPdg;
use Tests\TestCase;

/**
 * Primes ajoutées à la création du contrat (docs/rh-contrat-pdf.md, ADR 0048) : une seule
 * opération, toujours validées d'emblée, en passant par le service des primes et ses règles.
 */
class ContratPrimesTest extends TestCase
{
    use DesigneUnPdg;
    use RefreshDatabase;

    private const CREER = ['rh-contrats.read', 'rh-contrats.create', 'rh-contrats.update', 'rh-remunerations.read', 'rh-affectations-remuneration.read'];

    private const PRIMES = ['rh-affectations-remuneration.create', AffectationRemunerationService::PERMISSION_VALIDATION];

    private Organization $org;

    private Site $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        Storage::fake(DocumentContratService::DISQUE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create(['name' => 'Eau La Maman']);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'ville' => 'Conakry']);
        $this->designerPdg($this->org, 'Mamadou', 'BAH');
        app(ModeleContratService::class)->activer($this->org->id, TypeContrat::CDI, User::factory()->create(['organization_id' => $this->org->id]));
        $this->user = $this->utilisateur([...self::CREER, ...self::PRIMES]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function utilisateur(array $permissions, ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function employe(?Organization $org = null): Employe
    {
        $orgId = ($org ?? $this->org)->id;
        $personne = Personne::create([
            'organization_id' => $orgId, 'nom' => 'DIALLO', 'prenom' => 'Thierno', 'civilite' => 'monsieur',
            'telephone' => '+22462'.fake()->unique()->numerify('#######'),
        ]);
        $fonction = FonctionRh::create(['organization_id' => $orgId, 'libelle' => 'Chauffeur', 'code' => 'CL'.fake()->unique()->numerify('####')]);

        return Employe::create([
            'organization_id' => $orgId, 'personne_id' => $personne->id, 'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $org ? null : $this->site->id, 'fonction_rh_id' => $fonction->id,
        ]);
    }

    private function type(string $libelle = 'Transport', array $attributs = [], ?Organization $org = null): TypeRemuneration
    {
        return TypeRemuneration::create([
            'organization_id' => ($org ?? $this->org)->id, 'code' => strtoupper(substr($libelle, 0, 4)).fake()->unique()->numerify('###'), 'libelle' => $libelle,
            'categorie' => 'prime', 'mode_calcul' => 'montant_fixe', 'periodicite' => 'mensuelle', 'nature_montant' => 'fixe', 'statut' => 'actif',
            ...$attributs,
        ]);
    }

    private function creer(Employe $employe, array $primes = [], array $donnees = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->from(route('contrats.create'))
            ->post(route('contrats.store'), [
                ...$this->conditionsContrat(),
                'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2026-11-01', 'salaire_base' => 750000,
                'primes' => $primes,
                ...$donnees,
            ]);
    }

    private function ligne(TypeRemuneration $type, array $surcharges = []): array
    {
        return ['type_remuneration_id' => $type->id, 'date_debut' => '2026-11-01', 'montant' => 200000, ...$surcharges];
    }

    // ── Création ─────────────────────────────────────────────────────────────

    public function test_un_contrat_sans_prime_se_cree_comme_avant(): void
    {
        $this->creer($this->employe())->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'Contrat créé.'));

        $this->assertSame(1, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());
        $this->assertSame('aucune prime ni aucun avantage', DocumentContrat::sole()->donnees['valeurs']['contrat.avantages']);
    }

    public function test_une_prime_est_creee_validee_et_figure_dans_le_pdf(): void
    {
        $employe = $this->employe();
        $type = $this->type();

        $this->creer($employe, [$this->ligne($type)])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Contrat créé avec 1 prime validée.'));

        $prime = AffectationRemuneration::sole();
        $this->assertSame(StatutAffectationRemuneration::VALIDEE, $prime->statut);
        $this->assertSame($employe->id, $prime->employe_id);
        $this->assertSame($this->user->id, $prime->valide_par);
        $this->assertSame('2026-11-01', $prime->date_debut->toDateString());
        $this->assertNull($prime->date_fin);
        $this->assertEquals(200000, (float) $prime->montant);

        $document = DocumentContrat::sole();
        $this->assertCount(1, $document->donnees['avantages']);
        $this->assertSame($prime->id, $document->donnees['avantages'][0]['affectation_id']);
        $this->assertStringContainsString('Transport (200 000 GNF par mois)', $document->donnees['valeurs']['contrat.avantages']);
    }

    public function test_plusieurs_primes_de_natures_differentes_sont_creees_ensemble(): void
    {
        $this->creer($this->employe(), [
            $this->ligne($this->type('Transport')),
            $this->ligne($this->type('Ancienneté', ['mode_calcul' => 'pourcentage_salaire_base']), ['montant' => null, 'taux' => 10]),
            $this->ligne($this->type('Heures', ['nature_montant' => 'variable']), ['montant' => null]),
            $this->ligne($this->type('Installation', ['periodicite' => 'ponctuelle']), ['date_debut' => '2026-12-01']),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Contrat créé avec 4 primes validées.'));

        $primes = AffectationRemuneration::with('type')->get()->keyBy('type.libelle');
        $this->assertCount(4, $primes);
        $this->assertTrue($primes->every(fn ($p) => $p->statut === StatutAffectationRemuneration::VALIDEE));
        $this->assertEquals(10, (float) $primes['Ancienneté']->taux);
        $this->assertNull($primes['Heures']->montant);
        $this->assertSame('2026-12-01', $primes['Installation']->date_debut->toDateString());
        $this->assertSame('2026-12-31', $primes['Installation']->date_fin->toDateString());
        $this->assertCount(4, DocumentContrat::sole()->donnees['avantages']);
    }

    public function test_une_prime_mensuelle_s_arrete_avec_le_cdd_ou_avec_son_type(): void
    {
        app(ModeleContratService::class)->activer($this->org->id, TypeContrat::CDD, User::factory()->create(['organization_id' => $this->org->id]));
        $this->creer($this->employe(), [
            $this->ligne($sans = $this->type('Transport')),
            $this->ligne($court = $this->type('Chantier', ['date_fin_application' => '2026-12-31'])),
        ], ['type_contrat' => 'cdd', 'date_fin' => '2027-06-30'])->assertSessionHasNoErrors();

        $this->assertSame('2027-06-30', AffectationRemuneration::where('type_remuneration_id', $sans->id)->sole()->date_fin->toDateString());
        $this->assertSame('2026-12-31', AffectationRemuneration::where('type_remuneration_id', $court->id)->sole()->date_fin->toDateString());
    }

    // ── Doublons et règles des primes ────────────────────────────────────────

    public function test_une_prime_deja_attribuee_est_refusee_et_rien_n_est_enregistre(): void
    {
        $employe = $this->employe();
        $type = $this->type();
        AffectationRemuneration::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_remuneration_id' => $type->id,
            'date_debut' => '2026-10-01', 'montant' => 100000, 'statut' => StatutAffectationRemuneration::VALIDEE->value,
        ]);

        $this->creer($employe, [$this->ligne($this->type('Logement')), $this->ligne($type)])
            ->assertSessionHasErrors('primes.1.date_debut');

        $this->assertSame(0, Contrat::count());
        $this->assertSame(1, AffectationRemuneration::count());
    }

    public function test_la_meme_prime_sur_deux_lignes_est_refusee(): void
    {
        $type = $this->type();

        $this->creer($this->employe(), [$this->ligne($type), $this->ligne($type)])
            ->assertSessionHasErrors('primes.1.date_debut');

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());
    }

    public function test_une_prime_terminee_n_empeche_pas_une_nouvelle_attribution(): void
    {
        $employe = $this->employe();
        $type = $this->type();
        AffectationRemuneration::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_remuneration_id' => $type->id,
            'date_debut' => '2026-01-01', 'date_fin' => '2026-10-31', 'montant' => 100000, 'statut' => StatutAffectationRemuneration::VALIDEE->value,
        ]);

        $this->creer($employe, [$this->ligne($type)])->assertSessionHasNoErrors();

        $this->assertSame(2, AffectationRemuneration::count());
    }

    public function test_un_echec_sur_une_ligne_n_enregistre_ni_contrat_ni_prime_et_permet_de_recommencer(): void
    {
        $employe = $this->employe();
        $valide = $this->type('Transport');
        $ferme = $this->type('Ancien', ['statut' => 'ferme']);

        $this->creer($employe, [$this->ligne($valide), $this->ligne($ferme)])
            ->assertSessionHasErrors('primes.1.type_remuneration_id');

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());
        $this->assertSame(0, DocumentContrat::count());
        $this->assertNull($employe->fresh()->date_embauche, 'date d’embauche non reprise d’un contrat annulé');

        // Nouvelle tentative corrigée : pas de doublon.
        $this->creer($employe, [$this->ligne($valide)])->assertSessionHasNoErrors();
        $this->assertSame(1, Contrat::count());
        $this->assertSame(1, AffectationRemuneration::count());
    }

    public function test_montants_et_taux_invalides_sont_refuses(): void
    {
        $employe = $this->employe();
        $type = $this->type();
        $pourcentage = $this->type('Ancienneté', ['mode_calcul' => 'pourcentage_salaire_base']);
        $variable = $this->type('Heures', ['nature_montant' => 'variable']);

        foreach ([
            [$this->ligne($type, ['montant' => -5]), 'primes.0.montant'],
            [$this->ligne($type, ['montant' => 0]), 'primes.0.montant'],
            [$this->ligne($type, ['montant' => 'deux cent mille']), 'primes.0.montant'],
            [$this->ligne($type, ['montant' => null]), 'primes.0.montant'],
            [$this->ligne($pourcentage, ['montant' => null, 'taux' => 150]), 'primes.0.taux'],
            [$this->ligne($pourcentage, ['montant' => null, 'taux' => null]), 'primes.0.taux'],
            [$this->ligne($variable), 'primes.0.montant'],
            [['type_remuneration_id' => '', 'date_debut' => '2026-11-01'], 'primes.0.type_remuneration_id'],
            [$this->ligne($type, ['date_debut' => 'demain']), 'primes.0.date_debut'],
        ] as [$ligne, $champ]) {
            $this->creer($employe, [$ligne])->assertSessionHasErrors($champ);
        }

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());
    }

    public function test_les_dates_d_une_prime_respectent_le_contrat_et_les_mois_figes(): void
    {
        $employe = $this->employe();
        $type = $this->type();

        $this->creer($employe, [$this->ligne($type, ['date_debut' => '2026-10-15'])])
            ->assertSessionHasErrors(['primes.0.date_debut' => 'La prime ne peut pas commencer avant le début du contrat (01/11/2026).']);

        app(ModeleContratService::class)->activer($this->org->id, TypeContrat::CDD, User::factory()->create(['organization_id' => $this->org->id]));
        $this->creer($employe, [$this->ligne($type, ['date_debut' => '2027-01-15'])], ['type_contrat' => 'cdd', 'date_fin' => '2026-12-31'])
            ->assertSessionHasErrors(['primes.0.date_debut' => 'La prime ne peut pas commencer après la fin du contrat (31/12/2026).']);

        PaiePeriode::create(['organization_id' => $this->org->id, 'annee' => 2026, 'mois' => 10, 'statut' => 'valide_rh']);
        $this->creer($employe, [$this->ligne($type, ['date_debut' => '2026-10-01'])], ['date_debut' => '2026-10-01'])
            ->assertSessionHasErrors('primes.0.date_debut');

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());
    }

    // ── Droits et isolation ──────────────────────────────────────────────────

    public function test_sans_le_droit_de_valider_ou_d_attribuer_aucune_prime_ni_contrat(): void
    {
        $type = $this->type();
        $employe = $this->employe();

        foreach ([
            [...self::CREER, 'rh-affectations-remuneration.create'],
            [...self::CREER, AffectationRemunerationService::PERMISSION_VALIDATION],
            self::CREER,
        ] as $droits) {
            $this->creer($employe, [$this->ligne($type)], user: $this->utilisateur($droits))->assertForbidden();
        }

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());

        // Sans prime, ces utilisateurs créent toujours des contrats.
        $this->creer($employe, user: $this->utilisateur(self::CREER))->assertSessionHasNoErrors();
        $this->assertSame(1, Contrat::count());
    }

    public function test_un_type_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();

        $this->creer($this->employe(), [$this->ligne($this->type('Ailleurs', [], $autreOrg))])
            ->assertSessionHasErrors('primes.0.type_remuneration_id');

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, AffectationRemuneration::count());
    }

    public function test_le_formulaire_ne_propose_les_primes_qu_avec_le_droit_et_les_types_actifs(): void
    {
        $employe = $this->employe();
        $actif = $this->type('Transport');
        $this->type('Brouillon', ['statut' => 'a_valider']);
        $autreOrg = Organization::factory()->create();
        $this->type('Ailleurs', [], $autreOrg);
        AffectationRemuneration::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_remuneration_id' => $actif->id,
            'date_debut' => '2026-10-01', 'montant' => 100000, 'statut' => StatutAffectationRemuneration::VALIDEE->value,
        ]);

        $this->actingAs($this->user)->get(route('contrats.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Contrats/Create')
                ->where('primes_contrat.types', fn ($types) => collect($types)->pluck('label')->all() === ['Transport'])
                ->where("primes_contrat.par_employe.{$employe->id}.0.type_id", $actif->id)
                ->where('primes_contrat.premier_jour_modifiable', null));

        $this->actingAs($this->utilisateur(self::CREER))->get(route('contrats.create'))
            ->assertInertia(fn (Assert $page) => $page->where('primes_contrat', null));
    }

    // ── Après la création ────────────────────────────────────────────────────

    public function test_la_modification_d_un_contrat_ne_touche_pas_aux_primes_et_les_montre_en_lecture_seule(): void
    {
        $employe = $this->employe();
        $this->creer($employe, [$this->ligne($this->type())])->assertSessionHasNoErrors();
        $contrat = Contrat::sole();
        $prime = AffectationRemuneration::sole();

        $this->actingAs($this->user)->get(route('contrats.edit', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('primes', ['Transport (200 000 GNF par mois)']));
        $this->actingAs($this->utilisateur(['rh-contrats.update', 'rh-contrats.read']))->get(route('contrats.edit', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('primes', null));

        // Des lignes envoyées à la modification sont ignorées : aucune réattribution, historique intact.
        $this->actingAs($this->user)->put(route('contrats.update', $contrat), [
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2026-11-01', 'salaire_base' => 800000,
            'primes' => [$this->ligne($this->type('Logement'))],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, AffectationRemuneration::count());
        $this->assertEquals($prime->updated_at, AffectationRemuneration::sole()->updated_at);
    }

    public function test_une_version_signee_garde_ses_primes_d_origine(): void
    {
        $employe = $this->employe();
        $this->creer($employe, [$this->ligne($this->type())])->assertSessionHasNoErrors();
        $contrat = Contrat::sole();
        $v1 = DocumentContrat::sole();
        $this->actingAs($this->user)->post(route('contrats.documents.signature', [$contrat, $v1]), ['signe_le' => today()->toDateString()])->assertSessionHasNoErrors();

        // Nouvelle prime attribuée après signature, dans « Primes des salariés ».
        app(AffectationRemunerationService::class)->affecter([
            'employe_id' => $employe->id, 'type_remuneration_id' => $this->type('Logement')->id, 'date_debut' => '2026-12-01', 'montant' => 300000,
        ], $this->user, valider: true);

        $this->actingAs($this->user)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('etat.code', 'signe_different'));
        $this->assertSame(1, DocumentContrat::count());
        $this->assertSame(StatutDocumentContrat::SIGNE, $v1->fresh()->statut);
        $this->assertCount(1, $v1->fresh()->donnees['avantages']);
        $this->assertSame(StatutContrat::ACTIF, $contrat->fresh()->statut_contrat);
    }
}
