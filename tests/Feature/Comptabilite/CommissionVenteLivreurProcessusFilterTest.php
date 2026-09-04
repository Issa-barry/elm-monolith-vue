<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\Livreur;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Correctif du 31/08/2026 : la fiche détail d'un bénéficiaire de commission (Livreur — Vente)
 * était figée sur ?processus=vente (défaut serveur ET lien depuis l'Index), ce qui masquait
 * silencieusement ses commissions Distribution client/Transfert logistique sans aucune
 * indication ni sélecteur pour changer de vue. Cf. docs/commissions.md.
 */
class CommissionVenteLivreurProcessusFilterTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
    }

    private function makeLivreur(): Livreur
    {
        return Livreur::factory()->create(['organization_id' => $this->org->id]);
    }

    /**
     * Un même livreur reçoit une commission sur chacun des 3 processus — montants distincts et
     * facilement reconnaissables pour détecter tout mélange ou toute perte.
     */
    private function makePartPourProcessus(string $livreurId, string $processusCode, float $montant): CommissionEnveloppePart
    {
        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => $processusCode],
            [
                'libelle' => $processusCode,
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => 'actif',
            ],
        );

        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return $enveloppe->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreurId,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    public function test_vue_tous_les_processus_est_le_defaut_et_consolide_les_3_processus(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 100000);

        // Aucun paramètre processus dans l'URL : simule l'arrivée depuis l'Index (qui ne le
        // force plus, cf. Comptabilite/CommissionVente/Index.vue) ou une visite directe.
        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Livreur/Show')
                ->where('filtre_processus', '')
                ->where('commission_summary.total_genere', fn ($v) => (float) $v === 950000.0)
                ->has('commission_details', 3)
                ->has('processus_options', 4)
                ->where('processus_options.0.value', '')
                ->where('processus_options.0.label', 'Tous les processus')
            );
    }

    public function test_totaux_de_la_repartition_par_processus_sont_corrects_et_ne_perdent_aucune_commission(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 100000);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk();

        $props = $response->viewData('page')['props'];
        $breakdown = collect($props['breakdown_par_processus'])->keyBy('code');

        $this->assertEquals(600000.0, (float) $breakdown[CommissionProcessus::CODE_VENTE]['total_genere']);
        $this->assertEquals(250000.0, (float) $breakdown[CommissionProcessus::CODE_DISTRIBUTION_CLIENT]['total_genere']);
        $this->assertEquals(100000.0, (float) $breakdown[CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT]['total_genere']);

        // La somme des 3 lignes doit reconstituer exactement le total consolidé affiché dans les
        // KPI — aucune commission de l'un des 3 processus ne doit disparaître de la vue globale.
        $sommeBreakdown = collect($breakdown)->sum('total_genere');
        $this->assertEquals((float) $props['commission_summary']['total_genere'], $sommeBreakdown);
        $this->assertEquals(950000.0, $sommeBreakdown);

        // Chaque ligne du détail par commande porte son origine, jamais un montant sans indication.
        $origines = collect($props['commission_details'])->pluck('processus')->sort()->values()->all();
        $this->assertSame([
            CommissionProcessus::CODE_DISTRIBUTION_CLIENT,
            CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            CommissionProcessus::CODE_VENTE,
        ], $origines);
    }

    public function test_vue_vente_isole_les_commissions_du_processus_vente(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 100000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', ['livreurId' => $livreur->id, 'processus' => CommissionProcessus::CODE_VENTE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filtre_processus', CommissionProcessus::CODE_VENTE)
                ->where('commission_summary.total_genere', fn ($v) => (float) $v === 600000.0)
                ->has('commission_details', 1)
                ->where('commission_details.0.processus', CommissionProcessus::CODE_VENTE)
                // Un processus précis étant déjà sélectionné, la répartition redondante n'est pas
                // affichée.
                ->where('breakdown_par_processus', null)
            );
    }

    public function test_vue_distribution_isole_les_commissions_de_distribution_client(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 100000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', ['livreurId' => $livreur->id, 'processus' => CommissionProcessus::CODE_DISTRIBUTION_CLIENT]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filtre_processus', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
                ->where('commission_summary.total_genere', fn ($v) => (float) $v === 250000.0)
                ->has('commission_details', 1)
                ->where('commission_details.0.processus', CommissionProcessus::CODE_DISTRIBUTION_CLIENT)
            );
    }

    public function test_vue_transfert_isole_les_commissions_de_transfert_logistique(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 100000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', ['livreurId' => $livreur->id, 'processus' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filtre_processus', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
                ->where('commission_summary.total_genere', fn ($v) => (float) $v === 100000.0)
                ->has('commission_details', 1)
                ->where('commission_details.0.processus', CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT)
            );
    }

    /**
     * Révisé le 02/09/2026 : l'Index "Commission vente" ne repose plus sur un défaut implicite
     * "vente" (case à cocher multiple avec union réelle désormais, cf. docs/commissions.md et
     * CommissionProcessusFilter) — aucune sélection y consolide donc aussi les 3 processus, comme
     * la fiche détail. Ce test vérifie ce nouveau défaut ET que les deux écrans gardent chacun
     * leur propre état de filtre, jamais partagé ni hérité de l'un vers l'autre : un filtre
     * explicite posé sur la fiche détail n'affecte jamais ce que montre l'Index, et vice versa.
     */
    public function test_le_defaut_tous_processus_de_lindex_est_independant_du_filtre_explicite_de_la_fiche(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);

        $indexProps = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame([], $indexProps['filtre_processus']);
        $this->assertNotNull(
            collect($indexProps['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id),
            'Le livreur doit apparaître dans la liste (par défaut Tous les processus).',
        );

        $showProps = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', ['livreurId' => $livreur->id, 'processus' => CommissionProcessus::CODE_VENTE]))
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame(CommissionProcessus::CODE_VENTE, $showProps['filtre_processus']);
        $this->assertEquals(600000.0, (float) $showProps['commission_summary']['total_genere']);
    }

    /**
     * Régression directe du 02/09/2026 (bug constaté en production) : cocher plusieurs cases du
     * filtre "Processus" sur l'Index (ex: Vente + Transfert logistique) doit montrer l'UNION des
     * deux — jusque-là, DataFilters.vue n'envoyait que la première valeur cochée
     * (?processus=vente), masquant silencieusement les autres processus cochés sans aucune
     * indication : un livreur dont le total réel combinait plusieurs processus voyait son total
     * artificiellement réduit au seul premier processus de la liste d'options. La colonne
     * "Processus" du détail confirme la provenance de chaque ligne, même consolidée.
     */
    public function test_lindex_unit_plusieurs_processus_coches_au_lieu_de_ne_garder_que_le_premier(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT, 100000);

        $indexProps = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['processus' => [
                CommissionProcessus::CODE_VENTE,
                CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
            ]]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertEqualsCanonicalizing(
            [CommissionProcessus::CODE_VENTE, CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT],
            $indexProps['filtre_processus'],
        );

        $row = collect($indexProps['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id);
        $this->assertNotNull($row);
        // Union de vente (600 000) + transfert logistique (100 000) — jamais uniquement le
        // premier coché (600 000 seul, l'ancien bug) ni les 3 processus (950 000, qui inclurait
        // la distribution non cochée).
        $this->assertEqualsWithDelta(700000.0, (float) $row['total_genere'], 0.01);
        $this->assertEqualsCanonicalizing(['Vente', 'Transfert logistique'], $row['processus_labels']);
    }

    /**
     * Régression du 02/09/2026 (incident production) : CommissionEnveloppe::source est
     * polymorphe (CommandeVente OU TransfertLogistique), mais l'eager-load
     * `enveloppe.source.site:id,nom` de ce contrôleur (et de
     * CommissionSiteController/CommissionProprietaireController, même pattern) suppose que TOUT
     * modèle source expose une relation `site()`. Vrai pour CommandeVente, faux pour
     * TransfertLogistique (qui n'a que siteSource()/siteDestination()) — RelationNotFoundException
     * dès qu'une CommissionEnveloppePart réellement issue d'un transfert (pas d'une commande
     * factice, contrairement à makePartPourProcessus() ci-dessus) apparaît dans le résultat.
     * Jamais détecté avant car aucun test existant ne créait d'enveloppe avec
     * source_type = TransfertLogistique::class. Corrigé par TransfertLogistique::site() (alias de
     * siteSource()).
     */
    public function test_filtre_transfert_logistique_ne_plante_pas_avec_une_vraie_enveloppe_issue_dun_transfert(): void
    {
        $livreur = $this->makeLivreur();
        $siteSource = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site Source', 'type' => 'depot']);
        $siteDestination = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site Dest', 'type' => 'siege']);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'livraison_logistique' => true]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $siteSource->id,
            'site_destination_id' => $siteDestination->id,
            'vehicule_id' => $vehicule->id,
            'equipe_livraison_id' => $equipe->id,
            'statut' => StatutTransfert::RECEPTION,
            'created_by' => $this->user->id,
        ]);

        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT],
            ['libelle' => 'Transfert logistique', 'declencheur' => 'reception_effectuee', 'strategie_ancrage_site' => 'source', 'statut' => 'actif'],
        );
        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => TransfertLogistique::class,
            'source_id' => $transfert->id,
            'processus_id' => $processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 125000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);
        $enveloppe->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 125000,
            'montant_net' => 125000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index', ['processus' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT]))
            ->assertOk();

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', ['livreurId' => $livreur->id, 'processus' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commission_summary.total_genere', fn ($v) => (float) $v === 125000.0)
            );
    }
}
