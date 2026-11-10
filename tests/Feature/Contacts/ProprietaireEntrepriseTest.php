<?php

namespace Tests\Feature\Contacts;

use App\Enums\TypeProprietaire;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Services\Contacts\ProprietaireEntreprise;
use App\Support\Contacts\MessagesContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 5 du référentiel Contacts (ADR 0060, CONTACT-027 à CONTACT-030) : propriétaire de type
 * entreprise relié à une fiche Entreprise — création ou rattachement explicite (D3), téléphone de
 * l'entreprise jamais celui du contact (D2), entreprise non visible jamais révélée (D6), la fiche
 * Entreprise fait foi (D5), droits D7 pour une entreprise partagée, passage en personne physique
 * (D8), contact interlocuteur sans doublon et principal seulement s'il n'y en a aucun (D9),
 * mention « au nom de » sur la fiche Personne (D10).
 */
class ProprietaireEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['proprietaires.read', 'proprietaires.create', 'proprietaires.update', 'contacts.read'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::VEHICULES);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
    }

    private function utilisateur(array $permissions = self::DROITS, ?Organization $org = null): User
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

    private function donnees(array $surcharges = []): array
    {
        return [
            'type' => 'entreprise',
            'raison_sociale' => 'Transports Kaloum',
            'telephone' => '622000001',
            'code_pays' => 'GN',
            'ville' => 'Conakry',
            'is_active' => true,
            ...$surcharges,
        ];
    }

    /** Entreprise qui porte la fonction Fournisseur (visible avec fournisseurs.read + module Achats). */
    private function entrepriseFournisseur(string $raisonSociale, array $attributs = [], ?Organization $org = null): EntrepriseTierce
    {
        $org ??= $this->org;
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => $raisonSociale, ...$attributs]);
        Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id]);

        return $entreprise;
    }

    /** Propriétaire entreprise relié, créé par le formulaire. */
    private function proprietaireRelie(User $user, array $surcharges = []): Proprietaire
    {
        $this->actingAs($user)->post(route('proprietaires.store'), $this->donnees($surcharges))->assertSessionHasNoErrors();

        return Proprietaire::where('organization_id', $this->org->id)->latest('id')->firstOrFail();
    }

    // ── Création (D2, D3, D9) ───────────────────────────────────────────────

    public function test_creation_sans_correspondance_cree_et_relie_la_fiche_entreprise(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['telephone_entreprise' => '624111111', 'identifiant_fiscal' => 'NIF-123']))
            ->assertRedirect(route('proprietaires.index'))
            ->assertSessionHas('success', 'Propriétaire créé avec succès ; sa fiche Entreprise a été créée.');

        $proprietaire = Proprietaire::where('organization_id', $this->org->id)->firstOrFail();
        $entreprise = $proprietaire->entrepriseTierce;
        $this->assertNotNull($entreprise);
        $this->assertSame('Transports Kaloum', $entreprise->raison_sociale);
        $this->assertSame('Transports Kaloum', $proprietaire->raison_sociale);
        $this->assertSame('NIF-123', $entreprise->identifiant_fiscal);
        $this->assertSame('+224624111111', $entreprise->telephone);
        $this->assertSame('+224622000001', $proprietaire->personne->telephone);
        $this->assertNull($entreprise->ville);
        $this->assertNull($entreprise->pays);
        // D9 : le contact devient l'interlocuteur principal de l'entreprise qui n'en avait aucun.
        $this->assertDatabaseHas('entreprise_interlocuteurs', [
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $proprietaire->personne_id,
            'est_principal' => true,
            'is_active' => true,
        ]);
        $this->assertSame('Transports Kaloum', $proprietaire->fresh()->nom_affichage);
    }

    public function test_ni_le_telephone_ni_la_localisation_du_contact_ne_sont_recopies_sur_l_entreprise(): void
    {
        $user = $this->utilisateur();
        $proprietaire = $this->proprietaireRelie($user, ['adresse' => 'Quartier Almamya', 'ville' => 'Kindia']);
        $this->assertSame('Kindia', $proprietaire->personne->ville);
        $this->assertNotNull($proprietaire->personne->adresse);

        $this->assertAucuneCoordonneePersonnelle($proprietaire->entrepriseTierce->fresh());

        // Aucune synchronisation ultérieure : la localisation du contact change, pas celle de l'entreprise.
        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['ville' => 'Boké', 'adresse' => 'Centre-ville']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Boké', $proprietaire->personne->fresh()->ville);
        $this->assertAucuneCoordonneePersonnelle($proprietaire->entrepriseTierce->fresh());
    }

    public function test_une_entreprise_creee_a_la_modification_ne_reprend_aucune_localisation_du_contact(): void
    {
        $proprietaire = Proprietaire::factory()->entreprise('Ancienne SARL')->create([
            'organization_id' => $this->org->id, 'telephone' => '+224622000001', 'ville' => 'Labé', 'adresse' => 'Route du Fouta',
        ]);

        $this->actingAs($this->utilisateur())
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'Ancienne SARL', 'ville' => 'Labé', 'adresse' => 'Route du Fouta']))
            ->assertSessionHasNoErrors();

        $this->assertAucuneCoordonneePersonnelle($proprietaire->fresh()->entrepriseTierce);
    }

    /** L'entreprise créée ne porte ni téléphone ni localisation déduits du contact. */
    private function assertAucuneCoordonneePersonnelle(EntrepriseTierce $entreprise): void
    {
        foreach (['telephone', 'telephone_normalise', 'pays', 'code_pays', 'code_phone_pays', 'ville', 'quartier', 'adresse', 'latitude', 'longitude'] as $colonne) {
            $this->assertNull($entreprise->{$colonne}, "entreprises_tierces.{$colonne} ne doit pas être reprise du contact");
        }
    }

    public function test_une_correspondance_exige_un_choix_explicite_et_rien_n_est_enregistre_sans(): void
    {
        $this->entrepriseFournisseur('TRANSPORTS KALOUM');
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees())
            ->assertSessionHasErrors(['entreprise_choix' => ProprietaireEntreprise::MESSAGE_CHOIX]);

        $this->assertDatabaseCount('proprietaires', 0);
        $this->assertDatabaseMissing('personnes', ['telephone_normalise' => '224622000001']);
        $this->assertSame(1, EntrepriseTierce::count());
    }

    public function test_choisir_une_entreprise_existante_la_relie_sans_la_modifier_et_garde_son_principal(): void
    {
        $entreprise = $this->entrepriseFournisseur('Transports KALOUM SA', ['identifiant_fiscal' => 'GN 555']);
        $principal = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224655000001']);
        EntrepriseInterlocuteur::forceCreate(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $principal->id, 'est_principal' => true, 'is_active' => true]);
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['raison_sociale' => 'Autre nom', 'identifiant_fiscal' => 'gn-555', 'entreprise_choix' => $entreprise->id]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Propriétaire créé avec succès et relié à l'entreprise Transports KALOUM SA.");

        $proprietaire = Proprietaire::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame($entreprise->id, $proprietaire->entreprise_tierce_id);
        // D5 : l'entreprise fait foi, la saisie ne la renomme pas.
        $this->assertSame('Transports KALOUM SA', $proprietaire->raison_sociale);
        $this->assertSame('Transports KALOUM SA', $entreprise->fresh()->raison_sociale);
        // D9 : principal en place conservé, le contact est ajouté sans rôle de principal.
        $this->assertTrue(EntrepriseInterlocuteur::where('personne_id', $principal->id)->firstOrFail()->est_principal);
        $this->assertDatabaseHas('entreprise_interlocuteurs', ['entreprise_tierce_id' => $entreprise->id, 'personne_id' => $proprietaire->personne_id, 'est_principal' => false]);
        $this->assertSame(2, EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)->count());
    }

    public function test_creer_une_nouvelle_entreprise_malgre_une_correspondance_par_raison_sociale(): void
    {
        $this->entrepriseFournisseur('Transports Kaloum');
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['entreprise_choix' => ProprietaireEntreprise::CHOIX_NOUVELLE]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, EntrepriseTierce::where('raison_sociale', 'Transports Kaloum')->count());
    }

    public function test_un_numero_d_entreprise_de_la_liste_interdit_d_en_creer_une_seconde(): void
    {
        $this->entrepriseFournisseur('Société Boké', ['telephone' => '+224624111111', 'telephone_normalise' => '224624111111']);
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['telephone_entreprise' => '624111111', 'entreprise_choix' => ProprietaireEntreprise::CHOIX_NOUVELLE]))
            ->assertSessionHasErrors('telephone_entreprise');

        $this->assertDatabaseCount('proprietaires', 0);
    }

    public function test_le_contact_deja_interlocuteur_garde_son_lien_sans_doublon(): void
    {
        $entreprise = $this->entrepriseFournisseur('Société Boké');
        $contact = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622000001', 'telephone_normalise' => '224622000001']);
        $lien = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $contact->id, 'est_principal' => false, 'is_active' => false]);
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['raison_sociale' => 'Société Boké', 'entreprise_choix' => $entreprise->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)->count());
        // Un lien désactivé reste désactivé : la désactivation était un geste explicite.
        $this->assertFalse($lien->fresh()->is_active);
        $this->assertSame($contact->id, Proprietaire::firstOrFail()->personne_id);
    }

    public function test_une_entreprise_ne_porte_qu_un_proprietaire(): void
    {
        $user = $this->utilisateur();
        $premier = $this->proprietaireRelie($user);

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['telephone' => '622000002', 'entreprise_choix' => $premier->entreprise_tierce_id]))
            ->assertSessionHasErrors('entreprise_choix');

        $this->assertSame(1, Proprietaire::where('entreprise_tierce_id', $premier->entreprise_tierce_id)->count());
    }

    // ── Visibilité (D6) ─────────────────────────────────────────────────────

    public function test_telephone_d_une_entreprise_non_visible_message_neutre_et_arret(): void
    {
        $this->entrepriseFournisseur('Secret SARL', ['telephone' => '+224624111111', 'telephone_normalise' => '224624111111']);
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('proprietaires.store'), $this->donnees(['telephone_entreprise' => '624111111']))
            ->assertSessionHasErrors(['telephone_entreprise' => MessagesContacts::NUMERO_NON_VISIBLE]);

        $this->assertDatabaseCount('proprietaires', 0);

        $this->actingAs($user)
            ->getJson(route('proprietaires.entreprises-correspondantes', ['telephone_entreprise' => '624111111', 'code_pays' => 'GN']))
            ->assertOk()
            ->assertJson(['statut' => 'indisponible', 'champ' => 'telephone_entreprise', 'correspondances' => []])
            ->assertDontSee('Secret');
    }

    public function test_nif_d_une_entreprise_non_visible_message_neutre_et_arret(): void
    {
        $this->entrepriseFournisseur('Secret SARL', ['identifiant_fiscal' => 'NIF 999']);

        $this->actingAs($this->utilisateur())
            ->post(route('proprietaires.store'), $this->donnees(['identifiant_fiscal' => 'nif-999']))
            ->assertSessionHasErrors(['identifiant_fiscal' => MessagesContacts::NIF_NON_VISIBLE]);

        $this->assertDatabaseCount('proprietaires', 0);
    }

    public function test_raison_sociale_seule_d_une_entreprise_non_visible_ne_revele_rien(): void
    {
        $cachee = $this->entrepriseFournisseur('Transports Kaloum');
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->getJson(route('proprietaires.entreprises-correspondantes', ['raison_sociale' => 'Transports Kaloum']))
            ->assertOk()
            ->assertJson(['statut' => 'aucune', 'correspondances' => []]);

        $this->actingAs($user)->post(route('proprietaires.store'), $this->donnees())->assertSessionHasNoErrors();

        $this->assertNotSame($cachee->id, Proprietaire::firstOrFail()->entreprise_tierce_id);
    }

    public function test_identifiant_d_entreprise_force_non_visible_ou_d_une_autre_organisation_refuse(): void
    {
        $cachee = $this->entrepriseFournisseur('Secret SARL');
        $ailleurs = $this->entrepriseFournisseur('Ailleurs SA', [], Organization::factory()->create());
        $user = $this->utilisateur();

        foreach ([$cachee->id, $ailleurs->id] as $id) {
            $this->actingAs($user)
                ->post(route('proprietaires.store'), $this->donnees(['raison_sociale' => 'Nouvelle', 'entreprise_choix' => $id]))
                ->assertSessionHasErrors(['entreprise_choix' => 'Entreprise introuvable. Relancez la recherche.']);
        }

        $this->assertDatabaseCount('proprietaires', 0);
    }

    public function test_la_recherche_liste_les_correspondances_visibles_avec_leurs_criteres(): void
    {
        $entreprise = $this->entrepriseFournisseur('Transports Kaloum', ['identifiant_fiscal' => 'NIF1']);
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read']);

        $this->actingAs($user)
            ->getJson(route('proprietaires.entreprises-correspondantes', ['raison_sociale' => 'transports  KALOUM', 'identifiant_fiscal' => 'nif 1']))
            ->assertOk()
            ->assertJsonPath('statut', 'correspondances')
            ->assertJsonPath('correspondances.0.id', $entreprise->id)
            ->assertJsonPath('correspondances.0.criteres', ['identifiant_fiscal', 'raison_sociale'])
            ->assertJsonPath('correspondances.0.selectionnable', true);
    }

    public function test_la_recherche_exige_le_droit_de_creer_ou_de_modifier(): void
    {
        $this->actingAs($this->utilisateur(['proprietaires.read']))
            ->getJson(route('proprietaires.entreprises-correspondantes', ['raison_sociale' => 'X']))
            ->assertForbidden();
    }

    // ── D5 : la fiche Entreprise fait foi ──────────────────────────────────

    public function test_renommer_l_entreprise_depuis_le_formulaire_fournisseur_se_repercute_sur_le_proprietaire(): void
    {
        $user = $this->utilisateur([...self::DROITS, 'fournisseurs.read', 'fournisseurs.update']);
        $proprietaire = $this->proprietaireRelie($user);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $proprietaire->entreprise_tierce_id]);

        $this->actingAs($user)
            ->put(route('fournisseurs.update', $fournisseur), [
                'raison_sociale' => 'Kaloum Logistique', 'phone' => '624777777', 'code_pays' => 'GN', 'ville' => 'Conakry',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Kaloum Logistique', $proprietaire->fresh()->raison_sociale);
        $this->assertSame('Kaloum Logistique', $proprietaire->fresh()->nom_affichage);
    }

    // ── D7 : identité d'une entreprise partagée ─────────────────────────────

    public function test_entreprise_seulement_proprietaire_renommee_avec_proprietaires_update(): void
    {
        $user = $this->utilisateur();
        $proprietaire = $this->proprietaireRelie($user);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'Kaloum Transit', 'identifiant_fiscal' => 'NIF-7']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Kaloum Transit', $proprietaire->entrepriseTierce->fresh()->raison_sociale);
        $this->assertSame('NIF-7', $proprietaire->entrepriseTierce->fresh()->identifiant_fiscal);
        $this->assertSame('Kaloum Transit', $proprietaire->fresh()->raison_sociale);
    }

    public function test_entreprise_partagee_sans_contacts_update_refusee_et_inchangee(): void
    {
        $user = $this->utilisateur();
        $proprietaire = $this->proprietaireRelie($user);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $proprietaire->entreprise_tierce_id]);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'Renommée', 'confirmer_modification_entreprise' => true]))
            ->assertSessionHasErrors(['raison_sociale' => ProprietaireEntreprise::MESSAGE_DROIT_PARTAGEE]);

        $this->assertSame('Transports Kaloum', $proprietaire->entrepriseTierce->fresh()->raison_sociale);

        $this->actingAs($user)
            ->get(route('proprietaires.edit', $proprietaire))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('entreprise.partagee', true)
                ->where('entreprise.peut_modifier_identite', false));
    }

    public function test_entreprise_partagee_avec_contacts_update_exige_la_confirmation(): void
    {
        $user = $this->utilisateur([...self::DROITS, 'contacts.update']);
        $proprietaire = $this->proprietaireRelie($user);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $proprietaire->entreprise_tierce_id]);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'Renommée']))
            ->assertSessionHasErrors(['confirmer_modification_entreprise' => ProprietaireEntreprise::MESSAGE_CONFIRMATION]);
        $this->assertSame('Transports Kaloum', $proprietaire->entrepriseTierce->fresh()->raison_sociale);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'Renommée', 'confirmer_modification_entreprise' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renommée', $proprietaire->entrepriseTierce->fresh()->raison_sociale);
        $this->assertSame('Renommée', $proprietaire->fresh()->raison_sociale);
    }

    public function test_identite_inchangee_d_une_entreprise_partagee_n_exige_rien(): void
    {
        $user = $this->utilisateur();
        $proprietaire = $this->proprietaireRelie($user);
        // Raison sociale écrite ailleurs en majuscules : la renvoyer telle quelle n'est pas un changement.
        $proprietaire->entrepriseTierce->update(['raison_sociale' => 'TRANSPORTS KALOUM']);
        Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $proprietaire->entreprise_tierce_id]);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'TRANSPORTS KALOUM', 'ville' => 'Kindia']))
            ->assertSessionHasNoErrors();

        $this->assertSame('TRANSPORTS KALOUM', $proprietaire->entrepriseTierce->fresh()->raison_sociale);
        $this->assertSame('Kindia', $proprietaire->personne->fresh()->ville);
    }

    // ── D8 : passage en personne physique ───────────────────────────────────

    public function test_passage_en_personne_physique_retire_le_lien_et_conserve_l_entreprise(): void
    {
        $user = $this->utilisateur();
        $proprietaire = $this->proprietaireRelie($user);
        $entrepriseId = $proprietaire->entreprise_tierce_id;

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['type' => 'personne_physique', 'nom' => 'Camara', 'prenom' => 'Awa']))
            ->assertSessionHasNoErrors();

        $proprietaire->refresh();
        $this->assertSame(TypeProprietaire::PERSONNE_PHYSIQUE, $proprietaire->type);
        $this->assertNull($proprietaire->entreprise_tierce_id);
        $this->assertNull($proprietaire->raison_sociale);
        $this->assertNotNull(EntrepriseTierce::find($entrepriseId));
        $this->assertDatabaseHas('entreprise_interlocuteurs', ['entreprise_tierce_id' => $entrepriseId, 'personne_id' => $proprietaire->personne_id]);
        $this->assertTrue(AuditLog::where('auditable_id', $proprietaire->id)->where('meta->origine', 'passage_personne_physique')->exists());
    }

    public function test_ancien_proprietaire_entreprise_sans_lien_relie_a_la_modification(): void
    {
        $user = $this->utilisateur();
        $proprietaire = Proprietaire::factory()->entreprise('Ancienne SARL')->create(['organization_id' => $this->org->id, 'telephone' => '+224622000001']);

        $this->actingAs($user)
            ->put(route('proprietaires.update', $proprietaire), $this->donnees(['raison_sociale' => 'Ancienne SARL']))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($proprietaire->fresh()->entreprise_tierce_id);
        $this->assertSame($proprietaire->id, Proprietaire::where('entreprise_tierce_id', $proprietaire->fresh()->entreprise_tierce_id)->value('id'));
    }

    // ── Contacts : fiche Entreprise et fiche Personne (D10) ─────────────────

    public function test_la_fiche_entreprise_porte_la_fonction_proprietaire_et_la_fiche_personne_la_mention_au_nom_de(): void
    {
        $user = $this->utilisateur();
        $proprietaire = $this->proprietaireRelie($user);

        $this->actingAs($user)
            ->get(route('contacts.entreprises.show', $proprietaire->entreprise_tierce_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fonctions.0.fonction', 'proprietaire')
                ->where('fonctions.0.id', $proprietaire->id)
                ->where('interlocuteurs.0.personne_id', $proprietaire->personne_id));

        $this->actingAs($user)
            ->get(route('contacts.personnes.show', $proprietaire->personne_id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fonctions.0.fonction', 'proprietaire')
                ->where('fonctions.0.au_nom_de', 'Transports Kaloum')
                ->where('fonctions.0.entreprise_lien', "/backoffice/contacts/entreprises/{$proprietaire->entreprise_tierce_id}"));
    }

    public function test_sans_proprietaires_read_l_entreprise_seulement_proprietaire_reste_invisible(): void
    {
        $proprietaire = $this->proprietaireRelie($this->utilisateur());

        $this->actingAs($this->utilisateur(['contacts.read', 'fournisseurs.read']))
            ->get(route('contacts.entreprises.show', $proprietaire->entreprise_tierce_id))
            ->assertNotFound();
    }

    // ── Journal ─────────────────────────────────────────────────────────────

    public function test_le_journal_ne_contient_ni_nom_ni_telephone(): void
    {
        $proprietaire = $this->proprietaireRelie($this->utilisateur(), ['telephone_entreprise' => '624111111']);

        $journal = AuditLog::where('auditable_id', $proprietaire->id)->get();
        $this->assertNotEmpty($journal);
        foreach ($journal as $entree) {
            $texte = json_encode([$entree->old_values, $entree->new_values, $entree->meta]);
            $this->assertStringNotContainsString('Kaloum', $texte);
            $this->assertStringNotContainsString('622000001', $texte);
            $this->assertStringNotContainsString('624111111', $texte);
        }
    }
}
