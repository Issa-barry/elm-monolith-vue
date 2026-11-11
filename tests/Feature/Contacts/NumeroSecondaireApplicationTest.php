<?php

namespace Tests\Feature\Contacts;

use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\ContactsFusion;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Vehicule;
use App\Services\Contacts\Fusion\AnnulationFusionContacts;
use App\Services\Contacts\Fusion\FusionContacts;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * CONTACT-063 (ADR 0060, lot 9b) : le numéro secondaire d'une fiche est détecté par tous les
 * formulaires qui créent ou rattachent une identité. Aucune nouvelle identité n'est créée avec lui,
 * aucun rattachement automatique : la fiche existante est désignée (nom et fonctions si le lecteur
 * peut la voir, message neutre sinon).
 */
class NumeroSecondaireApplicationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    /** Numéro secondaire de la fiche « Aminata Diallo » (livreur). */
    private const SECONDAIRE = '+224622700001';

    private const LOCAL = '622700001';

    private Personne $titulaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'contacts.read', 'contacts.fusionner', 'contacts.gerer_interlocuteurs', 'clients.read', 'clients.create', 'clients.update',
            'livreurs.read', 'livreurs.create', 'livreurs.update', 'fournisseurs.read', 'fournisseurs.create',
            'proprietaires.read', 'proprietaires.create', 'parrains.read', 'vehicules.read', 'vehicules.update',
            'equipes-livraison.create', 'rh-employes.read', 'rh-employes.create', 'users.update',
        ]);
        foreach ([ModuleFeature::ACHATS, ModuleFeature::VEHICULES, ModuleFeature::RH] as $module) {
            Feature::for($this->org)->activate($module);
        }

        $this->titulaire = $this->personne(['prenom' => 'Aminata', 'nom' => 'Diallo', 'telephone' => '+224622700000', 'telephone_secondaire' => self::SECONDAIRE]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        DB::table('livreurs')->where('id', $livreur->id)->update(['personne_id' => $this->titulaire->id]);
    }

    private function personne(array $attributs): Personne
    {
        $personne = new Personne(['organization_id' => $this->org->id, ...$attributs]);
        $personne->telephone_normalise = isset($attributs['telephone']) ? Personne::normaliserTelephone($attributs['telephone']) : null;
        $personne->save();

        return $personne;
    }

    private function personnesAvec(string $numero): int
    {
        return Personne::withTrashed()->where('organization_id', $this->org->id)->where('telephone_normalise', Personne::normaliserTelephone($numero))->count();
    }

    private function messageVisible(): string
    {
        return sprintf(MessagesContacts::NUMERO_SECONDAIRE_FICHE, 'Aminata Diallo (Livreur)');
    }

    // ── Garde commune des modèles ───────────────────────────────────────────

    public function test_resoudre_ou_creer_refuse_un_numero_secondaire_sans_rien_creer(): void
    {
        $this->actingAs($this->user);

        try {
            Personne::resoudreOuCreer($this->org->id, ['telephone' => self::SECONDAIRE, 'nom' => 'Autre']);
            $this->fail('Création acceptée avec un numéro secondaire.');
        } catch (ValidationException $e) {
            $this->assertSame($this->messageVisible(), $e->errors()['telephone'][0]);
        }

        $this->assertSame(0, $this->personnesAvec(self::SECONDAIRE));
        $this->assertSame(self::SECONDAIRE, $this->titulaire->fresh()->telephone_secondaire);
    }

    public function test_une_creation_directe_ou_un_changement_de_numero_principal_est_refuse(): void
    {
        $this->expectException(ValidationException::class);
        $this->personne(['nom' => 'Direct', 'telephone' => self::SECONDAIRE]);
    }

    public function test_changer_son_numero_principal_pour_le_secondaire_d_une_autre_fiche_est_refuse(): void
    {
        $autre = $this->personne(['nom' => 'Barry', 'telephone' => '+224622700099']);

        $this->expectException(ValidationException::class);
        $autre->update(['telephone' => self::SECONDAIRE, 'telephone_normalise' => Personne::normaliserTelephone(self::SECONDAIRE)]);
    }

    public function test_une_fiche_peut_prendre_son_propre_secondaire_comme_principal(): void
    {
        $this->titulaire->update(['telephone' => self::SECONDAIRE, 'telephone_normalise' => Personne::normaliserTelephone(self::SECONDAIRE), 'telephone_secondaire' => null]);

        $this->assertSame(Personne::normaliserTelephone(self::SECONDAIRE), $this->titulaire->fresh()->telephone_normalise);
    }

    public function test_le_numero_principal_d_une_fiche_l_emporte_et_la_fiche_archivee_ne_bloque_pas(): void
    {
        // Principal d'une fiche : résolu vers elle (règle inchangée), même s'il est aussi un secondaire.
        $principal = $this->personne(['nom' => 'Principal', 'telephone' => '+224622700050']);
        $this->personne(['nom' => 'Ancien', 'telephone' => '+224622700051', 'telephone_secondaire' => '+224622700050']);
        $this->assertSame($principal->id, Personne::resoudreOuCreer($this->org->id, ['telephone' => '+224622700050'])->id);

        // Fiche archivée : son numéro secondaire ne bloque plus.
        $this->titulaire->delete();
        $nouvelle = Personne::resoudreOuCreer($this->org->id, ['telephone' => self::SECONDAIRE]);
        $this->assertTrue($nouvelle->wasRecentlyCreated);
    }

    public function test_une_entreprise_ne_peut_pas_etre_creee_sur_le_secondaire_d_une_autre_entreprise(): void
    {
        EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Kaloum SA', 'telephone' => '+224622700200', 'telephone_normalise' => '224622700200', 'telephone_secondaire' => '+224622700201']);

        $this->expectException(ValidationException::class);
        EntrepriseTierce::resoudreOuCreer($this->org->id, ['raison_sociale' => 'Autre SARL', 'telephone' => '+224622700201']);
    }

    public function test_message_neutre_si_le_lecteur_ne_peut_pas_voir_la_fiche(): void
    {
        $lecteur = $this->makeUserWithPermissions($this->org, ['clients.read', 'clients.create']);
        $this->actingAs($lecteur);

        try {
            Personne::resoudreOuCreer($this->org->id, ['telephone' => self::SECONDAIRE]);
            $this->fail('Création acceptée avec un numéro secondaire.');
        } catch (ValidationException $e) {
            $this->assertSame(MessagesContacts::NUMERO_SECONDAIRE_NON_VISIBLE, $e->errors()['telephone'][0]);
            $this->assertStringNotContainsString('Aminata', $e->errors()['telephone'][0]);
        }
    }

    public function test_un_numero_secondaire_d_une_autre_organisation_ne_compte_pas(): void
    {
        $autreOrg = Organization::factory()->create();
        $personne = Personne::resoudreOuCreer($autreOrg->id, ['telephone' => self::SECONDAIRE]);

        $this->assertTrue($personne->wasRecentlyCreated);
    }

    // ── Formulaires ─────────────────────────────────────────────────────────

    public function test_formulaire_client_detecte_le_numero_avant_et_refuse_a_la_soumission(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('clients.verifier-telephone', ['telephone' => self::LOCAL, 'code_phone_pays' => '+224']))
            ->assertOk()
            ->assertJson(['found' => true, 'blocking' => true, 'type' => 'secondaire', 'nom' => 'Aminata Diallo', 'message' => $this->messageVisible()]);

        $this->actingAs($this->user)
            ->post(route('clients.store'), ['nom_complet' => 'Nouveau client', 'telephone' => self::LOCAL, 'code_pays' => 'GN', 'ville' => 'Conakry', 'type' => 'externe'])
            ->assertSessionHasErrors(['telephone' => $this->messageVisible()]);

        $this->assertSame(0, Client::where('organization_id', $this->org->id)->count());
        $this->assertSame(0, $this->personnesAvec(self::SECONDAIRE));
    }

    public function test_formulaire_livreur_refuse_le_numero(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('livreurs.store'), ['nom_complet' => 'Nouveau livreur', 'telephone' => self::SECONDAIRE])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telephone' => $this->messageVisible()]);

        $this->assertSame(1, Livreur::where('organization_id', $this->org->id)->count());
        $this->assertSame(0, $this->personnesAvec(self::SECONDAIRE));
    }

    public function test_formulaire_fournisseur_refuse_le_numero_sur_son_champ(): void
    {
        $this->actingAs($this->user)
            ->post(route('fournisseurs.store'), ['nom' => 'Sow', 'prenom' => 'Ibrahima', 'code_pays' => 'GN', 'ville' => 'Conakry', 'phone' => self::LOCAL])
            ->assertSessionHasErrors(['phone' => $this->messageVisible()]);

        $this->assertSame(0, Fournisseur::where('organization_id', $this->org->id)->count());
    }

    public function test_formulaire_proprietaire_refuse_le_numero(): void
    {
        $this->actingAs($this->user)
            ->post(route('proprietaires.store'), ['type' => 'personne_physique', 'nom' => 'Bah', 'prenom' => 'Oumar', 'telephone' => self::LOCAL, 'code_pays' => 'GN', 'ville' => 'Conakry', 'is_active' => true])
            ->assertSessionHasErrors(['telephone' => $this->messageVisible()]);

        $this->assertSame(0, $this->personnesAvec(self::SECONDAIRE));
    }

    public function test_recherche_employe_designe_la_fiche_et_la_creation_est_refusee(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('employes.recherche-telephone', ['telephone' => self::SECONDAIRE]))
            ->assertOk()
            ->assertJson([
                'statut' => 'secondaire',
                'message' => $this->messageVisible(),
                'fiche' => ['id' => $this->titulaire->id, 'nom' => 'Aminata Diallo', 'fonctions' => ['Livreur']],
            ]);

        $this->actingAs($this->user)
            ->post(route('employes.store'), ['prenom' => 'Fanta', 'nom' => 'Camara', 'telephone' => self::SECONDAIRE, 'type_employe' => 'interne', 'statut' => 'actif'])
            ->assertSessionHasErrors('telephone');

        $this->assertSame(0, $this->personnesAvec(self::SECONDAIRE));
    }

    public function test_recherche_parrain_signale_le_numero_sans_le_rattacher(): void
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->getJson(route('vehicules.parrain.rechercher', $vehicule).'?telephone='.self::LOCAL.'&code_pays=GN')
            ->assertOk()
            ->assertJson(['found' => false, 'personne' => null, 'secondaire' => ['message' => $this->messageVisible()]]);
    }

    public function test_equipe_de_livraison_signale_le_numero_au_controle_live(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('equipes-livraison.verifier-telephone', ['telephone' => self::SECONDAIRE]))
            ->assertOk()
            ->assertJson(['conflict' => true, 'message' => $this->messageVisible()]);
    }

    public function test_ajout_d_un_interlocuteur_bloque_le_numero(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Kaloum SA']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);

        $this->actingAs($this->user)
            ->getJson(route('contacts.entreprises.interlocuteurs.recherche-telephone', ['entreprise' => $entreprise->id, 'telephone' => self::SECONDAIRE]))
            ->assertOk()
            ->assertJson(['statut' => 'indisponible', 'message' => $this->messageVisible()]);
    }

    // ── Fusion (CONTACT-058) ────────────────────────────────────────────────

    public function test_fusion_le_secondaire_de_b_peut_devenir_le_principal_de_a_et_l_annulation_le_rend(): void
    {
        $a = $this->personne(['nom' => 'Camara', 'telephone' => '+224622700300']);
        Client::create(['organization_id' => $this->org->id, 'personne_id' => $a->id, 'type' => 'externe', 'nom_complet' => 'Camara', 'telephone' => '+224622700300']);

        $service = app(FusionContacts::class);
        $choix = ['telephone' => Personne::normaliserTelephone(self::SECONDAIRE)];
        $simulation = $service->simuler($this->user, 'personne', $a->id, $this->titulaire->id, $choix);
        $this->assertSame([], $simulation['blocages']);

        $fusion = $service->executer($this->user, 'personne', $a->id, $this->titulaire->id, $simulation['choix'], ['fusion' => true, 'numeros_retires' => true], $simulation['empreinte'], (string) Str::uuid());

        $this->assertSame(Personne::normaliserTelephone(self::SECONDAIRE), $a->fresh()->telephone_normalise);
        $this->assertNull(DB::table('personnes')->where('id', $this->titulaire->id)->value('telephone_secondaire_normalise'));

        app(AnnulationFusionContacts::class)->annuler($fusion, $this->user);

        $this->assertSame(ContactsFusion::ANNULEE, $fusion->fresh()->statut);
        $this->assertSame(Personne::normaliserTelephone(self::SECONDAIRE), $this->titulaire->fresh()->telephone_secondaire_normalise);
        $this->assertSame('224622700300', $a->fresh()->telephone_normalise);
    }

    public function test_fusion_bloquee_si_le_numero_retenu_est_le_secondaire_d_une_troisieme_fiche(): void
    {
        $a = $this->personne(['nom' => 'Camara', 'telephone' => '+224622700400']);
        Client::create(['organization_id' => $this->org->id, 'personne_id' => $a->id, 'type' => 'externe', 'nom_complet' => 'Camara', 'telephone' => '+224622700400']);
        $b = $this->personne(['nom' => 'Sylla', 'telephone' => '+224622700401']);
        $livreurB = Livreur::factory()->create(['organization_id' => $this->org->id]);
        DB::table('livreurs')->where('id', $livreurB->id)->update(['personne_id' => $b->id]);
        // Donnée antérieure à la règle : le numéro de B est aussi le secondaire d'une troisième fiche.
        DB::table('personnes')->where('id', $this->titulaire->id)->update(['telephone_secondaire_normalise' => '224622700401']);

        $simulation = app(FusionContacts::class)->simuler($this->user, 'personne', $a->id, $b->id, ['telephone' => '224622700401']);

        $this->assertContains('numero_secondaire', array_column($simulation['blocages'], 'code'));
    }

    public function test_annulation_ne_rend_pas_un_numero_devenu_le_secondaire_d_une_autre_fiche(): void
    {
        $a = $this->personne(['nom' => 'Camara', 'telephone' => '+224622700500']);
        Client::create(['organization_id' => $this->org->id, 'personne_id' => $a->id, 'type' => 'externe', 'nom_complet' => 'Camara', 'telephone' => '+224622700500']);
        $b = $this->personne(['nom' => 'Sylla', 'telephone' => '+224622700501']);
        $livreurB = Livreur::factory()->create(['organization_id' => $this->org->id]);
        DB::table('livreurs')->where('id', $livreurB->id)->update(['personne_id' => $b->id]);

        $service = app(FusionContacts::class);
        $simulation = $service->simuler($this->user, 'personne', $a->id, $b->id, ['telephone_secondaire' => '']);
        $fusion = $service->executer($this->user, 'personne', $a->id, $b->id, $simulation['choix'], ['fusion' => true, 'numeros_retires' => true], $simulation['empreinte'], (string) Str::uuid());

        // Après la fusion, le numéro libéré de B devient le secondaire d'une autre fiche.
        $this->titulaire->update(['telephone_secondaire' => '+224622700501']);

        $analyse = app(AnnulationFusionContacts::class)->analyser($fusion, $this->user);
        $this->assertContains('Numéro repris depuis par une autre fiche.', array_column($analyse['interventions'], 'motif'));

        app(AnnulationFusionContacts::class)->annuler($fusion, $this->user);
        $this->assertSame(ContactsFusion::ANNULEE_PARTIELLEMENT, $fusion->fresh()->statut);
        $this->assertNull(DB::table('personnes')->where('id', $b->id)->value('telephone_normalise'));
    }
}
