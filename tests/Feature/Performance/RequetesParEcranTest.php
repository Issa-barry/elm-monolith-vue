<?php

namespace Tests\Feature\Performance;

use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\CommissionEnveloppePart;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Garde-fous de performance : le nombre de requêtes d'un écran ne doit pas grandir avec le nombre
 * de lignes affichées, et une réponse JSON ne recalcule pas les données partagées des pages.
 */
class RequetesParEcranTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['livreurs.read', 'vehicules.read', 'ventes.read']);
    }

    /**
     * Le premier appel d'un test charge une seule fois les rôles et le cache des permissions :
     * il sert de préchauffage et n'est pas compté.
     *
     * @return list<string>
     */
    private function requetesDe(callable $appel): array
    {
        $appel();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $appel();
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $sql;
    }

    private function requetesSurTable(array $sql, string $table): int
    {
        return count(array_filter($sql, fn (string $q) => str_contains($q, "from \"{$table}\"") || str_contains($q, "from `{$table}`")));
    }

    public function test_la_liste_des_livreurs_ne_fait_pas_une_requete_par_livreur(): void
    {
        Livreur::factory()->count(2)->create(['organization_id' => $this->org->id]);
        $avec2 = count($this->requetesDe(fn () => $this->actingAs($this->user)->get(route('livreurs.index'))->assertOk()));

        Livreur::factory()->count(6)->create(['organization_id' => $this->org->id]);
        $avec8 = count($this->requetesDe(fn () => $this->actingAs($this->user)->get(route('livreurs.index'))->assertOk()));

        $this->assertSame($avec2, $avec8);
    }

    public function test_la_liste_des_vehicules_charge_les_proprietaires_en_une_fois(): void
    {
        $orgId = $this->org->id;
        $typeId = TypeVehicule::where('organization_id', $orgId)->firstOrFail()->id;
        $creer = function (int $n) use ($orgId, $typeId): void {
            foreach (range(1, $n) as $_) {
                Vehicule::factory()->create([
                    'organization_id' => $orgId,
                    'type_vehicule_id' => $typeId,
                    'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $orgId])->id,
                ]);
            }
        };

        $creer(2);
        $avec2 = $this->requetesSurTable($this->requetesDe(fn () => $this->actingAs($this->user)->get(route('vehicules.index'))->assertOk()), 'personnes');

        $creer(5);
        $avec7 = $this->requetesSurTable($this->requetesDe(fn () => $this->actingAs($this->user)->get(route('vehicules.index'))->assertOk()), 'personnes');

        $this->assertSame($avec2, $avec7);
    }

    public function test_une_reponse_json_ne_calcule_pas_les_donnees_partagees(): void
    {
        $sql = $this->requetesDe(fn () => $this->actingAs($this->user)
            ->getJson(route('saved-filters.index', 'ventes'))
            ->assertOk());

        foreach (['notifications', 'contact_messages', 'transferts_logistiques', 'mouvements_fonds', 'roles'] as $table) {
            $this->assertSame(0, $this->requetesSurTable($sql, $table), "requête inattendue sur {$table}");
        }
    }

    public function test_une_page_conserve_les_donnees_partagees_et_l_agence_par_defaut(): void
    {
        $autre = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence A',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $this->user->sites()->attach($autre->id, ['role' => 'employe', 'is_default' => false]);

        $this->actingAs($this->user)
            ->get(route('livreurs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('auth.permissions', fn ($permissions) => $permissions['livreurs.read'] === true
                    && $permissions['livreurs.delete'] === false)
                ->where('auth.default_site.nom', 'Site Principal')
                ->where('auth.user_sites', $this->user->voitToutesLesAgences() ? [] : [
                    ['id' => (string) $autre->id, 'nom' => 'Agence A'],
                    ['id' => (string) $this->user->sites()->where('nom', 'Site Principal')->value('sites.id'), 'nom' => 'Site Principal'],
                ])
                ->has('stock_alertes')
                ->has('ecarts_reception')
                ->has('anomalies_encaissement')
                ->has('theme')
                ->has('org_sites', 2));
    }

    public function test_les_totaux_precharges_d_une_periode_sont_ceux_des_fiches(): void
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'type' => TypePeriodePaiement::LIVREUR->value,
            'reference' => 'PAY-202610-Q1-LIV',
            'date_debut' => '2026-10-01',
            'date_fin' => '2026-10-15',
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);
        foreach ([[1000.50, 400], [2500.25, 2500.25]] as $i => [$net, $paye]) {
            PaiementFiche::create([
                'organization_id' => $this->org->id,
                'periode_id' => $periode->id,
                'reference' => 'FP-PERF-'.$i,
                'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
                'beneficiaire_id' => Livreur::factory()->create(['organization_id' => $this->org->id])->id,
                'beneficiaire_nom' => 'Livreur',
                'montant_brut' => $net,
                'total_deductions' => 0,
                'montant_net' => $net,
                'montant_paye' => $paye,
            ]);
        }

        $direct = PaiementPeriode::findOrFail($periode->id);
        $precharge = PaiementPeriode::whereKey($periode->id)->avecTotauxFiches()->firstOrFail();

        $sql = $this->requetesDe(fn () => [$precharge->nb_fiches, $precharge->total_net, $precharge->total_paye]);
        $this->assertSame([], $sql);
        $this->assertSame($direct->nb_fiches, $precharge->nb_fiches);
        $this->assertSame($direct->total_net, $precharge->total_net);
        $this->assertSame($direct->total_paye, $precharge->total_paye);
        $this->assertSame(2, $precharge->nb_fiches);
    }

    public function test_les_beneficiaires_precharges_sont_ceux_de_la_resolution_unitaire(): void
    {
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $site = Site::where('organization_id', $this->org->id)->firstOrFail();
        $supprime = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $supprime->delete();

        $parts = collect([
            [CommissionEnveloppePart::TYPE_LIVREUR, $livreur->id],
            [CommissionEnveloppePart::TYPE_LIVREUR, $livreur->id],
            [CommissionEnveloppePart::TYPE_PROPRIETAIRE, $proprietaire->id],
            [CommissionEnveloppePart::TYPE_SITE, $site->id],
            [CommissionEnveloppePart::TYPE_LIVREUR, $supprime->id],
        ])->map(fn (array $b) => (new CommissionEnveloppePart)->forceFill(['beneficiaire_type' => $b[0], 'beneficiaire_id' => $b[1]]));

        $precharges = CommissionEnveloppePart::beneficiairesPrecharges($parts);

        foreach ($parts as $part) {
            $attendu = $part->resoudreBeneficiaire();
            $obtenu = $precharges[$part->beneficiaire_type.'|'.$part->beneficiaire_id] ?? null;
            $this->assertSame($attendu?->getKey(), $obtenu?->getKey());
            $this->assertSame($attendu?->telephone, $obtenu?->telephone);
        }
        $this->assertCount(3, $precharges);
    }
}
