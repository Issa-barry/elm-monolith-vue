<?php

namespace Tests\Feature\Commission;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Organization;
use App\Models\TypeVehicule;
use App\Services\Commission\CommissionPartageLivraisonCategorieChecker;
use App\Services\Commission\CommissionRegleResolver;
use App\Services\Commission\ReglesCommissionPrechargees;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lot 4 performance : la résolution en mémoire doit rendre exactement la règle de
 * CommissionRegleResolver::resolve(), pour chaque combinaison (portée, type de véhicule, statut,
 * dates de validité, versions qui se chevauchent, organisation, processus, cible).
 */
class ReglesCommissionPrechargeesTest extends TestCase
{
    use RefreshDatabase;

    private const CIBLE = 'equipe_livraison';

    private Organization $org;

    private CommissionProcessus $vente;

    private CommissionProcessus $logistique;

    private string $typeA;

    private string $typeB;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->org = Organization::factory()->create();
        $this->vente = $this->processus($this->org, CommissionProcessus::CODE_VENTE);
        $this->logistique = $this->processus($this->org, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT);
        $this->typeA = TypeVehicule::factory()->create(['organization_id' => $this->org->id])->id;
        $this->typeB = TypeVehicule::factory()->create(['organization_id' => $this->org->id])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function processus(Organization $org, string $code): CommissionProcessus
    {
        return CommissionProcessus::create([
            'organization_id' => $org->id,
            'code' => $code,
            'libelle' => $code,
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function regle(array $attributs = []): CommissionRegle
    {
        // created_at toujours distinct : l'ordre entre deux versions de même date d'effet est défini.
        $this->sequence++;
        $regle = CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->vente->id,
            'libelle' => 'Règle '.$this->sequence,
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => self::CIBLE,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 100 + $this->sequence,
            'type_vehicule_id' => null,
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'statut' => 'active',
            ...$attributs,
        ]);
        DB::table('commission_regles')->where('id', $regle->id)->update(['created_at' => Carbon::parse('2025-01-01')->addMinutes($this->sequence)]);

        return $regle;
    }

    /** Compare les deux résolutions et renvoie l'id trouvé (null = aucune règle). */
    private function comparer(?string $variante, ?string $produit, ?string $categorie, ?string $type, string $date = '2026-11-11', ?CommissionProcessus $processus = null, ?Organization $org = null, string $cible = self::CIBLE): ?string
    {
        $processus ??= $this->vente;
        $org ??= $this->org;
        $jour = Carbon::parse($date);
        $attendue = CommissionRegleResolver::resolve($org->id, $processus->id, $cible, $variante, $produit, $categorie, $jour, $type);
        $prechargees = ReglesCommissionPrechargees::charger($org->id, [$this->vente->id, $this->logistique->id, $processus->id], $cible, $jour);

        $this->assertTrue($prechargees->couvre($org->id, $processus->id, $cible, $jour));
        $this->assertSame($attendue?->id, $prechargees->resoudre($processus->id, $variante, $produit, $categorie, $type)?->id, "variante=$variante produit=$produit categorie=$categorie type=$type date=$date");

        return $attendue?->id;
    }

    public function test_sans_regle_aucune_regle_n_est_resolue(): void
    {
        $this->assertNull($this->comparer('var-1', 'prod-1', 'cat-1', $this->typeA));
        $this->assertNull($this->comparer(null, null, 'cat-1', null));
    }

    public function test_cascade_de_priorite_variante_produit_categorie_globale_et_type_exact(): void
    {
        $regles = [
            'globale' => $this->regle(),
            'globale_type' => $this->regle(['type_vehicule_id' => $this->typeA]),
            'categorie' => $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1']),
            'categorie_type' => $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1', 'type_vehicule_id' => $this->typeA]),
            'produit' => $this->regle(['scope_type' => 'produit', 'scope_id' => 'prod-1']),
            'produit_type' => $this->regle(['scope_type' => 'produit', 'scope_id' => 'prod-1', 'type_vehicule_id' => $this->typeA]),
            'variante' => $this->regle(['scope_type' => 'variante', 'scope_id' => 'var-1']),
            'variante_type' => $this->regle(['scope_type' => 'variante', 'scope_id' => 'var-1', 'type_vehicule_id' => $this->typeA]),
        ];

        $this->assertSame($regles['variante_type']->id, $this->comparer('var-1', 'prod-1', 'cat-1', $this->typeA));
        $this->assertSame($regles['variante']->id, $this->comparer('var-1', 'prod-1', 'cat-1', $this->typeB));
        $this->assertSame($regles['produit_type']->id, $this->comparer('var-2', 'prod-1', 'cat-1', $this->typeA));
        $this->assertSame($regles['categorie_type']->id, $this->comparer(null, 'prod-2', 'cat-1', $this->typeA));
        $this->assertSame($regles['categorie']->id, $this->comparer(null, null, 'cat-1', null));
        $this->assertSame($regles['globale_type']->id, $this->comparer(null, null, 'cat-2', $this->typeA));
        $this->assertSame($regles['globale']->id, $this->comparer(null, null, 'cat-2', $this->typeB));
    }

    public function test_statuts_et_dates_de_validite(): void
    {
        $this->regle(['statut' => 'brouillon', 'scope_type' => 'categorie', 'scope_id' => 'cat-1']);
        $ancienne = $this->regle(['statut' => 'remplacee', 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
        $actuelle = $this->regle(['effective_from' => '2026-07-01']);
        $future = $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1', 'effective_from' => '2026-12-01']);

        $this->assertNull($this->comparer(null, null, 'cat-1', null, '2025-12-31'));
        $this->assertSame($ancienne->id, $this->comparer(null, null, 'cat-1', null, '2026-01-01'));
        $this->assertSame($ancienne->id, $this->comparer(null, null, 'cat-1', null, '2026-06-30'));
        $this->assertSame($actuelle->id, $this->comparer(null, null, 'cat-1', null, '2026-07-01'));
        $this->assertSame($actuelle->id, $this->comparer(null, null, 'cat-1', null, '2026-11-30'));
        $this->assertSame($future->id, $this->comparer(null, null, 'cat-1', null, '2026-12-01'));
    }

    public function test_versions_qui_se_chevauchent_la_plus_recemment_entree_en_vigueur_puis_creee_l_emporte(): void
    {
        $this->regle(['effective_from' => '2026-03-01']);
        $plusRecente = $this->regle(['effective_from' => '2026-05-01']);
        $this->regle(['effective_from' => '2026-04-01']);
        $memeDateCreeeAvant = $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1', 'effective_from' => '2026-05-01']);
        $memeDateCreeeApres = $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1', 'effective_from' => '2026-05-01']);

        $this->assertSame($plusRecente->id, $this->comparer(null, null, 'cat-9', null));
        $this->assertSame($memeDateCreeeApres->id, $this->comparer(null, null, 'cat-1', null));
        $this->assertNotSame($memeDateCreeeAvant->id, $memeDateCreeeApres->id);
    }

    public function test_cloisonnement_par_organisation_processus_et_cible(): void
    {
        $autreOrg = Organization::factory()->create();
        $venteAutreOrg = $this->processus($autreOrg, CommissionProcessus::CODE_VENTE);
        $this->regle(['organization_id' => $autreOrg->id, 'processus_id' => $venteAutreOrg->id]);
        $logistique = $this->regle(['processus_id' => $this->logistique->id]);
        $this->regle(['cible_type' => 'proprietaire']);

        $this->assertNull($this->comparer(null, null, 'cat-1', null));
        $this->assertSame($logistique->id, $this->comparer(null, null, 'cat-1', null, processus: $this->logistique));
        $this->assertNotNull($this->comparer(null, null, 'cat-1', null, cible: 'proprietaire'));
        $this->assertNotNull($this->comparer(null, null, 'cat-1', null, processus: $venteAutreOrg, org: $autreOrg));
    }

    public function test_combinaisons_generees_identiques_au_resolveur(): void
    {
        mt_srand(20261111);
        $portees = ['global' => [null], 'categorie' => ['cat-1', 'cat-2'], 'produit' => ['prod-1', 'prod-2'], 'variante' => ['var-1', 'var-2']];
        $types = [null, $this->typeA, $this->typeB];
        $debuts = ['2026-01-01', '2026-03-01', '2026-06-01', '2026-11-11', '2026-12-01'];
        $fins = [null, null, '2026-02-28', '2026-05-31', '2026-11-11', '2026-11-30'];
        $statuts = ['active', 'active', 'remplacee', 'brouillon'];
        $processus = [$this->vente, $this->logistique];

        foreach (range(1, 160) as $_) {
            $portee = array_rand($portees);
            $debut = $debuts[array_rand($debuts)];
            $fin = $fins[array_rand($fins)];
            $this->regle([
                'processus_id' => $processus[array_rand($processus)]->id,
                'scope_type' => $portee,
                'scope_id' => $portees[$portee][array_rand($portees[$portee])],
                'type_vehicule_id' => $types[array_rand($types)],
                'effective_from' => $debut,
                'effective_to' => $fin !== null && $fin >= $debut ? $fin : null,
                'statut' => $statuts[array_rand($statuts)],
            ]);
        }

        $resolues = 0;
        foreach (['2025-12-31', '2026-01-01', '2026-02-28', '2026-03-01', '2026-05-31', '2026-06-01', '2026-11-11', '2026-11-30', '2026-12-01'] as $date) {
            foreach ($processus as $p) {
                foreach ([null, 'var-1', 'var-2', 'var-3'] as $variante) {
                    foreach ([null, 'prod-1', 'prod-2'] as $produit) {
                        foreach ([null, 'cat-1', 'cat-2', 'cat-3'] as $categorie) {
                            foreach ($types as $type) {
                                $resolues += (int) ($this->comparer($variante, $produit, $categorie, $type, $date, $p) !== null);
                            }
                        }
                    }
                }
            }
        }

        // Le jeu généré doit exercer des résolutions réelles, pas seulement des absences de règle.
        $this->assertGreaterThan(1000, $resolues);
    }

    public function test_hors_du_chargement_l_enveloppe_retombe_sur_le_resolveur(): void
    {
        $this->regle(['processus_id' => $this->logistique->id, 'scope_type' => 'categorie', 'scope_id' => 'cat-1', 'montant' => 4321]);
        $prechargees = ReglesCommissionPrechargees::charger($this->org->id, [$this->vente->id], self::CIBLE, Carbon::today());

        $this->assertFalse($prechargees->couvre($this->org->id, $this->logistique->id, self::CIBLE, Carbon::today()));
        $this->assertFalse($prechargees->couvre($this->org->id, $this->vente->id, self::CIBLE, Carbon::tomorrow()));
        $this->assertSame(4321, CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe($this->org->id, $this->logistique->id, 'cat-1', null, Carbon::today(), $prechargees));
        $this->assertSame(
            CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe($this->org->id, $this->vente->id, 'cat-1', $this->typeA, Carbon::today()),
            CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe($this->org->id, $this->vente->id, 'cat-1', $this->typeA, Carbon::today(), $prechargees),
        );
    }

    public function test_egalite_d_ordre_au_niveau_decisif_laisse_le_resolveur_sql_trancher(): void
    {
        $a = $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1', 'montant' => 300]);
        $b = $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1', 'montant' => 700]);
        $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-2']);
        DB::table('commission_regles')->whereIn('id', [$a->id, $b->id])->update(['created_at' => '2025-06-01 08:00:00']);
        $jour = Carbon::today();
        $prechargees = ReglesCommissionPrechargees::charger($this->org->id, [$this->vente->id], self::CIBLE, $jour);

        $this->assertFalse($prechargees->tranche($this->vente->id, null, null, 'cat-1', null));
        $this->assertTrue($prechargees->tranche($this->vente->id, null, null, 'cat-2', null));
        $this->assertTrue($prechargees->tranche($this->vente->id, null, null, 'cat-3', null));
        $this->assertSame(
            (int) CommissionRegleResolver::resolve($this->org->id, $this->vente->id, self::CIBLE, null, null, 'cat-1', $jour)->montant,
            CommissionPartageLivraisonCategorieChecker::resoudreEnveloppe($this->org->id, $this->vente->id, 'cat-1', null, $jour, $prechargees),
        );
    }

    public function test_un_seul_chargement_quel_que_soit_le_nombre_de_resolutions(): void
    {
        $this->regle(['scope_type' => 'categorie', 'scope_id' => 'cat-1']);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $prechargees = ReglesCommissionPrechargees::charger($this->org->id, [$this->vente->id, $this->logistique->id], self::CIBLE, Carbon::today());
        foreach (['cat-1', 'cat-2', 'cat-3'] as $categorie) {
            foreach ([null, $this->typeA, $this->typeB] as $type) {
                $prechargees->resoudre($this->vente->id, null, null, $categorie, $type);
                $prechargees->resoudre($this->logistique->id, null, null, $categorie, $type);
            }
        }

        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
