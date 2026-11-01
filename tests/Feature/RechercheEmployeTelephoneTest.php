<?php

namespace Tests\Feature;

use App\Enums\StatutEmploye;
use App\Models\Client;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * « Nouvel employé » commence par le téléphone (docs/rh-employes-identite.md) : recherche par
 * numéro normalisé quel que soit son format, dans l'organisation seulement ; contrôle anti-doublon
 * final à l'enregistrement ; téléphone du contact d'urgence au pays de l'employé.
 */
class RechercheEmployeTelephoneTest extends TestCase
{
    use RefreshDatabase;

    private const NUMERO = '+224622602693';

    private Organization $org;

    private Site $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create();
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->user = $this->utilisateur(['rh-employes.read', 'rh-employes.create', 'rh-employes.update']);
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

    private function personne(string $telephone = self::NUMERO, ?Organization $org = null): Personne
    {
        return Personne::create([
            'organization_id' => ($org ?? $this->org)->id, 'prenom' => 'Mamadou', 'nom' => 'DIALLO',
            'telephone' => $telephone, 'telephone_normalise' => Personne::normaliserTelephone($telephone),
        ]);
    }

    private function employe(?Personne $personne = null, array $attributs = []): Employe
    {
        $personne ??= $this->personne();

        return Employe::create([
            'organization_id' => $personne->organization_id, 'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'), 'type_employe' => 'interne',
            'statut' => StatutEmploye::ACTIF->value, 'site_id' => $this->site->id, ...$attributs,
        ]);
    }

    private function rechercher(string $telephone, ?string $indicatif = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->getJson(route('employes.recherche-telephone', array_filter([
            'telephone' => $telephone, 'indicatif' => $indicatif,
        ])));
    }

    private function creer(array $donnees = [])
    {
        return $this->actingAs($this->user)->post(route('employes.store'), [
            'prenom' => 'Aïssatou', 'nom' => 'Camara', 'telephone' => self::NUMERO,
            'type_employe' => 'interne', 'statut' => 'actif', ...$donnees,
        ]);
    }

    // ── Recherche ────────────────────────────────────────────────────────────

    public function test_un_numero_existant_est_reconnu_quel_que_soit_son_format(): void
    {
        $employe = $this->employe();

        foreach ([
            ['+224622602693', null], ['+224 622 60 26 93', null], ['00224622602693', null],
            ['224622602693', null], ['622602693', '+224'], ['0622602693', '+224'], ['622 60 26 93', '+224'],
        ] as [$saisie, $indicatif]) {
            $this->rechercher($saisie, $indicatif)->assertOk()
                ->assertJsonPath('statut', 'employe')
                ->assertJsonPath('telephone', self::NUMERO)
                ->assertJsonPath('code_pays', 'GN')
                ->assertJsonPath('employes.0.id', $employe->id)
                ->assertJsonPath('employes.0.peut_consulter', true)
                ->assertJsonPath('employes.0.peut_modifier', true);
        }
    }

    public function test_un_numero_inconnu_permet_un_nouveau_dossier(): void
    {
        $this->rechercher(self::NUMERO)->assertOk()
            ->assertJsonPath('statut', 'nouveau')
            ->assertJsonPath('employes', [])
            ->assertJsonPath('personne', null);
    }

    public function test_un_numero_invalide_est_signale(): void
    {
        $this->rechercher('12345')->assertOk()->assertJsonPath('statut', 'invalide');
    }

    public function test_un_numero_d_une_autre_organisation_n_est_jamais_revele(): void
    {
        $this->employe($this->personne(org: Organization::factory()->create()));

        $this->rechercher(self::NUMERO)->assertOk()->assertJsonPath('statut', 'nouveau');
    }

    public function test_sans_droit_de_creer_un_employe_la_recherche_est_refusee(): void
    {
        $this->rechercher(self::NUMERO, user: $this->utilisateur(['rh-employes.read']))->assertForbidden();
    }

    public function test_plusieurs_dossiers_sont_tous_presentes_sans_choix_arbitraire(): void
    {
        $personne = $this->personne();
        $this->employe($personne);
        $this->employe($personne, ['statut' => StatutEmploye::SORTI->value]);

        $reponse = $this->rechercher(self::NUMERO)->assertOk()
            ->assertJsonPath('statut', 'employe')
            ->assertJsonCount(2, 'employes');

        $sorti = collect($reponse->json('employes'))->firstWhere('statut', 'sorti');
        $this->assertNotNull($sorti);
        $this->assertFalse($sorti['peut_modifier']);
    }

    public function test_une_personne_connue_sous_un_autre_role_est_reprise_sans_doublon(): void
    {
        $personne = $this->personne();
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);

        $this->rechercher(self::NUMERO)->assertOk()
            ->assertJsonPath('statut', 'personne')
            ->assertJsonPath('personne.prenom', 'Mamadou')
            ->assertJsonPath('personne.roles', ['livreur']);

        $this->creer()->assertSessionHasNoErrors();
        $this->assertSame(1, Personne::where('telephone_normalise', '224622602693')->count());
        $this->assertSame($personne->id, Employe::sole()->personne_id);
        // Identité déjà connue : jamais écrasée par la saisie.
        $this->assertSame('DIALLO', $personne->fresh()->nom);
    }

    public function test_un_dossier_archive_n_empeche_pas_une_reembauche(): void
    {
        $this->employe()->delete();

        $this->rechercher(self::NUMERO)->assertOk()
            ->assertJsonPath('statut', 'personne')
            ->assertJsonPath('dossiers_archives', 1);
        $this->creer()->assertSessionHasNoErrors();
        $this->assertSame(1, Employe::count());
    }

    public function test_hors_de_ses_agences_l_existence_est_dite_sans_lien_vers_la_fiche(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $this->employe(attributs: ['site_id' => $autreSite->id]);
        $manager = $this->utilisateur(['rh-employes.read', 'rh-employes.create', 'rh-employes.update'], 'manager');

        $this->rechercher(self::NUMERO, user: $manager)->assertOk()
            ->assertJsonPath('statut', 'employe')
            ->assertJsonPath('employes.0.peut_consulter', false)
            ->assertJsonPath('employes.0.peut_modifier', false);
    }

    // ── Contrôle final à l'enregistrement ────────────────────────────────────

    public function test_l_enregistrement_refuse_un_doublon_meme_sans_recherche(): void
    {
        $this->employe();

        $this->creer(['telephone' => '+224 622 60 26 93'])->assertSessionHasErrors('telephone');

        $this->assertSame(1, Employe::count());
        $this->assertSame(1, Personne::where('telephone_normalise', '224622602693')->count());
    }

    public function test_un_numero_enregistre_au_meme_instant_est_refuse_proprement(): void
    {
        // Simule une autre création validée entre la recherche de la personne et son insertion.
        $insere = false;
        Personne::creating(function (Personne $p) use (&$insere) {
            if (! $insere && $p->telephone_normalise === '224622602693') {
                $insere = true;
                DB::table('personnes')->insert([
                    'id' => (string) Str::ulid(), 'organization_id' => $p->organization_id,
                    'telephone' => self::NUMERO, 'telephone_normalise' => '224622602693',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->creer()->assertSessionHasErrors(['telephone' => 'Ce numéro vient d’être enregistré par quelqu’un d’autre. Cliquez de nouveau sur « Rechercher » pour voir le dossier existant.']);
        $this->assertSame(0, Employe::count());
    }

    public function test_la_creation_enregistre_le_numero_au_format_international(): void
    {
        $this->creer(['telephone' => '00224 622 60 26 93'])->assertSessionHasNoErrors();

        $personne = Employe::sole()->personne;
        $this->assertSame(self::NUMERO, $personne->telephone);
        $this->assertSame('224622602693', $personne->telephone_normalise);
        $this->assertSame('GN', $personne->code_pays);
    }

    // ── Contact d'urgence ────────────────────────────────────────────────────

    public function test_le_contact_d_urgence_est_au_pays_de_l_employe_et_au_format_international(): void
    {
        $this->creer(['contact_urgence_telephone' => '0625 12 34 56'])->assertSessionHasNoErrors();
        $this->assertSame('+224625123456', Employe::sole()->contact_urgence_telephone);
    }

    public function test_un_contact_d_urgence_d_un_autre_pays_est_refuse(): void
    {
        $this->creer(['contact_urgence_telephone' => '+33612345678'])
            ->assertSessionHasErrors(['contact_urgence_telephone' => 'Le numéro du contact d’urgence doit être du même pays que celui de l’employé (+224).']);
        $this->creer(['contact_urgence_telephone' => '12'])->assertSessionHasErrors('contact_urgence_telephone');
        $this->assertSame(0, Employe::count());
    }

    public function test_la_creation_sans_telephone_est_refusee(): void
    {
        $this->creer(['telephone' => null])
            ->assertSessionHasErrors(['telephone' => 'Le numéro de téléphone est obligatoire : il permet de vérifier que l’employé n’est pas déjà enregistré.']);
        $this->assertSame(0, Employe::count());
    }

    public function test_une_fiche_ancienne_sans_telephone_reste_modifiable_et_son_contact_d_urgence_suit_le_pays_par_defaut(): void
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'prenom' => 'Mamadou', 'nom' => 'DIALLO']);
        $employe = $this->employe($personne);

        $this->actingAs($this->user)->put(route('employes.update', $employe), [
            'prenom' => 'Mamadou', 'nom' => 'DIALLO', 'telephone' => null, 'type_employe' => 'interne',
            'statut' => 'actif', 'site_id' => $this->site->id, 'contact_urgence_telephone' => '625123456',
        ])->assertSessionHasNoErrors();

        $this->assertSame('+224625123456', $employe->fresh()->contact_urgence_telephone);
    }

    private function rechercherContact(string $telephone, ?string $indicatif = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->getJson(route('employes.recherche-contact-urgence', array_filter([
            'telephone' => $telephone, 'indicatif' => $indicatif,
        ])));
    }

    public function test_un_contact_d_urgence_deja_connu_est_reconnu_quel_que_soit_son_format(): void
    {
        $personne = $this->personne();
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $avant = Personne::count();

        foreach ([['+224 622 60 26 93', null], ['0622602693', '+224'], ['00224622602693', null]] as [$saisie, $indicatif]) {
            $this->rechercherContact($saisie, $indicatif)->assertOk()
                ->assertJsonPath('statut', 'trouve')
                ->assertJsonPath('telephone', self::NUMERO)
                ->assertJsonPath('correspondances', [['nom' => $personne->nom_complet, 'role' => 'livreur']]);
        }
        // Lecture seule : aucune fiche créée ni modifiée.
        $this->assertSame($avant, Personne::count());
    }

    public function test_plusieurs_fiches_pour_un_numero_sont_toutes_proposees(): void
    {
        $this->personne();
        Client::create(['organization_id' => $this->org->id, 'nom_complet' => 'Boutique Fanta', 'telephone' => self::NUMERO]);

        $this->rechercherContact(self::NUMERO)->assertOk()
            ->assertJsonPath('statut', 'trouve')
            ->assertJsonCount(2, 'correspondances')
            ->assertJsonPath('correspondances.1', ['nom' => 'Boutique Fanta', 'role' => 'client']);
    }

    public function test_un_contact_inconnu_ou_d_une_autre_organisation_n_est_pas_revele(): void
    {
        $this->personne(org: Organization::factory()->create());

        $this->rechercherContact(self::NUMERO)->assertOk()
            ->assertJsonPath('statut', 'inconnu')
            ->assertJsonPath('correspondances', []);
        $this->rechercherContact('123')->assertOk()->assertJsonPath('statut', 'invalide');
    }

    public function test_la_recherche_du_contact_d_urgence_exige_un_droit_sur_les_employes(): void
    {
        $this->rechercherContact(self::NUMERO, user: $this->utilisateur(['rh-employes.read']))->assertForbidden();
        $this->rechercherContact(self::NUMERO, user: $this->utilisateur(['rh-employes.update']))->assertOk();
    }

    public function test_un_meme_contact_pour_deux_salaries_ne_cree_aucune_fiche(): void
    {
        $avant = Personne::count();
        $this->creer(['contact_urgence_telephone' => '+224625123456', 'contact_urgence_nom' => 'Fanta CAMARA']);
        $this->creer(['telephone' => '+224664112233', 'prenom' => 'Ibrahima', 'contact_urgence_telephone' => '625 12 34 56', 'contact_urgence_nom' => 'Fanta CAMARA']);

        $this->assertSame(2, Employe::where('contact_urgence_telephone', '+224625123456')->count());
        // Seules les deux personnes des salariés sont créées, jamais une fiche pour le contact.
        $this->assertSame($avant + 2, Personne::count());
    }

    public function test_un_ancien_contact_d_urgence_en_saisie_libre_reste_modifiable(): void
    {
        $employe = $this->employe(attributs: ['contact_urgence_telephone' => '62 51 23 45 6']);

        $this->actingAs($this->user)->put(route('employes.update', $employe), [
            'prenom' => 'Mamadou', 'nom' => 'DIALLO', 'telephone' => self::NUMERO, 'type_employe' => 'interne',
            'statut' => 'actif', 'site_id' => $this->site->id, 'contact_urgence_telephone' => '62 51 23 45 6',
            'service_rh_id' => null,
        ])->assertSessionHasNoErrors();

        $this->assertSame('62 51 23 45 6', $employe->fresh()->contact_urgence_telephone);
    }
}
