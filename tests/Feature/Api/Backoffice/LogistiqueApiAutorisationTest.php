<?php

namespace Tests\Feature\Api\Backoffice;

use App\Enums\BaseCalculLogistique;
use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommission;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionLogistique;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Sécurité des routes `v1/backoffice` (ADR 0059) : ces routes acceptaient tout jeton Sanctum, alors
 * que le login standard en délivre à tout compte. Un compte sans permission pouvait valider une
 * réception et déclencher les commissions. Les règles sont celles du back-office web
 * (TransfertLogistiquePolicy), avec un contrôle d'organisation que le passe-droit du super
 * administrateur ne contourne jamais.
 */
class LogistiqueApiAutorisationTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $source;

    private Site $destination;

    private Vehicule $vehicule;

    private EquipeLivraison $equipe;

    private Produit $produit;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        $this->source = $this->site($this->org, 'Source');
        $this->destination = $this->site($this->org, 'Destination');
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
            'capacite_packs' => 500,
        ]);
        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe',
            'is_active' => true,
        ]);
        $this->vehicule->update(['equipe_livraison_id' => $this->equipe->id]);
        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Eau']);
        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Eau', 'categorie_id' => $this->categorie->id], ['prix_vente' => 5000]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function routesProvider(): array
    {
        return [
            'statistiques' => ['GET', 'stats'],
            'ressources' => ['GET', 'logistique.ressources'],
            'liste des transferts' => ['GET', 'logistique.transferts.index'],
            'création' => ['POST', 'logistique.transferts.store'],
            'détail' => ['GET', 'logistique.transferts.show'],
            'saisie de réception' => ['PUT', 'logistique.transferts.reception'],
            'validation de réception' => ['POST', 'logistique.transferts.valider-reception'],
            'validation administrateur' => ['POST', 'logistique.transferts.validation-admin'],
            'liste des commissions' => ['GET', 'logistique.commissions.index'],
            'détail de commission' => ['GET', 'logistique.commissions.show'],
        ];
    }

    #[DataProvider('routesProvider')]
    public function test_un_compte_sans_permission_est_refuse(string $methode, string $route): void
    {
        $this->appeler($this->compte(), $methode, $route)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Accès non autorisé.']);
    }

    #[DataProvider('routesProvider')]
    public function test_un_compte_sans_organisation_est_refuse_meme_avec_les_permissions(string $methode, string $route): void
    {
        $compte = User::factory()->create(['organization_id' => null]);
        $compte->givePermissionTo(['logistique.read', 'logistique.create', 'logistique.update', 'factures.read']);

        $this->appeler($compte, $methode, $route)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Accès non autorisé.']);
    }

    #[DataProvider('routesProvider')]
    public function test_un_super_administrateur_sans_organisation_est_refuse(string $methode, string $route): void
    {
        $compte = User::factory()->create(['organization_id' => null]);
        $compte->assignRole('super_admin');

        $this->appeler($compte, $methode, $route)->assertForbidden();
    }

    public function test_un_jeton_de_compte_ordinaire_ne_valide_rien_et_ne_genere_aucune_commission(): void
    {
        $this->configurerBareme();
        $transfert = $this->transfert(StatutTransfert::RECEPTION);
        $jeton = $this->compte()->createToken('application-client')->plainTextToken;

        $this->withToken($jeton)
            ->postJson(route('api.backoffice.logistique.transferts.validation-admin', $transfert), ['decision' => 'accord'])
            ->assertForbidden();

        $transfert->refresh();
        $this->assertNull($transfert->validation_reception);
        $this->assertNull($transfert->validated_by);
        $this->assertSame(0, $this->nombreEnveloppes($transfert));
    }

    public function test_une_reception_refusee_ne_change_ni_le_statut_ni_la_commission(): void
    {
        $this->configurerBareme();
        $this->destination->update(['approbation_reception_logistique_obligatoire' => false]);
        $transfert = $this->transfert(StatutTransfert::TRANSIT);

        $this->appeler($this->compte(), 'POST', 'logistique.transferts.valider-reception', $transfert)
            ->assertForbidden();

        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
        $this->assertSame(0, $this->nombreEnveloppes($transfert));
    }

    public function test_une_saisie_refusee_ne_modifie_pas_les_quantites(): void
    {
        $transfert = $this->transfert(StatutTransfert::TRANSIT);
        $ligne = $transfert->lignes()->firstOrFail();

        $this->actingAs($this->compte())
            ->putJson(route('api.backoffice.logistique.transferts.reception', $transfert), ['lignes' => [[
                'id' => $ligne->id,
                'quantite_recue' => 1,
                'ecart_type' => TypeEcartLogistique::MANQUANT->value,
            ]]])
            ->assertForbidden();

        $this->assertSame(100, $ligne->fresh()->quantite_recue);
    }

    public function test_l_autre_organisation_est_refusee_y_compris_pour_son_super_administrateur(): void
    {
        $autre = Organization::factory()->create();
        $transfert = $this->transfert(StatutTransfert::RECEPTION);
        $commission = $this->commissionLegacy($transfert);

        $admin = User::factory()->create(['organization_id' => $autre->id]);
        $admin->assignRole('admin_entreprise');
        $superAdmin = User::factory()->create(['organization_id' => $autre->id]);
        $superAdmin->assignRole('super_admin');

        foreach ([$admin, $superAdmin] as $compte) {
            $this->actingAs($compte)
                ->getJson(route('api.backoffice.logistique.transferts.show', $transfert))
                ->assertForbidden();
            $this->actingAs($compte)
                ->getJson(route('api.backoffice.logistique.commissions.show', $commission))
                ->assertForbidden();
            $this->actingAs($compte)
                ->postJson(route('api.backoffice.logistique.transferts.validation-admin', $transfert), ['decision' => 'accord'])
                ->assertForbidden();
            $this->actingAs($compte)
                ->getJson(route('api.backoffice.logistique.transferts.index'))
                ->assertOk()
                ->assertJsonCount(0);
            $this->actingAs($compte)
                ->getJson(route('api.backoffice.logistique.commissions.index'))
                ->assertOk()
                ->assertJsonCount(0);
        }

        $this->assertNull($transfert->fresh()->validation_reception);
    }

    /** Le parcours réel de l'application Pro avec le rôle seedé `admin_entreprise`. */
    public function test_l_administrateur_d_entreprise_garde_tout_le_parcours_de_l_application_pro(): void
    {
        $this->configurerBareme();
        $admin = User::factory()->create(['organization_id' => $this->org->id]);
        $admin->assignRole('admin_entreprise');
        $this->actingAs($admin);

        $this->getJson(route('api.backoffice.stats'))->assertOk()->assertJsonStructure(['total_factures', 'nb_total', 'nb_impayees']);
        $this->getJson(route('api.backoffice.logistique.ressources'))
            ->assertOk()
            ->assertJsonStructure(['user_site', 'sites', 'vehicules'])
            ->assertJsonCount(2, 'sites');

        $cree = $this->postJson(route('api.backoffice.logistique.transferts.store'), [
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->destination->id,
            'vehicule_id' => $this->vehicule->id,
            'lignes' => [['produit_id' => $this->produit->id, 'quantite_demandee' => 3]],
        ])->assertCreated();
        $this->assertNotEmpty($cree->json('reference'));

        $transfert = $this->transfert(StatutTransfert::TRANSIT);
        $this->getJson(route('api.backoffice.logistique.transferts.index'))->assertOk()->assertJsonCount(2);
        $this->getJson(route('api.backoffice.logistique.transferts.show', $transfert))
            ->assertOk()
            ->assertJsonPath('reference', $transfert->reference);

        $ligne = $transfert->lignes()->firstOrFail();
        $this->putJson(route('api.backoffice.logistique.transferts.reception', $transfert), ['lignes' => [[
            'id' => $ligne->id,
            'quantite_recue' => 100,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]]])->assertOk();
        $this->postJson(route('api.backoffice.logistique.transferts.valider-reception', $transfert))->assertOk();
        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);

        $this->postJson(route('api.backoffice.logistique.transferts.validation-admin', $transfert), ['decision' => 'accord'])
            ->assertOk();
        $this->assertSame('accord', $transfert->fresh()->validation_reception);
        $this->assertSame($admin->id, $transfert->fresh()->validated_by);
        $this->assertSame(1, $this->nombreEnveloppes($transfert));

        $commission = $this->commissionLegacy($transfert);
        $this->getJson(route('api.backoffice.logistique.commissions.index'))->assertOk()->assertJsonCount(1);
        $this->getJson(route('api.backoffice.logistique.commissions.show', $commission))
            ->assertOk()
            ->assertJsonPath('id', $commission->id);
    }

    public function test_le_super_administrateur_agit_dans_son_organisation(): void
    {
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $transfert = $this->transfert(StatutTransfert::RECEPTION);

        $this->actingAs($superAdmin)
            ->getJson(route('api.backoffice.logistique.transferts.show', $transfert))
            ->assertOk();
        $this->actingAs($superAdmin)
            ->postJson(route('api.backoffice.logistique.transferts.validation-admin', $transfert), ['decision' => 'refus', 'motif' => 'Écart'])
            ->assertOk();

        $this->assertSame('refus', $transfert->fresh()->validation_reception);
        $this->assertSame(0, $this->nombreEnveloppes($transfert));
    }

    public function test_la_validation_administrateur_garde_son_message_sur_un_transfert_non_receptionne(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id]);
        $admin->assignRole('admin_entreprise');

        $this->actingAs($admin)
            ->postJson(route('api.backoffice.logistique.transferts.validation-admin', $this->transfert(StatutTransfert::TRANSIT)), ['decision' => 'accord'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Seul un transfert réceptionné peut être validé.']);
    }

    public function test_un_agent_en_lecture_seule_consulte_sans_pouvoir_ecrire(): void
    {
        $agent = $this->compte(['logistique.read'], [$this->source]);
        $transfert = $this->transfert(StatutTransfert::TRANSIT);

        $this->actingAs($agent)->getJson(route('api.backoffice.logistique.transferts.show', $transfert))->assertOk();
        $this->actingAs($agent)->getJson(route('api.backoffice.logistique.commissions.index'))->assertOk();
        $this->actingAs($agent)->postJson(route('api.backoffice.logistique.transferts.valider-reception', $transfert))->assertForbidden();
        $this->actingAs($agent)->getJson(route('api.backoffice.logistique.ressources'))->assertForbidden();
    }

    public function test_seul_un_agent_du_site_destination_receptionne(): void
    {
        $transfert = $this->transfert(StatutTransfert::TRANSIT);

        $this->actingAs($this->compte(['logistique.read', 'logistique.update'], [$this->source]))
            ->postJson(route('api.backoffice.logistique.transferts.valider-reception', $transfert))
            ->assertForbidden();
        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);

        $this->actingAs($this->compte(['logistique.read', 'logistique.update'], [$this->destination]))
            ->postJson(route('api.backoffice.logistique.transferts.valider-reception', $transfert))
            ->assertOk();
        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
    }

    public function test_la_decision_administrateur_reste_reservee_aux_administrateurs(): void
    {
        $agent = $this->compte(['logistique.read', 'logistique.update'], [$this->destination]);
        $transfert = $this->transfert(StatutTransfert::RECEPTION);

        $this->actingAs($agent)
            ->postJson(route('api.backoffice.logistique.transferts.validation-admin', $transfert), ['decision' => 'accord'])
            ->assertForbidden();

        $this->assertNull($transfert->fresh()->validation_reception);
    }

    public function test_les_listes_d_un_agent_sont_limitees_a_ses_agences(): void
    {
        $visible = $this->transfert(StatutTransfert::TRANSIT);
        $autreSite = $this->site($this->org, 'Autre');
        $masque = $this->transfert(StatutTransfert::TRANSIT, $autreSite, $this->site($this->org, 'Autre 2'));
        $this->commissionLegacy($visible);
        $this->commissionLegacy($masque);

        $agent = $this->compte(['logistique.read'], [$this->destination]);

        $this->actingAs($agent)->getJson(route('api.backoffice.logistique.transferts.index'))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $visible->id);
        $this->actingAs($agent)->getJson(route('api.backoffice.logistique.commissions.index'))
            ->assertOk()
            ->assertJsonCount(1);
    }

    /** @return array<string, array{0: string}> */
    public static function champsEtrangersProvider(): array
    {
        return [
            'site source' => ['site_source_id'],
            'site destination' => ['site_destination_id'],
            'véhicule' => ['vehicule_id'],
            'produit' => ['lignes.0.produit_id'],
        ];
    }

    #[DataProvider('champsEtrangersProvider')]
    public function test_la_creation_refuse_une_ressource_d_une_autre_organisation(string $champ): void
    {
        $autre = Organization::factory()->create();
        $admin = User::factory()->create(['organization_id' => $this->org->id]);
        $admin->assignRole('admin_entreprise');

        $etrangers = [
            'site_source_id' => $this->site($autre, 'Étranger A')->id,
            'site_destination_id' => $this->site($autre, 'Étranger B')->id,
            'vehicule_id' => Vehicule::factory()->create(['organization_id' => $autre->id])->id,
            'lignes.0.produit_id' => $this->makeProduitAvecVariante($autre, ['nom' => 'Étranger'], ['prix_vente' => 1])->id,
        ];

        $charge = [
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->destination->id,
            'vehicule_id' => $this->vehicule->id,
            'lignes' => [['produit_id' => $this->produit->id, 'quantite_demandee' => 1]],
        ];
        data_set($charge, $champ, $etrangers[$champ]);

        $this->actingAs($admin)
            ->postJson(route('api.backoffice.logistique.transferts.store'), $charge)
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);

        $this->assertSame(0, TransfertLogistique::where('organization_id', $this->org->id)->count());
    }

    public function test_un_agent_ne_cree_un_transfert_que_depuis_son_agence(): void
    {
        $agent = $this->compte(['logistique.create'], [$this->destination]);
        $charge = [
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->site($this->org, 'Autre')->id,
            'lignes' => [['produit_id' => $this->produit->id, 'quantite_demandee' => 1]],
        ];

        $this->actingAs($agent)
            ->postJson(route('api.backoffice.logistique.transferts.store'), $charge)
            ->assertStatus(422)
            ->assertJsonValidationErrors('site_source_id');

        $charge['site_source_id'] = $this->destination->id;
        $this->actingAs($agent)
            ->postJson(route('api.backoffice.logistique.transferts.store'), $charge)
            ->assertCreated();
    }

    /** Toute nouvelle route de ces groupes doit déclarer son autorisation, sinon ce test échoue. */
    public function test_toutes_les_routes_logistique_et_statistiques_declarent_leur_autorisation(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/backoffice/logistique') || $route->uri() === 'api/v1/backoffice/stats');

        $this->assertCount(10, $routes);

        foreach ($routes as $route) {
            $this->assertNotEmpty(
                collect($route->gatherMiddleware())->filter(fn ($m) => str_starts_with((string) $m, 'backoffice.autoriser:')),
                "Route sans autorisation : {$route->uri()}",
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function appeler(User $compte, string $methode, string $route, ?TransfertLogistique $transfert = null): TestResponse
    {
        $transfert ??= $this->transfert(StatutTransfert::RECEPTION);
        $parametres = match (true) {
            str_contains($route, 'commissions.show') => $this->commissionLegacy($transfert),
            str_contains($route, 'transferts.show'), str_contains($route, 'reception'), str_contains($route, 'validation-admin') => $transfert,
            default => [],
        };

        return $this->actingAs($compte)->json($methode, route('api.backoffice.'.$route, $parametres), [
            'decision' => 'accord',
            'lignes' => [],
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<Site>  $sites
     */
    private function compte(array $permissions = [], array $sites = []): User
    {
        $compte = User::factory()->create(['organization_id' => $this->org->id]);
        $compte->givePermissionTo($permissions);

        foreach ($sites as $index => $site) {
            $compte->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $index === 0]);
        }

        return $compte->fresh();
    }

    private function site(Organization $org, string $nom): Site
    {
        return Site::create(['organization_id' => $org->id, 'nom' => $nom, 'type' => 'depot', 'localisation' => 'Conakry']);
    }

    private function transfert(StatutTransfert $statut, ?Site $source = null, ?Site $destination = null): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => ($source ?? $this->source)->id,
            'site_destination_id' => ($destination ?? $this->destination)->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => $statut,
            'date_depart_reelle' => now()->toDateString(),
            'created_by' => User::factory()->create(['organization_id' => $this->org->id])->id,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 100,
            'quantite_chargee' => 100,
            'quantite_recue' => 100,
            'ecart_type' => TypeEcartLogistique::CONFORME->value,
        ]);

        return $transfert;
    }

    private function commissionLegacy(TransfertLogistique $transfert): CommissionLogistique
    {
        return CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $this->vehicule->id,
            'base_calcul' => BaseCalculLogistique::FORFAIT,
            'valeur_base' => 1000,
            'quantite_reference' => 1,
            'montant_total' => 1000,
            'montant_verse' => 0,
            'statut' => StatutCommission::CREEE,
        ]);
    }

    private function configurerBareme(): void
    {
        $processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $processus->update(['statut' => CommissionActivationStatut::ACTIF->value]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 200,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $livreur->id, 'taux_commission' => 100]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $this->equipe->id,
            'categorie_id' => $this->categorie->id,
            'processus_id' => $processus->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 200,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function nombreEnveloppes(TransfertLogistique $transfert): int
    {
        return CommissionEnveloppe::where('source_type', TransfertLogistique::class)
            ->where('source_id', $transfert->id)
            ->count();
    }
}
