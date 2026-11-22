<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Commissions des livreurs, vue par défaut (option B, 22/11/2026) : quinzaine en cours et
 * quinzaines antérieures non soldées ; « Toutes les périodes » reste un choix explicite
 * (PerimetreParDefautLivreurs).
 *
 * Jeu de référence, au 20/11/2026 (quinzaine en cours : 2026-11-P2), pour le livreur Diallo :
 * | Quinzaine | Commissions                                  | Dépenses | Solde   | Vue par défaut |
 * | 11-P2     | 40 000 impayée                               | 5 000    | 35 000  | oui (en cours) |
 * | 10-P1     | 100 000 payée                                | —        | 0       | non            |
 * | 10-P2     | 100 000 payée + 50 000 partielle (20 000)    | 30 000   | 0       | non (fiche payée net des dépenses) |
 * | 09-P1     | 60 000 impayée                               | 10 000   | 50 000  | oui            |
 * | 09-P2     | 80 000 partielle (30 000) + 20 000 payée     | —        | 50 000  | oui            |
 * | 08-P1     | 15 000 « Créée »                             | —        | 15 000  | oui            |
 * | 08-P2     | 70 000 payée                                 | 12 000 validée après paiement | −12 000 | oui (report) |
 * Reste à payer : 138 000, identique sur les deux vues.
 */
class CommissionsLivreursVueParDefautTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const URL = '/backoffice/comptabilite/commissions/vente';

    private Site $site;

    private string $processus;

    private DepenseType $type;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            ['libelle' => 'Vente', 'declencheur' => 'chargement_valide', 'strategie_ancrage_site' => 'operation', 'statut' => 'actif'],
        )->id;
        $this->type = DepenseType::create(['organization_id' => $this->org->id, 'code' => 'AV', 'libelle' => 'Avance', 'categorie' => 'interne',
            'commentaire_obligatoire' => false, 'justificatif_obligatoire' => false, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function livreur(string $nom, string $telephone): Livreur
    {
        return Livreur::create(['organization_id' => $this->org->id, 'is_active' => true, 'nom_complet' => $nom,
            'personne_id' => Personne::create(['organization_id' => $this->org->id, 'nom' => $nom, 'prenom' => 'Test', 'telephone' => $telephone])->id]);
    }

    private function commission(Livreur $livreur, string $date, float $montant, string $statut, float $verse = 0): void
    {
        $this->n++;
        $vente = CommandeVente::withoutEvents(fn () => CommandeVente::create(['organization_id' => $this->org->id, 'reference' => sprintf('VPD-%04d', $this->n),
            'site_id' => $this->site->id, 'statut' => 'livree', 'total_commande' => 500000]));
        $enveloppe = CommissionEnveloppe::create(['organization_id' => $this->org->id, 'source_type' => CommandeVente::class, 'source_id' => $vente->id,
            'processus_id' => $this->processus, 'cible_type' => 'equipe_livraison', 'cible_id' => (string) Str::ulid(), 'montant_total' => $montant,
            'earned_at' => $date, 'statut' => $statut]);
        CommissionEnveloppePart::create(['enveloppe_id' => $enveloppe->id, 'beneficiaire_type' => 'livreur', 'beneficiaire_id' => $livreur->id,
            'taux_repartition_snapshot' => 100, 'montant_brut' => $montant, 'montant_net' => $montant, 'montant_verse' => $verse,
            'statut' => $statut, 'validated_at' => $statut === 'creee' ? null : $date]);
    }

    private function depense(Livreur $livreur, string $date, float $montant): void
    {
        Depense::create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'user_id' => $this->user->id, 'depense_type_id' => $this->type->id,
            'beneficiaire_type' => 'livreur', 'beneficiaire_id' => $livreur->id, 'montant' => $montant, 'date_depense' => $date,
            'statut' => StatutDepense::VALIDE->value]);
    }

    /** @return array{diallo: Livreur, solde: Livreur, courant: Livreur} */
    private function jeu(): array
    {
        $diallo = $this->livreur('Diallo', '620000001');
        // 2026-11-P2, quinzaine en cours.
        $this->commission($diallo, '2026-11-17', 40000, 'impaye');
        $this->depense($diallo, '2026-11-17', 5000);
        // 2026-10-P1, payée.
        $this->commission($diallo, '2026-10-05', 100000, 'paye', 100000);
        // 2026-10-P2 : fiche de 120 000 (150 000 − 30 000 de dépenses) payée, allouée FIFO.
        $this->commission($diallo, '2026-10-17', 100000, 'paye', 100000);
        $this->commission($diallo, '2026-10-20', 50000, 'partiel', 20000);
        $this->depense($diallo, '2026-10-18', 30000);
        // 2026-09-P1, impayée.
        $this->commission($diallo, '2026-09-03', 60000, 'impaye');
        $this->depense($diallo, '2026-09-04', 10000);
        // 2026-09-P2, partielle : la commission payée de la quinzaine reste affichée avec elle.
        $this->commission($diallo, '2026-09-20', 80000, 'partiel', 30000);
        $this->commission($diallo, '2026-09-21', 20000, 'paye', 20000);
        // 2026-08-P1, encore à valider.
        $this->commission($diallo, '2026-08-10', 15000, 'creee');
        // 2026-08-P2, payée, puis une dépense validée après paiement (reportée sur la fiche suivante).
        $this->commission($diallo, '2026-08-20', 70000, 'paye', 70000);
        $this->depense($diallo, '2026-08-25', 12000);

        // Livreur entièrement soldé, sans activité dans la quinzaine en cours.
        $solde = $this->livreur('Bah', '620000002');
        $this->commission($solde, '2026-10-06', 25000, 'paye', 25000);

        // Livreur actif seulement dans la quinzaine en cours.
        $courant = $this->livreur('Camara', '620000003');
        $this->commission($courant, '2026-11-18', 30000, 'impaye');

        return compact('diallo', 'solde', 'courant');
    }

    /** @return array<string, array<string, mixed>> lignes de l'écran par nom */
    private function ecran(array $params = []): array
    {
        $props = $this->actingAs($this->user)->get(self::URL.'?'.http_build_query($params))->assertOk()->viewData('page')['props'];

        return collect($props['beneficiaires'])->keyBy('beneficiaire_nom')->all() + ['__props' => $props];
    }

    /** @return array<string, array<int, string>> lignes du CSV par nom */
    private function csv(array $params = []): array
    {
        $contenu = $this->actingAs($this->user)->get(self::URL.'/export/excel?'.http_build_query($params))->assertOk()->streamedContent();
        $lignes = array_map(fn (string $l) => str_getcsv($l, ';'), array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $contenu)))));
        array_shift($lignes);

        return collect($lignes)->keyBy(0)->all();
    }

    private static function montant(string $texte): float
    {
        return (float) preg_replace('/\D/', '', $texte);
    }

    public function test_par_defaut_quinzaine_en_cours_et_quinzaines_non_soldees(): void
    {
        $this->jeu();

        $ecran = $this->ecran();
        $diallo = $ecran['Diallo'];

        // 11-P2 (40 000) + 09-P1 (60 000) + 09-P2 (80 000 + 20 000) + 08-P1 (15 000) + 08-P2 (70 000).
        $this->assertSame(6, $diallo['nb_commandes']);
        $this->assertEqualsWithDelta(285000, $diallo['total_net_cumule'] + $diallo['total_frais'], 0.01);
        // Dépenses des quinzaines retenues seulement : 5 000 + 10 000 + 12 000, jamais les 30 000
        // de 10-P2 déjà déduits par la fiche payée.
        $this->assertEqualsWithDelta(27000, $diallo['total_frais'], 0.01);
        $this->assertEqualsWithDelta(120000, $diallo['total_verse'], 0.01);
        $this->assertEqualsWithDelta(138000, $diallo['solde_restant'], 0.01);

        $this->assertArrayNotHasKey('Bah', $ecran, 'un livreur entièrement soldé sort de la vue par défaut');
        $this->assertArrayHasKey('Camara', $ecran);
        $this->assertSame([], $ecran['__props']['selected_periodes']);
    }

    public function test_le_reste_a_payer_est_identique_sur_toutes_les_periodes(): void
    {
        $this->jeu();

        $defaut = $this->ecran();
        $toutes = $this->ecran(['periode' => ['toutes']]);

        $this->assertSame(['toutes'], $toutes['__props']['selected_periodes']);
        $this->assertSame(9, $toutes['Diallo']['nb_commandes']);
        $this->assertEqualsWithDelta(57000, $toutes['Diallo']['total_frais'], 0.01);
        $this->assertArrayHasKey('Bah', $toutes);
        foreach (['Diallo', 'Camara'] as $nom) {
            $this->assertEqualsWithDelta($toutes[$nom]['solde_restant'], $defaut[$nom]['solde_restant'], 0.01, $nom);
        }
        $this->assertEqualsWithDelta(0, $toutes['Bah']['solde_restant'], 0.01);
        $this->assertEqualsWithDelta($toutes['__props']['kpis']['solde_total'], $defaut['__props']['kpis']['solde_total'], 0.01);
    }

    public function test_reste_quinzaine_par_quinzaine_avec_une_periode_choisie(): void
    {
        $l = $this->jeu();
        $attendus = [
            '2026-11-P2' => [5000, 35000],
            '2026-10-P1' => [0, 0],
            '2026-10-P2' => [30000, 0],
            '2026-09-P1' => [10000, 50000],
            '2026-09-P2' => [0, 50000],
            '2026-08-P1' => [0, 15000],
        ];
        foreach ($attendus as $code => [$frais, $reste]) {
            $ligne = $this->ecran(['periode' => [$code]])['Diallo'];
            $this->assertEqualsWithDelta($frais, $ligne['total_frais'], 0.01, $code);
            $this->assertEqualsWithDelta($reste, $ligne['solde_restant'], 0.01, $code);
        }
        // 08-P2 : dépense validée après paiement, net nul, rien de dû sur la quinzaine seule.
        $this->assertEqualsWithDelta(0, $this->ecran(['periode' => ['2026-08-P2']])['Diallo']['solde_restant'], 0.01);
        $this->assertNotNull($l['diallo']);
    }

    public function test_les_exports_suivent_exactement_le_filtre_de_lecran(): void
    {
        $this->jeu();

        foreach ([[], ['periode' => ['toutes']], ['periode' => ['2026-09-P1']], ['statut' => ['impaye']]] as $params) {
            $ecran = $this->ecran($params);
            unset($ecran['__props']);
            $csv = $this->csv($params);

            $this->assertEqualsCanonicalizing(array_keys($ecran), array_keys($csv), json_encode($params));
            foreach ($ecran as $nom => $ligne) {
                // Colonnes : 7 = dépenses, 9 = déjà payé, 10 = reste à payer (arrondis à l'unité).
                $this->assertEqualsWithDelta(round($ligne['total_frais']), self::montant($csv[$nom][7]), 0.01, $nom.' '.json_encode($params));
                $this->assertEqualsWithDelta(round($ligne['total_verse']), self::montant($csv[$nom][9]), 0.01, $nom.' '.json_encode($params));
                $this->assertEqualsWithDelta(round($ligne['solde_restant']), self::montant($csv[$nom][10]), 0.01, $nom.' '.json_encode($params));
            }
        }

        $this->assertSame('Quinzaine en cours et quinzaines non soldées', $this->csv()['Diallo'][4]);
        $this->assertSame('Toutes périodes', $this->csv(['periode' => ['toutes']])['Diallo'][4]);
        $this->actingAs($this->user)->get(self::URL.'/export/pdf')->assertOk();
    }

    public function test_avec_un_filtre_statut_la_vue_par_defaut_reste_appliquee(): void
    {
        $this->jeu();

        $ligne = $this->ecran(['statut' => ['impaye']])['Diallo'];

        // Impayées retenues : 11-P2 (40 000) et 09-P1 (60 000), avec les dépenses de ces deux
        // quinzaines seulement (règle existante du filtre Statut).
        $this->assertSame(2, $ligne['nb_commandes']);
        $this->assertEqualsWithDelta(15000, $ligne['total_frais'], 0.01);
        $this->assertEqualsWithDelta(85000, $ligne['solde_restant'], 0.01);
    }

    public function test_toutes_l_emporte_sur_les_quinzaines_cochees_avec_lui(): void
    {
        $this->jeu();

        $props = $this->ecran(['periode' => ['toutes', '2026-09-P1']])['__props'];

        $this->assertSame(['toutes'], $props['selected_periodes']);
        $this->assertCount(3, $props['beneficiaires']);
    }

    public function test_la_recherche_filtre_par_nom_et_par_telephone(): void
    {
        $this->jeu();

        $this->assertSame(['Diallo'], array_keys(array_diff_key($this->ecran(['search' => 'dial']), ['__props' => true])));
        $this->assertSame(['Camara'], array_keys(array_diff_key($this->ecran(['search' => '0003']), ['__props' => true])));
        $this->assertSame(['Bah'], array_keys(array_diff_key($this->ecran(['search' => 'bah', 'periode' => ['toutes']]), ['__props' => true])));
    }

    public function test_une_vue_enregistree_peut_retenir_toutes_les_periodes(): void
    {
        $this->jeu();

        $id = $this->actingAs($this->user)->postJson(route('saved-filters.store', 'commissions-livreurs'), [
            'name' => 'Historique complet', 'visibility' => 'personal', 'filters' => ['periode' => 'toutes'],
        ])->assertCreated()->json('id');

        $props = $this->ecran(['saved_view' => $id])['__props'];
        $this->assertSame(['toutes'], $props['selected_periodes']);
        $this->assertCount(3, $props['beneficiaires']);

        $this->postJson(route('saved-filters.store', 'commissions-livreurs'), [
            'name' => 'Invalide', 'visibility' => 'personal', 'filters' => ['periode' => 'tout'],
        ])->assertUnprocessable();
    }
}
