<?php

namespace Tests\Feature\Api\Client;

use App\Enums\CategorieDepense;
use App\Enums\PrestataireType;
use App\Enums\StatutDepense;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class DepensesControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeVehicule(Organization $org, string $proprietaireId, string $nom = 'Vehicule Test'): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireId,
            'nom_vehicule' => $nom,
        ]);
    }

    /** Un seul DepenseType par (org, code) — contrainte unique organization_id+code. */
    private function depenseType(Organization $org, string $code = 'carburant', string $libelle = 'Carburant'): DepenseType
    {
        return DepenseType::firstOrCreate(
            ['organization_id' => $org->id, 'code' => $code],
            ['libelle' => $libelle, 'categorie' => CategorieDepense::VEHICULE->value]
        );
    }

    private function makeDepense(Organization $org, Vehicule $vehicule, array $overrides = []): Depense
    {
        $type = $this->depenseType(
            $org,
            $overrides['type_code'] ?? 'carburant',
            $overrides['type_libelle'] ?? 'Carburant'
        );

        return Depense::factory()->create(array_merge([
            'organization_id' => $org->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $type->id,
            'statut' => StatutDepense::VALIDE->value,
            'montant' => 5000,
            'date_depense' => now()->toDateString(),
        ], array_diff_key($overrides, array_flip(['type_code', 'type_libelle']))));
    }

    public function test_lists_depenses_across_all_accessible_vehicules(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeDepense($org, $vehiculeA, ['montant' => 4000]);
        $this->makeDepense($org, $vehiculeB, ['montant' => 6000]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine'))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing(
            [4000.0, 6000.0],
            collect($response->json('data'))->pluck('montant')->all()
        );
    }

    public function test_avoids_n_plus_one_queries(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        for ($i = 0; $i < 5; $i++) {
            $this->makeDepense($org, $vehiculeA);
            $this->makeDepense($org, $vehiculeB);
        }

        Sanctum::actingAs($user, ['*']);

        DB::enableQueryLog();
        $this->getJson(route('client.depenses.mine'))->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Borne large et stable : peu importe le nombre exact de requêtes (auth,
        // pagination count, dépenses + eager loads), il ne doit JAMAIS croître
        // avec le nombre de dépenses (10 ici) — un N+1 dépasserait largement.
        $this->assertLessThan(15, $queryCount);
    }

    public function test_livreur_can_see_depenses_of_their_teams_vehicule(): void
    {
        $org = Organization::factory()->create();
        $livreurUser = $this->makeLivreurUser($org);
        $proprietaireUser = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $proprietaireUser->proprietaire->id);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $this->makeDepense($org, $vehicule, ['montant' => 7000]);

        Sanctum::actingAs($livreurUser, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant', 7000);
    }

    private function makeDepensePersonnelle(Organization $org, string $livreurId, array $overrides = []): Depense
    {
        return Depense::factory()->create(array_merge([
            'organization_id' => $org->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreurId,
            'depense_type_id' => $this->depenseType($org, 'avance_salaire', 'Avance sur salaire')->id,
            'statut' => StatutDepense::VALIDE->value,
            'montant' => 25000,
            'date_depense' => now()->toDateString(),
        ], $overrides));
    }

    public function test_livreur_sees_depense_attached_directly_to_them(): void
    {
        // Régression 02/11 : une avance sur salaire validée au back-office (concerné = livreur,
        // sans véhicule) n'apparaissait jamais dans son espace.
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $this->makeDepensePersonnelle($org, $user->livreur->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant', 25000)
            ->assertJsonPath('data.0.type_label', 'Avance sur salaire')
            ->assertJsonPath('data.0.vehicule', null);
    }

    public function test_livreur_sees_own_depenses_with_those_of_their_teams_vehicule(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $vehicule = $this->makeVehicule($org, $this->makeProprietaireUser($org)->proprietaire->id);
        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $user->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $this->makeDepense($org, $vehicule, ['montant' => 7000]);
        $this->makeDepensePersonnelle($org, $user->livreur->id);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine'))->assertOk();
        $this->assertEqualsCanonicalizing([7000.0, 25000.0], collect($response->json('data'))->pluck('montant')->all());

        $this->getJson(route('client.depenses.mine', ['vehicule_id' => $vehicule->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant', 7000);
    }

    public function test_total_valide_includes_older_depenses_without_period_filter(): void
    {
        // Régression 03/11 : une avance validée en septembre affichait 0 GNF sur l'accueil
        // en novembre, alors que la page Dépenses (toutes périodes) la comptait.
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $this->makeDepensePersonnelle($org, $user->livreur->id, [
            'montant' => 2000,
            'date_depense' => now()->subMonths(2)->toDateString(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine', ['statut' => StatutDepense::VALIDE->value, 'per_page' => 1]))
            ->assertOk()
            ->assertJsonPath('total_valide', 2000);
    }

    public function test_total_valide_covers_vehicule_and_personal_depenses_of_livreur_only_when_validated(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $autreLivreur = $this->makeLivreurUser($org);
        $vehicule = $this->makeVehicule($org, $this->makeProprietaireUser($org)->proprietaire->id);
        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $user->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $this->makeDepense($org, $vehicule, ['montant' => 7000]);
        $this->makeDepense($org, $vehicule, ['montant' => 900, 'statut' => StatutDepense::SOUMIS->value]);
        $this->makeDepensePersonnelle($org, $user->livreur->id, ['montant' => 2000]);
        $this->makeDepensePersonnelle($org, $autreLivreur->livreur->id, ['montant' => 50000]);

        Sanctum::actingAs($user, ['*']);

        // Sans filtre de statut, la liste contient aussi la dépense soumise, pas le total.
        $this->getJson(route('client.depenses.mine', ['per_page' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('total_valide', 9000);
    }

    public function test_total_valide_respects_filters(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeDepense($org, $vehiculeA, ['montant' => 4000, 'date_depense' => '2026-08-10']);
        $this->makeDepense($org, $vehiculeB, ['montant' => 6000, 'date_depense' => '2026-09-10']);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine', ['vehicule_id' => $vehiculeA->id]))
            ->assertOk()
            ->assertJsonPath('total_valide', 4000);
        $this->getJson(route('client.depenses.mine', ['date_debut' => '2026-09-01', 'date_fin' => '2026-09-30']))
            ->assertOk()
            ->assertJsonPath('total_valide', 6000);
        $this->getJson(route('client.depenses.mine', ['statut' => StatutDepense::SOUMIS->value]))
            ->assertOk()
            ->assertJsonPath('total_valide', 0);
    }

    public function test_total_valide_is_isolated_between_organizations(): void
    {
        $org = Organization::factory()->create();
        $autreOrg = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeDepense($org, $vehicule, ['montant' => 4000]);
        $this->makeDepense($autreOrg, $vehicule, ['montant' => 80000]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonPath('total_valide', 4000);
    }

    public function test_livreur_filters_apply_to_their_own_depenses(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $this->makeDepensePersonnelle($org, $user->livreur->id, ['statut' => StatutDepense::BROUILLON->value]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine', ['statut' => StatutDepense::VALIDE->value]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_never_shows_another_livreurs_depense(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $this->makeDepensePersonnelle($org, $this->makeLivreurUser($org)->livreur->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_never_shows_a_livreurs_depense_to_a_proprietaire(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeDepensePersonnelle($org, $this->makeLivreurUser($org)->livreur->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_livreur_does_not_see_personal_depense_of_another_organization(): void
    {
        $org = Organization::factory()->create();
        $autreOrg = Organization::factory()->create();
        $user = $this->makeLivreurUser($org);
        $this->makeDepensePersonnelle($autreOrg, $user->livreur->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    private function parrainDe(User $user, Organization $org): Parrain
    {
        $parrain = Parrain::factory()->create(['organization_id' => $org->id]);
        $parrain->forceFill(['user_id' => $user->id])->save();

        return $parrain;
    }

    private function consultantDe(?User $user, Organization $org): Prestataire
    {
        $consultant = Prestataire::create([
            'organization_id' => $org->id,
            'personne_id' => Personne::factory()->create(['organization_id' => $org->id])->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
        $consultant->forceFill(['user_id' => $user?->id])->save();

        return $consultant;
    }

    private function makeDepenseRattachee(Organization $org, string $type, string $beneficiaireId, int $montant = 7000): Depense
    {
        return Depense::factory()->create([
            'organization_id' => $org->id,
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaireId,
            'depense_type_id' => $this->depenseType($org, 'avance_salaire', 'Avance sur salaire')->id,
            'statut' => StatutDepense::VALIDE->value,
            'montant' => $montant,
            'date_depense' => now()->toDateString(),
        ]);
    }

    public function test_parrain_sees_depense_attached_directly_to_them(): void
    {
        // Régression 05/11 : une avance validée au back-office (concerné = parrain) était retenue
        // sur sa fiche mais n'apparaissait ni sur la page Dépenses ni sur la carte de l'accueil.
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $parrain = $this->parrainDe($user, $org);
        $this->makeDepenseRattachee($org, 'parrain', $parrain->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant', 7000)
            ->assertJsonPath('data.0.type_label', 'Avance sur salaire')
            ->assertJsonPath('data.0.vehicule', null)
            ->assertJsonPath('total_valide', 7000);
    }

    public function test_compte_uniquement_parrain_sees_own_depense(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $parrain = $this->parrainDe($user, $org);
        $this->makeDepenseRattachee($org, 'parrain', $parrain->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total_valide', 7000);
    }

    public function test_consultant_sees_depense_attached_directly_to_them(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $consultant = $this->consultantDe($user, $org);
        $this->makeDepenseRattachee($org, 'prestataire', $consultant->id, 3000);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total_valide', 3000);
    }

    public function test_never_shows_depense_of_another_parrain_or_consultant(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->parrainDe($user, $org);
        $this->consultantDe($user, $org);
        $this->makeDepenseRattachee($org, 'parrain', Parrain::factory()->create(['organization_id' => $org->id])->id, 50000);
        $this->makeDepenseRattachee($org, 'prestataire', $this->consultantDe(null, $org)->id, 60000);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total_valide', 0);
    }

    public function test_account_without_parrain_activity_does_not_see_parrain_depenses(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeDepenseRattachee($org, 'parrain', Parrain::factory()->create(['organization_id' => $org->id])->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_parrain_does_not_see_own_id_depense_of_another_organization(): void
    {
        $org = Organization::factory()->create();
        $autreOrg = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $parrain = $this->parrainDe($user, $org);
        $this->makeDepenseRattachee($autreOrg, 'parrain', $parrain->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_parrain_does_not_see_depenses_of_sponsored_vehicule(): void
    {
        // ADR 0054 D1 : le véhicule parrainé reste en lecture restreinte, sans ses dépenses.
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $parrain = $this->parrainDe($user, $org);
        $vehicule = $this->makeVehicule($org, $this->makeProprietaireUser($org)->proprietaire->id);
        $vehicule->forceFill(['parrain_id' => $parrain->id])->save();
        $this->makeDepense($org, $vehicule, ['montant' => 8000]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vehicule_filter_excludes_parrain_depense(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $parrain = $this->parrainDe($user, $org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeDepense($org, $vehicule, ['montant' => 4000]);
        $this->makeDepenseRattachee($org, 'parrain', $parrain->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine', ['vehicule_id' => $vehicule->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.montant', 4000)
            ->assertJsonPath('total_valide', 4000);
    }

    public function test_proprietaire_sees_depense_attached_directly_to_them(): void
    {
        // 05/11 : une dépense « Propriétaire » validée était retenue sur sa fiche et notifiée,
        // mais absente de la liste.
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeDepenseRattachee($org, 'proprietaire', $user->proprietaire->id, 6000);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.vehicule', null)
            ->assertJsonPath('total_valide', 6000);
    }

    public function test_never_shows_another_proprietaires_direct_depense(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeDepenseRattachee($org, 'proprietaire', $this->makeProprietaireUser($org)->proprietaire->id, 6000);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('total_valide', 0);
    }

    public function test_proprietaire_direct_depense_of_another_organization_is_hidden(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeDepenseRattachee(Organization::factory()->create(), 'proprietaire', $user->proprietaire->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vehicule_filter_excludes_proprietaire_direct_depense(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeDepense($org, $vehicule, ['montant' => 4000]);
        $this->makeDepenseRattachee($org, 'proprietaire', $user->proprietaire->id);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine', ['vehicule_id' => $vehicule->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('total_valide', 4000);
    }

    public function test_isolates_another_proprietaires_depenses(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $ownerVehicule = $this->makeVehicule($org, $owner->proprietaire->id);
        $this->makeDepense($org, $ownerVehicule, ['montant' => 1000]);

        $other = $this->makeProprietaireUser($org);
        $otherVehicule = $this->makeVehicule($org, $other->proprietaire->id);
        $this->makeDepense($org, $otherVehicule, ['montant' => 9999]);

        Sanctum::actingAs($other, ['*']);

        $response = $this->getJson(route('client.depenses.mine'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(9999, $response->json('data.0.montant'));
    }

    public function test_isolates_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($orgA);
        $vehiculeA = $this->makeVehicule($orgA, $userA->proprietaire->id);
        $this->makeDepense($orgA, $vehiculeA, ['montant' => 1000]);

        $orgB = Organization::factory()->create();
        $userB = $this->makeProprietaireUser($orgB);

        Sanctum::actingAs($userB, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters_by_vehicule_id(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeDepense($org, $vehiculeA, ['montant' => 1000]);
        $this->makeDepense($org, $vehiculeB, ['montant' => 2000]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['vehicule_id' => $vehiculeA->id]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(1000, $response->json('data.0.montant'));
    }

    public function test_filters_by_statut(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeDepense($org, $vehicule, ['montant' => 1000, 'statut' => StatutDepense::VALIDE->value]);
        $this->makeDepense($org, $vehicule, ['montant' => 2000, 'statut' => StatutDepense::BROUILLON->value]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['statut' => StatutDepense::BROUILLON->value]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(2000, $response->json('data.0.montant'));
    }

    public function test_filters_by_depense_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $typeCarburant = $this->depenseType($org, 'carburant', 'Carburant');
        $typeEntretien = $this->depenseType($org, 'entretien', 'Entretien');

        Depense::factory()->create([
            'organization_id' => $org->id, 'beneficiaire_type' => 'vehicule', 'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $typeCarburant->id, 'statut' => StatutDepense::VALIDE->value, 'montant' => 1000,
            'date_depense' => now()->toDateString(),
        ]);
        Depense::factory()->create([
            'organization_id' => $org->id, 'beneficiaire_type' => 'vehicule', 'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $typeEntretien->id, 'statut' => StatutDepense::VALIDE->value, 'montant' => 2000,
            'date_depense' => now()->toDateString(),
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['depense_type_id' => $typeEntretien->id]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('entretien', $response->json('data.0.type_code'));
    }

    public function test_filters_by_date_range(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeDepense($org, $vehicule, ['montant' => 1000, 'date_depense' => now()->subYear()->toDateString()]);
        $this->makeDepense($org, $vehicule, ['montant' => 2000, 'date_depense' => now()->toDateString()]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', [
            'date_debut' => now()->subDays(7)->toDateString(),
            'date_fin' => now()->toDateString(),
        ]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame(2000, $response->json('data.0.montant'));
    }

    public function test_paginates_results(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        for ($i = 0; $i < 5; $i++) {
            $this->makeDepense($org, $vehicule, ['montant' => 1000 + $i]);
        }

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.depenses.mine', ['per_page' => 2]))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_returns_empty_list_when_no_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_staff_only_account_is_accepted_without_data(): void
    {
        // ADR 0055 : l'espace client est ouvert à tout compte actif ; sans activité, aucune donnée.
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.depenses.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.depenses.mine'))->assertStatus(401);
    }
}
