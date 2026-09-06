<?php

namespace Tests\Feature\Notification;

use App\Jobs\DispatchPushNotificationsJob;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\DepenseValideeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : scope strict —
 * seule une dépense catégorie VEHICULE, imputée à un propriétaire réellement
 * connecté, déclenche cette notification. livreur/site/salarie/prestataire
 * restent hors périmètre (aucun envoi, aucune erreur).
 */
class DepenseValideeNotificationTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        foreach (['admin_entreprise'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['depenses.read', 'depenses.create', 'depenses.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    /**
     * Admin Entreprise reste soumis au plafond de montant depuis le
     * 04/09/2026 (cf. docs/depenses-validation.md, DEPVAL-001) — plafond très
     * haut ici car ces tests portent sur la notification, pas sur le plafond.
     */
    private function adminUser(): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('admin_entreprise');
        $user->givePermissionTo(['depenses.read', 'depenses.create', 'depenses.update']);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        DroitCreationDepense::updateOrCreate(
            ['organization_id' => $this->org->id, 'role_name' => 'admin_entreprise'],
            ['perimetre' => 'toutes_agences', 'sites' => null, 'peut_valider' => true, 'plafond_validation' => 999_999_999]
        );

        return $user;
    }

    public function test_valider_une_depense_vehicule_notifie_le_proprietaire_connecte(): void
    {
        Notification::fake();
        Queue::fake();

        $proprietaireUser = $this->makeProprietaireUser($this->org);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireUser->proprietaire->id,
        ]);

        $type = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        Notification::assertSentTo($proprietaireUser, DepenseValideeNotification::class);
        Queue::assertPushed(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->userIds === [$proprietaireUser->id]
            && $job->payload['data']['type'] === 'expense.validated'
            && $job->payload['data']['depense_id'] === $depense->id);
    }

    /** Préférence désactivée : ni database ni push (même garantie que le reste du dispatcher). */
    public function test_preference_depenses_desactivee_bloque_aussi_le_push(): void
    {
        Notification::fake();
        Queue::fake();

        $proprietaireUser = $this->makeProprietaireUser($this->org);
        $proprietaireUser->update(['notification_preferences' => ['depenses' => false]]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaireUser->proprietaire->id,
        ]);

        $type = DepenseType::factory()->vehicule()->create(['organization_id' => $this->org->id]);
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        Notification::assertNotSentTo($proprietaireUser, DepenseValideeNotification::class);
        Queue::assertNotPushed(DispatchPushNotificationsJob::class);
    }

    public function test_depense_interne_najamais_denvoi(): void
    {
        Notification::fake();

        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);
        $depense = Depense::factory()->soumis()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'depense_type_id' => $type->id,
        ]);

        $this->actingAs($this->adminUser())
            ->patch(route('depenses.valider', $depense))
            ->assertRedirect();

        Notification::assertNothingSent();
    }
}
