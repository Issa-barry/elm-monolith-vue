<?php

namespace Tests\Feature;

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
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Changement de véhicule d'un livreur (transfert d'équipe) — règle métier (décision AMOA,
 * 02/09/2026) : le partage de commission par catégorie doit être intégralement refait des
 * deux côtés (départ ET arrivée), jamais repris automatiquement, dans une seule transaction
 * (tout ou rien). Cf. EquipeLivraisonController::transferer() et docs/commissions.md.
 */
class EquipeLivraisonTransfertLivreurTest extends TestCase
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
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function categorieDefaut(): Categorie
    {
        return Categorie::firstOrCreate(
            ['organization_id' => $this->org->id, 'nom' => 'Sachets'],
            ['statut' => 'actif'],
        );
    }

    private function processusPour(string $code): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $code],
            [
                'libelle' => $code,
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );
    }

    /** Barème Livreur global (portée GLOBAL, jamais par type de véhicule) — 100 GNF/unité par défaut. */
    private function configurerBareme(string $processusCode, int $montant = 100): void
    {
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processusPour($processusCode)->id,
            'libelle' => "Livreur — {$processusCode}",
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function makeVehicule(bool $vente = true, bool $logistique = false): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'livraison_vente' => $vente,
            'livraison_logistique' => $logistique,
        ]);
    }

    /** @param  array<int, array{livreur: Livreur, role: string}>  $membres */
    private function makeEquipe(Vehicule $vehicule, array $membres): EquipeLivraison
    {
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'proprietaire_id' => $vehicule->proprietaire_id,
            'is_active' => true,
        ]);

        foreach ($membres as $ordre => $m) {
            EquipeLivreur::create([
                'equipe_id' => $equipe->id,
                'livreur_id' => $m['livreur']->id,
                'role' => $m['role'],
                'ordre' => $ordre,
            ]);
        }

        return $equipe;
    }

    /** @param  array<string, int>  $montantsParLivreurId */
    private function configurerPartage(EquipeLivraison $equipe, string $processusCode, Categorie $categorie, array $montantsParLivreurId): void
    {
        $processus = $this->processusPour($processusCode);

        foreach ($montantsParLivreurId as $livreurId => $montant) {
            EquipeLivraisonPartageCategorie::create([
                'equipe_id' => $equipe->id,
                'processus_id' => $processus->id,
                'categorie_id' => $categorie->id,
                'livreur_id' => $livreurId,
                'part_pourcentage' => 0,
                'montant_unitaire' => $montant,
                'effective_from' => now()->subDay(),
                'effective_to' => null,
            ]);
        }
    }

    private function part(string $livreurId, int $montant): array
    {
        return ['livreur_id' => $livreurId, 'montant_unitaire' => $montant];
    }

    // ── Scénarios ────────────────────────────────────────────────────────────

    public function test_transfert_simple_deplace_le_livreur_et_referme_lancien_partage(): void
    {
        $this->configurerBareme(CommissionProcessus::CODE_VENTE, 100);
        $categorie = $this->categorieDefaut();

        $vehiculeA = $this->makeVehicule();
        $vehiculeB = $this->makeVehicule();

        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $y = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $z = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipeA = $this->makeEquipe($vehiculeA, [
            ['livreur' => $x, 'role' => 'chauffeur'],
            ['livreur' => $y, 'role' => 'convoyeur'],
        ]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_VENTE, $categorie, [
            $x->id => 60, $y->id => 40,
        ]);

        $equipeB = $this->makeEquipe($vehiculeB, [['livreur' => $z, 'role' => 'chauffeur']]);
        $this->configurerPartage($equipeB, CommissionProcessus::CODE_VENTE, $categorie, [$z->id => 100]);

        $response = $this->actingAs($this->user)->post(route('equipes-livraison.transfert.store', $x->id), [
            'nouveau_vehicule_id' => $vehiculeB->id,
            'role' => 'chauffeur',
            'partages_depart' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $categorie->id,
                'parts' => [$this->part($y->id, 100)],
            ]],
            'partages_arrivee' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $categorie->id,
                'parts' => [$this->part($z->id, 60), $this->part($x->id, 40)],
            ]],
        ]);

        $response->assertRedirectContains("/backoffice/vehicules/{$vehiculeB->id}");

        // X a quitté l'équipe A et rejoint l'équipe B.
        $this->assertDatabaseMissing('equipe_livreurs', ['equipe_id' => $equipeA->id, 'livreur_id' => $x->id]);
        $this->assertDatabaseHas('equipe_livreurs', ['equipe_id' => $equipeB->id, 'livreur_id' => $x->id, 'role' => 'chauffeur']);

        // L'équipe A n'est pas dissoute (Y reste) et le véhicule A reste actif.
        $this->assertNotNull(EquipeLivraison::find($equipeA->id));
        $this->assertTrue($vehiculeA->fresh()->is_active);
        $this->assertTrue($vehiculeB->fresh()->is_active);

        // Ancien partage de l'équipe A fermé, nouveau partage (Y=100) actif.
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeA->id, 'livreur_id' => $x->id, 'montant_unitaire' => 60,
        ]);
        $this->assertNotNull(
            EquipeLivraisonPartageCategorie::where('equipe_id', $equipeA->id)->where('livreur_id', $x->id)->first()->effective_to,
        );
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeA->id, 'livreur_id' => $y->id, 'montant_unitaire' => 100, 'effective_to' => null,
        ]);

        // Ancien partage de l'équipe B fermé, nouveau partage (Z=60, X=40) actif.
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeB->id, 'livreur_id' => $z->id, 'montant_unitaire' => 60, 'effective_to' => null,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeB->id, 'livreur_id' => $x->id, 'montant_unitaire' => 40, 'effective_to' => null,
        ]);
    }

    public function test_transfert_dissout_lequipe_de_depart_si_dernier_membre(): void
    {
        $this->configurerBareme(CommissionProcessus::CODE_VENTE, 100);
        $categorie = $this->categorieDefaut();

        $vehiculeA = $this->makeVehicule();
        $vehiculeB = $this->makeVehicule();

        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $z = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipeA = $this->makeEquipe($vehiculeA, [['livreur' => $x, 'role' => 'chauffeur']]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_VENTE, $categorie, [$x->id => 100]);

        $equipeB = $this->makeEquipe($vehiculeB, [['livreur' => $z, 'role' => 'chauffeur']]);
        $this->configurerPartage($equipeB, CommissionProcessus::CODE_VENTE, $categorie, [$z->id => 100]);

        $response = $this->actingAs($this->user)->post(route('equipes-livraison.transfert.store', $x->id), [
            'nouveau_vehicule_id' => $vehiculeB->id,
            'role' => 'chauffeur',
            'partages_arrivee' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $categorie->id,
                'parts' => [$this->part($z->id, 60), $this->part($x->id, 40)],
            ]],
        ]);

        $response->assertRedirectContains("/backoffice/vehicules/{$vehiculeB->id}");

        $this->assertSoftDeleted('equipes_livraison', ['id' => $equipeA->id]);
        $this->assertFalse($vehiculeA->fresh()->is_active);
        $this->assertTrue($vehiculeB->fresh()->is_active);
        $this->assertDatabaseHas('equipe_livreurs', ['equipe_id' => $equipeB->id, 'livreur_id' => $x->id]);
    }

    public function test_transfert_vers_vehicule_sans_equipe_cree_une_nouvelle_equipe(): void
    {
        $this->configurerBareme(CommissionProcessus::CODE_VENTE, 100);
        $categorie = $this->categorieDefaut();

        $vehiculeA = $this->makeVehicule();
        $vehiculeB = $this->makeVehicule(); // aucune équipe créée dessus

        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $y = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipeA = $this->makeEquipe($vehiculeA, [
            ['livreur' => $x, 'role' => 'chauffeur'],
            ['livreur' => $y, 'role' => 'convoyeur'],
        ]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_VENTE, $categorie, [$x->id => 60, $y->id => 40]);

        $response = $this->actingAs($this->user)->post(route('equipes-livraison.transfert.store', $x->id), [
            'nouveau_vehicule_id' => $vehiculeB->id,
            'role' => 'chauffeur',
            'partages_depart' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $categorie->id,
                'parts' => [$this->part($y->id, 100)],
            ]],
        ]);

        $response->assertRedirectContains("/backoffice/vehicules/{$vehiculeB->id}");

        $nouvelleEquipe = EquipeLivraison::where('vehicule_id', $vehiculeB->id)->firstOrFail();
        $this->assertDatabaseHas('equipe_livreurs', ['equipe_id' => $nouvelleEquipe->id, 'livreur_id' => $x->id, 'role' => 'chauffeur']);
        $this->assertEquals(1, EquipeLivreur::where('equipe_id', $nouvelleEquipe->id)->count());
        // Rien n'était configuré côté arrivée (nouvelle équipe) : aucun partage forcé.
        $this->assertEquals(0, EquipeLivraisonPartageCategorie::where('equipe_id', $nouvelleEquipe->id)->count());
    }

    public function test_transfert_avec_vehicule_vente_et_logistique_refait_les_deux_processus(): void
    {
        $this->configurerBareme(CommissionProcessus::CODE_VENTE, 100);
        $this->configurerBareme(CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 80);
        $categorie = $this->categorieDefaut();

        $vehiculeA = $this->makeVehicule(vente: true, logistique: true);
        $vehiculeB = $this->makeVehicule(vente: true, logistique: true);

        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $y = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $z = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipeA = $this->makeEquipe($vehiculeA, [
            ['livreur' => $x, 'role' => 'chauffeur'],
            ['livreur' => $y, 'role' => 'convoyeur'],
        ]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_VENTE, $categorie, [$x->id => 60, $y->id => 40]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, $categorie, [$x->id => 50, $y->id => 30]);

        $equipeB = $this->makeEquipe($vehiculeB, [['livreur' => $z, 'role' => 'chauffeur']]);
        $this->configurerPartage($equipeB, CommissionProcessus::CODE_VENTE, $categorie, [$z->id => 100]);
        $this->configurerPartage($equipeB, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, $categorie, [$z->id => 80]);

        $response = $this->actingAs($this->user)->post(route('equipes-livraison.transfert.store', $x->id), [
            'nouveau_vehicule_id' => $vehiculeB->id,
            'role' => 'chauffeur',
            'partages_depart' => [
                [
                    'processus_code' => CommissionProcessus::CODE_VENTE,
                    'categorie_id' => $categorie->id,
                    'parts' => [$this->part($y->id, 100)],
                ],
                [
                    'processus_code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
                    'categorie_id' => $categorie->id,
                    'parts' => [$this->part($y->id, 80)],
                ],
            ],
            'partages_arrivee' => [
                [
                    'processus_code' => CommissionProcessus::CODE_VENTE,
                    'categorie_id' => $categorie->id,
                    'parts' => [$this->part($z->id, 60), $this->part($x->id, 40)],
                ],
                [
                    'processus_code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
                    'categorie_id' => $categorie->id,
                    'parts' => [$this->part($z->id, 50), $this->part($x->id, 30)],
                ],
            ],
        ]);

        $response->assertRedirectContains("/backoffice/vehicules/{$vehiculeB->id}");

        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeA->id, 'livreur_id' => $y->id, 'montant_unitaire' => 100, 'effective_to' => null,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeA->id, 'livreur_id' => $y->id, 'montant_unitaire' => 80, 'effective_to' => null,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeB->id, 'livreur_id' => $x->id, 'montant_unitaire' => 40, 'effective_to' => null,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeB->id, 'livreur_id' => $x->id, 'montant_unitaire' => 30, 'effective_to' => null,
        ]);
    }

    public function test_transfert_echoue_si_somme_ne_correspond_pas_a_lenveloppe_rollback_total(): void
    {
        $this->configurerBareme(CommissionProcessus::CODE_VENTE, 100);
        $categorie = $this->categorieDefaut();

        $vehiculeA = $this->makeVehicule();
        $vehiculeB = $this->makeVehicule();

        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $y = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $z = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipeA = $this->makeEquipe($vehiculeA, [
            ['livreur' => $x, 'role' => 'chauffeur'],
            ['livreur' => $y, 'role' => 'convoyeur'],
        ]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_VENTE, $categorie, [$x->id => 60, $y->id => 40]);

        $equipeB = $this->makeEquipe($vehiculeB, [['livreur' => $z, 'role' => 'chauffeur']]);
        $this->configurerPartage($equipeB, CommissionProcessus::CODE_VENTE, $categorie, [$z->id => 100]);

        $response = $this->actingAs($this->user)->post(route('equipes-livraison.transfert.store', $x->id), [
            'nouveau_vehicule_id' => $vehiculeB->id,
            'role' => 'chauffeur',
            'partages_depart' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $categorie->id,
                'parts' => [$this->part($y->id, 100)],
            ]],
            // Somme = 90 au lieu de 100 : doit échouer.
            'partages_arrivee' => [[
                'processus_code' => CommissionProcessus::CODE_VENTE,
                'categorie_id' => $categorie->id,
                'parts' => [$this->part($z->id, 60), $this->part($x->id, 30)],
            ]],
        ]);

        $response->assertStatus(422);

        // Rollback total : X est toujours dans l'équipe A, rien n'a changé côté B.
        $this->assertDatabaseHas('equipe_livreurs', ['equipe_id' => $equipeA->id, 'livreur_id' => $x->id]);
        $this->assertDatabaseMissing('equipe_livreurs', ['equipe_id' => $equipeB->id, 'livreur_id' => $x->id]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeA->id, 'livreur_id' => $x->id, 'montant_unitaire' => 60, 'effective_to' => null,
        ]);
        $this->assertDatabaseHas('equipe_livraison_partages_categorie', [
            'equipe_id' => $equipeB->id, 'livreur_id' => $z->id, 'montant_unitaire' => 100, 'effective_to' => null,
        ]);
    }

    public function test_transfert_refuse_sans_permission(): void
    {
        $this->configurerBareme(CommissionProcessus::CODE_VENTE, 100);
        $categorie = $this->categorieDefaut();

        $vehiculeA = $this->makeVehicule();
        $vehiculeB = $this->makeVehicule();
        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $equipeA = $this->makeEquipe($vehiculeA, [['livreur' => $x, 'role' => 'chauffeur']]);
        $this->configurerPartage($equipeA, CommissionProcessus::CODE_VENTE, $categorie, [$x->id => 100]);

        // Même organisation que le livreur (sinon le garde-fou 404 cross-org intercepte avant
        // même l'autorisation) mais sans le droit equipes-livraison.update.
        $sansPermission = $this->makeUserWithPermissions($this->org, []);
        $site = Site::where('organization_id', $this->org->id)->firstOrFail();
        $sansPermission->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansPermission)
            ->post(route('equipes-livraison.transfert.store', $x->id), [
                'nouveau_vehicule_id' => $vehiculeB->id,
                'role' => 'chauffeur',
            ])
            ->assertStatus(403);
    }

    public function test_transfert_refuse_vers_le_meme_vehicule(): void
    {
        $vehiculeA = $this->makeVehicule();
        $x = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeEquipe($vehiculeA, [['livreur' => $x, 'role' => 'chauffeur']]);

        $this->actingAs($this->user)
            ->post(route('equipes-livraison.transfert.store', $x->id), [
                'nouveau_vehicule_id' => $vehiculeA->id,
                'role' => 'chauffeur',
            ])
            ->assertStatus(422);
    }
}
