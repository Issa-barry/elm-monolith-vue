<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
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
 * probables d'entreprises en lecture seule — critères, blocage F1 (même fonction), isolation par
 * organisation. Les personnes ne sont jamais rapprochées : deux numéros différents désignent deux
 * personnes (règle du 10/11/2026).
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

    private function entreprise(array $attributs = [], ?Organization $org = null): EntrepriseTierce
    {
        return EntrepriseTierce::create(['organization_id' => ($org ?? $this->org)->id, ...$attributs]);
    }

    private function personne(array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
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

    public function test_les_entreprises_sont_rapprochees_par_nif_et_raison_sociale_normalises(): void
    {
        $a = $this->entreprise(['raison_sociale' => 'Société Kaloum S.A.R.L', 'identifiant_fiscal' => 'GN 123-45']);
        $b = $this->entreprise(['raison_sociale' => 'SOCIETE KALOUM SARL', 'identifiant_fiscal' => 'gn12345']);

        $paire = $this->paire($this->analyse()['entreprises'], $a->id, $b->id);

        $this->assertNotNull($paire);
        $this->assertContains('identifiant_fiscal', $paire['criteres']);
        $this->assertSame([], $paire['blocages']);
        $this->assertSame(1, $this->analyse()['resume']['fusionnables']);
    }

    public function test_les_entreprises_sont_rapprochees_par_le_meme_e_mail(): void
    {
        $a = $this->entreprise(['raison_sociale' => 'Kaloum Transport', 'email' => 'contact@kaloum.gn']);
        $b = $this->entreprise(['raison_sociale' => 'KT Logistique', 'email' => 'Contact@Kaloum.gn ']);

        $this->assertSame(['email'], $this->paire($this->analyse()['entreprises'], $a->id, $b->id)['criteres']);
    }

    public function test_une_entreprise_supprimee_ou_d_une_autre_organisation_est_ignoree(): void
    {
        $this->entreprise(['raison_sociale' => 'Doublon SARL']);
        $this->entreprise(['raison_sociale' => 'Doublon SARL'], Organization::factory()->create());
        $this->entreprise(['raison_sociale' => 'Doublon SARL'])->delete();

        $this->assertSame([], $this->analyse()['entreprises']);
    }

    public function test_deux_personnes_ne_sont_jamais_rapprochees_meme_nom_meme_ville_meme_e_mail(): void
    {
        // Deux numéros différents = deux personnes différentes (règle du 10/11/2026), homonymes compris.
        $this->personne(['nom_complet' => 'Mamadou Saliou Bah', 'ville' => 'Conakry', 'email' => 'msbah@exemple.gn']);
        $this->personne(['nom_complet' => 'MAMADOU SALIOU  BAH', 'ville' => 'conakry', 'email' => 'MSBah@exemple.gn']);
        $this->personne(['nom_complet' => 'Fatoumata Camara', 'ville' => 'Kindia']);
        $this->personne(['nom_complet' => 'Fatoumata Camara', 'ville' => 'Kindia']);

        $analyse = $this->analyse();

        $this->assertArrayNotHasKey('personnes', $analyse);
        $this->assertSame([], $analyse['entreprises']);
        $this->assertSame(['paires_entreprises' => 0, 'fusionnables' => 0, 'bloquees_meme_fonction' => 0], $analyse['resume']);
    }

    // ── Blocage F1 ──────────────────────────────────────────────────────────

    public function test_f1_meme_fonction_des_deux_cotes_bloque_la_fusion(): void
    {
        $a = $this->entreprise(['raison_sociale' => 'Kaloum Services']);
        $b = $this->entreprise(['raison_sociale' => 'KALOUM SERVICES']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $a->id, 'is_active' => true]);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $b->id, 'is_active' => true]);

        $paire = $this->paire($this->analyse()['entreprises'], $a->id, $b->id);

        $this->assertSame(['meme_fonction'], array_column($paire['blocages'], 'code'));
        $this->assertStringContainsString('fournisseur', $paire['blocages'][0]['detail']);
        $this->assertSame(1, $this->analyse()['resume']['bloquees_meme_fonction']);
    }

    public function test_une_fonction_d_un_seul_cote_ne_bloque_pas(): void
    {
        $a = $this->entreprise(['raison_sociale' => 'Matoto Froid']);
        $b = $this->entreprise(['raison_sociale' => 'Matoto Froid']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $a->id, 'is_active' => true]);

        $paire = $this->paire($this->analyse()['entreprises'], $a->id, $b->id);

        $this->assertSame([], $paire['blocages']);
        $this->assertSame('fournisseur', $paire['a']['id'] === $a->id ? $paire['a']['fonctions'][0]['fonction'] : $paire['b']['fonctions'][0]['fonction']);
    }

    // ── Commande ────────────────────────────────────────────────────────────

    public function test_la_commande_n_ecrit_rien_en_base_et_exporte_les_paires_d_entreprises_seulement(): void
    {
        $this->entreprise(['raison_sociale' => 'Kadiatou Distribution']);
        $this->entreprise(['raison_sociale' => 'KADIATOU DISTRIBUTION']);
        // Personnes homonymes avec le même e-mail : jamais dans le rapport.
        $p1 = $this->personne(['nom_complet' => 'Kadiatou Diallo', 'email' => 'kd@exemple.gn']);
        $this->personne(['nom_complet' => 'Kadiatou Diallo', 'email' => 'kd@exemple.gn']);
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $p1->id, 'nom_complet' => 'Tricycle-3', 'is_active' => true]);
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
            ->expectsOutputToContain('Personnes : jamais rapprochées')
            ->assertSuccessful();

        $this->assertSame([], $ecritures);
        $lignes = array_values(array_filter(explode("\n", (string) file_get_contents($fichier))));
        $this->assertCount(2, $lignes);
        $this->assertStringContainsString('a_raison_sociale', $lignes[0]);
        $this->assertStringContainsString('Kadiatou Distribution', $lignes[1]);
        $this->assertStringContainsString('même raison sociale', $lignes[1]);
        $this->assertStringNotContainsString('Kadiatou Diallo', $lignes[1]);
        @unlink($fichier);
    }

    public function test_la_commande_exige_un_acteur_avec_contacts_read(): void
    {
        $this->artisan('contacts:doublons')->assertExitCode(2);
        $this->artisan('contacts:doublons', ['--acteur' => $this->acteur([])->id])->assertExitCode(2);
    }
}
