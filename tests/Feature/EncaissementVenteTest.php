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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EncaissementVenteTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(Organization $org): User
    {
        Permission::firstOrCreate(['name' => 'ventes.update', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'factures.encaisser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['ventes.update', 'factures.encaisser']);

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

    // ── Autorisation : permission dédiée factures.encaisser (2026-09-13) ──────
    // can_encaisser/StoreEncaissementVenteController vérifiaient jusqu'ici ventes.update — la
    // route n'avait alors AUCUN contrôle d'autorisation propre. La permission dédiée
    // factures.encaisser doit désormais être totalement indépendante de ventes.update.

    public function test_encaissement_refuse_sans_permission_dediee(): void
    {
        ['facture' => $facture, 'org' => $org] = $this->creerContexte();

        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('ventes.update');

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        );

        $response->assertStatus(403);
        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_autorise_par_sa_seule_permission_dediee(): void
    {
        ['facture' => $facture, 'org' => $org] = $this->creerContexte();

        Permission::firstOrCreate(['name' => 'factures.encaisser', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        // Rôle attaché uniquement pour satisfaire EnsureIsStaffAccount::hasBackofficeAccess()
        // (middleware 'staff') — ce rôle vide n'apporte aucune permission, la capacité
        // d'encaisser vient exclusivement du don direct ci-dessous.
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo('factures.encaisser');
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test 2',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        )->assertRedirect();

        $this->assertDatabaseHas('encaissements_ventes', [
            'facture_vente_id' => $facture->id,
            'montant' => 1000,
        ]);
    }

    // ── Référence obligatoire Mobile Money / Virement (2026-09-14) ────────────
    // Mode et opérateur sont deux dimensions distinctes (cf. App\Enums\OperateurMobileMoney) :
    // mode_paiement reste stable (especes/mobile_money/virement/cheque), l'opérateur (Orange
    // Money, Kulu, Soutra Money, MOMO, PayCard) est un champ séparé.

    public function test_encaissement_mobile_money_sans_operateur_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'mobile_money',
                'reference_paiement' => 'OM-123456',
            ]
        );

        $response->assertSessionHasErrors('operateur_mobile_money');
        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_mobile_money_sans_reference_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
            ]
        );

        $response->assertSessionHasErrors('reference_paiement');
        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_virement_sans_reference_est_refuse(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $response = $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'virement',
            ]
        );

        $response->assertSessionHasErrors('reference_paiement');
        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }

    public function test_encaissement_cheque_sans_reference_est_accepte(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'cheque',
            ]
        )->assertRedirect();

        $this->assertDatabaseHas('encaissements_ventes', [
            'facture_vente_id' => $facture->id,
            'mode_paiement' => 'cheque',
            'reference_paiement' => null,
        ]);
    }

    public function test_encaissement_mobile_money_avec_operateur_et_reference_est_enregistre(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'mobile_money',
                'operateur_mobile_money' => 'orange_money',
                'reference_paiement' => 'OM-987654',
            ]
        )->assertRedirect();

        $this->assertDatabaseHas('encaissements_ventes', [
            'facture_vente_id' => $facture->id,
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'reference_paiement' => 'OM-987654',
        ]);
    }

    public function test_encaissement_sans_date_utilise_date_du_jour(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();

        $this->actingAs($user)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 2000,
                'mode_paiement' => 'especes',
                // date_encaissement absent : doit defaulter à today()
            ]
        )->assertRedirect();

        $enc = $facture->encaissements()->first();
        $this->assertNotNull($enc);
        $this->assertEquals(now()->toDateString(), $enc->date_encaissement->toDateString());
    }

    public function test_store_returns_403_for_facture_from_other_organization(): void
    {
        ['facture' => $facture] = $this->creerContexte();
        $autreUser = $this->utilisateur(Organization::factory()->create());

        $this->actingAs($autreUser)->post(
            route('encaissements.store', $facture),
            [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ]
        )->assertStatus(403);

        $this->assertSame(0, $facture->fresh()->encaissements()->count());
    }

    // ── Encaissement destroy ──────────────────────────────────────────────────

    public function test_destroy_returns_403_for_encaissement_from_other_organization(): void
    {
        ['facture' => $facture, 'user' => $user] = $this->creerContexte();
        $enc = $facture->encaissements()->create([
            'montant' => 1000, 'date_encaissement' => now()->toDateString(), 'mode_paiement' => 'especes',
        ]);
        $autreUser = $this->utilisateur(Organization::factory()->create());

        $this->actingAs($autreUser)
            ->delete(route('encaissements.destroy', $enc))
            ->assertStatus(403);

        $this->assertNotNull($enc->fresh());
    }

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
