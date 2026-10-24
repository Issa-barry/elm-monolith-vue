<?php

namespace Tests\Feature;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Parrain;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Commission\PartageConformiteVehiculesService;
use App\Services\Commission\PartageParrainService;
use App\Services\Commission\PartParrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Part du parrain « à définir » / « définie » (PARRAIN-021) : état métier distinct de la conformité
 * du partage, jamais bloquant. Une part entièrement nulle n'est « définie » que si un utilisateur
 * l'a confirmée ; la grille enregistre tous les partages du parrain en une transaction, avec les
 * contrôles du changement de parrain. Barème Livreur 1 000 GNF/pack en Vente et en Logistique
 * (la distribution d'un véhicule parrainé garde le barème Logistique).
 */
class ParrainPartADefinirTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Categorie $categorie;

    private Vehicule $vehicule;

    private EquipeLivraison $equipe;

    private Livreur $chauffeur;

    private Livreur $convoyeur;

    private CommissionProcessus $vente;

    private CommissionProcessus $logistique;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');

        $this->initOrgAndUser([
            'vehicules.read', 'vehicules.update',
            'equipes-livraison.read', 'equipes-livraison.update',
        ]);
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet d\'eau', 'statut' => 'actif']);
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => Site::where('organization_id', $this->org->id)->value('id'),
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'livraison_vente' => true,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);
        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe',
            'is_active' => true,
        ]);
        foreach (['chauffeur', 'convoyeur'] as $ordre => $role) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true]);
            EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $livreur->id, 'role' => $role, 'ordre' => $ordre]);
            $this->{$role} = $livreur;
        }

        $this->vente = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $this->logistique = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        foreach ([$this->vente, $this->logistique] as $processus) {
            CommissionRegle::create([
                'organization_id' => $this->org->id,
                'processus_id' => $processus->id,
                'libelle' => "Livreur {$processus->code}",
                'scope_type' => CommissionScopeType::CATEGORIE->value,
                'scope_id' => $this->categorie->id,
                'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                'mode' => CommissionMode::A_REPARTIR->value,
                'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
                'montant' => 1000,
                'effective_from' => '2026-10-01',
                'statut' => CommissionRegleStatut::ACTIVE->value,
            ]);
            foreach ([$this->chauffeur->id => 600, $this->convoyeur->id => 400] as $livreurId => $montant) {
                EquipeLivraisonPartageCategorie::create([
                    'equipe_id' => $this->equipe->id,
                    'processus_id' => $processus->id,
                    'categorie_id' => $this->categorie->id,
                    'livreur_id' => $livreurId,
                    'part_pourcentage' => 0,
                    'montant_unitaire' => $montant,
                    'effective_from' => '2026-10-01',
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Même chemin que StoreParrainController (lignes du parrain à 0 GNF). */
    private function parrainer(): Parrain
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $this->vehicule = $this->vehicule->fresh();
        DB::transaction(function () use ($parrain) {
            $ancien = $this->vehicule->parrain_id;
            $this->vehicule->update(['parrain_id' => $parrain->id]);
            PartageParrainService::appliquerChangementDeParrain($this->vehicule, $ancien);
        });

        return $parrain;
    }

    /**
     * Répartition Vente et Distribution (même montants), au format de la grille.
     *
     * @return list<array<string, mixed>>
     */
    private function repartitions(int $chauffeur, int $convoyeur, int $parrain): array
    {
        return array_map(fn (string $code) => [
            'processus_code' => $code,
            'categorie_id' => $this->categorie->id,
            'parts' => [
                ['livreur_id' => $this->chauffeur->id, 'montant_unitaire' => $chauffeur],
                ['livreur_id' => $this->convoyeur->id, 'montant_unitaire' => $convoyeur],
            ],
            'part_parrain' => $parrain,
        ], [CommissionProcessus::CODE_VENTE, CommissionProcessus::CODE_DISTRIBUTION_CLIENT]);
    }

    /** @param  list<array<string, mixed>>  $repartitions */
    private function definir(array $repartitions, bool $confirmer = false, ?string $parrainId = null)
    {
        return $this->actingAs($this->user)->put(route('vehicules.parrain.part.update', $this->vehicule), [
            'parrain_id' => $parrainId ?? $this->vehicule->fresh()->parrain_id,
            'repartitions' => $repartitions,
            'confirmer_part_nulle' => $confirmer,
        ]);
    }

    private function statut(): ?string
    {
        return PartParrainService::statut($this->vehicule->fresh());
    }

    /** @return array<string, int> "livreur:id" | "parrain:id" => GNF/pack, version active */
    private function lignesActives(CommissionProcessus $processus): array
    {
        return EquipeLivraisonPartageCategorie::where('equipe_id', $this->equipe->id)
            ->where('processus_id', $processus->id)
            ->actifA(Carbon::today())
            ->get()
            ->mapWithKeys(fn (EquipeLivraisonPartageCategorie $l) => [
                ($l->parrain_id ? "parrain:{$l->parrain_id}" : "livreur:{$l->livreur_id}") => (int) $l->montant_unitaire,
            ])
            ->sortKeys()
            ->all();
    }

    /** Lignes actives du parrain actuel à 0 GNF jamais confirmées (PARRAIN-022). */
    private function partsARepartir(): int
    {
        return EquipeLivraisonPartageCategorie::where('equipe_id', $this->equipe->id)
            ->where('parrain_id', $this->vehicule->fresh()->parrain_id)
            ->actifA(Carbon::today())
            ->get()
            ->filter(fn (EquipeLivraisonPartageCategorie $l) => $l->partParrainARepartir())
            ->count();
    }

    private function distribution(): CommissionProcessus
    {
        return CommissionProcessus::where('organization_id', $this->org->id)
            ->where('code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
            ->firstOrFail();
    }

    // ── Scénarios ────────────────────────────────────────────────────────────

    public function test_un_nouveau_parrain_a_une_part_a_definir_sans_bloquer_les_partages(): void
    {
        $this->actingAs($this->user)->post(route('vehicules.parrain.store', $this->vehicule), [
            'nom_complet' => 'Ousmane Test',
            'telephone' => '622000111',
            'code_pays' => 'GN',
        ])->assertRedirect();

        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
        $this->assertSame(2, $this->partsARepartir(), 'Vente et Distribution : lignes à 0 GNF créées par le système.');

        // PARRAIN-022 : partage complet mais « à répartir » (ligne à 0 GNF créée par le système).
        $statuts = PartageConformiteVehiculesService::statuts($this->org->id, collect([$this->vehicule->fresh()]));
        $this->assertSame('a_repartir', $statuts[$this->vehicule->id][CommissionProcessus::CODE_VENTE]);

        $this->actingAs($this->user)->get(route('vehicules.show', $this->vehicule))
            ->assertInertia(fn ($page) => $page
                ->where('parrain_part.statut', PartParrainService::A_DEFINIR)
                ->where('parrain_part.repartition_obligatoire_du', null));
    }

    public function test_un_vehicule_deja_parraine_a_la_reprise_est_a_definir_et_retrouve_par_le_filtre(): void
    {
        $this->parrainer();
        $autre = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Non parrainé']);

        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
        $this->assertNull(PartParrainService::statut($autre));

        $this->actingAs($this->user)->get(route('vehicules.index', ['parrain_part' => 'a_definir']))
            ->assertInertia(fn ($page) => $page
                ->has('vehicules', 1)
                ->where('vehicules.0.id', $this->vehicule->id)
                ->where('vehicules.0.parrain_part', PartParrainService::A_DEFINIR));
    }

    public function test_les_processus_suivent_lordre_metier_dans_la_fiche_et_la_grille(): void
    {
        $this->parrainer();

        $this->actingAs($this->user)->get(route('vehicules.show', $this->vehicule))
            ->assertInertia(fn ($page) => $page
                ->where('parrain_remuneration.0.processus_code', CommissionProcessus::CODE_VENTE)
                ->where('parrain_remuneration.1.processus_code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT));

        $this->actingAs($this->user)->getJson(route('vehicules.parrain.part', $this->vehicule))
            ->assertJsonPath('groupes.0.processus_code', CommissionProcessus::CODE_VENTE)
            ->assertJsonPath('groupes.1.processus_code', CommissionProcessus::CODE_DISTRIBUTION_CLIENT);
    }

    public function test_la_grille_liste_tous_les_partages_du_parrain_part_nulle_comprise(): void
    {
        $this->parrainer();

        $this->actingAs($this->user)->getJson(route('vehicules.parrain.part', $this->vehicule))
            ->assertOk()
            ->assertJsonPath('statut', PartParrainService::A_DEFINIR)
            ->assertJsonCount(2, 'groupes')
            ->assertJsonPath('groupes.0.part_parrain_actuelle', 0);
    }

    public function test_une_part_positive_rend_la_part_definie_sur_tous_les_partages(): void
    {
        $parrain = $this->parrainer();

        $this->definir($this->repartitions(500, 300, 200))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(PartParrainService::DEFINIE, $this->statut());
        $this->assertSame(0, $this->partsARepartir());
        $attendu = ["livreur:{$this->chauffeur->id}" => 500, "livreur:{$this->convoyeur->id}" => 300, "parrain:{$parrain->id}" => 200];
        ksort($attendu);
        $this->assertSame($attendu, $this->lignesActives($this->vente));
        $this->assertSame($attendu, $this->lignesActives($this->distribution()));
        // Le transfert entre sites ne paie jamais le parrain : intact.
        $logistique = ["livreur:{$this->chauffeur->id}" => 600, "livreur:{$this->convoyeur->id}" => 400];
        ksort($logistique);
        $this->assertSame($logistique, $this->lignesActives($this->logistique));
    }

    public function test_une_part_nulle_exige_une_confirmation_qui_est_enregistree(): void
    {
        $this->parrainer();
        $avant = EquipeLivraisonPartageCategorie::count();

        $this->definir($this->repartitions(600, 400, 0))->assertSessionHasErrors('confirmer_part_nulle');
        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());

        $this->definir($this->repartitions(600, 400, 0), confirmer: true)->assertSessionHasNoErrors();

        $this->assertSame(PartParrainService::DEFINIE, $this->statut());
        $this->assertSame(0, $this->partsARepartir());
        $this->assertSame($avant, EquipeLivraisonPartageCategorie::count(), 'Rien n\'a changé : aucune nouvelle version.');
    }

    public function test_remettre_la_part_a_zero_apres_une_part_positive_redemande_une_confirmation(): void
    {
        $this->parrainer();
        $this->definir($this->repartitions(500, 300, 200))->assertSessionHasNoErrors();

        $this->definir($this->repartitions(600, 400, 0))->assertSessionHasErrors('confirmer_part_nulle');
        $this->assertSame(PartParrainService::DEFINIE, $this->statut(), 'Refusé : la part positive reste en place.');
    }

    public function test_changer_de_parrain_remet_la_confirmation_a_vide(): void
    {
        $this->parrainer();
        $this->definir($this->repartitions(600, 400, 0), confirmer: true)->assertSessionHasNoErrors();
        $this->assertSame(PartParrainService::DEFINIE, $this->statut());

        $this->parrainer();

        $this->assertSame(2, $this->partsARepartir());
        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
    }

    public function test_changer_de_parrain_par_lecran_remet_la_part_a_definir(): void
    {
        $this->parrainer();
        $this->definir($this->repartitions(600, 400, 0), confirmer: true)->assertSessionHasNoErrors();

        $this->actingAs($this->user)->post(route('vehicules.parrain.store', $this->vehicule), [
            'nom_complet' => 'Nouveau Parrain',
            'telephone' => '622000222',
            'code_pays' => 'GN',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->partsARepartir());
        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
    }

    public function test_un_vehicule_non_parraine_na_aucun_etat_et_aucune_grille(): void
    {
        $this->assertNull($this->statut());

        $this->definir($this->repartitions(500, 300, 200), parrainId: 'aucun')->assertSessionHasErrors('repartitions');
        $this->assertSame([600, 400], collect($this->lignesActives($this->vente))->sortDesc()->values()->all());

        $this->actingAs($this->user)->get(route('vehicules.show', $this->vehicule))
            ->assertInertia(fn ($page) => $page->where('parrain_part.statut', null));
    }

    public function test_sans_le_droit_de_modifier_lequipe_la_grille_est_refusee(): void
    {
        $this->parrainer();
        $sansDroit = $this->makeUserWithPermissions($this->org, ['vehicules.read', 'vehicules.update']);
        $sansDroit->sites()->attach($this->user->sites()->first()->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansDroit)->getJson(route('vehicules.parrain.part', $this->vehicule))->assertForbidden();
        $this->actingAs($sansDroit)->put(route('vehicules.parrain.part.update', $this->vehicule), [
            'parrain_id' => $this->vehicule->fresh()->parrain_id,
            'repartitions' => $this->repartitions(500, 300, 200),
        ])->assertForbidden();

        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
    }

    public function test_un_depassement_du_bareme_est_refuse_sans_rien_enregistrer(): void
    {
        $parrain = $this->parrainer();
        $avant = $this->lignesActives($this->vente);

        $this->definir($this->repartitions(600, 400, 200))->assertSessionHasErrors('repartitions');

        $this->assertSame($avant, $this->lignesActives($this->vente));
        $this->assertArrayHasKey("parrain:{$parrain->id}", $avant);
        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
    }

    public function test_une_repartition_invalide_annule_tout_lenregistrement(): void
    {
        $this->parrainer();
        $avantVente = $this->lignesActives($this->vente);
        $repartitions = $this->repartitions(500, 300, 200);
        $repartitions[1]['part_parrain'] = 300; // Distribution : 500 + 300 + 300 > 1 000

        $this->definir($repartitions)->assertSessionHasErrors('repartitions');

        $this->assertSame($avantVente, $this->lignesActives($this->vente), 'La Vente, pourtant valide, n\'est pas enregistrée seule.');
        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
    }

    public function test_un_partage_oublie_est_refuse(): void
    {
        $this->parrainer();

        $this->definir([$this->repartitions(500, 300, 200)[0]])->assertSessionHasErrors('repartitions');

        $this->assertSame(PartParrainService::A_DEFINIR, $this->statut());
    }

    public function test_la_confirmation_ne_rend_pas_conforme_un_partage_qui_ne_lest_pas(): void
    {
        $this->parrainer();
        $this->definir($this->repartitions(600, 400, 0), confirmer: true)->assertSessionHasNoErrors();

        // Le barème Vente passe à 1 200 : le partage (1 000) n'est plus conforme, confirmation ou non.
        CommissionRegle::where('processus_id', $this->vente->id)->update(['montant' => 1200]);

        $statuts = PartageConformiteVehiculesService::statuts($this->org->id, collect([$this->vehicule->fresh()]));
        $this->assertSame('a_faire', $statuts[$this->vehicule->id][CommissionProcessus::CODE_VENTE]);
        $this->assertSame(PartParrainService::DEFINIE, $this->statut());
    }
}
