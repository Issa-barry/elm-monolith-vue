<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\Contacts\DetectionDoublons;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 9a du référentiel Contacts (ADR 0060, CONTACT-053, CONTACT-054) : détection des doublons
 * probables en lecture seule — critères, blocages F1 (même fonction) et F2 (deux comptes),
 * fonctions rattachées à un compte en cas de fusion (ADR 0056), isolation par organisation.
 */
class DetectionDoublonsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
    }

    private function acteur(array $permissions = ['contacts.read']): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence '.$user->id, 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function personne(array $attributs = [], ?Organization $org = null): Personne
    {
        return Personne::factory()->create(['organization_id' => ($org ?? $this->org)->id, 'email' => null, 'ville' => null, ...$attributs]);
    }

    private function analyse(): array
    {
        return app(DetectionDoublons::class)->analyser($this->org->id);
    }

    /** Paire qui réunit ces deux fiches, quel que soit l'ordre. */
    private function paire(array $paires, string $id1, string $id2): ?array
    {
        return collect($paires)->first(fn (array $p) => [$p['a']['id'], $p['b']['id']] == [min($id1, $id2), max($id1, $id2)]);
    }

    // ── Critères ────────────────────────────────────────────────────────────

    public function test_meme_email_et_meme_nom_dans_la_meme_ville_sans_tenir_compte_de_la_casse_ni_des_accents(): void
    {
        $a = $this->personne(['nom_complet' => 'Mamadou Saliou Bah', 'ville' => 'Conakry', 'email' => 'msbah@exemple.gn']);
        $b = $this->personne(['nom_complet' => 'MAMADOU SALIOU  BAH', 'ville' => 'conakry', 'email' => 'MSBah@exemple.gn ']);

        $paire = $this->paire($this->analyse()['personnes'], $a->id, $b->id);

        $this->assertNotNull($paire);
        $this->assertEqualsCanonicalizing(['email', 'nom_ville'], $paire['criteres']);
        $this->assertSame([], $paire['blocages']);
        $this->assertSame(1, $this->analyse()['resume']['fusionnables']);
    }

    public function test_meme_nom_dans_deux_villes_ou_sans_ville_n_est_pas_un_doublon(): void
    {
        $this->personne(['nom_complet' => 'Fatoumata Camara', 'ville' => 'Conakry']);
        $this->personne(['nom_complet' => 'Fatoumata Camara', 'ville' => 'Kindia']);
        $this->personne(['nom_complet' => 'Fatoumata Camara']);
        // Fiches sans nom (désignations de livreurs) : jamais rapprochées par le nom.
        $this->personne(['nom_complet' => null, 'nom' => null, 'prenom' => null, 'ville' => 'Conakry']);
        $this->personne(['nom_complet' => null, 'nom' => null, 'prenom' => null, 'ville' => 'Conakry']);

        $this->assertSame([], $this->analyse()['personnes']);
    }

    public function test_les_entreprises_sont_rapprochees_par_nif_et_raison_sociale_normalises(): void
    {
        $a = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Société Kaloum S.A.R.L', 'identifiant_fiscal' => 'GN 123-45']);
        $b = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'SOCIETE KALOUM SARL', 'identifiant_fiscal' => 'gn12345']);

        $paire = $this->paire($this->analyse()['entreprises'], $a->id, $b->id);

        $this->assertNotNull($paire);
        $this->assertContains('identifiant_fiscal', $paire['criteres']);
    }

    public function test_une_fiche_supprimee_ou_d_une_autre_organisation_est_ignoree(): void
    {
        $this->personne(['email' => 'doublon@exemple.gn']);
        $this->personne(['email' => 'doublon@exemple.gn'], Organization::factory()->create());
        $this->personne(['email' => 'doublon@exemple.gn'])->delete();

        $this->assertSame([], $this->analyse()['personnes']);
    }

    // ── Blocages et rattachement ────────────────────────────────────────────

    public function test_f1_meme_fonction_des_deux_cotes_bloque_la_fusion(): void
    {
        $a = $this->personne(['email' => 'f1@exemple.gn']);
        $b = $this->personne(['email' => 'f1@exemple.gn']);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $a->id]);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $b->id]);

        $paire = $this->paire($this->analyse()['personnes'], $a->id, $b->id);

        $this->assertSame(['meme_fonction'], array_column($paire['blocages'], 'code'));
        $this->assertStringContainsString('fournisseur', $paire['blocages'][0]['detail']);
    }

    public function test_f2_un_compte_de_chaque_cote_bloque_la_fusion(): void
    {
        // Rapprochées par le nom et la ville : un e-mail de connexion est unique par compte.
        $a = $this->personne(['nom_complet' => 'Alpha Oumar Sow', 'ville' => 'Labé']);
        $b = $this->personne(['nom_complet' => 'Alpha Oumar Sow', 'ville' => 'Labé']);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $a->id]);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $b->id]);

        $paire = $this->paire($this->analyse()['personnes'], $a->id, $b->id);

        $this->assertSame(['deux_comptes'], array_column($paire['blocages'], 'code'));
    }

    public function test_un_seul_compte_liste_les_fonctions_qui_lui_seraient_rattachees(): void
    {
        $a = $this->personne(['email' => 'adr56@exemple.gn']);
        $b = $this->personne(['email' => 'adr56@exemple.gn']);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $a->id]);
        $livreur = Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $b->id, 'nom_complet' => 'Tricycle-3', 'is_active' => true]);
        // Un client n'est pas rattaché d'office (hors ADR 0056) : il n'apparaît pas.
        Client::create(['organization_id' => $this->org->id, 'personne_id' => $b->id, 'nom_complet' => 'B', 'telephone' => $b->telephone, 'type' => 'externe']);

        $paire = $this->paire($this->analyse()['personnes'], $a->id, $b->id);

        $this->assertSame([], $paire['blocages']);
        $this->assertSame((string) $compte->id, $paire['rattachement']['compte']);
        $this->assertSame([['fonction' => 'livreur', 'id' => $livreur->id, 'reference' => null]], $paire['rattachement']['fonctions']);
    }

    // ── Commande ────────────────────────────────────────────────────────────

    public function test_la_commande_n_ecrit_rien_en_base_et_exporte_les_paires(): void
    {
        $this->personne(['nom_complet' => 'Kadiatou Diallo', 'email' => 'kd@exemple.gn']);
        $this->personne(['nom_complet' => 'Kadiatou Diallo', 'email' => 'kd@exemple.gn']);
        $acteur = $this->acteur();
        $fichier = tempnam(sys_get_temp_dir(), 'doublons').'.csv';

        $ecritures = [];
        DB::listen(function (QueryExecuted $q) use (&$ecritures) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $q->sql)) {
                $ecritures[] = $q->sql;
            }
        });

        $this->artisan('contacts:doublons', ['--acteur' => $acteur->id, '--export' => $fichier])
            ->expectsOutputToContain('lecture seule')
            ->assertSuccessful();

        $this->assertSame([], $ecritures);
        $lignes = array_filter(explode("\n", (string) file_get_contents($fichier)));
        $this->assertCount(2, $lignes);
        $this->assertStringContainsString('Kadiatou Diallo', $lignes[1]);
        $this->assertStringContainsString('même e-mail', $lignes[1]);
        @unlink($fichier);
    }

    public function test_la_commande_exige_un_acteur_avec_contacts_read(): void
    {
        $this->artisan('contacts:doublons')->assertExitCode(2);
        $this->artisan('contacts:doublons', ['--acteur' => $this->acteur([])->id])->assertExitCode(2);
    }
}
