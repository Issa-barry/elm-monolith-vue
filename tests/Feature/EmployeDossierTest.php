<?php

namespace Tests\Feature;

use App\Enums\Civilite;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\EmployeCoordonneesPaiement;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\ServiceRh;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dossier salarié (lot 1 RH) : champs facultatifs, données sensibles et salaires réservés à leur
 * permission, téléphone partagé protégé, liste limitée aux agences de l'utilisateur.
 */
class EmployeDossierTest extends TestCase
{
    use RefreshDatabase;

    private const CRUD = ['rh-employes.read', 'rh-employes.create', 'rh-employes.update', 'rh-employes.delete'];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dépôt', 'type' => 'depot']);
    }

    private function utilisateur(array $permissions, string $role = 'admin_entreprise', ?Site $site = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->sites()->attach(($site ?? $this->site)->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function employe(?Site $site = null, array $attributs = []): Employe
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'DIALLO',
            'prenom' => 'Aïssatou',
            // Plage mobile guinéenne valide pour libphonenumber (toutes les plages 6x ne le sont pas).
            'telephone' => '+22462'.fake()->unique()->numerify('#######'),
        ]);

        return Employe::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'),
            'type_employe' => 'interne',
            'statut' => 'actif',
            'site_id' => $site?->id,
            ...$attributs,
        ]);
    }

    private function payload(array $surcharges = []): array
    {
        return [
            'nom' => 'Diallo',
            'prenom' => 'aïssatou',
            'type_employe' => 'interne',
            'statut' => 'actif',
            ...$surcharges,
        ];
    }

    // ── Enregistrement du dossier ─────────────────────────────────────────────

    public function test_la_creation_enregistre_le_dossier_et_l_identite_civile(): void
    {
        $service = ServiceRh::create(['organization_id' => $this->org->id, 'libelle' => 'Logistique', 'code' => 'LOG']);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload([
                'service_rh_id' => $service->id,
                'date_embauche' => '2024-03-01',
                'date_naissance' => '1990-05-12',
                'lieu_naissance' => 'Conakry',
                'civilite' => 'mademoiselle',
                'nationalite' => 'Guinéenne',
                'contact_urgence_nom' => 'Mamadou Diallo',
                'contact_urgence_lien' => 'Frère',
                'contact_urgence_telephone' => '+224620000000',
            ]))
            ->assertSessionHasNoErrors();

        $employe = Employe::with('personne')->firstOrFail();
        $this->assertSame($service->id, $employe->service_rh_id);
        // Règle du 31/10/2026 : la date d'embauche n'est plus saisie à la création (premier contrat).
        $this->assertNull($employe->date_embauche);
        $this->assertSame('Mamadou Diallo', $employe->contact_urgence_nom);
        $this->assertSame('Frère', $employe->contact_urgence_lien);
        $this->assertSame('Guinéenne', $employe->personne->nationalite);
        $this->assertSame('1990-05-12', $employe->personne->date_naissance->format('Y-m-d'));
        $this->assertSame('Conakry', $employe->personne->lieu_naissance);
        $this->assertSame(Civilite::MADEMOISELLE, $employe->personne->civilite);
        // Sexe déduit de la civilité, pour garder l'ancienne colonne cohérente.
        $this->assertSame('F', $employe->personne->sexe);
    }

    public function test_la_civilite_remplace_le_sexe_et_se_modifie(): void
    {
        $employe = $this->employe($this->site);
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)
            ->get(route('employes.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('civilite_options', [
                    ['value' => 'monsieur', 'label' => 'Monsieur'],
                    ['value' => 'madame', 'label' => 'Madame'],
                    ['value' => 'mademoiselle', 'label' => 'Mademoiselle'],
                ])
                ->missing('mode_paiement_options'));

        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'civilite' => 'monsieur',
            ]))
            ->assertSessionHasNoErrors();

        $personne = $employe->personne->fresh();
        $this->assertSame(Civilite::MONSIEUR, $personne->civilite);
        $this->assertSame('M', $personne->sexe);

        $this->actingAs($user)
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employe.civilite', 'monsieur')
                ->where('employe.civilite_label', 'Monsieur'));

        // Civilité effacée : le sexe déduit l'est aussi.
        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'civilite' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull($employe->personne->fresh()->civilite);
        $this->assertNull($employe->personne->fresh()->sexe);
    }

    public function test_une_civilite_inconnue_est_refusee(): void
    {
        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload(['civilite' => 'F']))
            ->assertSessionHasErrors(['civilite' => 'Civilité invalide.']);

        $this->assertSame(0, Employe::count());
    }

    public function test_la_migration_reprend_le_sexe_deja_saisi_sans_rien_supprimer(): void
    {
        $homme = $this->employe()->personne;
        $femme = $this->employe()->personne;
        $inconnu = $this->employe()->personne;
        $dejaRenseignee = $this->employe()->personne;
        DB::table('personnes')->where('id', $homme->id)->update(['sexe' => 'M', 'civilite' => null]);
        DB::table('personnes')->where('id', $femme->id)->update(['sexe' => 'F', 'civilite' => null]);
        DB::table('personnes')->where('id', $dejaRenseignee->id)->update(['sexe' => 'F', 'civilite' => 'mademoiselle']);

        $migration = require database_path('migrations/2026_10_30_110000_add_civilite_to_personnes_table.php');
        $migration->up();
        $migration->up(); // rejouable

        $this->assertSame('monsieur', DB::table('personnes')->where('id', $homme->id)->value('civilite'));
        $this->assertSame('madame', DB::table('personnes')->where('id', $femme->id)->value('civilite'));
        $this->assertNull(DB::table('personnes')->where('id', $inconnu->id)->value('civilite'));
        $this->assertSame('mademoiselle', DB::table('personnes')->where('id', $dejaRenseignee->id)->value('civilite'));
        // L'ancienne colonne est conservée telle quelle.
        $this->assertSame('F', DB::table('personnes')->where('id', $femme->id)->value('sexe'));
    }

    public function test_les_champs_du_dossier_sont_facultatifs(): void
    {
        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertNull(Employe::firstOrFail()->date_embauche);
    }

    public function test_un_service_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $service = ServiceRh::create(['organization_id' => $autreOrg->id, 'libelle' => 'Logistique', 'code' => 'LOG']);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->post(route('employes.store'), $this->payload(['service_rh_id' => $service->id]))
            ->assertSessionHasErrors('service_rh_id');
    }

    public function test_la_sortie_ne_passe_plus_par_le_formulaire_de_l_employe(): void
    {
        // Règle du 31/10/2026 : la sortie s'enregistre par « Enregistrer le départ » (DepartEmployeTest).
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)
            ->post(route('employes.store'), $this->payload(['statut' => 'sorti']))
            ->assertSessionHasErrors('statut');

        $this->actingAs($user)
            ->post(route('employes.store'), $this->payload(['date_sortie' => '2024-01-01', 'motif_sortie' => 'Démission']))
            ->assertSessionHasNoErrors();
        $employe = Employe::sole();
        $this->assertNull($employe->date_sortie);
        $this->assertNull($employe->motif_sortie);

        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload(['statut' => 'sorti', 'date_sortie' => '2024-01-01']))
            ->assertSessionHasErrors('statut');
        $this->assertSame('actif', $employe->fresh()->statut->value);
    }

    // ── Nationalité et lien du contact d'urgence (listes, 31/10/2026) ────────

    public function test_la_nationalite_se_choisit_dans_la_liste_des_nationalites(): void
    {
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)->get(route('employes.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('nationalite_options.0', ['value' => 'Guinéenne', 'label' => 'Guinéenne', 'code' => 'GN'])
                ->where('nationalite_options', fn ($options) => collect($options)->contains('value', 'Française')
                    && collect($options)->contains('value', 'Bissau-guinéenne')
                    && ! collect($options)->contains('value', 'Guinée')
                    && ! collect($options)->contains('value', 'France')
                    && collect($options)->pluck('value')->duplicates()->isEmpty()));

        $this->actingAs($user)->post(route('employes.store'), $this->payload(['nationalite' => 'Guinée']))
            ->assertSessionHasErrors(['nationalite' => 'Choisissez une nationalité dans la liste.']);

        $this->actingAs($user)->post(route('employes.store'), $this->payload(['nationalite' => 'Française']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Française', Employe::sole()->personne->nationalite);
    }

    public function test_une_ancienne_nationalite_hors_liste_est_conservee(): void
    {
        $employe = $this->employe($this->site);
        $employe->personne->update(['nationalite' => 'guinéen']);
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)->get(route('employes.edit', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('nationalite_options', fn ($options) => collect($options)
                ->contains(fn ($o) => $o['value'] === 'guinéen' && $o['code'] === null)));

        $this->actingAs($user)->put(route('employes.update', $employe), $this->payload(['nationalite' => 'guinéen']))
            ->assertSessionHasNoErrors();
        $this->assertSame('guinéen', $employe->personne->fresh()->nationalite);

        $this->actingAs($user)->put(route('employes.update', $employe), $this->payload(['nationalite' => 'Guinéenne']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Guinéenne', $employe->personne->fresh()->nationalite);
    }

    public function test_le_lien_du_contact_d_urgence_se_choisit_dans_une_liste_avec_autre(): void
    {
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)->post(route('employes.store'), $this->payload(['contact_urgence_lien' => 'Épouse']))
            ->assertSessionHasErrors('contact_urgence_lien');
        $this->actingAs($user)->post(route('employes.store'), $this->payload(['contact_urgence_lien' => 'Autre']))
            ->assertSessionHasErrors(['contact_urgence_lien_precision' => 'Précisez le lien avec le contact.']);

        $this->actingAs($user)->post(route('employes.store'), $this->payload([
            'contact_urgence_lien' => 'Autre', 'contact_urgence_lien_precision' => ' Beau-frère ',
        ]))->assertSessionHasNoErrors();
        $employe = Employe::sole();
        $this->assertSame('Beau-frère', $employe->contact_urgence_lien);

        // Un lien de la liste efface la précision devenue inutile.
        $this->actingAs($user)->put(route('employes.update', $employe), $this->payload([
            'contact_urgence_lien' => 'Conjoint(e)', 'contact_urgence_lien_precision' => 'Beau-frère',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Conjoint(e)', $employe->fresh()->contact_urgence_lien);

        $this->actingAs($user)->get(route('employes.edit', $employe))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lien_contact_options', fn ($o) => collect($o)->pluck('value')->all() === [
                    'Conjoint(e)', 'Père', 'Mère', 'Frère', 'Sœur', 'Fils', 'Fille', 'Oncle', 'Tante',
                    'Cousin(e)', 'Ami(e)', 'Collègue', 'Tuteur / Tutrice', 'Autre',
                ])
                ->where('employe.contact_urgence_lien', 'Conjoint(e)'));
    }

    public function test_la_date_d_embauche_se_corrige_dans_la_fiche_sans_suivre_la_sortie(): void
    {
        $employe = $this->employe($this->site, ['statut' => 'sorti', 'date_sortie' => '2026-06-30', 'motif_sortie' => 'Démission']);
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)->put(route('employes.update', $employe), $this->payload(['date_embauche' => '2026-07-15']))
            ->assertSessionHasErrors('date_embauche');
        $this->actingAs($user)->put(route('employes.update', $employe), $this->payload(['date_embauche' => '2020-02-01']))
            ->assertSessionHasNoErrors();

        $employe->refresh();
        $this->assertSame('2020-02-01', $employe->date_embauche->toDateString());
        $this->assertSame('sorti', $employe->statut->value);
        $this->assertSame('Démission', $employe->motif_sortie);
    }

    // ── Données sensibles ─────────────────────────────────────────────────────

    public function test_le_numero_cnss_est_enregistre_avec_la_permission(): void
    {
        $employe = $this->employe($this->site);
        $user = $this->utilisateur([...self::CRUD, 'rh-employes.donnees_sensibles']);

        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'numero_cnss' => 'CNSS-123',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('CNSS-123', $employe->fresh()->numero_cnss);

        $this->actingAs($user)
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employe.donnees_sensibles.numero_cnss', 'CNSS-123')
                ->where('employe.donnees_sensibles.paiement_mode', null));
    }

    /**
     * Le mode de paiement du salaire est choisi par la Comptabilité (30/10/2026) : le dossier RH ne
     * crée, ne modifie ni ne supprime plus les coordonnées de paiement. Celles déjà enregistrées
     * restent intactes, chiffrées et consultables sur la fiche.
     */
    public function test_le_dossier_rh_ne_modifie_plus_les_coordonnees_de_paiement(): void
    {
        $employe = $this->employe($this->site);
        $existantes = EmployeCoordonneesPaiement::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'mode' => 'mobile_money',
            'etablissement' => 'Orange',
            'numero_compte' => '620123456',
            'titulaire' => 'Aïssatou Diallo',
        ]);
        $user = $this->utilisateur([...self::CRUD, 'rh-employes.donnees_sensibles']);

        // Valeurs envoyées par un ancien formulaire : ignorées.
        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'paiement_mode' => 'virement',
                'paiement_numero_compte' => '000',
            ]))
            ->assertSessionHasNoErrors();

        // Formulaire actuel, sans aucun champ de paiement : rien n'est supprimé.
        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
            ]))
            ->assertSessionHasNoErrors();

        $coordonnees = EmployeCoordonneesPaiement::where('employe_id', $employe->id)->sole();
        $this->assertSame($existantes->id, $coordonnees->id);
        $this->assertSame('mobile_money', $coordonnees->mode);
        $this->assertSame('620123456', $coordonnees->numero_compte);
        $brut = DB::table('employe_coordonnees_paiement')->where('employe_id', $employe->id)->value('numero_compte');
        $this->assertNotSame('620123456', $brut);

        $autre = $this->employe($this->site);
        $this->actingAs($user)
            ->put(route('employes.update', $autre), $this->payload([
                'telephone' => $autre->telephone,
                'site_id' => $this->site->id,
                'paiement_mode' => 'especes',
            ]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('employe_coordonnees_paiement', ['employe_id' => $autre->id]);

        $this->actingAs($user)
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page
                ->where('employe.donnees_sensibles.paiement_mode_label', 'Mobile Money')
                ->where('employe.donnees_sensibles.paiement_numero_compte', '620123456'));
    }

    public function test_sans_permission_les_donnees_sensibles_ne_sont_ni_envoyees_ni_modifiables(): void
    {
        $employe = $this->employe($this->site, ['numero_cnss' => 'CNSS-ORIGINE']);
        $user = $this->utilisateur(self::CRUD);

        $this->actingAs($user)
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.donnees_sensibles', null));

        $this->actingAs($user)
            ->put(route('employes.update', $employe), $this->payload([
                'telephone' => $employe->telephone,
                'site_id' => $this->site->id,
                'numero_cnss' => 'PIRATE',
                'paiement_mode' => 'virement',
                'paiement_numero_compte' => '000',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('CNSS-ORIGINE', $employe->fresh()->numero_cnss);
        $this->assertDatabaseMissing('employe_coordonnees_paiement', ['employe_id' => $employe->id]);
    }

    public function test_le_numero_cnss_n_apparait_jamais_dans_une_serialisation_brute(): void
    {
        $employe = $this->employe(null, ['numero_cnss' => 'CNSS-123']);

        $this->assertArrayNotHasKey('numero_cnss', $employe->toArray());
    }

    // ── Salaires ──────────────────────────────────────────────────────────────

    public function test_le_salaire_n_est_envoye_qu_avec_rh_remunerations_read(): void
    {
        $employe = $this->employe($this->site);
        Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id, 'type_contrat' => 'cdi',
            'date_debut' => '2024-01-01', 'salaire_base' => 2_500_000, 'statut_contrat' => 'actif',
        ]);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.contrats.0.salaire_base', null));

        $this->actingAs($this->utilisateur([...self::CRUD, 'rh-remunerations.read']))
            ->get(route('employes.show', $employe))
            ->assertInertia(fn (Assert $page) => $page->where('employe.contrats.0.salaire_base', fn ($v) => (float) $v === 2_500_000.0));
    }

    // ── Téléphone partagé (M4) ────────────────────────────────────────────────

    public function test_le_telephone_d_une_personne_ayant_un_compte_ne_peut_pas_etre_efface(): void
    {
        $employe = $this->employe($this->site);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $employe->personne_id]);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->put(route('employes.update', $employe), $this->payload(['telephone' => '', 'site_id' => $this->site->id]))
            ->assertSessionHasErrors('telephone');

        $this->assertNotNull($employe->personne->fresh()->telephone);
    }

    public function test_le_telephone_d_un_employe_sans_autre_role_peut_etre_efface(): void
    {
        $employe = $this->employe($this->site);

        $this->actingAs($this->utilisateur(self::CRUD))
            ->put(route('employes.update', $employe), $this->payload(['telephone' => '', 'site_id' => $this->site->id]))
            ->assertSessionHasNoErrors();

        $this->assertNull($employe->personne->fresh()->telephone);
    }

    // ── Cloisonnement par agence ──────────────────────────────────────────────

    public function test_la_liste_est_limitee_aux_agences_de_l_utilisateur(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
        $employeA = $this->employe($this->site);
        $this->employe($autreSite);
        $this->employe(null);

        $this->actingAs($this->utilisateur(['rh-employes.read'], 'manager'))
            ->get(route('employes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employes', 1)
                ->where('employes.0.id', $employeA->id));
    }

    public function test_un_administrateur_voit_toutes_les_agences_et_peut_filtrer(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence B', 'type' => 'depot']);
        $this->employe($this->site);
        $employeB = $this->employe($autreSite);
        $admin = $this->utilisateur(['rh-employes.read']);

        $this->actingAs($admin)
            ->get(route('employes.index'))
            ->assertInertia(fn (Assert $page) => $page->has('employes', 2)->has('sites', 2));

        $this->actingAs($admin)
            ->get(route('employes.index', ['site_ids' => [$autreSite->id]]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('employes', 1)
                ->where('employes.0.id', $employeB->id));
    }
}
