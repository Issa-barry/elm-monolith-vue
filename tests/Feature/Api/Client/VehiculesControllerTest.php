<?php

namespace Tests\Feature\Api\Client;

use App\Models\Categorie;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * GET /v1/mobile/vehicules/mine — enrichissement du contrat (équipe complète, capacités par
 * catégorie, propriétaire) demandé suite à l'audit du 27/08/2026 : le front espace-client
 * n'avait accès qu'au chauffeur (jamais l'équipe complète), à une capacité héritée non fiable,
 * et à aucune donnée propriétaire.
 */
class VehiculesControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeVehicule(Organization $org, string $proprietaireId, array $overrides = []): Vehicule
    {
        return Vehicule::factory()->create(array_merge([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireId,
            'nom_vehicule' => 'Vehicule Test',
        ], $overrides));
    }

    private function makeEquipe(Organization $org, Vehicule $vehicule): EquipeLivraison
    {
        return EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
        ]);
    }

    private function addMembre(
        EquipeLivraison $equipe,
        Organization $org,
        string $role,
        int $ordre,
        array $livreurOverrides = [],
    ): Livreur {
        $livreur = Livreur::factory()->create(array_merge([
            'organization_id' => $org->id,
        ], $livreurOverrides));

        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreur->id,
            'role' => $role,
            'ordre' => $ordre,
        ]);

        return $livreur;
    }

    private function makeCategorie(Organization $org, string $nom): Categorie
    {
        return Categorie::create([
            'organization_id' => $org->id,
            'nom' => $nom,
            'statut' => 'actif',
        ]);
    }

    /** @return array{data: array<int, array<string, mixed>>} */
    private function fetch(): array
    {
        return $this->getJson(route('client.vehicules.mine'))->assertOk()->json();
    }

    public function test_vehicule_avec_deux_livreurs_renvoie_les_deux_membres_de_lequipe(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $equipe = $this->makeEquipe($org, $vehicule);

        $this->addMembre($equipe, $org, 'chauffeur', 0, ['nom_complet' => 'Camara Ya Moussa', 'telephone' => '+224600000001']);
        $this->addMembre($equipe, $org, 'convoyeur', 1, ['nom_complet' => 'Bah Salifou', 'telephone' => '+224600000002']);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertCount(2, $data[0]['equipe']);
        $roles = collect($data[0]['equipe'])->pluck('role')->sort()->values()->all();
        $this->assertSame(['chauffeur', 'convoyeur'], $roles);
    }

    public function test_telephone_des_membres_vient_de_personne(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $equipe = $this->makeEquipe($org, $vehicule);

        $livreur = $this->addMembre($equipe, $org, 'chauffeur', 0, [
            'nom_complet' => 'Camara Ya Moussa',
            'telephone' => '+224611222333',
        ]);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertSame($livreur->fresh()->telephone, $data[0]['equipe'][0]['telephone']);
        $this->assertSame('+224611222333', $data[0]['equipe'][0]['telephone']);
        $this->assertSame($livreur->id, $data[0]['equipe'][0]['id']);
        $this->assertSame(0, $data[0]['equipe'][0]['ordre']);
    }

    public function test_membre_inactif_est_exclu_de_lequipe(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $equipe = $this->makeEquipe($org, $vehicule);

        $this->addMembre($equipe, $org, 'chauffeur', 0, ['is_active' => true]);
        $this->addMembre($equipe, $org, 'convoyeur', 1, ['is_active' => false]);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertCount(1, $data[0]['equipe']);
        $this->assertSame('chauffeur', $data[0]['equipe'][0]['role']);
    }

    public function test_plusieurs_capacites_sont_toutes_renvoyees(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $sachets = $this->makeCategorie($org, 'Sachet eau');
        $bouteilles = $this->makeCategorie($org, 'Bouteille');

        VehiculeCapacite::create(['organization_id' => $org->id, 'vehicule_id' => $vehicule->id, 'categorie_id' => $sachets->id, 'capacite_max' => 800]);
        VehiculeCapacite::create(['organization_id' => $org->id, 'vehicule_id' => $vehicule->id, 'categorie_id' => $bouteilles->id, 'capacite_max' => 540]);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertCount(2, $data[0]['capacites']);
        $parCategorie = collect($data[0]['capacites'])->keyBy('categorie');
        $this->assertSame(800, $parCategorie['Sachet eau']['capacite']);
        $this->assertSame(540, $parCategorie['Bouteille']['capacite']);
        $this->assertSame($sachets->id, $parCategorie['Sachet eau']['categorie_id']);
    }

    public function test_absence_de_capacite_renvoie_tableau_vide(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeVehicule($org, $user->proprietaire->id);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertSame([], $data[0]['capacites']);
    }

    public function test_absence_dequipe_renvoie_tableau_vide(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeVehicule($org, $user->proprietaire->id);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertSame([], $data[0]['equipe']);
    }

    public function test_proprietaire_est_renvoye(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org, [], ['telephone' => '+224622333444']);
        $this->makeVehicule($org, $user->proprietaire->id);

        Sanctum::actingAs($user, ['*']);

        $data = $this->fetch();

        $this->assertSame($user->proprietaire->id, $data[0]['proprietaire']['id']);
        $this->assertSame('+224622333444', $data[0]['proprietaire']['telephone']);
        $this->assertNotEmpty($data[0]['proprietaire']['nom_complet']);
    }

    public function test_vehicule_sans_proprietaire_renvoie_proprietaire_null(): void
    {
        $org = Organization::factory()->create();
        $livreurUser = $this->makeLivreurUser($org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => null,
        ]);
        $equipe = $this->makeEquipe($org, $vehicule);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        Sanctum::actingAs($livreurUser, ['*']);

        $data = $this->fetch();

        $this->assertNull($data[0]['proprietaire']);
    }

    public function test_isolation_entre_proprietaires(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $this->makeVehicule($org, $owner->proprietaire->id, ['nom_vehicule' => 'Vehicule Owner']);

        $other = $this->makeProprietaireUser($org);
        $this->makeVehicule($org, $other->proprietaire->id, ['nom_vehicule' => 'Vehicule Other']);

        Sanctum::actingAs($other, ['*']);

        $data = $this->fetch();

        $this->assertCount(1, $data);
        $this->assertSame('Vehicule Other', $data[0]['nom']);
        $this->assertSame($other->proprietaire->id, $data[0]['proprietaire']['id']);
    }

    public function test_isolation_entre_organisations(): void
    {
        $orgA = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($orgA);
        $this->makeVehicule($orgA, $userA->proprietaire->id);

        $orgB = Organization::factory()->create();
        $userB = $this->makeProprietaireUser($orgB);

        Sanctum::actingAs($userB, ['*']);

        $this->assertSame([], $this->fetch());
    }

    public function test_aucun_np1_evident(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $categorie = $this->makeCategorie($org, 'Sachet eau');

        foreach (range(1, 4) as $i) {
            $vehicule = $this->makeVehicule($org, $user->proprietaire->id, ['nom_vehicule' => "Vehicule {$i}"]);
            $equipe = $this->makeEquipe($org, $vehicule);
            $this->addMembre($equipe, $org, 'chauffeur', 0);
            $this->addMembre($equipe, $org, 'convoyeur', 1);
            VehiculeCapacite::create([
                'organization_id' => $org->id,
                'vehicule_id' => $vehicule->id,
                'categorie_id' => $categorie->id,
                'capacite_max' => 500,
            ]);
        }

        Sanctum::actingAs($user, ['*']);

        DB::enableQueryLog();
        $this->getJson(route('client.vehicules.mine'))->assertOk()->assertJsonCount(4);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Borne large et stable : peu importe le nombre exact de requêtes (auth, résolution
        // d'identité, véhicules + eager loads, transferts en transit), il ne doit JAMAIS croître
        // avec le nombre de véhicules (4 ici, chacun avec équipe + capacité) — un N+1 sur
        // equipe/proprietaire/capacites dépasserait largement cette borne.
        $this->assertLessThan(20, $queryCount);
    }
}
