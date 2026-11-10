<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Support\Contacts\MessagesContacts;
use App\Support\Permissions\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 4 du référentiel Contacts (ADR 0060, CONTACT-021 à CONTACT-026) : gestion des interlocuteurs
 * depuis la fiche Entreprise — permission `contacts.gerer_interlocuteurs`, entreprise visible,
 * appartenance du lien à l'entreprise, confirmation explicite d'une personne connue, message neutre
 * pour une personne non visible, un seul principal actif, désactivation sans suppression, journal
 * sans donnée personnelle, rattachement de compte d'ADR 0056 inchangé.
 */
class InterlocuteursTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['contacts.read', 'fournisseurs.read', 'contacts.gerer_interlocuteurs'];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence A', 'type' => 'depot', 'localisation' => 'Conakry']);
    }

    private function utilisateur(array $permissions = self::DROITS, string $role = 'manager', ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $org ??= $this->org;
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $site = $org->is($this->org) ? $this->site : Site::create(['organization_id' => $org->id, 'nom' => 'Siège', 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** Entreprise fournisseur : visible des lecteurs qui ont fournisseurs.read et le module Achats. */
    private function entreprise(string $raisonSociale = 'Société Kaloum', ?Organization $org = null, bool $active = true): EntrepriseTierce
    {
        $org ??= $this->org;
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => $raisonSociale, 'is_active' => $active]);
        Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id]);

        return $entreprise;
    }

    private function personne(string $telephone, array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => $telephone, ...$attributs]);
    }

    /** Personne visible : fournisseur personne physique. */
    private function personneVisible(string $telephone, array $attributs = []): Personne
    {
        $personne = $this->personne($telephone, $attributs);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);

        return $personne;
    }

    private function lien(EntrepriseTierce $entreprise, Personne $personne, array $attributs = []): EntrepriseInterlocuteur
    {
        return EntrepriseInterlocuteur::create([
            'organization_id' => $entreprise->organization_id,
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $personne->id,
            'is_active' => true,
            ...$attributs,
        ]);
    }

    private function url(string $action, EntrepriseTierce $entreprise, ?EntrepriseInterlocuteur $interlocuteur = null, array $query = []): string
    {
        $parametres = ['entreprise' => $entreprise->id, ...($interlocuteur ? ['interlocuteur' => $interlocuteur->id] : []), ...$query];

        return route("contacts.entreprises.interlocuteurs.{$action}", $parametres);
    }

    private function ajouter(User $user, EntrepriseTierce $entreprise, array $donnees = []): TestResponse
    {
        return $this->actingAs($user)->post($this->url('store', $entreprise), [
            'telephone' => '+224 622 30 00 01', 'nom_complet' => 'mamadou diallo', 'fonction' => 'Responsable technique', ...$donnees,
        ]);
    }

    private function rechercher(User $user, EntrepriseTierce $entreprise, string $telephone): array
    {
        return $this->actingAs($user)->getJson($this->url('recherche-telephone', $entreprise, null, ['telephone' => $telephone]))->assertOk()->json();
    }

    private function principaux(EntrepriseTierce $entreprise): array
    {
        return EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)->where('est_principal', true)->pluck('id')->all();
    }

    // ── Permission ────────────────────────────────────────────────────────────

    public function test_la_permission_est_declaree_et_accordee_aux_roles_autorises(): void
    {
        $this->assertContains('contacts.gerer_interlocuteurs', PermissionCatalog::allPermissionNames());

        // Base existante : la migration de données l'ajoute aux deux rôles d'administration.
        foreach (['super_admin', 'admin_entreprise', 'commercial'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        (require database_path('migrations/2026_11_06_100400_backfill_permissions_contacts.php'))->up();
        $this->assertTrue(Role::findByName('super_admin', 'web')->hasPermissionTo('contacts.gerer_interlocuteurs'));
        $this->assertTrue(Role::findByName('admin_entreprise', 'web')->hasPermissionTo('contacts.gerer_interlocuteurs'));
        $this->assertFalse(Role::findByName('commercial', 'web')->hasPermissionTo('contacts.gerer_interlocuteurs'));

        // Nouvelle installation : le seeder l'accorde à admin_entreprise.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertTrue(Role::findByName('admin_entreprise', 'web')->hasPermissionTo('contacts.gerer_interlocuteurs'));
    }

    public function test_sans_la_permission_aucune_operation_n_est_possible(): void
    {
        $entreprise = $this->entreprise();
        $lien = $this->lien($entreprise, $this->personneVisible('+224622300002'));
        $user = $this->utilisateur(['contacts.read', 'fournisseurs.read']);
        $this->actingAs($user);

        $this->getJson($this->url('recherche-telephone', $entreprise, null, ['telephone' => '+224622300003']))->assertForbidden();
        $this->post($this->url('store', $entreprise), ['telephone' => '+224622300003', 'nom_complet' => 'X'])->assertForbidden();
        $this->patch($this->url('update', $entreprise, $lien), ['fonction' => 'Gérant'])->assertForbidden();
        $this->post($this->url('principal', $entreprise, $lien))->assertForbidden();
        $this->post($this->url('desactiver', $entreprise, $lien))->assertForbidden();
        $this->post($this->url('reactiver', $entreprise, $lien))->assertForbidden();

        $lien->refresh();
        $this->assertNull($lien->fonction);
        $this->assertFalse($lien->est_principal);
        $this->assertTrue($lien->is_active);
        $this->assertSame(1, EntrepriseInterlocuteur::count());

        $this->get(route('contacts.entreprises.show', $entreprise))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('peut_gerer_interlocuteurs', false));
    }

    public function test_la_fiche_transmet_la_permission(): void
    {
        $entreprise = $this->entreprise();
        $this->lien($entreprise, $this->personneVisible('+224622300004'), ['notes' => 'Joignable le matin']);

        $this->actingAs($this->utilisateur())->get(route('contacts.entreprises.show', $entreprise))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('peut_gerer_interlocuteurs', true)
                ->where('interlocuteurs.0.notes', 'Joignable le matin'));
    }

    // ── Organisation, visibilité, appartenance ───────────────────────────────

    public function test_une_entreprise_d_une_autre_organisation_repond_404_meme_au_super_admin(): void
    {
        $autre = Organization::factory()->create();
        Feature::for($autre)->activate(ModuleFeature::ACHATS);
        $entreprise = $this->entreprise('Ailleurs SA', $autre);
        $lien = EntrepriseInterlocuteur::create(['organization_id' => $autre->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => Personne::factory()->create(['organization_id' => $autre->id])->id, 'is_active' => true]);

        foreach ([$this->utilisateur(), $this->utilisateur([], 'super_admin')] as $user) {
            $this->actingAs($user);
            $this->getJson($this->url('recherche-telephone', $entreprise, null, ['telephone' => '+224622300005']))->assertNotFound();
            $this->post($this->url('store', $entreprise), ['telephone' => '+224622300005', 'nom_complet' => 'X'])->assertNotFound();
            $this->patch($this->url('update', $entreprise, $lien), ['fonction' => 'Gérant'])->assertNotFound();
            $this->post($this->url('principal', $entreprise, $lien))->assertNotFound();
            $this->post($this->url('desactiver', $entreprise, $lien))->assertNotFound();
            $this->post($this->url('reactiver', $entreprise, $lien->fresh()))->assertNotFound();
        }

        $lien->refresh();
        $this->assertTrue($lien->is_active);
        $this->assertFalse($lien->est_principal);
        $this->assertNull($lien->fonction);
        $this->assertSame(1, EntrepriseInterlocuteur::count());
    }

    public function test_une_entreprise_non_visible_du_lecteur_repond_404(): void
    {
        // Sans fonction lisible (aucun fournisseur ni prestataire), l'entreprise n'est pas visible.
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Sans fonction']);
        $user = $this->utilisateur();

        $this->actingAs($user)->getJson($this->url('recherche-telephone', $entreprise, null, ['telephone' => '+224622300006']))->assertNotFound();
        $this->ajouter($user, $entreprise)->assertNotFound();
        $this->assertSame(0, EntrepriseInterlocuteur::count());
    }

    public function test_un_interlocuteur_d_une_autre_entreprise_repond_404(): void
    {
        $a = $this->entreprise('Entreprise A');
        $b = $this->entreprise('Entreprise B');
        $lienDeB = $this->lien($b, $this->personneVisible('+224622300007'));
        $this->actingAs($this->utilisateur());

        $this->patch($this->url('update', $a, $lienDeB), ['fonction' => 'Gérant'])->assertNotFound();
        $this->post($this->url('principal', $a, $lienDeB))->assertNotFound();
        $this->post($this->url('desactiver', $a, $lienDeB))->assertNotFound();
        $this->post($this->url('reactiver', $a, $lienDeB))->assertNotFound();

        $lienDeB->refresh();
        $this->assertTrue($lienDeB->is_active);
        $this->assertFalse($lienDeB->est_principal);
        $this->assertNull($lienDeB->fonction);
    }

    // ── Recherche par téléphone ──────────────────────────────────────────────

    public function test_la_recherche_ne_modifie_rien(): void
    {
        $entreprise = $this->entreprise();
        $this->personneVisible('+224622300008', ['nom_complet' => 'Fatou Bah', 'ville' => 'Kindia']);
        $user = $this->utilisateur();
        $avant = [Personne::count(), EntrepriseInterlocuteur::count(), AuditLog::count()];

        $libre = $this->rechercher($user, $entreprise, '+224 622 30 00 09');
        $connu = $this->rechercher($user, $entreprise, '+224622300008');
        $invalide = $this->rechercher($user, $entreprise, '12');

        $this->assertSame('libre', $libre['statut']);
        $this->assertSame('+224622300009', $libre['telephone']);
        $this->assertSame('connu', $connu['statut']);
        $this->assertSame('Fatou Bah', $connu['personne']['nom']);
        $this->assertSame('Kindia', $connu['personne']['ville']);
        $this->assertSame(MessagesContacts::AUTRE_PERSONNE, $connu['message_autre_personne']);
        $this->assertSame('invalide', $invalide['statut']);
        $this->assertSame($avant, [Personne::count(), EntrepriseInterlocuteur::count(), AuditLog::count()]);
    }

    public function test_la_recherche_signale_un_interlocuteur_existant_actif_ou_desactive(): void
    {
        $entreprise = $this->entreprise();
        $this->lien($entreprise, $this->personneVisible('+224622300010'));
        $this->lien($entreprise, $this->personneVisible('+224622300011'), ['is_active' => false]);
        $user = $this->utilisateur();

        $actif = $this->rechercher($user, $entreprise, '+224622300010');
        $desactive = $this->rechercher($user, $entreprise, '+224622300011');

        $this->assertSame(['deja_interlocuteur', true], [$actif['statut'], $actif['lien_actif']]);
        $this->assertSame(['deja_interlocuteur', false], [$desactive['statut'], $desactive['lien_actif']]);
    }

    public function test_une_personne_non_visible_recoit_un_message_neutre(): void
    {
        $entreprise = $this->entreprise();
        $this->personne('+224622300012', ['nom_complet' => 'Compte Seul', 'ville' => 'Boké']);

        $r = $this->rechercher($this->utilisateur(), $entreprise, '+224622300012');

        $this->assertSame('indisponible', $r['statut']);
        $this->assertSame(MessagesContacts::NUMERO_NON_VISIBLE, $r['message']);
        $this->assertArrayNotHasKey('personne', $r);
        $json = json_encode($r, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Compte Seul', $json);
        $this->assertStringNotContainsString('Boké', $json);
    }

    // ── Ajout ────────────────────────────────────────────────────────────────

    public function test_un_numero_libre_cree_la_personne_et_le_lien(): void
    {
        $entreprise = $this->entreprise();
        $user = $this->utilisateur();

        $this->ajouter($user, $entreprise, ['email' => 'M.Diallo@exemple.gn', 'ville' => 'conakry', 'notes' => 'Préfère WhatsApp'])
            ->assertRedirect(route('contacts.entreprises.show', ['entreprise' => $entreprise, 'tab' => 'interlocuteurs']))
            ->assertSessionHas('success', 'Interlocuteur ajouté.');

        $lien = EntrepriseInterlocuteur::sole();
        $this->assertSame($entreprise->id, $lien->entreprise_tierce_id);
        $this->assertSame('Responsable technique', $lien->fonction);
        $this->assertSame('Préfère WhatsApp', $lien->notes);
        $this->assertFalse($lien->est_principal);
        $this->assertFalse($lien->acces_commissions);
        $this->assertSame($user->id, $lien->created_by);
        $this->assertSame('224622300001', $lien->personne->telephone_normalise);
        $this->assertSame('Mamadou Diallo', $lien->personne->nom_complet);
        $this->assertSame('Conakry', $lien->personne->ville);
        $this->assertSame($this->org->id, $lien->personne->organization_id);
    }

    public function test_le_telephone_et_le_nom_sont_obligatoires(): void
    {
        $entreprise = $this->entreprise();
        $user = $this->utilisateur();
        $avant = Personne::count();

        $this->ajouter($user, $entreprise, ['telephone' => ''])->assertSessionHasErrors('telephone');
        $this->ajouter($user, $entreprise, ['telephone' => '123'])->assertSessionHasErrors('telephone');
        $this->ajouter($user, $entreprise, ['nom_complet' => '', 'prenom' => ' ', 'nom' => ''])->assertSessionHasErrors('nom');

        $this->assertSame(0, EntrepriseInterlocuteur::count());
        $this->assertSame($avant, Personne::count());
    }

    public function test_une_personne_connue_n_est_rattachee_qu_avec_confirmation_explicite(): void
    {
        $entreprise = $this->entreprise();
        $fatou = $this->personneVisible('+224622300013', ['nom_complet' => 'Fatou Bah', 'ville' => 'Kindia', 'email' => 'fatou@exemple.gn']);
        $user = $this->utilisateur();

        // Sans confirmation : refus, rien n'est rattaché.
        $this->ajouter($user, $entreprise, ['telephone' => '+224622300013', 'nom_complet' => 'Autre Nom'])->assertSessionHasErrors('telephone');
        $this->assertSame(0, EntrepriseInterlocuteur::count());

        // Avec confirmation : rattachée, ses coordonnées ne sont pas écrasées par la saisie.
        $this->ajouter($user, $entreprise, [
            'telephone' => '+224622300013', 'personne_confirmee_id' => $fatou->id,
            'nom_complet' => 'Autre Nom', 'ville' => 'Labé', 'email' => 'autre@exemple.gn',
        ])->assertSessionHasNoErrors();

        $lien = EntrepriseInterlocuteur::sole();
        $this->assertSame($fatou->id, $lien->personne_id);
        $fatou->refresh();
        $this->assertSame(['Fatou Bah', 'Kindia', 'fatou@exemple.gn'], [$fatou->nom_complet, $fatou->ville, $fatou->email]);
    }

    public function test_un_ajout_sans_confirmation_ne_revele_pas_une_personne_non_visible(): void
    {
        $entreprise = $this->entreprise();
        $this->personne('+224622300014', ['nom_complet' => 'Compte Seul']);
        $user = $this->utilisateur();

        $reponse = $this->ajouter($user, $entreprise, ['telephone' => '+224622300014']);

        $reponse->assertSessionHasErrors(['telephone' => MessagesContacts::NUMERO_NON_VISIBLE]);
        $this->assertStringNotContainsString('Compte Seul', json_encode(session('errors')->getBag('default')->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, EntrepriseInterlocuteur::count());
    }

    public function test_un_identifiant_force_ne_rattache_jamais_une_personne_non_visible(): void
    {
        $entreprise = $this->entreprise();
        $cachee = $this->personne('+224622300015', ['nom_complet' => 'Compte Seul']);
        $visible = $this->personneVisible('+224622300016', ['nom_complet' => 'Fatou Bah']);
        $ailleurs = Personne::factory()->create(['organization_id' => Organization::factory()->create()->id, 'telephone' => '+224622300017', 'nom_complet' => 'Autre Org']);
        $user = $this->utilisateur();

        $essais = [
            // id d'une personne non visible avec son numéro
            ['telephone' => '+224622300015', 'personne_confirmee_id' => $cachee->id],
            // id d'une personne non visible avec un numéro libre
            ['telephone' => '+224622300018', 'personne_confirmee_id' => $cachee->id],
            // id visible, mais le numéro est celui d'une personne non visible
            ['telephone' => '+224622300015', 'personne_confirmee_id' => $visible->id],
            // id d'une autre organisation
            ['telephone' => '+224622300017', 'personne_confirmee_id' => $ailleurs->id],
            // id inexistant
            ['telephone' => '+224622300019', 'personne_confirmee_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        ];
        foreach ($essais as $essai) {
            $reponse = $this->ajouter($user, $entreprise, $essai);
            $reponse->assertSessionHasErrors(['telephone' => MessagesContacts::NUMERO_NON_VISIBLE]);
            $erreurs = json_encode(session('errors')->getBag('default')->toArray(), JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('Compte Seul', $erreurs);
            $this->assertStringNotContainsString('Autre Org', $erreurs);
        }

        $this->assertSame(0, EntrepriseInterlocuteur::count());
    }

    public function test_une_meme_personne_peut_representer_deux_entreprises(): void
    {
        $a = $this->entreprise('Entreprise A');
        $b = $this->entreprise('Entreprise B');
        $user = $this->utilisateur();

        $this->ajouter($user, $a, ['principal' => true])->assertSessionHasNoErrors();
        $personne = EntrepriseInterlocuteur::sole()->personne;
        $this->ajouter($user, $b, ['personne_confirmee_id' => $personne->id, 'fonction' => 'Directeur'])->assertSessionHasNoErrors();

        $this->assertSame(1, Personne::where('telephone_normalise', '224622300001')->count());
        $this->assertSame(2, EntrepriseInterlocuteur::where('personne_id', $personne->id)->count());
        $this->assertSame(1, count($this->principaux($a)));
        $this->assertSame([], $this->principaux($b));
    }

    public function test_un_interlocuteur_ne_peut_pas_etre_ajoute_deux_fois(): void
    {
        $entreprise = $this->entreprise();
        $user = $this->utilisateur();
        $this->ajouter($user, $entreprise)->assertSessionHasNoErrors();
        $personne = EntrepriseInterlocuteur::sole()->personne;

        $this->ajouter($user, $entreprise, ['personne_confirmee_id' => $personne->id])->assertSessionHasErrors('telephone');

        $this->assertSame(1, EntrepriseInterlocuteur::count());
    }

    // ── Principal ────────────────────────────────────────────────────────────

    public function test_un_seul_principal_actif_par_entreprise(): void
    {
        $entreprise = $this->entreprise();
        $user = $this->utilisateur();
        $this->ajouter($user, $entreprise, ['telephone' => '+224622300020', 'principal' => true]);
        $premier = EntrepriseInterlocuteur::sole();
        $this->assertSame([$premier->id], $this->principaux($entreprise));

        // Un second ajouté « principal » remplace le premier.
        $this->ajouter($user, $entreprise, ['telephone' => '+224622300021', 'nom_complet' => 'Alpha Camara', 'principal' => true]);
        $second = EntrepriseInterlocuteur::where('id', '!=', $premier->id)->sole();
        $this->assertSame([$second->id], $this->principaux($entreprise));

        // Désignation explicite.
        $this->actingAs($user)->post($this->url('principal', $entreprise, $premier))
            ->assertRedirect(route('contacts.entreprises.show', ['entreprise' => $entreprise, 'tab' => 'interlocuteurs']));
        $this->assertSame([$premier->id], $this->principaux($entreprise));

        // Désigner celui qui l'est déjà ne change rien.
        $avant = AuditLog::count();
        $this->post($this->url('principal', $entreprise, $premier));
        $this->assertSame([$premier->id], $this->principaux($entreprise));
        $this->assertSame($avant, AuditLog::count());
    }

    public function test_un_interlocuteur_desactive_ne_peut_pas_etre_principal(): void
    {
        $entreprise = $this->entreprise();
        $lien = $this->lien($entreprise, $this->personneVisible('+224622300022'), ['is_active' => false]);

        $this->actingAs($this->utilisateur())->post($this->url('principal', $entreprise, $lien))->assertSessionHasErrors('interlocuteur');

        $this->assertSame([], $this->principaux($entreprise));
    }

    // ── Modification ─────────────────────────────────────────────────────────

    public function test_la_modification_ne_touche_que_la_fonction_et_les_notes(): void
    {
        $entreprise = $this->entreprise();
        $personne = $this->personneVisible('+224622300023', ['nom_complet' => 'Fatou Bah', 'email' => 'fatou@exemple.gn', 'ville' => 'Kindia']);
        $lien = $this->lien($entreprise, $personne, ['fonction' => 'Comptable', 'acces_commissions' => true]);

        $this->actingAs($this->utilisateur())->patch($this->url('update', $entreprise, $lien), [
            'fonction' => 'Directrice financière', 'notes' => 'Signataire',
            'telephone' => '+224622399999', 'email' => 'pirate@exemple.gn', 'nom_complet' => 'Pirate',
            'est_principal' => true, 'acces_commissions' => false, 'is_active' => false, 'personne_id' => 'x',
        ])->assertSessionHasNoErrors();

        $lien->refresh();
        $this->assertSame(['Directrice financière', 'Signataire'], [$lien->fonction, $lien->notes]);
        $this->assertSame([false, true, true, $personne->id], [$lien->est_principal, $lien->acces_commissions, $lien->is_active, $lien->personne_id]);
        $personne->refresh();
        $this->assertSame(['Fatou Bah', 'fatou@exemple.gn', 'Kindia', '224622300023'], [$personne->nom_complet, $personne->email, $personne->ville, $personne->telephone_normalise]);
    }

    // ── Désactivation et réactivation ────────────────────────────────────────

    public function test_desactiver_conserve_le_lien_et_l_acces_commissions_puis_reactiver_sans_principal(): void
    {
        $entreprise = $this->entreprise();
        $lien = $this->lien($entreprise, $this->personneVisible('+224622300024'), ['fonction' => 'Gérant', 'est_principal' => true, 'acces_commissions' => true]);
        $user = $this->utilisateur();

        $this->actingAs($user)->post($this->url('desactiver', $entreprise, $lien))->assertSessionHas('success', 'Interlocuteur désactivé.');

        $lien->refresh();
        $this->assertFalse($lien->is_active);
        $this->assertFalse($lien->est_principal);
        $this->assertTrue($lien->acces_commissions);
        $this->assertNotNull($lien->desactive_le);
        $this->assertSame($user->id, $lien->desactive_par);
        $this->assertSame('Gérant', $lien->fonction);
        $this->assertSame(1, EntrepriseInterlocuteur::count());

        $this->post($this->url('reactiver', $entreprise, $lien))->assertSessionHas('success', 'Interlocuteur réactivé.');

        $lien->refresh();
        $this->assertTrue($lien->is_active);
        $this->assertFalse($lien->est_principal);
        $this->assertTrue($lien->acces_commissions);
        $this->assertNull($lien->desactive_le);
    }

    public function test_une_entreprise_desactivee_refuse_l_ajout_mais_garde_la_gestion_des_existants(): void
    {
        $entreprise = $this->entreprise('Dormante SA', null, false);
        $lien = $this->lien($entreprise, $this->personneVisible('+224622300025'));
        $user = $this->utilisateur();
        $personnes = Personne::count();

        $this->ajouter($user, $entreprise, ['telephone' => '+224622300026'])->assertSessionHasErrors('entreprise');
        $this->assertSame(1, EntrepriseInterlocuteur::count());
        $this->assertSame($personnes, Personne::count());

        $this->actingAs($user)->patch($this->url('update', $entreprise, $lien), ['fonction' => 'Liquidateur'])->assertSessionHasNoErrors();
        $this->post($this->url('principal', $entreprise, $lien))->assertSessionHasNoErrors();
        $this->post($this->url('desactiver', $entreprise, $lien))->assertSessionHasNoErrors();
        $this->post($this->url('reactiver', $entreprise, $lien))->assertSessionHasNoErrors();

        $lien->refresh();
        $this->assertSame('Liquidateur', $lien->fonction);
        $this->assertTrue($lien->is_active);
    }

    // ── Journal d'audit ──────────────────────────────────────────────────────

    public function test_chaque_operation_est_journalisee_sans_donnee_personnelle(): void
    {
        $entreprise = $this->entreprise();
        $user = $this->utilisateur();

        $this->ajouter($user, $entreprise, ['email' => 'm.diallo@exemple.gn', 'notes' => 'Note confidentielle', 'principal' => true]);
        $lien = EntrepriseInterlocuteur::sole();
        $this->actingAs($user)->patch($this->url('update', $entreprise, $lien), ['fonction' => 'Gérant', 'notes' => 'Autre note secrète']);
        $this->ajouter($user, $entreprise, ['telephone' => '+224622300027', 'nom_complet' => 'Alpha Camara']);
        $second = EntrepriseInterlocuteur::where('id', '!=', $lien->id)->sole();
        $this->post($this->url('principal', $entreprise, $second));
        $this->post($this->url('desactiver', $entreprise, $lien));
        $this->post($this->url('reactiver', $entreprise, $lien));

        $journal = AuditLog::where('auditable_type', $lien->getMorphClass())->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(
            ['created' => 2, 'status_changed' => 2, 'updated' => 2],
            $journal->countBy('event_code')->sortKeys()->all(),
        );
        $this->assertTrue($journal->every(fn (AuditLog $l) => $l->organization_id === $this->org->id && $l->actor_id === $user->id));

        $creation = $journal->firstWhere(fn (AuditLog $l) => $l->event_code === 'created' && $l->auditable_id === $lien->id);
        // assertEquals : une colonne JSON MySQL réordonne les clés.
        $this->assertEquals(['entreprise_tierce_id' => $entreprise->id, 'est_principal' => true], $creation->new_values);
        $modification = $journal->firstWhere(fn (AuditLog $l) => $l->event_code === 'updated' && $l->auditable_id === $lien->id);
        $this->assertEqualsCanonicalizing(['fonction', 'notes'], $modification->meta['champs_modifies']);
        $designation = $journal->firstWhere(fn (AuditLog $l) => $l->event_code === 'updated' && $l->auditable_id === $second->id);
        $this->assertSame([$lien->id], $designation->meta['ancien_principal_ids']);

        $contenu = $journal->map(fn (AuditLog $l) => json_encode([$l->old_values, $l->new_values, $l->meta], JSON_UNESCAPED_UNICODE))->implode(' ');
        foreach (['Mamadou', 'Diallo', 'Alpha', 'Camara', '622300001', '622300027', 'exemple.gn', 'confidentielle', 'secrète', 'Gérant'] as $donnee) {
            $this->assertStringNotContainsString($donnee, $contenu, $donnee);
        }
    }

    // ── Rattachement de compte (ADR 0056) ───────────────────────────────────

    public function test_le_rattachement_de_compte_reste_celui_d_adr_0056(): void
    {
        $entreprise = $this->entreprise();
        $user = $this->utilisateur();
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);

        // Même Personne que le compte (confirmée) : reliée automatiquement par l'observer d'ADR 0056.
        $titulaire = $this->personneVisible('+224622300028', ['nom_complet' => 'Titulaire Compte']);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $titulaire->id]);
        $compte->assignRole('client');
        $this->ajouter($user, $entreprise, ['telephone' => '+224622300028', 'personne_confirmee_id' => $titulaire->id])->assertSessionHasNoErrors();
        $this->assertSame($compte->id, EntrepriseInterlocuteur::where('personne_id', $titulaire->id)->sole()->user_id);

        // Nouvelle Personne : jamais reliée à un compte par la seule saisie.
        $this->ajouter($user, $entreprise, ['telephone' => '+224622300029', 'nom_complet' => 'Nouveau Contact'])->assertSessionHasNoErrors();
        $this->assertNull(EntrepriseInterlocuteur::where('personne_id', '!=', $titulaire->id)->sole()->user_id);
    }

    // ── Verrou (MySQL / MariaDB) ─────────────────────────────────────────────

    public function test_les_operations_sur_le_principal_verrouillent_l_entreprise(): void
    {
        $entreprise = $this->entreprise();
        $lien = $this->lien($entreprise, $this->personneVisible('+224622300030'));
        $user = $this->utilisateur();
        $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);

        $operations = [
            'ajout' => fn () => $this->ajouter($user, $entreprise, ['telephone' => '+224622300031', 'principal' => true]),
            'principal' => fn () => $this->actingAs($user)->post($this->url('principal', $entreprise, $lien)),
            'desactiver' => fn () => $this->actingAs($user)->post($this->url('desactiver', $entreprise, $lien)),
        ];
        $requetes = collect();
        DB::listen(function ($q) use (&$requetes) {
            $requetes->push($q->sql);
        });
        foreach ($operations as $nom => $operation) {
            $requetes = collect();
            $operation()->assertSessionHasNoErrors();

            $verrous = $requetes->filter(fn (string $sql) => str_contains($sql, 'entreprises_tierces') && str_contains(strtolower($sql), 'for update'));
            if ($mysql) {
                $this->assertNotEmpty($verrous, "{$nom} : l'entreprise n'est pas verrouillée (SELECT ... FOR UPDATE).");
            } else {
                // SQLite n'émet pas de clause de verrou : il sérialise déjà les écritures.
                $this->assertTrue($requetes->contains(fn (string $sql) => str_contains($sql, 'entreprises_tierces')), $nom);
            }
        }
    }
}
