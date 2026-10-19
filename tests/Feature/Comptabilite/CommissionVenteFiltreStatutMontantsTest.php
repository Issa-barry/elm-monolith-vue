<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
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
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

// ── Écran Commissions des livreurs : le filtre Statut porte sur chaque commission (statut de
// la part), jamais sur le statut global du livreur — décision produit du 18/10/2026. Les lignes,
// les colonnes, les cartes et l'export ne cumulent que les commissions retenues, et seules les
// dépenses datées dans les périodes de paie de ces commissions sont déduites. ──────────────────

class CommissionVenteFiltreStatutMontantsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siteA;

    private Site $siteB;

    private Livreur $amadou;

    private Livreur $binta;

    private ?CommissionProcessus $processus = null;

    private ?DepenseType $depenseType = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);

        $this->siteA = $this->makeSite('Agence Cba');
        $this->siteB = $this->makeSite('Agence Kouria');
        $vehiculeA = $this->makeVehicule($this->siteA);
        $vehiculeB = $this->makeVehicule($this->siteB);
        $this->amadou = $this->makeLivreur('DIALLO', 'Amadou');
        $this->binta = $this->makeLivreur('CAMARA', 'Binta');

        // Amadou (Cba) — statut global « Partiel » (une commission payée, aucune partielle).
        // 2026-09-P1 : impayée 100 000 + dépense 10 000.
        // 2026-09-P2 : à valider 40 000 + dépense 5 000.
        // 2026-08-P1 : payée 30 000.
        $this->makePart($this->amadou, $vehiculeA, '2026-09-03 10:00:00', 100000, 0, StatutCommission::IMPAYE);
        $this->makePart($this->amadou, $vehiculeA, '2026-09-20 10:00:00', 40000, 0, StatutCommission::CREEE);
        $this->makePart($this->amadou, $vehiculeA, '2026-08-05 10:00:00', 30000, 30000, StatutCommission::PAYE);
        $this->makeDepense($this->amadou, $this->siteA, '2026-09-05', 10000);
        $this->makeDepense($this->amadou, $this->siteA, '2026-09-22', 5000);

        // Binta (Kouria) — 2026-09-P1 : partielle 50 000 (20 000 versés), impayée 60 000 ;
        // 2026-09-P2 : à valider 15 000. Aucune dépense.
        $this->makePart($this->binta, $vehiculeB, '2026-09-04 10:00:00', 50000, 20000, StatutCommission::PARTIEL);
        $this->makePart($this->binta, $vehiculeB, '2026-09-06 10:00:00', 60000, 0, StatutCommission::IMPAYE);
        $this->makePart($this->binta, $vehiculeB, '2026-09-18 10:00:00', 15000, 0, StatutCommission::CREEE);
    }

    public function test_impaye_ne_cumule_que_les_commissions_validees_non_payees(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['impaye']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 2)
                ->where('beneficiaires', fn ($rows) => $this->ligne($rows, $this->amadou)['total_brut_cumule'] == 100000
                    // Seule la dépense de 2026-09-P1 (période de la commission impayée) est déduite.
                    && $this->ligne($rows, $this->amadou)['total_frais'] == 10000
                    && $this->ligne($rows, $this->amadou)['total_net_cumule'] == 90000
                    && $this->ligne($rows, $this->amadou)['total_verse'] == 0
                    && $this->ligne($rows, $this->amadou)['solde_restant'] == 90000
                    && $this->ligne($rows, $this->amadou)['total_genere'] == 100000
                    && $this->ligne($rows, $this->amadou)['en_attente_periode'] == 0
                    // Aucune commission à valider retenue : pas de bouton Valider sur la ligne.
                    && count($this->ligne($rows, $this->amadou)['creee_parts']) === 0
                    // Binta est « Partiel » au global mais a une commission impayée : elle apparaît,
                    // avec cette seule commission.
                    && $this->ligne($rows, $this->binta)['total_net_cumule'] == 60000
                    && $this->ligne($rows, $this->binta)['solde_restant'] == 60000)
                ->where('kpis.nb_livreurs', 2)
                ->where('kpis.total_genere', fn ($v) => (float) $v === 160000.0)
                ->where('kpis.total_net', fn ($v) => (float) $v === 150000.0)
                ->where('kpis.solde_total', fn ($v) => (float) $v === 150000.0)
                ->where('kpis.en_attente_periode', fn ($v) => (float) $v === 0.0)
            );
    }

    public function test_a_valider_ne_cumule_que_les_commissions_non_validees(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['creee']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 2)
                ->where('beneficiaires', fn ($rows) => $this->ligne($rows, $this->amadou)['total_brut_cumule'] == 40000
                    && $this->ligne($rows, $this->amadou)['total_frais'] == 5000
                    && $this->ligne($rows, $this->amadou)['total_net_cumule'] == 35000
                    && $this->ligne($rows, $this->amadou)['statut_global'] === 'creee'
                    && count($this->ligne($rows, $this->amadou)['creee_parts']) === 1
                    && $this->ligne($rows, $this->binta)['total_net_cumule'] == 15000)
                ->where('kpis.total_net', fn ($v) => (float) $v === 50000.0)
                ->where('kpis.payable', fn ($v) => (float) $v === 0.0)
            );
    }

    public function test_partiel_ne_recompte_pas_les_sommes_deja_versees(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['partiel']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 1)
                ->where('beneficiaires.0.beneficiaire_id', $this->binta->id)
                ->where('beneficiaires.0.total_net_cumule', fn ($v) => (float) $v === 50000.0)
                ->where('beneficiaires.0.total_verse', fn ($v) => (float) $v === 20000.0)
                ->where('beneficiaires.0.solde_restant', fn ($v) => (float) $v === 30000.0)
                ->where('kpis.solde_total', fn ($v) => (float) $v === 30000.0)
            );
    }

    public function test_paye_ne_cumule_que_les_commissions_reglees(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['paye']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 1)
                ->where('beneficiaires.0.beneficiaire_id', $this->amadou->id)
                // Aucune dépense dans 2026-08-P1 : rien n'est déduit.
                ->where('beneficiaires.0.total_frais', fn ($v) => (float) $v === 0.0)
                ->where('beneficiaires.0.total_net_cumule', fn ($v) => (float) $v === 30000.0)
                ->where('beneficiaires.0.total_verse', fn ($v) => (float) $v === 30000.0)
                ->where('beneficiaires.0.solde_restant', fn ($v) => (float) $v === 0.0)
                ->where('beneficiaires.0.statut_global', 'paye')
            );
    }

    public function test_plusieurs_statuts_cumulent_leurs_commissions_et_leurs_depenses_une_seule_fois(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['impaye', 'creee']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 2)
                ->where('beneficiaires', fn ($rows) => $this->ligne($rows, $this->amadou)['total_brut_cumule'] == 140000
                    && $this->ligne($rows, $this->amadou)['total_frais'] == 15000
                    && $this->ligne($rows, $this->amadou)['total_net_cumule'] == 125000
                    && $this->ligne($rows, $this->binta)['total_net_cumule'] == 75000)
            );
    }

    public function test_sans_filtre_statut_les_montants_restent_cumules_sur_toutes_les_commissions(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 2)
                ->where('beneficiaires', fn ($rows) => $this->ligne($rows, $this->amadou)['total_brut_cumule'] == 170000
                    && $this->ligne($rows, $this->amadou)['total_frais'] == 15000
                    && $this->ligne($rows, $this->amadou)['total_net_cumule'] == 155000
                    && $this->ligne($rows, $this->amadou)['total_verse'] == 30000
                    && $this->ligne($rows, $this->amadou)['solde_restant'] == 125000
                    && $this->ligne($rows, $this->binta)['total_net_cumule'] == 125000)
            );
    }

    public function test_statut_se_combine_avec_periode_agence_et_recherche(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['impaye', 'creee'], 'periode' => ['2026-09-P1']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 2)
                ->where('beneficiaires', fn ($rows) => $this->ligne($rows, $this->amadou)['total_net_cumule'] == 90000
                    && $this->ligne($rows, $this->binta)['total_net_cumule'] == 60000)
            );

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['impaye'], 'site_ids' => [$this->siteB->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 1)
                ->where('beneficiaires.0.beneficiaire_id', $this->binta->id)
                ->where('kpis.total_net', fn ($v) => (float) $v === 60000.0)
            );

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['statut' => ['impaye'], 'search' => 'Amadou']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('beneficiaires', 1)
                ->where('beneficiaires.0.beneficiaire_id', $this->amadou->id)
                ->where('kpis.total_net', fn ($v) => (float) $v === 90000.0)
            );
    }

    public function test_export_excel_reprend_les_montants_filtres(): void
    {
        $csv = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel', ['statut' => ['impaye']]))
            ->assertOk()
            ->streamedContent();

        $lignes = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lignes, 'En-tête + Amadou + Binta');

        $amadou = $this->ligneCsv($lignes, 'Amadou');
        // Généré ; Brut ; Dépenses ; Net ; Déjà payé ; Reste — commission à valider et dépense de
        // 2026-09-P2 exclues.
        $this->assertSame(['100 000', '100 000', '10 000', '90 000', '0', '90 000', 'Impayée'], array_slice($amadou, 5, 7));

        $binta = $this->ligneCsv($lignes, 'Binta');
        $this->assertSame(['60 000', '60 000', '0', '60 000', '0', '60 000', 'Impayée'], array_slice($binta, 5, 7));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param  iterable<int, array<string, mixed>>  $rows */
    private function ligne(iterable $rows, Livreur $livreur): array
    {
        $ligne = collect($rows)->first(fn ($r) => $r['beneficiaire_id'] === $livreur->id);
        $this->assertNotNull($ligne, "Ligne absente pour le livreur {$livreur->id}");

        return is_array($ligne) ? $ligne : $ligne->toArray();
    }

    /** @return array<int, string> */
    private function ligneCsv(array $lignes, string $nom): array
    {
        $ligne = collect($lignes)->first(fn (string $l) => str_contains($l, $nom));
        $this->assertNotNull($ligne, "Ligne CSV absente pour {$nom}");

        return array_map(fn ($c) => str_replace("\u{00A0}", ' ', $c), str_getcsv($ligne, ';'));
    }

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Site $site): Vehicule
    {
        return Vehicule::create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => 'Camion '.$site->nom,
            'immatriculation' => 'GN-'.uniqid(),
            'site_id' => $site->id,
            'categorie' => 'interne',
            'capacite_packs' => 500,
            'is_active' => true,
        ]);
    }

    private function makeLivreur(string $nom, string $prenom): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => $prenom,
            'telephone' => '62200'.random_int(1000, 9999),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => "{$prenom} {$nom}",
            'is_active' => true,
        ]);
    }

    private function makePart(Livreur $livreur, Vehicule $vehicule, string $earnedAt, float $montant, float $verse, StatutCommission $statut): void
    {
        $this->processus ??= CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => 'actif',
            ],
        );

        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'reference' => 'CMD-'.uniqid(),
            'site_id' => $vehicule->site_id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'chargement_en_cours',
            'total_commande' => 500000,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $earnedAt,
            'statut' => $statut->value,
        ]);

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'taux_repartition_snapshot' => 100,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => $verse,
            'statut' => $statut->value,
        ]);
    }

    private function makeDepense(Livreur $livreur, Site $site, string $date, float $montant): void
    {
        $this->depenseType ??= DepenseType::create([
            'organization_id' => $this->org->id,
            'code' => 'AVANCE',
            'libelle' => 'Avance sur commission',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ]);

        Depense::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $this->depenseType->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => $montant,
            'date_depense' => $date,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }
}
