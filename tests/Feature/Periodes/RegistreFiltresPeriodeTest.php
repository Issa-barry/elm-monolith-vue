<?php

namespace Tests\Feature\Periodes;

use App\Features\ModuleFeature;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Registre des écrans et exports du back-office filtrés par période calendaire (ADR 0057,
 * docs/periodes.md). Chaque entrée accepte une période rapide et refuse une période personnalisée
 * invalide avec le même message. Un nouvel écran qui utilise Periode::depuisRequeteValidee sans
 * être inscrit ici fait échouer le test.
 */
class RegistreFiltresPeriodeTest extends TestCase
{
    use RefreshDatabase;

    /** Écrans avec périodes rapides et dates libres (Du/Au). @var array<string, string> route => écran */
    public const REGISTRE = [
        'ventes.index' => 'Ventes',
        'ventes.export' => 'Ventes — export',
        'precommandes.index' => 'Précommandes',
        'distributions.index' => 'Distributions',
        'distributions.export' => 'Distributions — export',
        'factures.index' => 'Factures',
        'logistique.transferts.index' => 'Logistique — transferts',
        'logistique.receptions.index' => 'Logistique — réceptions',
        'depenses.index' => 'Dépenses',
        'depenses.export.excel' => 'Dépenses — export',
        'depenses.imprimer' => 'Dépenses — impression',
        'cashback.index' => 'Cashback',
        'comptabilite.commissions.cashback.index' => 'Commissions — cashback',
        'communications.index' => 'Communications',
        'comptabilite.historique.index' => 'Historique des actions',
        'propositions-vehicules.index' => 'Propositions de véhicules',
        'comptabilite.tresorerie.controle-encaissements.index' => 'Contrôle des encaissements',
        'comptabilite.commissions.monitoring.index' => 'Monitoring des commissions',
        'achats.index' => 'Achats',
        'achats.factures.index' => 'Factures fournisseurs',
        'comptabilite.tresorerie.mouvements.index' => 'Mouvements de fonds',
        'rapports.activite' => "Rapport d'activité",
        'rapports.activite.export' => "Rapport d'activité — export",
        'rapports.agences' => 'Rapport par agence',
        'rapports.agences.export' => 'Rapport par agence — export',
        'ma-situation' => 'Ma situation',
        'ma-situation.export' => 'Ma situation — export',
    ];

    /** Écrans à périodes rapides seulement : des dates libres sont refusées. @var array<string, string> */
    public const REGISTRE_SANS_DATES = [
        'dashboard' => 'Tableau de bord',
    ];

    /** Routes à paramètre, couvertes par leur propre test. @var array<string, string> */
    public const COUVERTES_AILLEURS = [
        'vehicules.show' => 'tests/Feature/VehiculeSituationVentesTest.php',
        'users.show' => 'tests/Feature/Users/FicheAgentTest.php',
        'comptabilite.historique.entite' => 'tests/Feature/Periodes/RegistreFiltresPeriodeTest.php (JSON)',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        // Modules désactivés par défaut mais dont les écrans sont au registre.
        Feature::for($org)->activate(ModuleFeature::ACHATS);
        $site = Site::factory()->for($org)->create();
        $this->admin = User::factory()->create(['organization_id' => $org->id]);
        $this->admin->assignRole('super_admin');
        $this->admin->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function ecrans(): array
    {
        return array_combine(
            array_values(self::REGISTRE),
            array_map(fn (string $route) => [$route], array_keys(self::REGISTRE)),
        );
    }

    #[DataProvider('ecrans')]
    public function test_accepte_une_periode_rapide(string $route): void
    {
        $this->actingAs($this->admin)
            ->get(route($route, ['periode' => 'hier']))
            ->assertOk()
            ->assertSessionHasNoErrors();
    }

    #[DataProvider('ecrans')]
    public function test_accepte_une_periode_personnalisee(string $route): void
    {
        $this->actingAs($this->admin)
            ->get(route($route, ['date_debut' => '2026-11-01', 'date_fin' => '2026-11-04']))
            ->assertOk();
    }

    #[DataProvider('ecrans')]
    public function test_refuse_une_periode_personnalisee_invalide_avec_le_meme_message(string $route): void
    {
        $this->actingAs($this->admin)
            ->from('/backoffice/dashboard')
            ->get(route($route, ['date_debut' => '2026-11-04', 'date_fin' => '2026-11-01']))
            ->assertRedirect('/backoffice/dashboard')
            ->assertSessionHasErrors(['date_fin' => 'La date de fin doit être postérieure ou égale à la date de début.']);
    }

    #[DataProvider('ecrans')]
    public function test_refuse_une_date_de_fin_seule(string $route): void
    {
        $this->actingAs($this->admin)
            ->get(route($route, ['date_fin' => '2026-11-04']))
            ->assertSessionHasErrors(['date_debut' => 'Renseignez aussi la date de début.']);
    }

    #[DataProvider('ecrans')]
    public function test_accepte_une_date_de_debut_seule_jusqu_a_aujourd_hui(string $route): void
    {
        $this->actingAs($this->admin)
            ->get(route($route, ['date_debut' => now()->subDays(3)->toDateString()]))
            ->assertOk()
            ->assertSessionHasNoErrors();
    }

    public function test_tableau_de_bord_periodes_rapides_trimestres_et_refus_des_dates(): void
    {
        foreach (['hier', 't1', 's2', 'annee_precedente', 'mois_dernier'] as $cle) {
            $this->actingAs($this->admin)->get(route('dashboard', ['periode' => $cle]))->assertOk();
        }

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['date_debut' => '2026-11-01', 'date_fin' => '2026-11-04']))
            ->assertSessionHasErrors(['date_debut' => 'Cet écran ne propose pas de dates libres : choisissez une période.']);

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['periode' => 'trimestre_dernier']))
            ->assertSessionHasErrors(['periode' => 'Période inconnue.']);
    }

    /** Bug corrigé : `subMonth()` débordait et « Mois dernier » affichait octobre un 31 octobre. */
    public function test_tableau_de_bord_mois_dernier_un_31(): void
    {
        Carbon::setTestNow('2026-10-31 12:00:00');

        $this->actingAs($this->admin)
            ->get(route('dashboard', ['periode' => 'mois_dernier']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periode', 'mois_precedent')
                ->where('periodes', fn ($options) => collect($options)->firstWhere('value', 'mois_precedent') === [
                    'value' => 'mois_precedent',
                    'label' => 'Mois dernier',
                    'date_debut' => '2026-09-01',
                    'date_fin' => '2026-09-30',
                ]));
    }

    public function test_historique_d_une_entite_refuse_une_periode_invalide_en_json(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('comptabilite.historique.entite', [
                'auditable_type' => 'App\\Models\\Depense',
                'auditable_id' => 'x',
                'date_debut' => '2026-11-04',
                'date_fin' => '2026-11-01',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_fin']);
    }

    public function test_chaque_ecran_qui_valide_une_periode_est_inscrit_au_registre(): void
    {
        $inscrites = [...array_keys(self::REGISTRE), ...array_keys(self::REGISTRE_SANS_DATES), ...array_keys(self::COUVERTES_AILLEURS)];
        $manquantes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => in_array('GET', $r->methods(), true) && $r->getName() !== null)
            ->filter(fn (RouteDefinition $r) => $this->valideUnePeriode($r))
            ->map(fn (RouteDefinition $r) => $r->getName())
            ->reject(fn (string $nom) => in_array($nom, $inscrites, true))
            ->values()
            ->all();

        $this->assertSame([], $manquantes, 'Écran filtré par période absent du registre RegistreFiltresPeriodeTest::REGISTRE.');
    }

    /**
     * L'action de la route (ou une méthode privée qu'elle appelle) valide une période :
     * Periode::depuisRequeteValidee, directement ou via RapportPerimetreResolver.
     */
    private function valideUnePeriode(RouteDefinition $route): bool
    {
        $controleur = $route->getControllerClass();
        $action = $route->getActionMethod();
        if ($controleur === null || ! class_exists($controleur)) {
            return false;
        }

        $classe = new \ReflectionClass($controleur);
        $methode = $action === $controleur || ! $classe->hasMethod($action) ? '__invoke' : $action;
        if (! $classe->hasMethod($methode)) {
            return false;
        }

        $corps = $this->corps($classe, $methode);
        preg_match_all('/\$this->(\w+)\(/', $corps, $appels);
        foreach (array_unique($appels[1]) as $appel) {
            if ($classe->hasMethod($appel)) {
                $corps .= $this->corps($classe, $appel);
            }
        }

        return str_contains($corps, 'depuisRequeteValidee') || str_contains($corps, 'RapportPerimetreResolver');
    }

    private function corps(\ReflectionClass $classe, string $methode): string
    {
        $reflexion = $classe->getMethod($methode);
        $lignes = file((string) $reflexion->getFileName());

        return implode('', array_slice($lignes, $reflexion->getStartLine() - 1, $reflexion->getEndLine() - $reflexion->getStartLine() + 1));
    }
}
