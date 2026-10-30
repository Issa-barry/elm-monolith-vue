<?php

namespace Tests\Feature;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Support\Documents\DocumentPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Récapitulatif PDF d'un contrat (docs/rh-contrat-pdf.md) : aperçu et téléchargement, mêmes accès
 * que la liste des contrats (permission, organisation, agence), salaire selon sa permission. Le
 * contenu du document est testé dans Documents\ModeleDocumentPdfTest.
 */
class ContratPdfTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
    }

    private function utilisateur(array $permissions, string $role = 'admin_entreprise', ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function contrat(?Site $site = null, array $attributs = [], ?Organization $org = null, string $matricule = '373601'): Contrat
    {
        $orgId = ($org ?? $this->org)->id;
        $personne = Personne::create([
            'organization_id' => $orgId, 'nom' => 'DIALLO', 'prenom' => 'Thierno Oumar', 'civilite' => 'monsieur',
            'telephone' => '+22462'.fake()->unique()->numerify('#######'),
        ]);
        $employe = Employe::create([
            'organization_id' => $orgId, 'personne_id' => $personne->id, 'matricule' => $matricule,
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $site?->id,
        ]);

        return Contrat::create(array_merge([
            'organization_id' => $orgId, 'employe_id' => $employe->id, 'type_contrat' => TypeContrat::CDI->value,
            'date_debut' => '2026-01-15', 'salaire_base' => 750000, 'statut_contrat' => StatutContrat::ACTIF->value,
        ], $attributs));
    }

    /** Document transmis à la vue, pour vérifier son contenu sans décoder le PDF. */
    private function capturerDocument(): \ArrayObject
    {
        $capture = new \ArrayObject;
        View::composer('pdf.contrat_travail', function ($view) use ($capture) {
            $capture['document'] = $view->getData()['document'];
        });

        return $capture;
    }

    public function test_apercu_dans_le_navigateur_et_telechargement(): void
    {
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(['rh-contrats.read']);

        $apercu = $this->actingAs($user)->get(route('contrats.pdf', ['contrat' => $contrat, 'preview' => 1]));
        $apercu->assertOk();
        $this->assertSame('application/pdf', $apercu->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $apercu->headers->get('Content-Disposition'));
        $this->assertStringContainsString('CTR-373601-20260115.pdf', (string) $apercu->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $apercu->getContent());

        $telechargement = $this->actingAs($user)->get(route('contrats.pdf', $contrat));
        $telechargement->assertOk();
        $this->assertStringContainsString('attachment', (string) $telechargement->headers->get('Content-Disposition'));
    }

    public function test_le_document_correspond_au_contrat_choisi(): void
    {
        $this->contrat($this->site, ['date_debut' => '2025-03-01', 'salaire_base' => 1_200_000], matricule: '548817');
        $contrat = $this->contrat($this->site, ['type_contrat' => TypeContrat::CDD->value, 'date_fin' => '2026-12-31']);
        $capture = $this->capturerDocument();

        $this->actingAs($this->utilisateur(['rh-contrats.read', 'rh-remunerations.read']))
            ->get(route('contrats.pdf', $contrat))
            ->assertOk();

        /** @var DocumentPdf $document */
        $document = $capture['document'];
        $this->assertSame('CTR-373601-20260115', $document->reference);
        $valeurs = collect($document->sections)->flatMap(fn ($s) => collect($s['lignes'])->pluck('valeur'))->all();
        $this->assertContains('CDD — contrat à durée déterminée', $valeurs);
        $this->assertContains('31/12/2026', $valeurs);
        $this->assertContains('750 000 GNF / mois', $valeurs);
        $this->assertNotContains('1 200 000 GNF / mois', $valeurs);
    }

    public function test_le_salaire_n_est_transmis_qu_avec_rh_remunerations_read(): void
    {
        $contrat = $this->contrat($this->site);
        $capture = $this->capturerDocument();

        $this->actingAs($this->utilisateur(['rh-contrats.read']))
            ->get(route('contrats.pdf', $contrat))
            ->assertOk();

        $valeurs = collect($capture['document']->sections)->flatMap(fn ($s) => collect($s['lignes'])->pluck('valeur'))->all();
        $this->assertNotContains('750 000 GNF', $valeurs);
        $this->assertContains('Réservé aux profils autorisés', $valeurs);
    }

    public function test_sans_rh_contrats_read_le_pdf_est_refuse(): void
    {
        $contrat = $this->contrat($this->site);

        $this->actingAs($this->utilisateur(['rh-employes.read']))
            ->get(route('contrats.pdf', $contrat))
            ->assertForbidden();
    }

    public function test_un_contrat_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $contrat = $this->contrat(null, [], $autreOrg);

        $this->actingAs($this->utilisateur(['rh-contrats.read']))
            ->get(route('contrats.pdf', $contrat))
            ->assertForbidden();
    }

    public function test_hors_de_ses_agences_le_pdf_est_refuse(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $manager = $this->utilisateur(['rh-contrats.read'], 'manager');

        $this->actingAs($manager)->get(route('contrats.pdf', $this->contrat($this->site)))->assertOk();
        $this->actingAs($manager)->get(route('contrats.pdf', $this->contrat($autreSite, matricule: '548817')))->assertForbidden();
        // Salarié sans agence : réservé à la vision de toutes les agences, comme dans la liste.
        $this->actingAs($manager)->get(route('contrats.pdf', $this->contrat(null, matricule: '653228')))->assertForbidden();
    }

    public function test_le_contrat_d_un_salarie_supprime_reste_consultable(): void
    {
        $contrat = $this->contrat($this->site);
        $contrat->employe->delete();
        $capture = $this->capturerDocument();

        $this->actingAs($this->utilisateur(['rh-contrats.read']))
            ->get(route('contrats.pdf', $contrat))
            ->assertOk();

        $this->assertContains('Monsieur Thierno Oumar DIALLO', $capture['document']->parties[1]['lignes']);
    }

    public function test_un_contrat_incomplet_est_genere_marque_incomplet(): void
    {
        $contrat = $this->contrat($this->site, ['salaire_base' => null]);
        $capture = $this->capturerDocument();

        $this->actingAs($this->utilisateur(['rh-contrats.read', 'rh-remunerations.read']))
            ->get(route('contrats.pdf', $contrat))
            ->assertOk();

        $this->assertSame('INCOMPLET', $capture['document']->filigrane);
        $this->assertStringContainsString('salaire de base', $capture['document']->encadre['lignes'][0]);
    }
}
