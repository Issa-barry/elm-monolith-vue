<?php

namespace Tests\Feature\Notification;

use App\Enums\StatutTransfert;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\TransfertReceptionneeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : "livraison terminée"
 * = réception validée d'un transfert logistique. Notifie le PROPRIÉTAIRE du
 * véhicule uniquement — jamais le livreur, qui vient lui-même d'effectuer
 * l'action.
 */
class TransfertReceptionneeNotificationTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private Organization $org;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Permission::firstOrCreate(['name' => 'logistique.valider_reception', 'guard_name' => 'web']);

        $site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->staff = User::factory()->create(['organization_id' => $this->org->id]);
        $this->staff->givePermissionTo('logistique.valider_reception');
        $this->staff->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeTransfertEnTransit(): array
    {
        $proprietaireUser = $this->makeProprietaireUser($this->org);
        $livreurUser = $this->makeLivreurUser($this->org);

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireUser->proprietaire->id,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);

        $siteSource = Site::where('organization_id', $this->org->id)->first();
        $siteDest = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Destination',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $siteSource->id,
            'site_destination_id' => $siteDest->id,
            'vehicule_id' => $vehicule->id,
            'equipe_livraison_id' => $equipe->id,
            'statut' => StatutTransfert::TRANSIT,
            'created_by' => $this->staff->id,
        ]);

        return [$transfert, $proprietaireUser, $livreurUser];
    }

    public function test_reception_validee_notifie_le_proprietaire_pas_le_livreur(): void
    {
        Notification::fake();

        [$transfert, $proprietaireUser, $livreurUser] = $this->makeTransfertEnTransit();

        $this->actingAs($this->staff)
            ->postJson(route('api.backoffice.logistique.transferts.valider-reception', $transfert))
            ->assertOk();

        Notification::assertSentTo($proprietaireUser, TransfertReceptionneeNotification::class);
        Notification::assertNotSentTo($livreurUser, TransfertReceptionneeNotification::class);
    }
}
