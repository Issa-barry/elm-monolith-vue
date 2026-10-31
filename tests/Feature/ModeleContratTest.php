<?php

namespace Tests\Feature;

use App\Enums\StatutModeleContrat;
use App\Enums\TypeContrat;
use App\Models\ModeleContrat;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Rh\Contrats\TrameModeleContrat;
use App\Support\Rh\Contrats\VariablesContrat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Modèles de contrat (Paramètres → RH, ADR 0044) : trame en brouillon, texte brut et variables
 * contrôlées, activation après relecture, permission `rh-referentiels.gerer`, un modèle par
 * organisation et par type.
 */
class ModeleContratTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
    }

    private function utilisateur(array $permissions = ['rh-referentiels.gerer'], ?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Matoto '.fake()->unique()->numerify('###'), 'type' => 'depot']);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /** @return array{titre: string, articles: list<array{titre: string, texte: string}>} */
    private function texte(string $article = 'Le salarié {{salarie.nom_complet}} est engagé.'): array
    {
        return ['titre' => 'Contrat de travail', 'articles' => [['titre' => 'Objet', 'texte' => $article]]];
    }

    public function test_sans_modele_enregistre_la_trame_est_proposee_en_brouillon(): void
    {
        $this->actingAs($this->utilisateur())->get(route('modeles-contrat.index'))
            ->assertInertia(fn (Assert $page) => $page->component('ModelesContrat/Index')
                ->has('modeles', 2)
                ->where('modeles.0.type_contrat', 'cdi')
                ->where('modeles.0.statut', 'brouillon')
                ->where('modeles.0.enregistre', false)
                ->where('modeles.0.articles_count', 12)
                ->where('modeles.1.type_contrat', 'cdd'));

        $this->assertSame(0, ModeleContrat::count());
    }

    public function test_la_trame_n_utilise_que_des_variables_connues_de_son_type(): void
    {
        foreach (TypeContrat::cases() as $type) {
            $textes = collect(TrameModeleContrat::articles($type))->flatMap(fn ($a) => [$a['titre'], $a['texte']])->all();
            $this->assertSame([], VariablesContrat::refusees([TrameModeleContrat::titre($type), ...$textes], $type), $type->value);
        }
    }

    public function test_l_edition_presente_le_texte_et_les_variables_du_type(): void
    {
        $this->actingAs($this->utilisateur())->get(route('modeles-contrat.edit', 'cdi'))
            ->assertInertia(fn (Assert $page) => $page->component('ModelesContrat/Edit')
                ->where('modele.titre', 'Contrat de travail à durée indéterminée')
                ->has('modele.articles', 12)
                ->where('variables', fn ($variables) => ! collect($variables)->contains('cle', 'contrat.motif_cdd')));

        $this->actingAs($this->utilisateur())->get(route('modeles-contrat.edit', 'cdd'))
            ->assertInertia(fn (Assert $page) => $page->where('variables', fn ($variables) => collect($variables)->contains('cle', 'contrat.motif_cdd')));
    }

    public function test_enregistrer_conserve_le_statut_et_incremente_la_revision(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), $this->texte())->assertSessionHasNoErrors();
        $modele = ModeleContrat::sole();
        $this->assertSame(1, $modele->revision);
        $this->assertSame(StatutModeleContrat::BROUILLON, $modele->statut);
        $this->assertSame($user->id, $modele->modifie_par);

        $this->actingAs($user)->patch(route('modeles-contrat.statut', ['cdi', 'activer']))->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), $this->texte('Nouveau texte {{salarie.matricule}}.'))->assertSessionHasNoErrors();

        $modele->refresh();
        $this->assertSame(2, $modele->revision);
        $this->assertSame(StatutModeleContrat::ACTIF, $modele->statut);
        $this->assertSame('Nouveau texte {{salarie.matricule}}.', $modele->articles[0]['texte']);
    }

    public function test_une_variable_inconnue_ou_reservee_aux_cdd_est_refusee(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), $this->texte('Bonjour {{salarie.prenom_inconnu}}.'))
            ->assertSessionHasErrors(['articles.0.texte' => 'Variables non reconnues pour un CDI : {{salarie.prenom_inconnu}}.']);
        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), $this->texte('Fin le {{contrat.date_fin}}.'))
            ->assertSessionHasErrors('articles.0.texte');
        $this->assertSame(0, ModeleContrat::count());

        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdd'), $this->texte('Fin le {{contrat.date_fin}}, motif : {{contrat.motif_cdd}}.'))
            ->assertSessionHasNoErrors();
    }

    public function test_un_modele_doit_avoir_au_moins_un_article_complet(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), ['titre' => 'Contrat', 'articles' => []])->assertSessionHasErrors('articles');
        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), ['titre' => 'Contrat', 'articles' => [['titre' => 'Objet', 'texte' => '']]])
            ->assertSessionHasErrors('articles.0.texte');
    }

    public function test_activer_enregistre_la_trame_puis_repasser_en_brouillon(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->patch(route('modeles-contrat.statut', ['cdd', 'activer']))->assertSessionHasNoErrors();
        $modele = ModeleContrat::sole();
        $this->assertSame(TypeContrat::CDD, $modele->type_contrat);
        $this->assertSame(StatutModeleContrat::ACTIF, $modele->statut);
        $this->assertSame($user->id, $modele->active_par);
        $this->assertCount(12, $modele->articles);

        $this->actingAs($user)->patch(route('modeles-contrat.statut', ['cdd', 'desactiver']))->assertSessionHasNoErrors();
        $this->assertSame(StatutModeleContrat::BROUILLON, $modele->fresh()->statut);
        $this->assertNull($modele->fresh()->active_par);
    }

    public function test_sans_rh_referentiels_gerer_tout_est_refuse(): void
    {
        $user = $this->utilisateur(['rh-contrats.read', 'rh-contrats.update']);

        $this->actingAs($user)->get(route('modeles-contrat.index'))->assertForbidden();
        $this->actingAs($user)->get(route('modeles-contrat.edit', 'cdi'))->assertForbidden();
        $this->actingAs($user)->put(route('modeles-contrat.update', 'cdi'), $this->texte())->assertForbidden();
        $this->actingAs($user)->patch(route('modeles-contrat.statut', ['cdi', 'activer']))->assertForbidden();
        $this->assertSame(0, ModeleContrat::count());
    }

    public function test_chaque_organisation_a_ses_propres_modeles(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->actingAs($this->utilisateur())->put(route('modeles-contrat.update', 'cdi'), $this->texte('Texte A {{salarie.nom}}.'))->assertSessionHasNoErrors();
        $this->actingAs($this->utilisateur())->patch(route('modeles-contrat.statut', ['cdi', 'activer']));

        $autre = $this->utilisateur(org: $autreOrg);
        $this->actingAs($autre)->get(route('modeles-contrat.edit', 'cdi'))
            ->assertInertia(fn (Assert $page) => $page->where('modele.enregistre', false)->where('modele.statut', 'brouillon'));
        $this->actingAs($autre)->put(route('modeles-contrat.update', 'cdi'), $this->texte('Texte B {{salarie.nom}}.'))->assertSessionHasNoErrors();

        $this->assertSame('Texte A {{salarie.nom}}.', ModeleContrat::where('organization_id', $this->org->id)->sole()->articles[0]['texte']);
        $this->assertSame(StatutModeleContrat::ACTIF, ModeleContrat::where('organization_id', $this->org->id)->sole()->statut);
        $this->assertSame(StatutModeleContrat::BROUILLON, ModeleContrat::where('organization_id', $autreOrg->id)->sole()->statut);
    }

    public function test_un_type_inconnu_est_introuvable(): void
    {
        $this->actingAs($this->utilisateur())->get('/backoffice/modeles-contrat/interim/edit')->assertNotFound();
    }
}
