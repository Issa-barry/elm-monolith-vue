<?php

namespace Tests\Feature;

use App\Enums\StatutDepense;
use App\Models\CommandeVente;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ADR 0031 — rattachement d'une dépense de carburant à sa commande de vente ou à son transfert
 * logistique (cf. docs/depenses-carburant-commande.md).
 */
class DepenseCarburantCommandeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $user;

    private DepenseType $carburant;

    private Vehicule $vehicule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-16 12:00:00'));

        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $permissions = ['depenses.read', 'depenses.create', 'depenses.update'];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);
        $this->user->assignRole('manager');
        $this->user->givePermissionTo($permissions);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'manager',
            'perimetre' => 'agences_selectionnees',
            'sites' => [$this->site->id],
            'is_actif' => true,
        ]);

        $this->carburant = DepenseType::factory()->vehicule()->create([
            'organization_id' => $this->org->id,
            'libelle' => 'Carburant véhicule',
            'code' => 'carburant_vehicule',
            'commande_obligatoire' => true,
        ]);

        $this->vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user);
    }

    private function vente(string $reference, string $date, array $attrs = []): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'reference' => $reference,
            ...$attrs,
        ]);
        $commande->forceFill(['created_at' => Carbon::parse($date.' 08:00:00')])->saveQuietly();

        return $commande;
    }

    private function transfert(string $reference, string $date, array $attrs = []): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => $reference,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $this->site->id,
            'vehicule_id' => $this->vehicule->id,
            'statut' => 'transit',
            'created_by' => $this->user->id,
            ...$attrs,
        ]);
        $transfert->forceFill(['created_at' => Carbon::parse($date.' 08:00:00')])->saveQuietly();

        return $transfert;
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'depense_type_id' => $this->carburant->id,
            'beneficiaire_id' => $this->vehicule->id,
            'site_id' => $this->site->id,
            'montant' => 150000,
            'date_depense' => '2026-10-16',
            'commentaire' => null,
            'statut' => 'soumis',
        ], $override);
    }

    private function candidats(string $dateDepense = '2026-10-16', ?string $siteId = null): array
    {
        return $this->getJson(route('depenses.commandes-carburant', array_filter([
            'vehicule_id' => $this->vehicule->id,
            'date_depense' => $dateDepense,
            'site_id' => $siteId,
        ])))->assertOk()->json();
    }

    private function autreSite(): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Kipé',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    // ── Liste des commandes rattachables ──────────────────────────────────────

    public function test_liste_les_commandes_du_vehicule_du_jour_de_la_depense(): void
    {
        $this->vente('VTE-161026-001', '2026-10-16');
        $this->transfert('TRF-161026-002', '2026-10-16');
        $this->vente('VTE-151026-003', '2026-10-15');
        $this->vente('VTE-161026-004', '2026-10-16', ['statut' => 'annulee']);
        $this->vente('VTE-161026-005', '2026-10-16', ['statut' => 'annulee_erreur_saisie']);
        $this->transfert('TRF-161026-006', '2026-10-16', ['statut' => 'annule']);
        $autre = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $this->vente('VTE-161026-008', '2026-10-16', ['vehicule_id' => $autre->id]);

        $resultat = $this->candidats();

        $this->assertEqualsCanonicalizing(
            ['VTE-161026-001', 'TRF-161026-002'],
            array_column($resultat['candidats'], 'reference'),
        );
        $this->assertNull($resultat['message']);
    }

    public function test_commande_creee_la_veille_et_livree_le_jour_est_listee(): void
    {
        $vente = $this->vente('VTE-151026-001', '2026-10-15', ['livree_at' => '2026-10-16 09:00:00']);
        $transfert = $this->transfert('TRF-151026-001', '2026-10-15', ['date_depart_reelle' => '2026-10-16']);

        $ids = array_column($this->candidats()['candidats'], 'id');

        $this->assertEqualsCanonicalizing([$vente->id, $transfert->id], $ids);
    }

    public function test_liste_filtree_par_agence_de_la_depense(): void
    {
        $kipe = $this->autreSite();
        $this->vente('VTE-161026-001', '2026-10-16');
        $this->vente('VTE-161026-002', '2026-10-16', ['site_id' => $kipe->id]);
        $this->transfert('TRF-161026-001', '2026-10-16', ['site_source_id' => $kipe->id, 'site_destination_id' => $this->site->id]);
        $this->transfert('TRF-161026-002', '2026-10-16', ['site_source_id' => $kipe->id, 'site_destination_id' => $kipe->id]);

        $references = array_column($this->candidats('2026-10-16', $this->site->id)['candidats'], 'reference');

        $this->assertEqualsCanonicalizing(['VTE-161026-001', 'TRF-161026-001'], $references);
        $this->assertCount(4, $this->candidats()['candidats']);
    }

    public function test_message_quand_aucune_commande_pour_ce_jour_et_cette_agence(): void
    {
        $this->vente('VTE-161026-001', '2026-10-16');

        $resultat = $this->candidats('2026-10-15', $this->site->id);

        $this->assertSame([], $resultat['candidats']);
        $this->assertStringContainsString("le 15/10/2026 pour l'agence Agence Test", $resultat['message']);
    }

    public function test_chaque_candidate_porte_son_code_abrege_pour_le_filtre(): void
    {
        $this->vente('VTE-161026-002', '2026-10-16');
        $this->transfert('TRF-161026-002', '2026-10-16');
        $this->vente('CMD-160826-012', '2026-10-16');

        $codes = array_column($this->candidats()['candidats'], 'code_abrege', 'reference');

        $this->assertSame('16-002', $codes['VTE-161026-002']);
        $this->assertSame('16-002', $codes['TRF-161026-002']);
        $this->assertSame('16-012', $codes['CMD-160826-012']);
    }

    public function test_liste_ignore_les_commandes_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        CommandeVente::factory()->create([
            'organization_id' => $autreOrg->id,
            'vehicule_id' => $this->vehicule->id,
            'reference' => 'VTE-161026-001',
        ]);

        $this->assertSame([], $this->candidats()['candidats']);
    }

    public function test_recherche_refusee_sans_permission_de_saisie(): void
    {
        $lecteur = User::factory()->create(['organization_id' => $this->org->id]);
        $lecteur->givePermissionTo('depenses.read');
        $lecteur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($lecteur)
            ->getJson(route('depenses.commandes-carburant', [
                'vehicule_id' => $this->vehicule->id,
                'date_depense' => '2026-10-16',
            ]))
            ->assertForbidden();
    }

    public function test_recherche_refuse_un_vehicule_d_une_autre_organisation(): void
    {
        $vehiculeAutreOrg = Vehicule::factory()->create();

        $this->getJson(route('depenses.commandes-carburant', [
            'vehicule_id' => $vehiculeAutreOrg->id,
            'date_depense' => '2026-10-16',
        ]))->assertUnprocessable();
    }

    // ── Enregistrement ────────────────────────────────────────────────────────

    public function test_carburant_sans_commande_ni_exception_est_refuse(): void
    {
        $this->post(route('depenses.store'), $this->payload())
            ->assertSessionHasErrors('commande');

        $this->assertDatabaseCount('depenses', 0);
    }

    public function test_carburant_rattache_a_une_vente_est_enregistre(): void
    {
        $commande = $this->vente('VTE-161026-002', '2026-10-16');

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('depenses', [
            'commande_vente_id' => $commande->id,
            'transfert_logistique_id' => null,
            'motif_sans_commande' => null,
            'statut' => StatutDepense::SOUMIS->value,
        ]);
    }

    public function test_carburant_rattache_a_un_transfert_est_enregistre(): void
    {
        $transfert = $this->transfert('TRF-161026-001', '2026-10-16');

        $this->post(route('depenses.store'), $this->payload(['transfert_logistique_id' => $transfert->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('depenses', ['transfert_logistique_id' => $transfert->id, 'commande_vente_id' => null]);
    }

    public function test_deux_commandes_a_la_fois_sont_refusees(): void
    {
        $commande = $this->vente('VTE-161026-001', '2026-10-16');
        $transfert = $this->transfert('TRF-161026-001', '2026-10-16');

        $this->post(route('depenses.store'), $this->payload([
            'commande_vente_id' => $commande->id,
            'transfert_logistique_id' => $transfert->id,
        ]))->assertSessionHasErrors('commande');
    }

    public function test_commande_annulee_est_refusee(): void
    {
        $commande = $this->vente('VTE-161026-001', '2026-10-16', ['statut' => 'annulee_erreur_saisie']);

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasErrors('commande');
    }

    public function test_transfert_annule_est_refuse(): void
    {
        $transfert = $this->transfert('TRF-161026-001', '2026-10-16', ['statut' => 'annule']);

        $this->post(route('depenses.store'), $this->payload(['transfert_logistique_id' => $transfert->id]))
            ->assertSessionHasErrors('commande');
    }

    public function test_commande_d_un_autre_vehicule_est_refusee(): void
    {
        $autre = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $commande = $this->vente('VTE-161026-001', '2026-10-16', ['vehicule_id' => $autre->id]);

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasErrors('commande');
    }

    public function test_commande_d_un_autre_jour_est_refusee(): void
    {
        $commande = $this->vente('VTE-141026-001', '2026-10-14');

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasErrors(['commande' => "Cette commande n'est pas du jour de la dépense."]);
    }

    public function test_precommande_livree_le_jour_de_la_depense_est_acceptee(): void
    {
        $commande = $this->vente('VTE-151026-001', '2026-10-15', ['livree_at' => '2026-10-16 09:00:00']);

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_commande_d_une_autre_agence_est_refusee(): void
    {
        $commande = $this->vente('VTE-161026-001', '2026-10-16', ['site_id' => $this->autreSite()->id]);

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasErrors(['commande' => "Cette commande ne concerne pas l'agence de la dépense."]);
    }

    public function test_transfert_arrivant_a_l_agence_de_la_depense_est_accepte(): void
    {
        $transfert = $this->transfert('TRF-161026-001', '2026-10-16', ['site_source_id' => $this->autreSite()->id]);

        $this->post(route('depenses.store'), $this->payload(['transfert_logistique_id' => $transfert->id]))
            ->assertSessionHasNoErrors();
    }

    public function test_commande_posterieure_a_la_depense_est_refusee(): void
    {
        $commande = $this->vente('VTE-161026-001', '2026-10-16');

        $this->post(route('depenses.store'), $this->payload([
            'commande_vente_id' => $commande->id,
            'date_depense' => '2026-10-15',
        ]))->assertSessionHasErrors('commande');
    }

    public function test_commande_d_une_autre_organisation_est_refusee(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'vehicule_id' => $this->vehicule->id,
        ]);

        $this->post(route('depenses.store'), $this->payload(['commande_vente_id' => $commande->id]))
            ->assertSessionHasErrors('commande');
    }

    public function test_exception_sans_motif_est_refusee(): void
    {
        $this->post(route('depenses.store'), $this->payload(['sans_commande' => true, 'motif_sans_commande' => '']))
            ->assertSessionHasErrors('motif_sans_commande');
    }

    public function test_exception_motivee_est_enregistree_sans_commande(): void
    {
        $commande = $this->vente('VTE-161026-001', '2026-10-16');

        $this->post(route('depenses.store'), $this->payload([
            'sans_commande' => true,
            'motif_sans_commande' => 'Plein de début de semaine',
            'commande_vente_id' => $commande->id,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('depenses', [
            'motif_sans_commande' => 'Plein de début de semaine',
            'commande_vente_id' => null,
        ]);
    }

    public function test_type_sans_commande_obligatoire_ignore_le_rattachement(): void
    {
        $reparation = DepenseType::factory()->vehicule()->create([
            'organization_id' => $this->org->id,
            'commande_obligatoire' => false,
        ]);
        $commande = $this->vente('VTE-161026-001', '2026-10-16');

        $this->post(route('depenses.store'), $this->payload([
            'depense_type_id' => $reparation->id,
            'commande_vente_id' => $commande->id,
            'motif_sans_commande' => 'ignoré',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('depenses', [
            'depense_type_id' => $reparation->id,
            'commande_vente_id' => null,
            'motif_sans_commande' => null,
        ]);
    }

    public function test_changer_de_vehicule_invalide_la_commande_rattachee(): void
    {
        $commande = $this->vente('VTE-161026-001', '2026-10-16');
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $this->carburant->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $this->vehicule->id,
            'commande_vente_id' => $commande->id,
        ]);
        $autre = Vehicule::factory()->create(['organization_id' => $this->org->id]);

        $this->put(route('depenses.update', $depense), $this->payload([
            'beneficiaire_id' => $autre->id,
            'commande_vente_id' => $commande->id,
            'statut' => 'brouillon',
        ]))->assertSessionHasErrors('commande');
    }

    // ── Soumission d'un brouillon antérieur à la règle ────────────────────────

    public function test_soumettre_un_brouillon_carburant_non_rattache_est_refuse(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $this->carburant->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $this->vehicule->id,
        ]);

        $this->patch(route('depenses.soumettre', $depense))->assertSessionHasErrors('commande');

        $this->assertSame(StatutDepense::BROUILLON, $depense->fresh()->statut);
    }

    public function test_soumettre_un_brouillon_avec_exception_est_accepte(): void
    {
        $depense = Depense::factory()->brouillon()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $this->carburant->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $this->vehicule->id,
            'motif_sans_commande' => 'Trajet au garage',
        ]);

        $this->patch(route('depenses.soumettre', $depense))->assertSessionHasNoErrors();

        $this->assertSame(StatutDepense::SOUMIS, $depense->fresh()->statut);
    }

    // ── Affichage ─────────────────────────────────────────────────────────────

    public function test_detail_et_liste_identifient_la_depense_sans_commande(): void
    {
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $this->carburant->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $this->vehicule->id,
            'motif_sans_commande' => 'Retour à vide',
        ]);

        $this->get(route('depenses.show', $depense))
            ->assertInertia(fn ($page) => $page
                ->where('depense.motif_sans_commande', 'Retour à vide')
                ->where('depense.commande', null)
                ->where('depense.commande_obligatoire', true));

        $this->get(route('depenses.index'))
            ->assertInertia(fn ($page) => $page
                ->where('depenses.data.0.sans_commande', true)
                ->where('depenses.data.0.commande_reference', null));
    }

    // ── Paramétrage du type ───────────────────────────────────────────────────

    public function test_seul_un_type_vehicule_peut_exiger_une_commande(): void
    {
        Permission::firstOrCreate(['name' => 'parametres.update', 'guard_name' => 'web']);
        $this->user->givePermissionTo('parametres.update');

        $this->post(route('depenses.types.store'), [
            'libelle' => 'Fournitures',
            'categorie' => 'interne',
            'commande_obligatoire' => true,
        ])->assertSessionHasErrors('commande_obligatoire');

        $this->post(route('depenses.types.store'), [
            'libelle' => 'Gasoil',
            'categorie' => 'vehicule',
            'commande_obligatoire' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('depense_types', ['libelle' => 'Gasoil', 'commande_obligatoire' => true]);
    }
}
