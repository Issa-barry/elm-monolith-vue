<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\ContactSousCategorie;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 8 du référentiel Contacts (ADR 0060, CONTACT-050 à CONTACT-052) : au back-office, un seul
 * groupe « Contacts » dans la recherche globale (badges par fonction, liens vers les écrans métier,
 * aucun résultat perdu), l'API v1/search/global inchangée ; création Fournisseur / Prestataire par
 * le parcours Contacts avec la fonction présélectionnée.
 */
class BasculeContactsTest extends TestCase
{
    use RefreshDatabase;

    private const LECTURE = ['contacts.read', 'clients.read', 'proprietaires.read', 'livreurs.read', 'fournisseurs.read'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        Feature::for($this->org)->activate(ModuleFeature::PRESTATAIRES);
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
    }

    private function utilisateur(array $permissions = self::LECTURE): User
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

    private function personne(array $attributs = []): Personne
    {
        return Personne::factory()->create(['organization_id' => $this->org->id, ...$attributs]);
    }

    private function client(Personne $p, array $attributs = []): Client
    {
        return Client::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'nom_complet' => $p->nom_complet, 'telephone' => $p->telephone, 'type' => ClientType::EXTERNE->value, ...$attributs]);
    }

    /** Recherche du back-office (session) : groupe « Contacts » regroupé. */
    private function rechercherBackOffice(User $user, string $terme, array $params = []): array
    {
        return $this->actingAs($user)->getJson(route('search.global', ['q' => $terme, ...$params]))->assertOk()->json('results');
    }

    // ── Recherche globale du back-office (L8-2) ─────────────────────────────

    public function test_un_seul_groupe_contacts_en_tete_a_la_place_des_groupes_clients_proprietaires_livreurs(): void
    {
        $p = $this->personne(['nom_complet' => 'Mariama Sow', 'telephone' => '+224622300001']);
        $client = $this->client($p);

        $resultats = $this->rechercherBackOffice($this->utilisateur(), 'Mariama');

        $this->assertSame('contacts', array_key_first($resultats));
        $this->assertArrayNotHasKey('clients', $resultats);
        $this->assertArrayNotHasKey('proprietaires', $resultats);
        $this->assertArrayNotHasKey('livreurs', $resultats);
        $item = $resultats['contacts']['items'][0];
        $this->assertSame('personnes/'.$p->id, $item['id']);
        $this->assertSame("/backoffice/contacts/personnes/{$p->id}", $item['href']);
        $this->assertSame([['libelle' => 'Client', 'href' => "/backoffice/clients/{$client->id}"]], $item['badges']);
    }

    public function test_une_personne_a_plusieurs_fonctions_porte_un_badge_par_fonction_avec_son_lien(): void
    {
        $p = $this->personne(['nom_complet' => 'Ibrahima Barry', 'telephone' => '+224622300002']);
        $client = $this->client($p);
        $proprietaire = Proprietaire::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'type' => 'personne_physique', 'is_active' => true]);
        $livreur = Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'nom_complet' => 'Chauffeur Ibrahima', 'is_active' => true]);

        $badges = collect($this->rechercherBackOffice($this->utilisateur(), '622300002')['contacts']['items'][0]['badges']);

        $this->assertEqualsCanonicalizing([
            ['libelle' => 'Client', 'href' => "/backoffice/clients/{$client->id}"],
            ['libelle' => 'Propriétaire', 'href' => "/backoffice/proprietaires/{$proprietaire->id}"],
            ['libelle' => 'Livreur', 'href' => "/livreurs/{$livreur->id}"],
        ], $badges->all());
    }

    public function test_aucun_resultat_perdu_nom_de_la_fiche_client_designation_du_livreur_client_sans_personne(): void
    {
        // Nom écrit sur la fiche Client différent de la Personne (synchronisation non activée).
        $p = $this->personne(['nom_complet' => 'Aissatou Diallo', 'telephone' => '+224622300003']);
        $this->client($p, ['nom_complet' => 'Boutique Aissatou Kipé']);
        // Désignation opérationnelle du livreur.
        $l = $this->personne(['nom_complet' => 'Ousmane Camara', 'telephone' => '+224622300004']);
        Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $l->id, 'nom_complet' => 'Tricycle-7 Matoto', 'is_active' => true]);
        // Client sans Personne (anomalie de reprise).
        $orphelin = Client::create(['organization_id' => $this->org->id, 'nom_complet' => 'Ancien Client Kipé', 'telephone' => '+224622300005', 'type' => ClientType::EXTERNE->value]);
        $user = $this->utilisateur();

        $this->assertSame('personnes/'.$p->id, $this->rechercherBackOffice($user, 'Boutique Aissatou')['contacts']['items'][0]['id']);
        $this->assertSame('personnes/'.$l->id, $this->rechercherBackOffice($user, 'Tricycle-7')['contacts']['items'][0]['id']);
        $item = collect($this->rechercherBackOffice($user, 'Ancien Client')['contacts']['items'])->firstWhere('id', 'clients/'.$orphelin->id);
        $this->assertNotNull($item);
        $this->assertSame("/backoffice/clients/{$orphelin->id}", $item['href']);
    }

    public function test_le_groupe_regroupe_affiche_autant_de_resultats_que_les_groupes_remplaces(): void
    {
        foreach (range(1, 7) as $i) {
            $this->client($this->personne(['nom_complet' => "Kadiatou Test {$i}", 'telephone' => '+22462240000'.$i]));
        }

        $items = $this->rechercherBackOffice($this->utilisateur(), 'Kadiatou', ['limit' => 5])['contacts']['items'];

        $this->assertCount(7, $items);
    }

    public function test_une_entreprise_porte_ses_badges_de_fonction(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Cimenterie Kamsar']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);

        $item = $this->rechercherBackOffice($this->utilisateur(), 'Cimenterie')['contacts']['items'][0];

        $this->assertSame("/backoffice/contacts/entreprises/{$entreprise->id}", $item['href']);
        $this->assertSame([['libelle' => 'Fournisseur', 'href' => null]], $item['badges']);
    }

    public function test_sans_contacts_read_les_groupes_historiques_restent(): void
    {
        $client = $this->client($this->personne(['nom_complet' => 'Fanta Keita', 'telephone' => '+224622300006']));

        // L'ancien groupe Clients cherche dans nom, prénom et téléphone de la fiche Client.
        $resultats = $this->rechercherBackOffice($this->utilisateur(['clients.read']), '622300006');

        $this->assertArrayHasKey('clients', $resultats);
        $this->assertArrayNotHasKey('contacts', $resultats);
        $this->assertSame($client->id, $resultats['clients']['items'][0]['id']);
    }

    public function test_un_groupe_dont_la_fonction_n_est_pas_lisible_dans_contacts_reste_separe(): void
    {
        // Sans le module Véhicules, la fonction Propriétaire n'est pas lisible dans Contacts : le
        // groupe Propriétaires est conservé pour ne rien perdre.
        Feature::for($this->org)->deactivate(ModuleFeature::VEHICULES);
        $p = $this->personne(['prenom' => 'Lansana', 'nom' => 'CONDE', 'nom_complet' => null, 'telephone' => '+224622300007']);
        Proprietaire::create(['organization_id' => $this->org->id, 'personne_id' => $p->id, 'type' => 'personne_physique', 'is_active' => true]);

        $resultats = $this->rechercherBackOffice($this->utilisateur(), 'Lansana');

        $this->assertArrayHasKey('proprietaires', $resultats);
        $this->assertArrayNotHasKey('clients', $resultats);
    }

    public function test_l_api_garde_ses_groupes_historiques(): void
    {
        $this->client($this->personne(['nom_complet' => 'Hawa Bangoura', 'telephone' => '+224622300008']));
        Sanctum::actingAs($this->utilisateur(), ['*']);

        $resultats = $this->getJson(route('api.search.global', ['q' => '622300008']))->assertOk()->json('results');

        $this->assertSame('clients', array_key_first($resultats));
        $this->assertSame(['id', 'title', 'subtitle'], array_keys($resultats['clients']['items'][0]));
        $this->assertArrayHasKey('contacts', $resultats);
    }

    // ── Création par le parcours Contacts (L8-3) ────────────────────────────

    public function test_le_parcours_presente_la_fonction_presélectionnee_et_les_sous_categories_fournisseur(): void
    {
        $sousCategorie = ContactSousCategorie::create(['organization_id' => $this->org->id, 'categorie' => 'fournisseur', 'code' => 'emballage', 'libelle' => 'Emballage', 'is_active' => true]);
        $user = $this->utilisateur(['contacts.read', 'contacts.create', 'fournisseurs.read', 'fournisseurs.create', 'prestataires.read', 'prestataires.create']);

        $this->actingAs($user)->get(route('contacts.create', ['type' => 'entreprise', 'fonction' => 'fournisseur']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('type', 'entreprise')
                ->where('fonction', 'fournisseur')
                ->where('sous_categories_fournisseur.0.value', $sousCategorie->id));

        $this->actingAs($user)->get(route('contacts.create', ['fonction' => 'client']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('fonction', null));
    }

    public function test_un_fournisseur_cree_par_le_parcours_garde_ses_sous_categories(): void
    {
        $sousCategorie = ContactSousCategorie::create(['organization_id' => $this->org->id, 'categorie' => 'fournisseur', 'code' => 'emballage', 'libelle' => 'Emballage', 'is_active' => true]);
        $user = $this->utilisateur(['contacts.read', 'contacts.create', 'fournisseurs.read', 'fournisseurs.create']);

        $this->actingAs($user)->post(route('contacts.entreprises.store'), [
            'raison_sociale' => 'Emballages Matam', 'telephone' => '+224622300009', 'ville' => 'Conakry',
            'fonction' => 'fournisseur', 'sous_categorie_ids' => [$sousCategorie->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $fournisseur = Fournisseur::whereHas('entrepriseTierce', fn ($q) => $q->where('raison_sociale', 'Emballages Matam'))->sole();
        $this->assertSame([$sousCategorie->id], $fournisseur->sousCategories()->pluck('contact_sous_categories.id')->all());
    }
}
