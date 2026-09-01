<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
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
     * L'Index "Commission vente" garde son identité d'écran (vente par défaut, inchangé) tandis
     * que la fiche détail applique désormais son propre défaut ("tous"), indépendant de celui de
     * la page d'où l'on vient — Comptabilite/CommissionVente/Index.vue ne transmet donc plus
     * ?processus= dans son lien vers la fiche (vérifié manuellement/E2E, non testable ici en
     * PHPUnit puisqu'il s'agit d'un attribut de template Vue) : ce test couvre le seul côté
     * backend de la garantie, à savoir que les deux défauts sont bien indépendants l'un de
     * l'autre plutôt que le second héritant silencieusement du premier.
     */
    public function test_le_defaut_tous_processus_de_la_fiche_est_independant_du_defaut_vente_de_lindex(): void
    {
        $livreur = $this->makeLivreur();
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_VENTE, 600000);
        $this->makePartPourProcessus($livreur->id, CommissionProcessus::CODE_DISTRIBUTION_CLIENT, 250000);

        $indexProps = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.index'))
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertEquals(CommissionProcessus::CODE_VENTE, $indexProps['filtre_processus']);
        $this->assertNotNull(
            collect($indexProps['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id),
            'Le livreur doit apparaître dans la liste (par défaut filtrée sur vente).',
        );

        $showProps = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->viewData('page')['props'];
        $this->assertSame('', $showProps['filtre_processus']);
        $this->assertEquals(850000.0, (float) $showProps['commission_summary']['total_genere']);
    }
}
