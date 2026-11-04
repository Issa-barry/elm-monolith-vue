<?php

namespace Tests\Feature\Api\Client;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

class ActiviteControllerTest extends TestCase
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

    private function makeCommande(Organization $org, Vehicule $vehicule, array $overrides = []): CommandeVente
    {
        return CommandeVente::factory()->create(array_merge([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
            'validated_at' => now(),
        ], $overrides));
    }

    private function makeSite(Organization $org): Site
    {
        return Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site '.uniqid(),
            'type' => 'depot',
            'localisation' => 'Test',
        ]);
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule, array $overrides = []): TransfertLogistique
    {
        $creator = User::factory()->create(['organization_id' => $org->id]);
        $site = $this->makeSite($org);

        return TransfertLogistique::create(array_merge([
            'organization_id' => $org->id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutTransfert::CLOTURE->value,
            'date_depart_reelle' => now()->toDateString(),
            'created_by' => $creator->id,
        ], $overrides));
    }

    public function test_lists_both_ventes_and_transferts_regardless_of_statut(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::CLOTUREE->value]);
        $this->makeTransfert($org, $vehicule, ['statut' => StatutTransfert::CLOTURE->value]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine'))->assertOk();

        $response->assertJsonCount(2, 'data');
        $types = collect($response->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['logistique', 'vente'], $types);
    }

    public function test_isolates_another_proprietaires_activite(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $ownerVehicule = $this->makeVehicule($org, $owner->proprietaire->id);
        $this->makeCommande($org, $ownerVehicule);

        $other = $this->makeProprietaireUser($org);
        $otherVehicule = $this->makeVehicule($org, $other->proprietaire->id);
        $this->makeCommande($org, $otherVehicule, ['reference' => 'CMD-OTHER']);

        Sanctum::actingAs($other, ['*']);

        $response = $this->getJson(route('client.activite.mine'))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-OTHER', $response->json('data.0.reference'));
    }

    public function test_isolates_another_organization(): void
    {
        $orgA = Organization::factory()->create();
        $userA = $this->makeProprietaireUser($orgA);
        $vehiculeA = $this->makeVehicule($orgA, $userA->proprietaire->id);
        $this->makeCommande($orgA, $vehiculeA);

        $orgB = Organization::factory()->create();
        $userB = $this->makeProprietaireUser($orgB);

        Sanctum::actingAs($userB, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pure_proprietaire_without_equipe_sees_their_activity(): void
    {
        // Régression du manque documenté : /v1/mobile/livraisons-transferts (l'historique
        // existant) résout par équipe de livreur uniquement, inaccessible à un
        // proprietaire pur. Cet endpoint doit fonctionner sans aucune équipe.
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_livreur_sees_activity_of_their_teams_vehicule(): void
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

        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($livreurUser, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_by_type_vente(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['type' => 'vente']))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('vente', $response->json('data.0.type'));
    }

    public function test_filters_by_type_logistique(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['type' => 'logistique']))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('logistique', $response->json('data.0.type'));
    }

    public function test_statut_filter_requires_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine', ['statut' => 'cloturee']))
            ->assertStatus(422);
    }

    public function test_filters_by_statut_within_a_type(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::CLOTUREE->value, 'reference' => 'CMD-CLOTUREE']);
        $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::A_CHARGER->value, 'reference' => 'CMD-ACHARGER']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', [
            'type' => 'vente',
            'statut' => StatutCommandeVente::CLOTUREE->value,
        ]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-CLOTUREE', $response->json('data.0.reference'));
    }

    public function test_filters_by_vehicule_id(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeCommande($org, $vehiculeA, ['reference' => 'CMD-A']);
        $this->makeCommande($org, $vehiculeB, ['reference' => 'CMD-B']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['vehicule_id' => $vehiculeA->id]))->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('CMD-A', $response->json('data.0.reference'));
    }

    public function test_paginates_results(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        for ($i = 0; $i < 5; $i++) {
            $this->makeCommande($org, $vehicule, ['reference' => "CMD-$i"]);
        }

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['per_page' => 2]))->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function periodesRapides(): array
    {
        // Horloge : mercredi 04/03/2026 15:30:00 (voir figerHorloge).
        return [
            'aujourd\'hui' => ['aujourd_hui', ['AUJOURDHUI-DEBUT', 'MAINTENANT']],
            'hier' => ['hier', ['HIER-DEBUT', 'HIER-FIN']],
            'cette semaine' => ['cette_semaine', ['MAINTENANT', 'AUJOURDHUI-DEBUT', 'HIER-FIN', 'HIER-DEBUT', 'LUNDI-DEBUT']],
            // Du lundi 23/02 au dimanche 01/03 : à cheval sur deux mois.
            'semaine dernière' => ['semaine_precedente', ['DIMANCHE-FIN', 'MOIS-DEBUT', 'FEVRIER-FIN', 'SEMAINE-1-LUNDI']],
            'ce mois' => ['ce_mois', ['MAINTENANT', 'AUJOURDHUI-DEBUT', 'HIER-FIN', 'HIER-DEBUT', 'LUNDI-DEBUT', 'DIMANCHE-FIN', 'MOIS-DEBUT']],
            'mois dernier' => ['mois_precedent', ['FEVRIER-FIN', 'SEMAINE-1-LUNDI', 'FEVRIER-DEBUT']],
            'cette année' => ['cette_annee', ['MAINTENANT', 'AUJOURDHUI-DEBUT', 'HIER-FIN', 'HIER-DEBUT', 'LUNDI-DEBUT', 'DIMANCHE-FIN', 'MOIS-DEBUT', 'FEVRIER-FIN', 'SEMAINE-1-LUNDI', 'FEVRIER-DEBUT', 'JANVIER-FIN', 'ANNEE-DEBUT']],
        ];
    }

    /** Opérations posées exactement aux bornes de chaque période, et juste à côté. */
    private function peuplerBornes(Organization $org, Vehicule $vehicule): void
    {
        foreach ([
            'FUTUR' => '2026-03-04 15:30:01',
            'MAINTENANT' => '2026-03-04 15:30:00',
            'AUJOURDHUI-DEBUT' => '2026-03-04 00:00:00',
            'HIER-FIN' => '2026-03-03 23:59:59',
            'HIER-DEBUT' => '2026-03-03 00:00:00',
            'LUNDI-DEBUT' => '2026-03-02 00:00:00',
            'DIMANCHE-FIN' => '2026-03-01 23:59:59',
            'MOIS-DEBUT' => '2026-03-01 00:00:00',
            'FEVRIER-FIN' => '2026-02-28 23:59:59',
            'SEMAINE-1-LUNDI' => '2026-02-23 00:00:00',
            'FEVRIER-DEBUT' => '2026-02-01 00:00:00',
            'JANVIER-FIN' => '2026-01-31 23:59:59',
            'ANNEE-DEBUT' => '2026-01-01 00:00:00',
            'ANNEE-1-FIN' => '2025-12-31 23:59:59',
        ] as $reference => $date) {
            $this->makeCommande($org, $vehicule, ['reference' => $reference, 'validated_at' => $date]);
        }
    }

    private function figerHorloge(string $maintenant = '2026-03-04 15:30:00'): void
    {
        $this->travelTo($maintenant);
    }

    /** @dataProvider periodesRapides */
    public function test_periode_rapide_respecte_ses_bornes_exactes(string $periode, array $attendues): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->peuplerBornes($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => $periode, 'per_page' => 100]))->assertOk();

        $this->assertEqualsCanonicalizing($attendues, array_column($response->json('data'), 'reference'));
        $this->assertSame(count($attendues), $response->json('meta.total'));
        $this->assertSame($periode, $response->json('filters.periode'));
    }

    public function test_periode_expose_ses_bornes_resolues_par_le_serveur(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $this->makeVehicule($org, $user->proprietaire->id);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => 'cette_semaine']))->assertOk();

        $this->assertSame('2026-03-02', $response->json('filters.date_debut'));
        $this->assertSame('2026-03-04', $response->json('filters.date_fin'));
    }

    public function test_semaine_a_cheval_sur_deux_annees_et_mois_precedent_de_janvier(): void
    {
        // Vendredi 01/01/2027 : la semaine courante a commencé le lundi 28/12/2026.
        $this->figerHorloge('2027-01-01 08:00:00');
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['reference' => 'SEMAINE-1-FIN', 'validated_at' => '2026-12-27 23:59:59']);
        $this->makeCommande($org, $vehicule, ['reference' => 'SEMAINE-LUNDI', 'validated_at' => '2026-12-28 00:00:00']);
        $this->makeCommande($org, $vehicule, ['reference' => 'DECEMBRE-DEBUT', 'validated_at' => '2026-12-01 00:00:00']);
        $this->makeCommande($org, $vehicule, ['reference' => 'ANNEE', 'validated_at' => '2027-01-01 00:00:00']);

        Sanctum::actingAs($user, ['*']);

        $references = fn (string $periode) => array_column(
            $this->getJson(route('client.activite.mine', ['periode' => $periode]))->assertOk()->json('data'),
            'reference',
        );

        $this->assertEqualsCanonicalizing(['SEMAINE-LUNDI', 'ANNEE'], $references('cette_semaine'));
        $this->assertEqualsCanonicalizing(['SEMAINE-1-FIN', 'SEMAINE-LUNDI', 'DECEMBRE-DEBUT'], $references('mois_precedent'));
        $this->assertEqualsCanonicalizing(['ANNEE'], $references('cette_annee'));
    }

    public function test_mois_precedent_ne_deborde_pas_un_31(): void
    {
        // Le 31/03 : un simple « moins un mois » retomberait sur le 03/03.
        $this->figerHorloge('2026-03-31 12:00:00');
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['reference' => 'FEVRIER', 'validated_at' => '2026-02-28 23:59:59']);
        $this->makeCommande($org, $vehicule, ['reference' => 'MARS', 'validated_at' => '2026-03-01 00:00:00']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => 'mois_precedent']))->assertOk();

        $this->assertSame(['FEVRIER'], array_column($response->json('data'), 'reference'));
    }

    public function test_periode_filtre_la_date_affichee_quand_il_manque_validation_ou_depart(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $commande = $this->makeCommande($org, $vehicule, ['reference' => 'VTE-NON-VALIDEE', 'validated_at' => null]);
        $commande->forceFill(['created_at' => '2026-03-04 09:00:00'])->saveQuietly();
        $transfert = $this->makeTransfert($org, $vehicule, ['reference' => 'TRF-SANS-DEPART', 'date_depart_reelle' => null]);
        $transfert->forceFill(['created_at' => '2026-03-03 09:00:00'])->saveQuietly();

        Sanctum::actingAs($user, ['*']);

        $aujourdhui = $this->getJson(route('client.activite.mine', ['periode' => 'aujourd_hui']))->assertOk();
        $this->assertSame(['VTE-NON-VALIDEE'], array_column($aujourdhui->json('data'), 'reference'));
        $this->assertSame('2026-03-04', $aujourdhui->json('data.0.date'));

        $hier = $this->getJson(route('client.activite.mine', ['periode' => 'hier']))->assertOk();
        $this->assertSame(['TRF-SANS-DEPART'], array_column($hier->json('data'), 'reference'));
        $this->assertSame('2026-03-03', $hier->json('data.0.date'));
    }

    public function test_periode_inclut_un_transfert_parti_le_jour_meme(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeTransfert($org, $vehicule, ['reference' => 'TRF-JOUR', 'date_depart_reelle' => '2026-03-04']);
        $this->makeTransfert($org, $vehicule, ['reference' => 'TRF-VEILLE', 'date_depart_reelle' => '2026-03-03']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => 'aujourd_hui']))->assertOk();

        $this->assertSame(['TRF-JOUR'], array_column($response->json('data'), 'reference'));
    }

    public function test_periode_se_combine_avec_type_statut_et_vehicule(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehiculeA = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule A');
        $vehiculeB = $this->makeVehicule($org, $user->proprietaire->id, 'Vehicule B');

        $this->makeCommande($org, $vehiculeA, ['reference' => 'OK', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-03-04 10:00:00']);
        $this->makeCommande($org, $vehiculeA, ['reference' => 'AUTRE-STATUT', 'statut' => StatutCommandeVente::A_CHARGER->value, 'validated_at' => '2026-03-04 10:00:00']);
        $this->makeCommande($org, $vehiculeA, ['reference' => 'AUTRE-JOUR', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-03-03 10:00:00']);
        $this->makeCommande($org, $vehiculeB, ['reference' => 'AUTRE-VEHICULE', 'statut' => StatutCommandeVente::CLOTUREE->value, 'validated_at' => '2026-03-04 10:00:00']);
        $this->makeTransfert($org, $vehiculeA, ['reference' => 'AUTRE-TYPE', 'date_depart_reelle' => '2026-03-04']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', [
            'periode' => 'aujourd_hui',
            'type' => 'vente',
            'statut' => StatutCommandeVente::CLOTUREE->value,
            'vehicule_id' => $vehiculeA->id,
        ]))->assertOk();

        $this->assertSame(['OK'], array_column($response->json('data'), 'reference'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_pagination_porte_sur_le_resultat_filtre_sans_doublon_ni_oubli(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        // Même horodatage pour plusieurs lignes : l'ordre entre pages doit rester stable.
        for ($i = 0; $i < 3; $i++) {
            $this->makeCommande($org, $vehicule, ['reference' => "VTE-$i", 'validated_at' => '2026-03-04 00:00:00']);
            $this->makeTransfert($org, $vehicule, ['reference' => "TRF-$i", 'date_depart_reelle' => '2026-03-04']);
        }
        $this->makeCommande($org, $vehicule, ['reference' => 'HORS-PERIODE', 'validated_at' => '2026-03-03 10:00:00']);

        Sanctum::actingAs($user, ['*']);

        $references = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson(route('client.activite.mine', ['periode' => 'aujourd_hui', 'per_page' => 2, 'page' => $page]))->assertOk();
            $response->assertJsonCount(2, 'data');
            $this->assertSame(6, $response->json('meta.total'));
            $this->assertSame(3, $response->json('meta.last_page'));
            $references = [...$references, ...array_column($response->json('data'), 'reference')];
        }

        $this->assertEqualsCanonicalizing(['VTE-0', 'VTE-1', 'VTE-2', 'TRF-0', 'TRF-1', 'TRF-2'], $references);
        $this->assertStringContainsString('periode=aujourd_hui', (string) $response->json('links.prev'));
    }

    public function test_total_exact_au_dela_de_deux_cents_operations(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        CommandeVente::factory()->count(205)->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::CLOTUREE->value,
            'validated_at' => '2026-02-10 10:00:00',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => 'cette_annee', 'per_page' => 100, 'page' => 3]))->assertOk();

        $this->assertSame(205, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
        $response->assertJsonCount(5, 'data');
    }

    public function test_periode_n_expose_jamais_l_activite_d_un_autre_proprietaire(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $owner = $this->makeProprietaireUser($org);
        $this->makeCommande($org, $this->makeVehicule($org, $owner->proprietaire->id), ['reference' => 'A-MOI', 'validated_at' => '2026-03-04 10:00:00']);

        $other = $this->makeProprietaireUser($org);
        $this->makeCommande($org, $this->makeVehicule($org, $other->proprietaire->id), ['reference' => 'A-UN-AUTRE', 'validated_at' => '2026-03-04 10:00:00']);

        $orgB = Organization::factory()->create();
        $autreOrg = $this->makeProprietaireUser($orgB);
        $this->makeCommande($orgB, $this->makeVehicule($orgB, $autreOrg->proprietaire->id), ['reference' => 'AUTRE-ORG', 'validated_at' => '2026-03-04 10:00:00']);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => 'aujourd_hui']))->assertOk();

        $this->assertSame(['A-MOI'], array_column($response->json('data'), 'reference'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_periode_reste_limitee_au_vehicule_de_l_equipe_du_livreur(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $livreurUser = $this->makeLivreurUser($org);
        $proprietaire = $this->makeProprietaireUser($org);
        $vehiculeEquipe = $this->makeVehicule($org, $proprietaire->proprietaire->id, 'Vehicule equipe');
        $autreVehicule = $this->makeVehicule($org, $proprietaire->proprietaire->id, 'Autre vehicule');

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehiculeEquipe->id,
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

        $this->makeTransfert($org, $vehiculeEquipe, ['reference' => 'TRF-EQUIPE', 'date_depart_reelle' => '2026-03-04']);
        $this->makeTransfert($org, $autreVehicule, ['reference' => 'TRF-AUTRE', 'date_depart_reelle' => '2026-03-04']);

        Sanctum::actingAs($livreurUser, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['periode' => 'aujourd_hui']))->assertOk();

        $this->assertSame(['TRF-EQUIPE'], array_column($response->json('data'), 'reference'));
    }

    public function test_plage_de_dates_explicite_inclut_ses_deux_jours_entiers(): void
    {
        $this->figerHorloge();
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);

        $this->makeCommande($org, $vehicule, ['reference' => 'AVANT', 'validated_at' => '2026-02-09 23:59:59']);
        $this->makeCommande($org, $vehicule, ['reference' => 'DEBUT', 'validated_at' => '2026-02-10 00:00:00']);
        $this->makeCommande($org, $vehicule, ['reference' => 'FIN', 'validated_at' => '2026-02-11 23:59:59']);
        $this->makeCommande($org, $vehicule, ['reference' => 'APRES', 'validated_at' => '2026-02-12 00:00:00']);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine', ['date_debut' => '2026-02-10', 'date_fin' => '2026-02-11']))->assertOk();

        $this->assertEqualsCanonicalizing(['DEBUT', 'FIN'], array_column($response->json('data'), 'reference'));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function parametresInvalides(): array
    {
        return [
            'période inconnue' => [['periode' => 'demain'], 'periode'],
            'période en tableau' => [['periode' => ['hier']], 'periode'],
            'période avec date de début' => [['periode' => 'hier', 'date_debut' => '2026-03-01'], 'periode'],
            'période avec date de fin' => [['periode' => 'hier', 'date_fin' => '2026-03-01'], 'periode'],
            'date de début invalide' => [['date_debut' => 'pas-une-date'], 'date_debut'],
            'date de fin invalide' => [['date_fin' => '2026-02-31'], 'date_fin'],
            'fin avant début' => [['date_debut' => '2026-03-02', 'date_fin' => '2026-03-01'], 'date_fin'],
            'page négative' => [['page' => -1], 'page'],
            'page nulle' => [['page' => 0], 'page'],
        ];
    }

    /** @dataProvider parametresInvalides */
    public function test_refuse_les_parametres_invalides(array $parametres, string $champ): void
    {
        $user = $this->makeProprietaireUser(Organization::factory()->create());

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine', $parametres))
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);
    }

    public function test_returns_empty_list_when_no_data(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_forbidden_for_staff_only_account(): void
    {
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        Sanctum::actingAs($user, ['*']);

        $this->getJson(route('client.activite.mine'))->assertStatus(403);
    }

    public function test_vente_expose_le_statut_de_paiement_de_sa_facture(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $commande = $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::LIVREE->value]);
        FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'statut_facture' => StatutFactureVente::PARTIEL,
        ]);
        $this->makeTransfert($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $data = collect($this->getJson(route('client.activite.mine'))->assertOk()->json('data'))->keyBy('type');

        $this->assertSame('livree', $data['vente']['statut']);
        $this->assertSame('Livrée', $data['vente']['statut_label']);
        $this->assertSame('partiel', $data['vente']['statut_paiement']);
        $this->assertSame('Partiellement payée', $data['vente']['statut_paiement_label']);
        $this->assertNull($data['logistique']['statut_paiement']);
        $this->assertNull($data['logistique']['statut_paiement_label']);
    }

    public function test_vente_sans_facture_n_a_pas_de_statut_de_paiement(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $this->makeCommande($org, $vehicule);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine'))->assertOk();

        $this->assertNull($response->json('data.0.statut_paiement'));
        $this->assertNull($response->json('data.0.statut_paiement_label'));
    }

    public function test_vente_soldee_en_attente_des_commissions_affiche_le_libelle_du_back_office(): void
    {
        $org = Organization::factory()->create();
        $user = $this->makeProprietaireUser($org);
        $vehicule = $this->makeVehicule($org, $user->proprietaire->id);
        $commande = $this->makeCommande($org, $vehicule, ['statut' => StatutCommandeVente::FACTURATION->value]);
        FactureVente::factory()->payee()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('client.activite.mine'))->assertOk();

        $this->assertSame('facturation', $response->json('data.0.statut'));
        $this->assertSame('Commissions à verser', $response->json('data.0.statut_label'));
        $this->assertSame('payee', $response->json('data.0.statut_paiement'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('client.activite.mine'))->assertStatus(401);
    }
}
