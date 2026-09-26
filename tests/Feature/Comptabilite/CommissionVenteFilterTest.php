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
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionVenteFilterTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read']);
        $this->actingAs($this->user);
    }

    // ── Régression : DataFilters envoie toujours les champs "select" sous
    // forme de tableau (ex: statut[]=impaye), même pour un choix unique.
    // Avant le fix, (string) $request->input('statut', '') plantait avec
    // "Array to string conversion" dès qu'un filtre était sélectionné
    // (cf. Sentry — même bug déjà corrigé sur Commission Logistique mais
    // jamais reporté ici). ────────────────────────────────────────────────────

    public function test_index_avec_statut_envoye_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['statut' => ['impaye']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/CommissionVente/Index')
                ->where('filtre_statut', 'impaye')
            );
    }

    public function test_index_avec_periode_envoyee_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['periode' => ['2026-06-P1']]))
            ->assertOk();
    }

    public function test_index_avec_site_ids_envoyes_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['site_ids' => [$this->user->sites()->first()->id]]))
            ->assertOk();
    }

    /**
     * Régression Sentry (preprod, 06/09/2026) : CommissionEnveloppe::source est polymorphe
     * (CommandeVente OU TransfertLogistique). Le filtre `site_ids` interrogeait la colonne
     * `site_id` brute du modèle source via `whereHas('enveloppe.source', ...)` — absente de
     * TransfertLogistique, qui n'expose que site_source_id/site_destination_id. Sur MySQL
     * (preprod), ça lève "Unknown column 'site_id'". Sur SQLite (suite de tests), la même colonne
     * manquante ne fait PAS planter la requête : elle est résolue silencieusement à NULL, donc
     * `site_id in (...)` ne matche jamais rien — la commission issue d'un transfert disparaît
     * juste silencieusement du résultat, sans aucune erreur. D'où l'assertion ci-dessous sur le
     * contenu réel (le livreur doit apparaître), pas seulement sur le code HTTP : un simple
     * assertOk() aurait laissé passer ce bug indéfiniment en local/CI. Corrigé en passant par la
     * relation `site()` (alias polymorphisme-safe, cf. TransfertLogistique::site()) plutôt que par
     * le nom de colonne brut du modèle source.
     */
    public function test_index_avec_site_ids_inclut_une_commission_issue_dun_transfert_logistique(): void
    {
        $site = $this->user->sites()->first();
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $siteDestination = Site::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'livraison_logistique' => true]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $site->id,
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
            'montant_total' => 50000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);
        $enveloppe->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 50000,
            'montant_net' => 50000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $indexProps = $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['site_ids' => [$site->id]]))
            ->assertOk()
            ->viewData('page')['props'];

        $row = collect($indexProps['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id);
        $this->assertNotNull($row, 'Le livreur doit apparaître : le transfert a bien lieu sur le site filtré (site_source_id).');
        $this->assertEqualsWithDelta(50000.0, (float) $row['total_genere'], 0.01);

        $exportContent = $this->get('/backoffice/comptabilite/commissions/vente/export/excel?'.http_build_query(['site_ids' => [$site->id]]))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString($site->nom, $exportContent, 'La ligne export du livreur (agence issue du transfert) doit être présente.');
    }

    /**
     * Régression Sentry (preprod, 13/09/2026) : le fix ci-dessus (passer par la relation `site()`
     * via whereHas('enveloppe.source.site', ...)) corrige bien la colonne `site_id` absente de
     * TransfertLogistique, mais cette chaîne à points expose un second bug — cette fois côté
     * Laravel — dès que la table commission_enveloppes contient AU MOINS DEUX source_type distincts
     * (CommandeVente ET TransfertLogistique) : whereHas() sur une relation à 3 niveaux qui traverse
     * un MorphTo intermédiaire (`source`) rappelle par erreur ->source() sur le modèle déjà résolu
     * du second type testé, qui ne porte pas cette relation — BadMethodCallException "Call to
     * undefined method App\Models\TransfertLogistique::source()". Le test précédent ne créait
     * qu'un seul TransfertLogistique (donc un seul source_type en base), ce qui ne déclenche jamais
     * ce second bug. Corrigé en passant par CommissionSourceSiteFilter (whereHasMorph() manuel,
     * jamais une chaîne à points) dans CommissionVenteController, CommissionSiteController et
     * CommissionProprietaireController (même pattern dupliqué dans les trois).
     */
    public function test_index_avec_site_ids_ne_plante_pas_quand_commande_vente_et_transfert_logistique_coexistent(): void
    {
        $site = $this->user->sites()->first();

        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $site->id]);
        $livreurVente = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $processusVente = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            ['libelle' => 'Vente', 'declencheur' => 'facture_encaissee', 'strategie_ancrage_site' => 'source', 'statut' => 'actif'],
        );
        $enveloppeVente = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processusVente->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 30000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);
        $enveloppeVente->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreurVente->id,
            'montant_brut' => 30000,
            'montant_net' => 30000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $livreurTransfert = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $siteDestination = Site::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'livraison_logistique' => true]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $site->id,
            'site_destination_id' => $siteDestination->id,
            'vehicule_id' => $vehicule->id,
            'equipe_livraison_id' => $equipe->id,
            'statut' => StatutTransfert::RECEPTION,
            'created_by' => $this->user->id,
        ]);
        $processusTransfert = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT],
            ['libelle' => 'Transfert logistique', 'declencheur' => 'reception_effectuee', 'strategie_ancrage_site' => 'source', 'statut' => 'actif'],
        );
        $enveloppeTransfert = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => TransfertLogistique::class,
            'source_id' => $transfert->id,
            'processus_id' => $processusTransfert->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 50000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);
        $enveloppeTransfert->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreurTransfert->id,
            'montant_brut' => 50000,
            'montant_net' => 50000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $indexProps = $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['site_ids' => [$site->id]]))
            ->assertOk()
            ->viewData('page')['props'];

        $livreurIds = collect($indexProps['beneficiaires'])->pluck('beneficiaire_id')->all();
        $this->assertContains((string) $livreurVente->id, $livreurIds, 'La commission issue de la CommandeVente doit rester visible.');
        $this->assertContains((string) $livreurTransfert->id, $livreurIds, 'La commission issue du TransfertLogistique doit rester visible.');
    }

    public function test_export_excel_avec_filtres_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente/export/excel?'.http_build_query([
            'statut' => ['impaye'],
            'periode' => ['2026-06-P1'],
        ]))->assertOk();
    }

    public function test_export_pdf_avec_filtres_en_tableau_ne_plante_pas(): void
    {
        $this->get('/backoffice/comptabilite/commissions/vente/export/pdf?'.http_build_query([
            'statut' => ['impaye'],
            'periode' => ['2026-06-P1'],
        ]))->assertOk();
    }
}
