<?php

namespace Tests\Feature\Api\Client;

use App\Enums\PrestataireType;
use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptes\RattachementCompteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Compte unique back-office + espace client (ADR 0055) : l'espace client est ouvert à tout compte
 * actif, quels que soient ses rôles et ses activités. Seules les données dépendent des activités
 * rattachées au compte. Les rôles back-office ne sont ni modifiés ni exposés comme droits.
 */
class CompteUniqueEspaceClientTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-04 10:00:00');
        $this->org = Organization::factory()->create();
    }

    private function agent(string $role = 'comptable', array $attributs = []): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $agent = User::factory()->withoutTwoFactor()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224'.fake()->unique()->numerify('6########'),
            ...$attributs,
        ]);
        $agent->assignRole($role);

        return $agent->fresh();
    }

    /** Un rattachement par `user_id` attribue le rôle externe (BusinessProfileRoleObserver) : on le retire pour reproduire un compte qui l'a perdu. */
    private function sansRolesExternes(User $user): User
    {
        $user->roles()->detach(Role::whereIn('name', User::EXTERNAL_ROLES)->pluck('id'));

        return $user->fresh();
    }

    private function proprietaireDe(User $user): Proprietaire
    {
        return Proprietaire::factory()->create(['organization_id' => $this->org->id, 'user_id' => $user->id]);
    }

    private function livreurDe(User $user): Livreur
    {
        return Livreur::factory()->create(['organization_id' => $this->org->id, 'user_id' => $user->id]);
    }

    private function parrainDe(User $user, User $acteur): Parrain
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        app(RattachementCompteService::class)->rattacher($parrain, $user, $acteur);

        return $parrain->fresh();
    }

    private function consultantDe(User $user): Prestataire
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
        $consultant->forceFill(['user_id' => $user->id])->save();

        return $consultant;
    }

    private function vehicule(?Proprietaire $proprietaire = null, ?Parrain $parrain = null): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire?->id ?? Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'parrain_id' => $parrain?->id,
        ]);
    }

    private function part(Vehicule $vehicule, string $type, string $beneficiaireId, float $montant): CommissionEnveloppePart
    {
        $vente = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'validated_at' => '2026-10-20',
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $vente->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'cible_type' => $type === 'proprietaire' ? CommissionCibleType::CODE_PROPRIETAIRE : CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'montant_verse' => 0,
            'earned_at' => '2026-10-20',
            'statut' => StatutCommission::IMPAYE,
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaireId,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE,
        ]);
    }

    /** @return list<string> */
    private function idsDesPartsVues(User $user): array
    {
        Sanctum::actingAs($user, ['*']);

        return collect($this->getJson('/api/v1/mobile/commissions?date_debut=2026-10-01&date_fin=2026-10-31')->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    /**
     * Données d'autres personnes de la même organisation : propriétaire, parrain et livreur tiers,
     * avec véhicule, vente et parts. Aucune ne doit apparaître pour un compte qui n'y est pas rattaché.
     *
     * @return array{vehicule: Vehicule, vente: CommandeVente, traces: list<string>}
     */
    private function donneesDeTiers(): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = $this->vehicule($proprietaire, $parrain);
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'nom' => 'Equipe tiers', 'is_active' => true, 'taux_commission_proprietaire' => 0]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);

        $parts = [
            $this->part($vehicule, 'proprietaire', $proprietaire->id, 10000),
            $this->part($vehicule, 'parrain', $parrain->id, 2500),
            $this->part($vehicule, 'livreur', $livreur->id, 6000),
        ];
        $vente = CommandeVente::where('vehicule_id', $vehicule->id)->firstOrFail();

        return [
            'vehicule' => $vehicule,
            'vente' => $vente,
            'traces' => [
                (string) $vehicule->id, (string) $vehicule->immatriculation, (string) $proprietaire->id,
                (string) $parrain->id, (string) $livreur->id, (string) $vente->id, (string) $vente->reference,
                ...array_map(fn (CommissionEnveloppePart $p) => (string) $p->id, $parts),
            ],
        ];
    }

    /** Toutes les lectures de l'espace client : un compte sans activité reçoit 200 (vide) ou 404, jamais un 403 ni la donnée d'un autre. */
    private function assertEspaceClientSansDonneesDeTiers(User $user, array $tiers): void
    {
        Sanctum::actingAs($user, ['*']);
        $vehiculeId = $tiers['vehicule']->id;

        $lectures = [
            '/api/v1/mobile/dashboard' => 200,
            '/api/v1/mobile/commissions' => 200,
            '/api/v1/mobile/depenses/mine' => 200,
            '/api/v1/mobile/activite' => 200,
            '/api/v1/mobile/commandes/mine' => 200,
            "/api/v1/mobile/commandes/{$tiers['vente']->id}" => 404,
            '/api/v1/mobile/propositions-vehicules' => 200,
            '/api/v1/mobile/vehicules/mine' => 200,
            '/api/v1/mobile/vehicules/rattaches' => 200,
            "/api/v1/mobile/vehicules/{$vehiculeId}/commissions" => 200,
            "/api/v1/mobile/vehicules/{$vehiculeId}/frais" => 404,
            '/api/v1/mobile/profile' => 200,
            '/api/v1/mobile/parrain/synthese' => 404,
            '/api/v1/mobile/parrain/vehicules' => 404,
            '/api/v1/mobile/parrain/commissions' => 404,
            '/api/v1/mobile/parrain/paiements' => 404,
            '/api/v1/mobile/livraisons-transferts' => 200,
            '/api/gains/mine' => 200,
            '/api/livraisons/en-cours' => 200,
        ];

        foreach ($lectures as $url => $statut) {
            $reponse = $this->getJson($url);
            $this->assertSame($statut, $reponse->status(), "{$url} : {$reponse->getContent()}");

            foreach ($tiers['traces'] as $trace) {
                $this->assertStringNotContainsString($trace, $reponse->getContent(), "{$url} expose une donnée d'un autre compte.");
            }
        }
    }

    // ── Un seul compte pour les deux applications ─────────────────────────────

    public function test_le_meme_compte_sans_activite_ouvre_le_back_office_et_l_espace_client_sans_doublon(): void
    {
        $agent = $this->agent();
        $comptesAvant = User::count();

        $this->post(route('login.store'), ['telephone' => $agent->telephone, 'password' => 'password']);
        $this->assertAuthenticatedAs($agent);
        $this->post(route('logout'));

        $this->postJson(route('api.auth.login'), [
            'telephone' => $agent->telephone,
            'password' => 'password',
            'device_name' => 'nuxt',
        ])->assertOk()->assertJsonPath('user.id', $agent->id);

        $this->assertSame($comptesAvant, User::count());
        $this->assertTrue($agent->fresh()->hasBackofficeAccess());
        $this->assertSame(['comptable'], $agent->fresh()->getRoleNames()->all());
    }

    // ── Comptes sans activité ─────────────────────────────────────────────────

    public function test_agent_sans_activite_entre_dans_l_espace_client_sans_aucune_donnee(): void
    {
        $tiers = $this->donneesDeTiers();
        $agent = $this->agent();

        Sanctum::actingAs($agent, ['*']);
        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('id', $agent->id)
            ->assertJsonPath('context.client_id', null)
            ->assertJsonPath('context.proprietaire_id', null)
            ->assertJsonPath('context.livreur_id', null)
            ->assertJsonPath('context.parrain_id', null)
            ->assertJsonPath('context.consultant_ids', []);

        $this->assertEspaceClientSansDonneesDeTiers($agent, $tiers);
    }

    public function test_super_admin_entre_sans_donnee_d_un_autre_ni_permission_exposee(): void
    {
        $tiers = $this->donneesDeTiers();
        $admin = $this->agent('super_admin');

        Sanctum::actingAs($admin, ['*']);
        $me = $this->getJson(route('api.auth.me'))->assertOk();
        $this->assertArrayNotHasKey('permissions', $me->json());
        $this->assertSame(['super_admin'], $me->json('roles'));

        $this->assertEspaceClientSansDonneesDeTiers($admin, $tiers);
    }

    // ── Agent + activité : seulement ses propres données ──────────────────────

    public function test_agent_proprietaire_voit_ses_donnees_meme_sans_role_externe(): void
    {
        $agent = $this->agent();
        $proprietaire = $this->proprietaireDe($agent);
        $agent = $this->sansRolesExternes($agent);
        $vehicule = $this->vehicule($proprietaire);
        $sienne = $this->part($vehicule, 'proprietaire', $proprietaire->id, 10000);
        $this->part($this->vehicule(), 'proprietaire', Proprietaire::factory()->create(['organization_id' => $this->org->id])->id, 9000);

        Sanctum::actingAs($agent, ['*']);
        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('context.proprietaire_id', $proprietaire->id)
            ->assertJsonPath('roles', ['comptable']);

        $this->getJson('/api/v1/mobile/vehicules/mine')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $vehicule->id);
        $this->assertSame([(string) $sienne->id], $this->idsDesPartsVues($agent));
    }

    public function test_agent_parrain_accede_a_l_espace_parrain_et_seulement_a_ses_parts(): void
    {
        $agent = $this->agent();
        $parrain = $this->parrainDe($agent, $this->agent('admin_entreprise'));
        $autreParrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $sienne = $this->part($this->vehicule(parrain: $parrain), 'parrain', $parrain->id, 2500);
        $this->part($this->vehicule(parrain: $autreParrain), 'parrain', $autreParrain->id, 9000);

        Sanctum::actingAs($agent, ['*']);
        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('context.parrain_id', $parrain->id)
            ->assertJsonPath('context.proprietaire_id', null)
            ->assertJsonPath('context.livreur_id', null);

        $this->getJson('/api/v1/mobile/parrain/synthese')->assertOk();
        $this->getJson('/api/v1/mobile/vehicules/mine')->assertOk()->assertExactJson([]);
        $this->assertSame([(string) $sienne->id], $this->idsDesPartsVues($agent));
    }

    public function test_agent_consultant_accede_a_ses_commissions(): void
    {
        $agent = $this->agent();
        $consultant = $this->consultantDe($agent);

        Sanctum::actingAs($agent, ['*']);
        $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('context.consultant_ids', [(string) $consultant->id]);
        $this->getJson('/api/v1/mobile/commissions')->assertOk();
    }

    public function test_ancien_livreur_garde_ses_commissions_sans_retrouver_le_vehicule_quitte(): void
    {
        $agent = $this->agent();
        $livreur = $this->livreurDe($agent);
        $vehicule = $this->vehicule();
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'nom' => 'Equipe', 'is_active' => true, 'taux_commission_proprietaire' => 0]);
        $membre = EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 1]);
        $acquise = $this->part($vehicule, 'livreur', $livreur->id, 6000);

        // Départ de l'équipe et fiche livreur désactivée : l'historique reste, l'accès opérationnel non.
        $membre->delete();
        $livreur->forceFill(['is_active' => false])->save();
        $agent = $this->sansRolesExternes($agent);

        Sanctum::actingAs($agent, ['*']);
        $this->getJson(route('api.auth.me'))->assertOk()->assertJsonPath('context.livreur_id', $livreur->id);

        $this->assertSame([(string) $acquise->id], $this->idsDesPartsVues($agent));
        $this->getJson('/api/v1/mobile/vehicules/mine')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/mobile/livraisons-transferts')->assertOk()->assertExactJson([]);
    }

    public function test_agent_cumulant_proprietaire_livreur_et_parrain_voit_toutes_ses_activites(): void
    {
        $agent = $this->agent('manager');
        $proprietaire = $this->proprietaireDe($agent);
        $livreur = $this->livreurDe($agent);
        $parrain = $this->parrainDe($agent, $this->agent('admin_entreprise'));

        Sanctum::actingAs($agent->fresh(), ['*']);
        $me = $this->getJson(route('api.auth.me'))
            ->assertOk()
            ->assertJsonPath('context.proprietaire_id', $proprietaire->id)
            ->assertJsonPath('context.livreur_id', $livreur->id)
            ->assertJsonPath('context.parrain_id', $parrain->id);

        $this->assertEqualsCanonicalizing(['manager', 'proprietaire', 'livreur'], $me->json('roles'));
        $this->assertTrue($agent->fresh()->hasBackofficeAccess());
    }

    // ── Désactivation et organisations ────────────────────────────────────────

    public function test_compte_desactive_est_refuse_partout(): void
    {
        $agent = $this->agent();
        $this->parrainDe($agent, $this->agent('admin_entreprise'));
        $agent->update(['is_active' => false]);

        Sanctum::actingAs($agent->fresh(), ['*']);
        $this->getJson(route('api.auth.me'))->assertForbidden();
        $this->getJson('/api/v1/mobile/dashboard')->assertForbidden();
        $this->getJson('/api/v1/mobile/parrain/synthese')->assertForbidden();

        $this->postJson(route('api.auth.login'), [
            'telephone' => $agent->telephone,
            'password' => 'password',
            'device_name' => 'nuxt',
        ])->assertForbidden();
    }

    public function test_une_fiche_d_une_autre_organisation_reste_invisible(): void
    {
        $agent = $this->agent();
        $autre = Organization::factory()->create();
        $fiche = Proprietaire::factory()->create(['organization_id' => $autre->id, 'user_id' => $agent->id]);
        Vehicule::factory()->create(['organization_id' => $autre->id, 'proprietaire_id' => $fiche->id]);
        $agent = $this->sansRolesExternes($agent);

        Sanctum::actingAs($agent, ['*']);
        $this->getJson(route('api.auth.me'))->assertOk()->assertJsonPath('context.proprietaire_id', null);
        $this->getJson('/api/v1/mobile/vehicules/mine')->assertOk()->assertExactJson([]);
    }
}
