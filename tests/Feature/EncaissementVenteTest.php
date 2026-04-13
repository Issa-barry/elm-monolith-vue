<?php

namespace Tests\Feature;

use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EncaissementVenteTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(Organization $org): User
    {
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('ventes.update');

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function creerContexte(): array
    {
        $org = Organization::factory()->create();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 5000,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 5000,
        ]);
        $user = $this->utilisateur($org);

        return compact('org', 'vehicule', 'commande', 'facture', 'user');
    }

    // ── Encaissement store ────────────────────────────────────────────────────

    public function test_encaissement_change_statut_facture_en_partiel(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
    }

    public function test_encaissement_complet_change_statut_facture_en_payee(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 5000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutFactureVente::PAYEE, $facture->fresh()->statut_facture);
    }

    public function test_encaissement_partiel_ne_change_pas_statut_en_payee(): void
    {
        ['facture' => $facture, 'commande' => $commande, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 2500,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
    }

    public function test_encaissement_depasse_restant_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 99999, // dépasse 5000
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertSessionHasErrors('montant');
    }

    public function test_encaissement_refuse_sur_facture_annulee(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $facture->update(['statut_facture' => StatutFactureVente::ANNULEE]);

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(422);
    }

    // ── Encaissement destroy ──────────────────────────────────────────────────

    public function test_suppression_encaissement_recalcule_statut_facture(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        // Ajouter deux encaissements partiels
        $enc1 = $facture->encaissements()->create([
            'montant' => 2000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);
        $facture->encaissements()->create([
            'montant' => 1000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);
        $facture->recalculStatut();

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);

        // Supprimer le premier
        $this->actingAs($user)->delete(route('encaissements.destroy', $enc1));

        $this->assertEquals(StatutFactureVente::PARTIEL, $facture->fresh()->statut_facture);
        $this->assertEquals(1000.0, (float) $facture->fresh()->montant_encaisse);
    }
}
