<?php

namespace Tests\Feature;

use App\Enums\CategorieVehicule;
use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Le propriétaire n'appartient pas au partage de commission : son montant vient du
 * barème Paramètres → Commissions, jamais du payload équipe. Les livreurs se
 * partagent 100 % PAR CATÉGORIE (equipe_livraison_partages_categorie, cf.
 * validPayloadAvecPartage()) — chaque catégorie ayant son propre barème Livraison,
 * son partage entre livreurs est lui aussi défini indépendamment.
 */
class EquipeLivraisonTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'equipes-livraison.read',
            'equipes-livraison.create',
            'equipes-livraison.update',
            'equipes-livraison.delete',
        ]);

        // Barème Livreur (100 GNF/unité, portée globale) requis pour valider les montants
        // fixes du partage : CommissionPartageLivraisonValidator compare la somme des
        // montants membres à ce barème — 100 est choisi pour que les valeurs déjà
        // utilisées par ces tests (autrefois des %) restent numériquement inchangées.
        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livreur — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 100,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function validPayload(string $proprietaireId, array $overrides = []): array
    {
        return array_merge([
            'nom' => 'Équipe Test',
            'is_active' => true,
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'proprietaire_id' => $proprietaireId,
            'membres' => [
                [
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'ordre' => 0,
                ],
            ],
        ], $overrides);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)
            ->get(route('equipes-livraison.index'))
            ->assertStatus(200);
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('equipes-livraison.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->makeAdminUser();

        $this->actingAs($user)
            ->get(route('equipes-livraison.index'))
            ->assertStatus(403);
    }

    // ── store ────────────────────────────────────────────────────────────────

    public function test_store_creates_equipe_avec_proprietaire_meme_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'vehicule_id' => $vehicule->id,
        ]);
    }

    public function test_store_persiste_telephone_normalise_e164(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('personnes', [
            'telephone' => '+224620000001',
            'organization_id' => $this->org->id,
        ]);
    }

    // ── nom_complet facultatif ───────────────────────────────────────────────

    public function test_store_creates_membre_avec_uniquement_un_surnom(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Chauffeur 1',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('livreurs', [
            'nom_complet' => 'Chauffeur 1',
        ]);
        $this->assertDatabaseHas('personnes', [
            'telephone' => '+224620000001',
            'nom' => null,
            'prenom' => null,
        ]);
    }

    public function test_store_creates_membre_sans_aucun_nom(): void
    {
        // nom_complet est facultatif dans le formulaire (seuls téléphone et rôle
        // sont obligatoires), mais jamais laissé vide en base : repli sur la
        // désignation par défaut "Chauffeur-1 {véhicule}" — cf. Livreur::designationParDefaut().
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [[
                    'livreur_id' => null,
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('livreurs', [
            'nom_complet' => "Chauffeur-1 {$vehicule->nom_vehicule}",
        ]);
        $this->assertDatabaseHas('personnes', [
            'telephone' => '+224620000001',
        ]);
    }

    public function test_store_numbers_designation_par_defaut_par_role(): void
    {
        // Deux convoyeurs sans nom sur la même équipe → numérotés par position,
        // pas tous "Convoyeur-1" — cf. Livreur::designationParDefaut().
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [
                    [
                        'livreur_id' => null,
                        'nom_complet' => 'Mamadou Diallo',
                        'telephone' => '+224620000001', 'role' => 'chauffeur',
                        'ordre' => 0,
                    ],
                    [
                        'livreur_id' => null,
                        'telephone' => '+224620000002', 'role' => 'convoyeur',
                        'ordre' => 1,
                    ],
                    [
                        'livreur_id' => null,
                        'telephone' => '+224620000003', 'role' => 'convoyeur',
                        'ordre' => 2,
                    ],
                ],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('livreurs', [
            'nom_complet' => "Convoyeur-1 {$vehicule->nom_vehicule}",
        ]);
        $this->assertDatabaseHas('personnes', ['telephone' => '+224620000002']);
        $this->assertDatabaseHas('livreurs', [
            'nom_complet' => "Convoyeur-2 {$vehicule->nom_vehicule}",
        ]);
        $this->assertDatabaseHas('personnes', ['telephone' => '+224620000003']);
    }

    public function test_store_creates_plusieurs_membres_avec_des_surnoms_differents(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [
                    [
                        'livreur_id' => null,
                        'nom_complet' => 'Petit Moussa',
                        'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                    ],
                    [
                        'livreur_id' => null,
                        'nom_complet' => 'Doudou',
                        'telephone' => '+224620000002', 'role' => 'convoyeur', 'ordre' => 1,
                    ],
                ],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('livreurs', ['nom_complet' => 'Petit Moussa']);
        $this->assertDatabaseHas('personnes', ['telephone' => '+224620000001']);
        $this->assertDatabaseHas('livreurs', ['nom_complet' => 'Doudou']);
        $this->assertDatabaseHas('personnes', ['telephone' => '+224620000002']);
    }

    public function test_update_ne_touche_jamais_nom_et_prenom_du_livreur_existant(): void
    {
        // Un livreur peut avoir nom/prenom renseignés par un autre moyen (ex :
        // auto-inscription) — l'équipe (qui n'envoie jamais nom/prenom) ne doit
        // jamais les écraser.
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $livreur = Livreur::whereHas('personne', fn ($q) => $q->where('telephone', '+224620000001'))->firstOrFail();
        $livreur->personne->update(['nom' => 'BARRY', 'prenom' => 'Issa']);

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->first();

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [[
                    'livreur_id' => $livreur->id,
                    'nom_complet' => 'Nouveau Surnom',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $livreur->refresh();
        $this->assertSame('BARRY', $livreur->nom);
        $this->assertSame('Issa', $livreur->prenom);
        $this->assertSame('Nouveau Surnom', $livreur->nom_complet);
    }

    public function test_store_echoue_si_telephone_contient_lettres(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224abc123456', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    public function test_store_echoue_si_telephone_longueur_incorrecte(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        // 8 chiffres locaux au lieu de 9
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+22462012345', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    public function test_store_derive_toujours_le_proprietaire_depuis_le_vehicule(): void
    {
        // Le propriétaire de l'équipe n'est jamais celui envoyé par le client : il est
        // systématiquement dérivé de Vehicule::proprietaire_id côté serveur — même absent du
        // payload, même si le client tente d'en envoyer un autre (cf. store()).
        $vraiProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($vraiProprietaire->id);
        $autreProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $payload = $this->validPayload($autreProprietaire->id, ['vehicule_id' => $vehicule->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $payload)
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $vraiProprietaire->id,
        ]);
        $this->assertDatabaseMissing('equipes_livraison', [
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $autreProprietaire->id,
        ]);
    }

    public function test_store_ignore_un_proprietaire_id_dune_autre_organisation_envoye_par_le_client(): void
    {
        // Même un proprietaire_id appartenant à une AUTRE organisation, envoyé par un client
        // malveillant, n'a aucun effet : le serveur ne fait jamais confiance à cette valeur.
        $vraiProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($vraiProprietaire->id);
        $autreOrg = Organization::factory()->create();
        $proprietaireAutreOrg = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaireAutreOrg->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $vraiProprietaire->id,
        ]);
    }

    public function test_store_autorise_vehicule_interne_sans_proprietaire(): void
    {
        $vehiculeInterne = $this->makeVehiculeInterne();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload(0, [
                'vehicule_id' => $vehiculeInterne->id,
                'proprietaire_id' => null,
                'nom' => 'Équipe Interne',
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehiculeInterne->id,
            'proprietaire_id' => null,
        ]);
    }

    public function test_store_autorise_partage_proprietaire_sur_vehicule_interne_avec_proprietaire_configure(): void
    {
        // Régression : un véhicule "interne" (categorie=INTERNE) peut tout à fait avoir un
        // propriétaire réel configuré (le propriétaire interne par défaut de l'organisation, cf.
        // Proprietaire::interneParDefautId) — l'équipe doit se créer avec ce propriétaire exactement
        // comme pour un véhicule partenaire (cas rapporté : véhicule "Abdoulaye").
        $proprietaireInterne = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['proprietaire_interne_id' => $proprietaireInterne->id]);
        $vehiculeInterne = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireInterne->id,
            'categorie' => CategorieVehicule::INTERNE,
        ]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaireInterne->id, [
                'vehicule_id' => $vehiculeInterne->id,
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'vehicule_id' => $vehiculeInterne->id,
            'proprietaire_id' => $proprietaireInterne->id,
        ]);
    }

    public function test_store_echoue_sans_membre(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'membres' => [],
            ]))
            ->assertSessionHasErrors('membres');
    }

    public function test_store_fails_si_vehicule_deja_utilise_dans_meme_org(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        // Même véhicule, membre différent pour éviter conflit livreur
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Ibrahima Barry',
                    'telephone' => '+224620000002', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('vehicule_id');
    }

    public function test_store_autorise_meme_nom_apres_suppression_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule1 = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule1->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->first();

        $this->actingAs($this->user)
            ->delete(route('equipes-livraison.destroy', $equipe))
            ->assertRedirectContains('/backoffice/vehicules/');

        // Le même nom doit être disponible après suppression (soft-delete)
        // vehicule1 est libéré après le soft-delete de l'équipe
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule1->id,
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000002', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');
    }

    public function test_store_fails_si_livreur_deja_dans_autre_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        // Même livreur (+224620000001) dans une autre équipe
        $vehicule2 = $this->makeVehicule();
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule2->id,
                'nom' => 'Équipe Deux',
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    public function test_update_autorise_membres_deja_dans_meme_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->first();

        // Mettre à jour en conservant le même membre → doit réussir
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');
    }

    public function test_update_fails_si_livreur_deja_dans_autre_equipe(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule1 = $this->makeVehicule();
        $vehicule2 = $this->makeVehicule();

        // Equipe 1 avec +224620000001
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, ['vehicule_id' => $vehicule1->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        // Equipe 2 avec +224620000002
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule2->id,
                'nom' => 'Équipe Deux',
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Ibrahima Barry',
                    'telephone' => '+224620000002', 'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe2 = EquipeLivraison::where('organization_id', $this->org->id)
            ->where('vehicule_id', $vehicule2->id)->first();

        // Tenter d'affecter le livreur de l'équipe 1 à l'équipe 2
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe2), $this->validPayload($proprietaire->id, [
                'vehicule_id' => $vehicule2->id,
                'nom' => 'Équipe Deux',
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'ordre' => 0,
                ]],
            ]))
            ->assertSessionHasErrors('membres.0.telephone');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_modifie_equipe_et_redirige(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $nouveauProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($nouveauProprietaire->id);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($nouveauProprietaire->id, [
                'vehicule_id' => $vehicule->id,
                'membres' => [[
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'id' => $equipe->id,
            'proprietaire_id' => $nouveauProprietaire->id,
            'vehicule_id' => $vehicule->id,
        ]);
    }

    public function test_update_ignore_un_proprietaire_id_dune_autre_organisation_envoye_par_le_client(): void
    {
        $vraiProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($vraiProprietaire->id);
        $equipe = $this->makeEquipe($vraiProprietaire->id);

        $autreOrg = Organization::factory()->create();
        $autreProprietaire = Proprietaire::factory()->create(['organization_id' => $autreOrg->id]);

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayload($autreProprietaire->id, ['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'id' => $equipe->id,
            'proprietaire_id' => $vraiProprietaire->id,
        ]);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_supprime_equipe_et_redirige(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $equipe = $this->makeEquipe($proprietaire->id);

        $this->actingAs($this->user)
            ->delete(route('equipes-livraison.destroy', $equipe))
            ->assertRedirect(route('equipes-livraison.index'));

        $this->assertSoftDeleted('equipes_livraison', ['id' => $equipe->id]);
    }

    // ── Partage Livraison PAR CATÉGORIE ──────────────────────────────────────
    // Plus un seul part_pourcentage par membre valable pour toute la commande —
    // le payload envoie partages_categorie[], corrélé aux membres via
    // membre_ordre (index dans membres[], jamais livreur_id : un nouveau
    // membre n'a pas encore d'id côté client).

    private function categorieDefaut(): Categorie
    {
        return Categorie::firstOrCreate(
            ['organization_id' => $this->org->id, 'nom' => 'Sachets'],
            ['statut' => 'actif'],
        );
    }

    private function validPayloadAvecPartage(array $overrides = []): array
    {
        $categorie = $this->categorieDefaut();

        return array_merge([
            'is_active' => true,
            'processus_code' => CommissionProcessus::CODE_VENTE,
            'membres' => [
                [
                    'livreur_id' => null,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001',
                    'role' => 'chauffeur',
                    'ordre' => 0,
                ],
            ],
            'partages_categorie' => [
                [
                    'categorie_id' => $categorie->id,
                    'parts' => [
                        ['membre_ordre' => 0, 'montant_unitaire' => 100],
                    ],
                ],
            ],
        ], $overrides);
    }

    public function test_store_avec_partage_ne_alimente_pas_les_champs_hors_partage(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage(['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->firstOrFail();
        $membre = EquipeLivreur::where('equipe_id', $equipe->id)->firstOrFail();

        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipe->id,
            'categorie_id' => $this->categorieDefaut()->id,
            'livreur_id' => $membre->livreur_id,
            'montant_unitaire' => 100,
        ]);
        // Jamais alimentés par ce contrôleur : le propriétaire vient du
        // barème (Paramètres → Commissions), pas de ce contrôleur ; le partage
        // vit désormais par catégorie, jamais sur equipe_livreurs.taux_commission.
        $this->assertSame(0.0, (float) $membre->taux_commission);
        $this->assertSame(0.0, (float) $equipe->commission_unitaire_par_pack);
        $this->assertNull($equipe->montant_par_pack_proprietaire);
        $this->assertNull($equipe->taux_commission_proprietaire);
    }

    public function test_store_avec_partage_derive_toujours_le_proprietaire_depuis_le_vehicule(): void
    {
        $vraiProprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($vraiProprietaire->id);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage(['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertDatabaseHas('equipes_livraison', [
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $vraiProprietaire->id,
        ]);
    }

    public function test_store_creates_equipe_avec_partage_distinct_par_categorie(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);
        $sachets = $this->categorieDefaut();
        $bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteilles', 'statut' => 'actif']);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage([
                'vehicule_id' => $vehicule->id,
                'membres' => [
                    [
                        'livreur_id' => null, 'nom_complet' => 'Mamadou Diallo',
                        'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                    ],
                    [
                        'livreur_id' => null, 'nom_complet' => 'Ibrahima Barry',
                        'telephone' => '+224620000002', 'role' => 'convoyeur', 'ordre' => 1,
                    ],
                ],
                // Partage DIFFÉRENT selon la catégorie — exactement le scénario
                // métier motivant cette évolution.
                'partages_categorie' => [
                    [
                        'categorie_id' => $sachets->id,
                        'parts' => [
                            ['membre_ordre' => 0, 'montant_unitaire' => 50],
                            ['membre_ordre' => 1, 'montant_unitaire' => 50],
                        ],
                    ],
                    [
                        'categorie_id' => $bouteilles->id,
                        'parts' => [
                            ['membre_ordre' => 0, 'montant_unitaire' => 60],
                            ['membre_ordre' => 1, 'montant_unitaire' => 40],
                        ],
                    ],
                ],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->firstOrFail();
        $chauffeur = EquipeLivreur::where('equipe_id', $equipe->id)->where('role', 'chauffeur')->firstOrFail();
        $convoyeur = EquipeLivreur::where('equipe_id', $equipe->id)->where('role', 'convoyeur')->firstOrFail();

        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipe->id, 'categorie_id' => $sachets->id,
            'livreur_id' => $chauffeur->livreur_id, 'montant_unitaire' => 50,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipe->id, 'categorie_id' => $bouteilles->id,
            'livreur_id' => $chauffeur->livreur_id, 'montant_unitaire' => 60,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipe->id, 'categorie_id' => $bouteilles->id,
            'livreur_id' => $convoyeur->livreur_id, 'montant_unitaire' => 40,
        ]);
    }

    public function test_store_echoue_si_somme_des_parts_depasse_100_pour_une_categorie(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);
        $categorie = $this->categorieDefaut();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage([
                'vehicule_id' => $vehicule->id,
                'membres' => [
                    [
                        'livreur_id' => null, 'nom_complet' => 'A',
                        'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                    ],
                    [
                        'livreur_id' => null, 'nom_complet' => 'B',
                        'telephone' => '+224620000002', 'role' => 'convoyeur', 'ordre' => 1,
                    ],
                ],
                'partages_categorie' => [[
                    'categorie_id' => $categorie->id,
                    'parts' => [
                        ['membre_ordre' => 0, 'montant_unitaire' => 70],
                        ['membre_ordre' => 1, 'montant_unitaire' => 50],
                    ],
                ]],
            ]))
            ->assertStatus(422);
    }

    public function test_store_echoue_si_somme_des_parts_inferieure_a_100_pour_une_categorie(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);
        $categorie = $this->categorieDefaut();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage([
                'vehicule_id' => $vehicule->id,
                'partages_categorie' => [[
                    'categorie_id' => $categorie->id,
                    'parts' => [
                        ['membre_ordre' => 0, 'montant_unitaire' => 60],
                    ],
                ]],
            ]))
            ->assertStatus(422);
    }

    /**
     * Plus de borne supérieure par membre (un montant fixe n'est jamais "hors
     * bornes" en soi, seule la somme compte) — mais le montant reste un entier
     * positif : une valeur négative est rejetée par la validation de champ.
     */
    public function test_store_rejette_un_montant_negatif(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);
        $categorie = $this->categorieDefaut();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage([
                'vehicule_id' => $vehicule->id,
                'partages_categorie' => [[
                    'categorie_id' => $categorie->id,
                    'parts' => [
                        ['membre_ordre' => 0, 'montant_unitaire' => -50],
                    ],
                ]],
            ]))
            ->assertSessionHasErrors('partages_categorie.0.parts.0.montant_unitaire');
    }

    public function test_store_rejette_un_membre_ordre_qui_ne_correspond_a_aucun_membre(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);
        $categorie = $this->categorieDefaut();

        // Un seul membre (index 0), mais le partage référence l'index 5 : inexistant.
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage([
                'vehicule_id' => $vehicule->id,
                'partages_categorie' => [[
                    'categorie_id' => $categorie->id,
                    'parts' => [
                        ['membre_ordre' => 5, 'montant_unitaire' => 100],
                    ],
                ]],
            ]))
            ->assertSessionHasErrors('partages_categorie.0.parts.0.membre_ordre');
    }

    public function test_update_avec_partage_conserve_les_champs_hors_partage_a_leur_valeur_par_defaut(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage(['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayloadAvecPartage(['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe->refresh();
        $this->assertSame(0.0, (float) $equipe->commission_unitaire_par_pack);
        $this->assertNull($equipe->montant_par_pack_proprietaire);
        $this->assertNull($equipe->taux_commission_proprietaire);
    }

    public function test_update_remplace_integralement_le_partage_categorie_precedent(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->makeVehicule($proprietaire->id);
        $categorie = $this->categorieDefaut();

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), $this->validPayloadAvecPartage(['vehicule_id' => $vehicule->id]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('organization_id', $this->org->id)->firstOrFail();
        $membre = EquipeLivreur::where('equipe_id', $equipe->id)->firstOrFail();

        // Modifier : même membre, mais nouvelle part pour la même catégorie — ne doit
        // jamais laisser coexister DEUX lignes ACTIVES (ancienne et nouvelle). Versionné
        // (jamais de delete) : l'ancienne ligne est fermée (effective_to renseigné), pas
        // supprimée — pour qu'une relance de commission historique puisse encore la
        // résoudre à sa date réelle.
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), $this->validPayloadAvecPartage([
                'vehicule_id' => $vehicule->id,
                'membres' => [[
                    'livreur_id' => $membre->livreur_id,
                    'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                ]],
            ]))
            ->assertRedirectContains('/backoffice/vehicules/');

        $this->assertEquals(
            1,
            EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
                ->where('categorie_id', $categorie->id)
                ->whereNull('effective_to')
                ->count(),
        );
        $this->assertEquals(
            2,
            EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)
                ->where('categorie_id', $categorie->id)
                ->count(),
            'L\'ancienne version reste en base (fermée), jamais supprimée.',
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * $proprietaireId permet de rattacher le véhicule à un Proprietaire précis créé par
     * l'appelant — le propriétaire d'une équipe est désormais toujours dérivé de
     * Vehicule::proprietaire_id côté serveur (cf. EquipeLivraisonController), jamais du payload.
     */
    private function makeVehicule(?string $proprietaireId = null): Vehicule
    {
        $proprietaireId ??= Proprietaire::factory()->create(['organization_id' => $this->org->id])->id;

        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireId,
        ]);
    }

    /** Source de vérité = Vehicule::proprietaire_id (jamais le payload de l'équipe). */
    private function makeVehiculeInterne(): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => null,
            'categorie' => CategorieVehicule::INTERNE,
        ]);
    }

    private function makeEquipe(string $proprietaireId): EquipeLivraison
    {
        return EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireId,
            'vehicule_id' => null,
            'is_active' => true,
            'taux_commission_proprietaire' => 60,
        ]);
    }
}
