<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommandeVenteAuditTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete', 'ventes.annuler']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Principal',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeContext(Organization $org): array
    {
        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Rouleau',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_vente' => 2000,
            'prix_usine' => 1500,
        ]);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 50,
        ]);

        $client = Client::factory()->create(['organization_id' => $org->id]);

        return compact('produit', 'vehicule', 'client');
    }

    private function storeCommande(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        return $this->actingAs($this->user)->post(route('ventes.store'), array_merge([
            'vehicule_id' => $vehicule->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000],
            ],
        ], $overrides));
    }

    // ── store creates audit log ───────────────────────────────────────────────

    public function test_store_creates_audit_log_with_created_event(): void
    {
        $this->storeCommande();

        $commande = CommandeVente::where('organization_id', $this->org->id)->first();
        $this->assertNotNull($commande);

        $log = AuditLog::where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('created', $log->event_code);
        $this->assertSame($this->user->id, $log->actor_id);
        $this->assertNull($log->old_values);
        $this->assertNotNull($log->new_values);
        $this->assertArrayHasKey('total_commande', $log->new_values);
    }

    // ── update creates audit log only when something changes ─────────────────

    public function test_update_creates_audit_log_when_ligne_qty_changes(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000]],
        ]);

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest('id')->first();
        $ligne = $commande->lignes->first();

        $this->actingAs($this->user)->put(route('ventes.update', $commande), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['id' => $ligne->id, 'produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 2000]],
        ]);

        $logs = AuditLog::where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->get();

        $this->assertCount(2, $logs);
        $updated = $logs->firstWhere('event_code', 'updated');
        $this->assertNotNull($updated);
        $this->assertArrayHasKey('lignes', $updated->old_values);
        $this->assertArrayHasKey('lignes', $updated->new_values);
    }

    public function test_update_does_not_create_audit_log_when_nothing_changes(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000]],
        ]);

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest('id')->first();
        $ligne = $commande->lignes->first();

        // re-submit the same data
        $this->actingAs($this->user)->put(route('ventes.update', $commande), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['id' => $ligne->id, 'produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000]],
        ]);

        $logs = AuditLog::where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->where('event_code', 'updated')
            ->get();

        $this->assertCount(0, $logs);
    }

    // ── annuler creates audit log ─────────────────────────────────────────────

    public function test_annuler_creates_audit_log_with_cancelled_event(): void
    {
        ['produit' => $produit, 'vehicule' => $vehicule] = $this->makeContext($this->org);

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 2000]],
        ]);

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest('id')->first();

        // valider to move to en_cours
        $commande->update(['statut' => StatutCommandeVente::EN_COURS]);

        $this->actingAs($this->user)->patch(route('ventes.annuler', $commande), [
            'motif_annulation_code' => 'erreur_saisie',
        ]);

        $log = AuditLog::where('auditable_type', CommandeVente::class)
            ->where('auditable_id', $commande->id)
            ->where('event_code', 'cancelled')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->user->id, $log->actor_id);
    }

    // ── show exposes historiques prop ─────────────────────────────────────────

    public function test_show_exposes_historiques_prop(): void
    {
        $this->storeCommande();

        $commande = CommandeVente::where('organization_id', $this->org->id)->first();

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Show')
                ->has('historiques')
                ->has('historiques.0', fn (Assert $log) => $log
                    ->where('event_code', 'created')
                    ->has('event_label')
                    ->has('actor_name')
                    ->has('created_at')
                    ->etc()
                )
            );
    }

    // ── org isolation ─────────────────────────────────────────────────────────

    public function test_show_does_not_expose_other_org_audit_logs(): void
    {
        // Create a commande for our org
        $this->storeCommande();
        $commande = CommandeVente::where('organization_id', $this->org->id)->first();

        // Create an audit log for a different org that points to same auditable_id
        $otherOrg = Organization::create(['name' => 'Autre Org', 'slug' => 'autre-org']);
        AuditLog::create([
            'organization_id' => $otherOrg->id,
            'auditable_type' => CommandeVente::class,
            'auditable_id' => $commande->id,
            'event_code' => 'created',
            'event_label' => 'Commande créée',
            'actor_id' => null,
            'actor_name_snapshot' => 'Autre User',
            'old_values' => null,
            'new_values' => null,
            'created_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Show')
                ->where('historiques', fn ($logs) => collect($logs)->every(
                    fn ($l) => $l['actor_name'] !== 'Autre User'
                ))
            );
    }
}
