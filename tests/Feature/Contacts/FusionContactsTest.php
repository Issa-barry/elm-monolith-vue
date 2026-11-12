<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Features\ModuleFeature;
use App\Models\CategorieLibre;
use App\Models\Client;
use App\Models\ContactsFusion;
use App\Models\ContactsFusionOperation;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\Contacts\Fusion\AnnulationFusionContacts;
use App\Services\Contacts\Fusion\FusionContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Lot 9b du référentiel Contacts (ADR 0060, CONTACT-055 à CONTACT-062) : fusion manuelle de deux
 * fiches — simulation sans écriture, blocages F1/F2/décisions 4 et 6, organisation, téléphones
 * (décision 2), compte (décision 5), journal, modifications concurrentes, annulation sûre.
 */
class FusionContactsTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = ['contacts.read', 'contacts.fusionner', 'clients.read', 'livreurs.read', 'fournisseurs.read', 'proprietaires.read', 'users.update'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
    }

    private function acteur(array $permissions = self::PERMISSIONS, ?Organization $org = null): User
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

    private function personne(array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, 'email' => null, 'telephone_secondaire' => null, ...$attributs]);
    }

    private function client(Personne $personne, array $attributs = []): Client
    {
        return Client::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => ClientType::EXTERNE->value,
            'nom_complet' => $personne->nom_complet, 'telephone' => $personne->telephone, ...$attributs]);
    }

    private function livreur(Personne $personne, array $attributs = []): Livreur
    {
        // La factory ignore le personne_id fourni (elle résout sa propre identité) : on le repose ensuite.
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
        DB::table('livreurs')->where('id', $livreur->id)->update(['personne_id' => $personne->id]);

        return $livreur->fresh();
    }

    private function service(): FusionContacts
    {
        return app(FusionContacts::class);
    }

    /** Simule puis exécute avec l'empreinte de la simulation. */
    private function fusionner(User $acteur, Personne|EntrepriseTierce $a, Personne|EntrepriseTierce $b, array $choix = [], array $confirmations = [], ?string $jeton = null): ContactsFusion
    {
        $type = $a instanceof Personne ? 'personne' : 'entreprise';
        $simulation = $this->service()->simuler($acteur, $type, $a->id, $b->id, $choix);

        return $this->service()->executer($acteur, $type, $a->id, $b->id, $simulation['choix'], ['fusion' => true, ...$confirmations], $simulation['empreinte'], $jeton ?? (string) Str::uuid());
    }

    /** @return array<string, int> */
    private function comptages(): array
    {
        $resultat = [];
        foreach (Schema::getTableListing() as $table) {
            $nom = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            $resultat[$nom] = DB::table($nom)->count();
        }

        return $resultat;
    }

    private function codesBlocage(array $simulation): array
    {
        return array_column($simulation['blocages'], 'code');
    }

    // ── Simulation ──────────────────────────────────────────────────────────

    public function test_la_simulation_n_ecrit_rien(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne(['nom_complet' => 'Rayanatou Sylla', 'telephone' => '+224624000001']);
        $b = $this->personne(['nom_complet' => 'Rayanatou', 'telephone' => '+224624000002']);
        $this->livreur($a);
        $this->client($b);
        $avant = $this->comptages();

        $simulation = $this->service()->simuler($acteur, 'personne', $a->id, $b->id);

        $this->assertSame([], $simulation['blocages']);
        $this->assertSame($avant, $this->comptages());
        $this->assertSame('+224624000002', $b->fresh()->telephone);
        $this->assertSame(['fusion'], $simulation['confirmations_requises']);
        $this->assertContains('transferee', array_column($simulation['relations'], 'action'));
    }

    public function test_par_defaut_les_noms_restent_ceux_de_a_et_les_champs_vides_sont_completes(): void
    {
        // Le nom affiché de A peut venir de sa fonction (livreur) : un nom vide sur la Personne ne
        // doit jamais être remplacé par celui de B sans choix explicite.
        $a = $this->personne(['nom_complet' => null, 'ville' => null]);
        $b = $this->personne(['nom_complet' => 'Mariama C.', 'ville' => 'Dubreka']);
        $this->livreur($a, ['nom_complet' => 'Mariama Camara']);
        $this->client($b);

        $choix = $this->service()->simuler($this->acteur(), 'personne', $a->id, $b->id)['choix'];

        $this->assertSame('a', $choix['nom_complet']);
        $this->assertSame('b', $choix['ville']);
    }

    public function test_une_fiche_d_une_autre_organisation_est_introuvable(): void
    {
        $autre = Organization::factory()->create();
        $a = $this->personne();
        $this->client($a);
        $b = Personne::factory()->create(['organization_id' => $autre->id]);

        $this->expectException(NotFoundHttpException::class);
        $this->service()->simuler($this->acteur(), 'personne', $a->id, $b->id);
    }

    // ── Exécution ───────────────────────────────────────────────────────────

    public function test_la_fusion_transfere_les_liens_archive_b_et_ne_perd_aucune_ligne(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne(['nom_complet' => 'Rayanatou Sylla', 'telephone' => '+224624000001', 'ville' => null]);
        $b = $this->personne(['nom_complet' => 'Rayanatou', 'telephone' => '+224624000002', 'ville' => 'Dubreka']);
        $livreur = $this->livreur($a);
        $client = $this->client($b);
        $categorie = CategorieLibre::create(['organization_id' => $this->org->id, 'code' => 'vip', 'libelle' => 'VIP']);
        $b->categoriesLibres()->attach($categorie->id);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Boutique Dubreka', 'contact_personne_id' => $b->id]);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
        $lien = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $b->id, 'fonction' => 'Gérante']);
        $avant = $this->comptages();

        $fusion = $this->fusionner($acteur, $a, $b, ['nom_complet' => 'a', 'ville' => 'b']);

        $this->assertSame(ContactsFusion::EXECUTEE, $fusion->statut);
        // Les fonctions changent de fiche sans être recréées : mêmes ids, tout leur historique suit.
        $this->assertSame($a->id, $client->fresh()->personne_id);
        $this->assertSame($a->id, $livreur->fresh()->personne_id);
        $this->assertSame($a->id, $lien->fresh()->personne_id);
        $this->assertSame($a->id, $entreprise->fresh()->contact_personne_id);
        $this->assertTrue($a->fresh()->categoriesLibres->contains($categorie));
        // Arbitrage champ par champ, et F3 : le numéro de B devient le secondaire de A.
        $this->assertSame('Rayanatou Sylla', $a->fresh()->getAttributes()['nom_complet']);
        $this->assertSame('Dubreka', $a->fresh()->ville);
        $this->assertSame('+224624000002', $a->fresh()->telephone_secondaire);
        $this->assertSame('224624000002', $a->fresh()->telephone_secondaire_normalise);
        // B archivée, jamais supprimée, avec le lien vers A ; son numéro est libéré.
        $archivee = Personne::withTrashed()->findOrFail($b->id);
        $this->assertTrue($archivee->trashed());
        $this->assertSame($a->id, $archivee->fusionnee_dans_id);
        $this->assertNull($archivee->telephone_normalise);
        // Aucune ligne supprimée ni créée ailleurs que dans le journal.
        $apres = $this->comptages();
        foreach ($avant as $table => $nombre) {
            if (in_array($table, ['contacts_fusions', 'contacts_fusion_operations', 'audit_logs'], true)) {
                continue;
            }
            $this->assertSame($nombre, $apres[$table], "Nombre de lignes modifié dans {$table}");
        }
        $this->assertSame(1, ContactsFusion::count());
        $this->assertTrue($fusion->operations()->where('nature', ContactsFusionOperation::LIEN)->where('cible_id', $client->id)->exists());
        $this->assertTrue($fusion->operations()->where('champ', 'ville')->where('valeur_apres', 'Dubreka')->exists());
    }

    public function test_f1_meme_fonction_des_deux_cotes_fiche_archivee_comprise(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne();
        $b = $this->personne();
        $this->livreur($a);
        $this->client($b);
        $this->livreur($b)->delete();

        $simulation = $this->service()->simuler($acteur, 'personne', $a->id, $b->id);

        $this->assertContains('F1', $this->codesBlocage($simulation));
        $this->expectException(ValidationException::class);
        $this->service()->executer($acteur, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true], $simulation['empreinte'], (string) Str::uuid());
    }

    public function test_decision_4_interlocuteurs_de_la_meme_entreprise(): void
    {
        $a = $this->personne();
        $b = $this->personne();
        $this->client($a);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'SARL Kaloum']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
        foreach ([$a, $b] as $p) {
            EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $p->id]);
        }

        $simulation = $this->service()->simuler($this->acteur(), 'personne', $a->id, $b->id);

        $this->assertContains('interlocuteur', $this->codesBlocage($simulation));
    }

    public function test_f2_un_compte_de_chaque_cote_bloque(): void
    {
        $a = $this->personne();
        $b = $this->personne();
        $this->client($a);
        $this->livreur($b);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $a->id]);
        User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $b->id]);

        $this->assertContains('F2', $this->codesBlocage($this->service()->simuler($this->acteur(), 'personne', $a->id, $b->id)));
    }

    public function test_compte_sur_b_confirmation_renforcee_et_droits_de_modifier_les_utilisateurs(): void
    {
        $a = $this->personne(['telephone' => '+224624000011']);
        $b = $this->personne(['telephone' => '+224624000012']);
        $this->client($a);
        $this->livreur($b);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $b->id]);
        $identifiants = $compte->authIdentities()->pluck('normalized_value', 'id')->all();

        $sansDroit = $this->service()->simuler($this->acteur(array_diff(self::PERMISSIONS, ['users.update'])), 'personne', $a->id, $b->id);
        $this->assertContains('droits_compte', $this->codesBlocage($sansDroit));

        $acteur = $this->acteur();
        $simulation = $this->service()->simuler($acteur, 'personne', $a->id, $b->id);
        $this->assertSame([], $simulation['blocages']);
        $this->assertSame('b', $simulation['compte']['cote']);
        $this->assertSame($compte->authIdentities()->pluck('value')->all(), $simulation['compte']['identifiants_connexion']);
        $this->assertContains('transfert_compte', $simulation['confirmations_requises']);

        try {
            $this->service()->executer($acteur, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true, 'compte' => true, 'transfert_compte' => 'oui'], $simulation['empreinte'], (string) Str::uuid());
            $this->fail('Transfert du compte sans confirmation renforcée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmations.transfert_compte', $e->errors());
        }

        $this->fusionner($acteur, $a, $b, [], ['compte' => true, 'transfert_compte' => 'transferer']);

        $this->assertSame($a->id, $compte->fresh()->personne_id);
        // L'identifiant de connexion du compte n'est jamais modifié par la fusion.
        $this->assertSame($identifiants, $compte->authIdentities()->pluck('normalized_value', 'id')->all());
    }

    public function test_decision_6_fonction_non_visible_refuse_la_fusion(): void
    {
        $a = $this->personne();
        $b = $this->personne();
        $this->client($a);
        $this->client($b, ['telephone' => '+224600000999']);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $b->id, 'is_active' => true]);

        $simulation = $this->service()->simuler($this->acteur(['contacts.read', 'contacts.fusionner', 'clients.read']), 'personne', $a->id, $b->id);

        $this->assertContains('visibilite', $this->codesBlocage($simulation));
    }

    public function test_une_donnee_d_une_autre_organisation_liee_a_b_bloque(): void
    {
        $a = $this->personne();
        $b = $this->personne();
        $this->client($a);
        $this->livreur($b);
        Client::create(['organization_id' => Organization::factory()->create()->id, 'personne_id' => $b->id, 'type' => ClientType::EXTERNE->value, 'nom_complet' => 'Ailleurs', 'telephone' => '+224600000001']);

        $this->assertContains('organisation', $this->codesBlocage($this->service()->simuler($this->acteur(), 'personne', $a->id, $b->id)));
    }

    public function test_une_modification_apres_la_simulation_refuse_la_fusion(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne();
        $b = $this->personne(['ville' => 'Kindia']);
        $this->client($a);
        $this->livreur($b);
        $simulation = $this->service()->simuler($acteur, 'personne', $a->id, $b->id);

        $b->update(['ville' => 'Boké']);

        try {
            $this->service()->executer($acteur, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true], $simulation['empreinte'], (string) Str::uuid());
            $this->fail('Fusion acceptée malgré une modification concurrente.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('modifiées depuis la simulation', $e->errors()['fusion'][0]);
        }
        $this->assertFalse($b->fresh()->trashed());
        $this->assertSame(0, ContactsFusion::count());
    }

    public function test_un_double_envoi_ne_fusionne_qu_une_fois(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne();
        $b = $this->personne();
        $this->client($a);
        $this->livreur($b);
        $simulation = $this->service()->simuler($acteur, 'personne', $a->id, $b->id);
        $jeton = (string) Str::uuid();

        $premiere = $this->service()->executer($acteur, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true], $simulation['empreinte'], $jeton);
        $seconde = $this->service()->executer($acteur, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true], $simulation['empreinte'], $jeton);

        $this->assertSame($premiere->id, $seconde->id);
        $this->assertSame(1, ContactsFusion::count());
    }

    public function test_numeros_non_retenus_confirmation_et_journal(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne(['telephone' => '+224624000021', 'telephone_secondaire' => '+224624000022']);
        $b = $this->personne(['telephone' => '+224624000023']);
        $this->client($a);
        $this->livreur($b);

        $simulation = $this->service()->simuler($acteur, 'personne', $a->id, $b->id);
        $this->assertSame(['224624000023'], array_column($simulation['telephones']['retires'], 'normalise'));
        $this->assertContains('numeros_retires', $simulation['confirmations_requises']);

        // Le numéro de B peut devenir le principal (décision 5).
        $fusion = $this->fusionner($acteur, $a, $b, ['telephone' => '224624000023', 'telephone_secondaire' => '224624000021'], ['numeros_retires' => true]);

        $this->assertSame('+224624000023', $a->fresh()->telephone);
        $this->assertSame('+224624000021', $a->fresh()->telephone_secondaire);
        $this->assertTrue($fusion->operations()->where('nature', ContactsFusionOperation::LAISSE)->where('valeur_avant', '+224624000022')->exists());
    }

    // ── Entreprises ─────────────────────────────────────────────────────────

    public function test_fusion_d_entreprises_interlocuteurs_et_principal_arbitre(): void
    {
        $acteur = $this->acteur([...self::PERMISSIONS, 'contacts.read']);
        $a = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'SARL Kaloum', 'telephone' => '+224620000001', 'telephone_normalise' => '224620000001']);
        $b = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Kaloum SARL', 'telephone' => '+224620000002', 'telephone_normalise' => '224620000002']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $a->id, 'is_active' => true]);
        $clientPro = Client::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $b->id, 'type' => ClientType::EXTERNE->value, 'nom_complet' => 'Kaloum SARL', 'telephone' => '+224620000002']);
        $commun = $this->personne();
        $mamadou = $this->personne();
        $alpha = $this->personne();
        $this->client($commun, ['telephone' => '+224600000101']);
        $this->client($mamadou, ['telephone' => '+224600000102']);
        $this->client($alpha, ['telephone' => '+224600000103']);
        $principalA = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $a->id, 'personne_id' => $mamadou->id]);
        $principalA->forceFill(['est_principal' => true])->save();
        $principalB = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $b->id, 'personne_id' => $alpha->id]);
        $principalB->forceFill(['est_principal' => true])->save();
        EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $a->id, 'personne_id' => $commun->id]);
        $doublon = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $b->id, 'personne_id' => $commun->id]);

        $simulation = $this->service()->simuler($acteur, 'entreprise', $a->id, $b->id);
        $this->assertSame([], $simulation['blocages']);
        $this->assertSame($principalA->id, $simulation['interlocuteur_principal']['choix']);

        $this->fusionner($acteur, $a, $b, ['interlocuteur_principal' => $principalB->id]);

        $this->assertSame($a->id, $clientPro->fresh()->entreprise_tierce_id);
        // La raison sociale de A reste le nom principal du client professionnel (L7-2).
        $this->assertSame('SARL Kaloum', $clientPro->fresh()->nom_complet);
        $this->assertSame($a->id, $principalB->fresh()->entreprise_tierce_id);
        $this->assertTrue((bool) $principalB->fresh()->est_principal);
        $this->assertFalse((bool) $principalA->fresh()->est_principal);
        // Interlocuteur déjà lié à A : laissé sur la fiche archivée, jamais supprimé.
        $this->assertSame($b->id, $doublon->fresh()->entreprise_tierce_id);
        $this->assertTrue(EntrepriseTierce::withTrashed()->findOrFail($b->id)->trashed());
    }

    // ── Annulation ──────────────────────────────────────────────────────────

    public function test_annulation_complete_restaure_tout(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne(['nom_complet' => 'Rayanatou Sylla', 'telephone' => '+224624000031', 'ville' => null]);
        $b = $this->personne(['nom_complet' => 'Rayanatou', 'telephone' => '+224624000032', 'ville' => 'Dubreka']);
        $this->livreur($a);
        $client = $this->client($b);
        $fusion = $this->fusionner($acteur, $a, $b, ['ville' => 'b']);

        $annulation = app(AnnulationFusionContacts::class);
        $this->assertSame([], $annulation->analyser($fusion, $acteur)['interventions']);
        $fusion = $annulation->annuler($fusion, $acteur);

        $this->assertSame(ContactsFusion::ANNULEE, $fusion->statut);
        $this->assertFalse($b->fresh()->trashed());
        $this->assertNull($b->fresh()->fusionnee_dans_id);
        $this->assertSame('224624000032', $b->fresh()->telephone_normalise);
        $this->assertSame($b->id, $client->fresh()->personne_id);
        $this->assertNull($a->fresh()->ville);
        $this->assertNull($a->fresh()->telephone_secondaire);
    }

    public function test_annulation_partielle_n_ecrase_jamais_une_modification_posterieure(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne(['ville' => null, 'telephone' => '+224624000041']);
        $b = $this->personne(['ville' => 'Dubreka', 'telephone' => '+224624000042']);
        $this->livreur($a);
        $client = $this->client($b);
        $fusion = $this->fusionner($acteur, $a, $b, ['ville' => 'b']);
        DB::table('personnes')->where('id', $a->id)->update(['ville' => 'Kindia']);

        $analyse = app(AnnulationFusionContacts::class)->analyser($fusion, $acteur);
        $this->assertTrue($analyse['possible']);
        $this->assertCount(1, $analyse['interventions']);
        $this->assertStringContainsString('Ville', $analyse['interventions'][0]['libelle']);

        $fusion = app(AnnulationFusionContacts::class)->annuler($fusion, $acteur);

        $this->assertSame(ContactsFusion::ANNULEE_PARTIELLEMENT, $fusion->statut);
        $this->assertSame('Kindia', $a->fresh()->ville);
        $this->assertSame($b->id, $client->fresh()->personne_id);
        $this->assertFalse($b->fresh()->trashed());
    }

    public function test_annulation_un_role_ajoute_au_compte_n_est_jamais_retire_automatiquement(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne(['telephone' => '+224624000051']);
        $b = $this->personne(['telephone' => '+224624000052']);
        $this->client($a);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $a->id]);
        $livreur = $this->livreur($b, ['user_id' => null]);
        $fusion = $this->fusionner($acteur, $a, $b, [], ['compte' => true]);

        // ADR 0056 : la fonction transférée est rattachée au compte de A, et c'est journalisé.
        $this->assertSame($compte->id, $livreur->fresh()->user_id);
        $this->assertTrue($fusion->operations()->where('table_cible', 'livreurs')->where('champ', 'user_id')->exists());
        $roleAjoute = $fusion->operations()->where('table_cible', 'model_has_roles')->exists();

        $annulee = app(AnnulationFusionContacts::class)->annuler($fusion, $acteur);

        $this->assertNull($livreur->fresh()->user_id);
        $this->assertSame($b->id, $livreur->fresh()->personne_id);
        $this->assertSame($roleAjoute ? ContactsFusion::ANNULEE_PARTIELLEMENT : ContactsFusion::ANNULEE, $annulee->statut);
        if ($roleAjoute) {
            // Le rôle reste : son retrait demande un responsable.
            $this->assertTrue($compte->fresh()->hasRole('livreur'));
        }
    }

    public function test_annulation_impossible_si_b_n_est_plus_archivee_par_cette_fusion(): void
    {
        $acteur = $this->acteur();
        $a = $this->personne();
        $b = $this->personne();
        $this->client($a);
        $this->livreur($b);
        $fusion = $this->fusionner($acteur, $a, $b);
        Personne::withTrashed()->findOrFail($b->id)->restore();

        $this->assertFalse(app(AnnulationFusionContacts::class)->analyser($fusion, $acteur)['possible']);
        $this->expectException(ValidationException::class);
        app(AnnulationFusionContacts::class)->annuler($fusion, $acteur);
    }
}
