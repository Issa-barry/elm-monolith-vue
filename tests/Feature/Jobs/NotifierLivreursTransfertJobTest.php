<?php

namespace Tests\Feature\Jobs;

use App\Enums\StatutTransfert;
use App\Jobs\NotifierLivreursTransfertJob;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\TransfertCreeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : avant ce correctif,
 * ce job envoyait uniquement un push Expo direct — aucune notification
 * database (absente de la cloche GET /v1/mobile/notifications), aucune
 * préférence jamais consultée. Rejoint désormais exactement le pattern de
 * NotifierLivreursCommandeVenteJob.
 */
class NotifierLivreursTransfertJobTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeTransfertAvecEquipe(Organization $org, ?array $livreurPrefs = null): array
    {
        $livreurUser = $this->makeLivreurUser($org);
        if ($livreurPrefs !== null) {
            $livreurUser->update(['notification_preferences' => $livreurPrefs]);
        }
        $livreurUser->forceFill(['expo_push_token' => 'ExponentPushToken[livreur]'])->save();

        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $transfert = $this->makeTransfert($org, $vehicule, $equipe->id);

        return [$transfert, $livreurUser];
    }

    private function makeTransfert(Organization $org, Vehicule $vehicule, ?string $equipeId): TransfertLogistique
    {
        $siteA = Site::create(['organization_id' => $org->id, 'nom' => 'Site A', 'type' => 'depot', 'localisation' => 'Conakry']);
        $siteB = Site::create(['organization_id' => $org->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Conakry']);
        $createur = User::factory()->create(['organization_id' => $org->id]);

        return TransfertLogistique::create([
            'organization_id' => $org->id,
            'site_source_id' => $siteA->id,
            'site_destination_id' => $siteB->id,
            'vehicule_id' => $vehicule->id,
            'equipe_livraison_id' => $equipeId,
            'statut' => $equipeId ? StatutTransfert::TRANSIT : StatutTransfert::BROUILLON,
            'created_by' => $createur->id,
        ]);
    }

    public function test_notifies_database_and_push_by_default(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$transfert, $livreurUser] = $this->makeTransfertAvecEquipe($org);

        (new NotifierLivreursTransfertJob($transfert->id, $transfert->reference))->handle();

        Notification::assertSentTo($livreurUser, TransfertCreeNotification::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host'));
    }

    public function test_skips_livreur_who_disabled_livraisons_preference(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$transfert, $livreurUser] = $this->makeTransfertAvecEquipe($org, livreurPrefs: ['livraisons' => false]);

        (new NotifierLivreursTransfertJob($transfert->id, $transfert->reference))->handle();

        Notification::assertNotSentTo($livreurUser, TransfertCreeNotification::class);
        Http::assertNothingSent();
    }

    public function test_does_nothing_when_transfert_has_no_equipe(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        $vehicule = Vehicule::factory()->create(['organization_id' => $org->id]);
        $transfert = $this->makeTransfert($org, $vehicule, null);

        (new NotifierLivreursTransfertJob($transfert->id, $transfert->reference))->handle();

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
