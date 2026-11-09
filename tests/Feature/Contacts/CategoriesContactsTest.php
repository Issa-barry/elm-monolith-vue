<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\CategorieLibre;
use App\Models\ContactSousCategorie;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Support\Contacts\CategoriesLibresParDefaut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Lot 6a du référentiel Contacts (ADR 0060, CONTACT-033 à CONTACT-037) : écran « Catégories de
 * contacts » (contacts.gerer_categories), sous-catégories Fournisseur seules administrables,
 * sous-catégories système protégées, catégories libres (Partenaire par défaut) attribuées avec
 * contacts.update aux fiches visibles, filtres des listes, aucun effet métier.
 */
class CategoriesContactsTest extends TestCase
{
    use RefreshDatabase;

    private const GESTION = ['contacts.read', 'contacts.gerer_categories'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
    }

    private function utilisateur(array $permissions, ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $org ??= $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('manager');
        $site = Site::create(['organization_id' => $org->id, 'nom' => 'Agence '.$user->id, 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function partenaire(?Organization $org = null): CategorieLibre
    {
        return CategorieLibre::where('organization_id', ($org ?? $this->org)->id)->where('code', 'partenaire')->firstOrFail();
    }

    private function sousCategorieFournisseur(string $libelle, bool $active = true, ?Organization $org = null): ContactSousCategorie
    {
        return ContactSousCategorie::create([
            'organization_id' => ($org ?? $this->org)->id, 'categorie' => 'fournisseur',
            'code' => str($libelle)->slug('_')->toString(), 'libelle' => $libelle, 'is_active' => $active,
        ]);
    }

    /** Entreprise fournisseur : visible avec fournisseurs.read et le module Achats. */
    private function entrepriseFournisseur(): EntrepriseTierce
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Société Kaloum']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);

        return $entreprise;
    }

    private function personneFournisseur(): Personne
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);

        return $personne;
    }

    // ── Partenaire par défaut (décision G) ──────────────────────────────────

    public function test_chaque_organisation_recoit_partenaire_une_seule_fois(): void
    {
        $this->assertSame('Partenaire', $this->partenaire()->libelle);
        $this->assertSame(0, CategoriesLibresParDefaut::initialiserPour($this->org->id));

        // Renommée puis réinitialisée : jamais une seconde Partenaire.
        $this->partenaire()->update(['libelle' => 'Partenaire institutionnel']);
        $this->assertSame(0, CategoriesLibresParDefaut::initialiserPour($this->org->id));
        $this->assertSame(1, CategorieLibre::where('organization_id', $this->org->id)->count());
    }

    public function test_la_migration_de_donnees_rejouee_ne_cree_aucun_doublon(): void
    {
        $migration = require database_path('migrations/2026_11_08_200100_seed_categorie_libre_partenaire.php');
        DB::table('contact_categories')->where('organization_id', $this->org->id)->delete();

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('contact_categories')->where('organization_id', $this->org->id)->where('code', 'partenaire')->count());
    }

    // ── Écran ───────────────────────────────────────────────────────────────

    public function test_l_ecran_exige_contacts_gerer_categories(): void
    {
        $this->actingAs($this->utilisateur(['contacts.read', 'contacts.update']))
            ->get(route('contacts.categories.index'))
            ->assertForbidden();

        $this->actingAs($this->utilisateur(self::GESTION))
            ->get(route('contacts.categories.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Contacts/Categories/Index')
                ->where('sections.0.categorie', 'client')
                ->where('sections.0.administrable', false)
                ->where('sections.1.categorie', 'fournisseur')
                ->where('sections.1.administrable', true)
                ->where('sections.2.categorie', 'prestataire')
                // Lot 6b (décision Q3) : les qualifications Prestataire s'administrent.
                ->where('sections.2.administrable', true)
                ->where('libres.0.code', 'partenaire'));
    }

    public function test_creer_une_sous_categorie_fournisseur_code_stable_et_libelle_unique(): void
    {
        $user = $this->utilisateur(self::GESTION);

        $this->actingAs($user)->post(route('contacts.categories.sous-categories.store'), ['categorie' => 'fournisseur', 'libelle' => '  Emballages  '])->assertSessionHasNoErrors();
        $emballages = ContactSousCategorie::where('organization_id', $this->org->id)->where('categorie', 'fournisseur')->firstOrFail();
        $this->assertSame('emballages', $emballages->code);
        $this->assertSame('Emballages', $emballages->libelle);
        $this->assertFalse($emballages->is_system);

        $this->actingAs($user)->post(route('contacts.categories.sous-categories.store'), ['categorie' => 'fournisseur', 'libelle' => 'EMBALLAGES'])->assertSessionHasErrors('libelle');

        // Renommée : le code ne change pas ; un nouveau « Emballages » reçoit un autre code.
        $this->actingAs($user)->patch(route('contacts.categories.sous-categories.update', $emballages), ['libelle' => 'Cartons'])->assertSessionHasNoErrors();
        $this->assertSame('emballages', $emballages->fresh()->code);
        $this->actingAs($user)->post(route('contacts.categories.sous-categories.store'), ['categorie' => 'fournisseur', 'libelle' => 'Emballages'])->assertSessionHasNoErrors();
        $this->assertTrue(ContactSousCategorie::where('organization_id', $this->org->id)->where('code', 'emballages_2')->exists());
    }

    /** Lot 6b (décision Q3) : seules les natures client restent en lecture seule. */
    public function test_les_natures_client_restent_en_lecture_seule(): void
    {
        $user = $this->utilisateur(self::GESTION);
        $grossiste = ContactSousCategorie::where('organization_id', $this->org->id)->where('code', 'grossiste')->firstOrFail();

        $this->actingAs($user)->post(route('contacts.categories.sous-categories.store'), ['categorie' => 'client', 'libelle' => 'Nouvelle'])->assertSessionHasErrors('categorie');
        $this->actingAs($user)->patch(route('contacts.categories.sous-categories.update', $grossiste), ['libelle' => 'Gros', 'is_active' => false])->assertSessionHasErrors('categorie');

        $this->assertSame('Grossiste', $grossiste->fresh()->libelle);
        $this->assertTrue($grossiste->fresh()->is_active);
        $this->assertSame(7, ContactSousCategorie::where('organization_id', $this->org->id)->count());
    }

    public function test_une_sous_categorie_systeme_reste_protegee_meme_pour_le_super_admin(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = $this->utilisateur([]);
        $superAdmin->assignRole('super_admin');
        $consultant = ContactSousCategorie::where('organization_id', $this->org->id)->where('code', 'consultant')->firstOrFail();

        $this->actingAs($superAdmin)->patch(route('contacts.categories.sous-categories.update', $consultant), ['libelle' => 'Conseiller', 'is_active' => false])->assertSessionHasErrors();

        $this->assertSame('Consultant', $consultant->fresh()->libelle);
        $this->assertTrue($consultant->fresh()->is_active);
    }

    public function test_un_element_d_une_autre_organisation_repond_404(): void
    {
        $ailleurs = Organization::factory()->create();
        $user = $this->utilisateur(self::GESTION);

        $this->actingAs($user)->patch(route('contacts.categories.sous-categories.update', $this->sousCategorieFournisseur('Emballages', true, $ailleurs)), ['libelle' => 'X'])->assertNotFound();
        $this->actingAs($user)->patch(route('contacts.categories.libres.update', $this->partenaire($ailleurs)), ['libelle' => 'X'])->assertNotFound();
        $this->assertSame('Partenaire', $this->partenaire($ailleurs)->libelle);
    }

    public function test_desactiver_puis_reactiver_une_sous_categorie_et_une_categorie_libre(): void
    {
        $user = $this->utilisateur(self::GESTION);
        $emballages = $this->sousCategorieFournisseur('Emballages');

        $this->actingAs($user)->patch(route('contacts.categories.sous-categories.update', $emballages), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($emballages->fresh()->is_active);
        $this->actingAs($user)->patch(route('contacts.categories.libres.update', $this->partenaire()), ['is_active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->partenaire()->is_active);
        $this->actingAs($user)->patch(route('contacts.categories.libres.update', $this->partenaire()), ['is_active' => true])->assertSessionHasNoErrors();
        $this->assertTrue($this->partenaire()->is_active);
        $this->assertTrue(AuditLog::where('auditable_id', $emballages->id)->where('event_code', 'status_changed')->exists());
    }

    public function test_categorie_libre_libelle_unique_sans_casse_et_code_stable(): void
    {
        $user = $this->utilisateur(self::GESTION);

        $this->actingAs($user)->post(route('contacts.categories.libres.store'), ['libelle' => 'PARTENAIRE'])->assertSessionHasErrors('libelle');
        $this->actingAs($user)->post(route('contacts.categories.libres.store'), ['libelle' => 'Institutionnel'])->assertSessionHasNoErrors();
        $this->actingAs($user)->patch(route('contacts.categories.libres.update', $this->partenaire()), ['libelle' => 'institutionnel'])->assertSessionHasErrors('libelle');
        $this->actingAs($user)->patch(route('contacts.categories.libres.update', $this->partenaire()), ['libelle' => 'Partenaire commercial'])->assertSessionHasNoErrors();

        $this->assertSame('partenaire', $this->partenaire()->code);
        $this->assertSame('Partenaire commercial', $this->partenaire()->libelle);
    }

    public function test_reordonner_une_liste(): void
    {
        $user = $this->utilisateur(self::GESTION);
        $this->actingAs($user)->post(route('contacts.categories.libres.store'), ['libelle' => 'Institutionnel']);
        $institutionnel = CategorieLibre::where('code', 'institutionnel')->firstOrFail();

        $this->actingAs($user)->post(route('contacts.categories.ordre'), ['liste' => 'libres', 'ids' => [$institutionnel->id, $this->partenaire()->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, $institutionnel->fresh()->ordre);
        $this->assertSame(2, $this->partenaire()->ordre);

        // Liste incomplète, ou liste non administrable : refus.
        $this->actingAs($user)->post(route('contacts.categories.ordre'), ['liste' => 'libres', 'ids' => [$institutionnel->id]])->assertSessionHasErrors('ids');
        $grossiste = ContactSousCategorie::where('organization_id', $this->org->id)->where('code', 'grossiste')->firstOrFail();
        $this->actingAs($user)->post(route('contacts.categories.ordre'), ['liste' => 'client', 'ids' => [$grossiste->id]])->assertSessionHasErrors('categorie');
    }

    // ── Sous-catégories d'un fournisseur ────────────────────────────────────

    private function donneesFournisseur(array $surcharges = []): array
    {
        return ['raison_sociale' => 'Kaloum Emballages', 'phone' => '622000009', 'code_pays' => 'GN', 'ville' => 'Conakry', ...$surcharges];
    }

    public function test_un_fournisseur_porte_plusieurs_sous_categories_journalisees(): void
    {
        $emballages = $this->sousCategorieFournisseur('Emballages');
        $matieres = $this->sousCategorieFournisseur('Matières premières');
        $user = $this->utilisateur(['fournisseurs.read', 'fournisseurs.create', 'fournisseurs.update']);

        $this->actingAs($user)->post(route('fournisseurs.store'), $this->donneesFournisseur(['sous_categorie_ids' => [$emballages->id, $matieres->id]]))->assertSessionHasNoErrors();
        $fournisseur = Fournisseur::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertEqualsCanonicalizing([$emballages->id, $matieres->id], $fournisseur->sousCategories()->pluck('contact_sous_categories.id')->all());

        $this->actingAs($user)->put(route('fournisseurs.update', $fournisseur), $this->donneesFournisseur(['sous_categorie_ids' => [$matieres->id]]))->assertSessionHasNoErrors();
        $this->assertSame([$matieres->id], $fournisseur->sousCategories()->pluck('contact_sous_categories.id')->all());
        $this->assertTrue(AuditLog::where('auditable_id', $fournisseur->id)->where('meta->origine', 'sous_categories')->exists());

        // Sans le champ : rien ne change.
        $this->actingAs($user)->put(route('fournisseurs.update', $fournisseur), $this->donneesFournisseur())->assertSessionHasNoErrors();
        $this->assertSame([$matieres->id], $fournisseur->sousCategories()->pluck('contact_sous_categories.id')->all());
    }

    public function test_une_sous_categorie_desactivee_se_garde_mais_ne_s_ajoute_pas(): void
    {
        $emballages = $this->sousCategorieFournisseur('Emballages');
        $archivee = $this->sousCategorieFournisseur('Archivée', false);
        $user = $this->utilisateur(['fournisseurs.read', 'fournisseurs.create', 'fournisseurs.update']);
        $this->actingAs($user)->post(route('fournisseurs.store'), $this->donneesFournisseur(['sous_categorie_ids' => [$emballages->id]]));
        $fournisseur = Fournisseur::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($user)->put(route('fournisseurs.update', $fournisseur), $this->donneesFournisseur(['sous_categorie_ids' => [$emballages->id, $archivee->id]]))->assertSessionHasErrors('sous_categorie_ids');

        $fournisseur->sousCategories()->attach($archivee->id);
        $emballages->update(['is_active' => false]);
        $this->actingAs($user)->put(route('fournisseurs.update', $fournisseur), $this->donneesFournisseur(['sous_categorie_ids' => [$emballages->id, $archivee->id], 'notes' => 'gardées']))->assertSessionHasNoErrors();
        $this->assertSame('gardées', $fournisseur->fresh()->notes);
        $this->assertSame(2, $fournisseur->sousCategories()->count());
    }

    public function test_une_sous_categorie_d_une_autre_fonction_ou_organisation_est_refusee(): void
    {
        $user = $this->utilisateur(['fournisseurs.read', 'fournisseurs.create']);
        $machiniste = ContactSousCategorie::where('organization_id', $this->org->id)->where('code', 'machiniste')->firstOrFail();
        $ailleurs = $this->sousCategorieFournisseur('Emballages', true, Organization::factory()->create());

        foreach ([$machiniste->id, $ailleurs->id] as $id) {
            $this->actingAs($user)->post(route('fournisseurs.store'), $this->donneesFournisseur(['sous_categorie_ids' => [$id]]))->assertSessionHasErrors('sous_categorie_ids');
        }
        $this->assertDatabaseCount('fournisseurs', 0);
    }

    // ── Attribution des catégories libres ───────────────────────────────────

    public function test_attribuer_une_categorie_libre_exige_contacts_update_et_une_fiche_visible(): void
    {
        $personne = $this->personneFournisseur();
        $entreprise = $this->entrepriseFournisseur();
        $ids = ['categorie_ids' => [$this->partenaire()->id]];

        $this->actingAs($this->utilisateur(['contacts.read', 'fournisseurs.read']))
            ->put(route('contacts.personnes.categories.update', $personne), $ids)->assertForbidden();
        // Droit présent mais fiche non visible (pas de fournisseurs.read) : 404.
        $this->actingAs($this->utilisateur(['contacts.read', 'contacts.update']))
            ->put(route('contacts.entreprises.categories.update', $entreprise), $ids)->assertNotFound();

        $user = $this->utilisateur(['contacts.read', 'contacts.update', 'fournisseurs.read']);
        $this->actingAs($user)->put(route('contacts.personnes.categories.update', $personne), $ids)->assertSessionHasNoErrors();
        $this->actingAs($user)->put(route('contacts.entreprises.categories.update', $entreprise), $ids)->assertSessionHasNoErrors();

        $this->assertSame([$this->partenaire()->id], $personne->categoriesLibres()->pluck('contact_categories.id')->all());
        $this->assertSame([$this->partenaire()->id], $entreprise->categoriesLibres()->pluck('contact_categories.id')->all());

        $this->actingAs($user)->put(route('contacts.personnes.categories.update', $personne), ['categorie_ids' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, $personne->categoriesLibres()->count());
    }

    public function test_categorie_libre_desactivee_gardee_mais_pas_ajoutee_et_autre_organisation_refusee(): void
    {
        $personne = $this->personneFournisseur();
        $user = $this->utilisateur(['contacts.read', 'contacts.update', 'fournisseurs.read']);
        $this->partenaire()->update(['is_active' => false]);

        $this->actingAs($user)->put(route('contacts.personnes.categories.update', $personne), ['categorie_ids' => [$this->partenaire()->id]])->assertSessionHasErrors('categorie_ids');

        $personne->categoriesLibres()->attach($this->partenaire()->id);
        $this->actingAs($user)->put(route('contacts.personnes.categories.update', $personne), ['categorie_ids' => [$this->partenaire()->id]])->assertSessionHasNoErrors();
        $this->assertSame(1, $personne->categoriesLibres()->count());

        $ailleurs = $this->partenaire(Organization::factory()->create());
        $this->actingAs($user)->put(route('contacts.personnes.categories.update', $personne), ['categorie_ids' => [$ailleurs->id]])->assertSessionHasErrors('categorie_ids');
    }

    public function test_les_fiches_presentent_les_categories_et_le_droit_de_les_modifier(): void
    {
        $personne = $this->personneFournisseur();
        $personne->categoriesLibres()->attach($this->partenaire()->id);

        $this->actingAs($this->utilisateur(['contacts.read', 'fournisseurs.read']))
            ->get(route('contacts.personnes.show', $personne))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('categories.0.libelle', 'Partenaire')
                ->where('peut_modifier_categories', false)
                ->where('categories_options', []));

        $this->actingAs($this->utilisateur(['contacts.read', 'contacts.update', 'fournisseurs.read']))
            ->get(route('contacts.personnes.show', $personne))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('peut_modifier_categories', true)
                ->where('categories_options.0.value', $this->partenaire()->id));
    }

    public function test_le_filtre_categorie_des_listes(): void
    {
        $marquee = $this->personneFournisseur();
        $this->personneFournisseur();
        $marquee->categoriesLibres()->attach($this->partenaire()->id);
        $entreprise = $this->entrepriseFournisseur();
        $entreprise->categoriesLibres()->attach($this->partenaire()->id);
        EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Autre SA'])->fournisseurs()->create(['organization_id' => $this->org->id]);
        $user = $this->utilisateur(['contacts.read', 'fournisseurs.read']);

        $this->actingAs($user)->get(route('contacts.personnes.index', ['categorie' => 'partenaire']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('personnes.total', 1)
                ->where('personnes.data.0.id', $marquee->id)
                ->where('options.categories.0.value', 'partenaire'));
        $this->actingAs($user)->get(route('contacts.entreprises.index', ['categorie' => 'partenaire']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entreprises.total', 1)
                ->where('entreprises.data.0.id', $entreprise->id));
    }

    public function test_le_journal_d_attribution_ne_contient_aucune_donnee_personnelle(): void
    {
        $personne = $this->personneFournisseur();
        $this->actingAs($this->utilisateur(['contacts.read', 'contacts.update', 'fournisseurs.read']))
            ->put(route('contacts.personnes.categories.update', $personne), ['categorie_ids' => [$this->partenaire()->id]]);

        $entree = AuditLog::where('auditable_id', $personne->id)->firstOrFail();
        $this->assertSame([$this->partenaire()->id], $entree->meta['categories_ajoutees']);
        $texte = json_encode([$entree->old_values, $entree->new_values, $entree->meta]);
        $this->assertStringNotContainsString((string) $personne->telephone, $texte);
        $this->assertStringNotContainsString('Partenaire', $texte);
    }

    // ── Aucun effet métier ──────────────────────────────────────────────────

    public function test_aucun_code_metier_ne_lit_les_categories_libres_ni_les_sous_categories_fournisseur(): void
    {
        $autorises = [
            'Models/CategorieLibre.php', 'Models/CategorieLibreAttribution.php', 'Models/Personne.php',
            'Models/EntrepriseTierce.php', 'Models/Fournisseur.php', 'Models/Organization.php',
            // Relation et modèle de liaison du lot 1, lus par aucune règle avant le lot 6b.
            'Models/Prestataire.php', 'Models/FonctionSousCategorie.php',
            'Policies/CategorieLibrePolicy.php', 'Support/Contacts/', 'Services/Contacts/',
            'Http/Controllers/Contacts/', 'Http/Controllers/FournisseurController.php',
            // Lot 6b : affichage et saisie des qualifications Prestataire (la règle consultant passe
            // par Prestataire::qualifieConsultant(), dans le modèle).
            'Http/Controllers/PrestataireController.php',
        ];
        $fautifs = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $fichier) {
            $relatif = str_replace('\\', '/', $fichier->getRelativePathname());
            $contenu = $fichier->getContents();
            $lit = str_contains($contenu, 'CategorieLibre') || str_contains($contenu, 'contact_categories')
                || str_contains($contenu, 'fournisseur_sous_categories') || str_contains($contenu, 'sousCategories');
            $autorise = collect($autorises)->contains(fn (string $a) => str_starts_with($relatif, $a));
            if ($lit && ! $autorise) {
                $fautifs[] = $relatif;
            }
        }

        $this->assertSame([], $fautifs, 'Les catégories libres et sous-catégories fournisseur ne doivent avoir aucun effet métier.');
    }
}
