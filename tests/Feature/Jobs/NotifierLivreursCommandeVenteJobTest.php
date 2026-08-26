<?php

namespace Tests\Feature\Jobs;

use App\Enums\StatutCommandeVente;
use App\Jobs\NotifierLivreursCommandeVenteJob;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Organization;
use App\Models\Site;
use App\Models\Vehicule;
use App\Notifications\CommandeValideeNotification;
use App\Services\ExpoPushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Régression du 26/08/2026 (audit notifications, LOT 6) : ce job est le SEUL
 * point d'envoi réel de la catégorie de préférence `activite` — avant
 * correctif, `notification_preferences` était stocké/exposé via l'API mais
 * jamais consulté ici, rendant le réglage "désactiver l'activité" totalement
 * sans effet (ni notification en base, ni push Expo).
 */
class NotifierLivreursCommandeVenteJobTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private function makeCommandeAvecEquipe(Organization $org, ?array $livreurPrefs = null, ?array $proprietairePrefs = null): array
    {
        $proprietaireUser = $this->makeProprietaireUser($org);
        if ($proprietairePrefs !== null) {
            $proprietaireUser->update(['notification_preferences' => $proprietairePrefs]);
        }

        $livreurUser = $this->makeLivreurUser($org);
        if ($livreurPrefs !== null) {
            $livreurUser->update(['notification_preferences' => $livreurPrefs]);
        }
        $livreurUser->forceFill(['expo_push_token' => 'ExponentPushToken[livreur]'])->save();
        $proprietaireUser->forceFill(['expo_push_token' => 'ExponentPushToken[proprietaire]'])->save();

        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaireUser->proprietaire->id,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Equipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $livreurUser->livreur->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Test',
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS->value,
        ]);

        return [$commande, $livreurUser, $proprietaireUser];
    }

    public function test_notifies_and_pushes_both_by_default(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande, $livreurUser, $proprietaireUser] = $this->makeCommandeAvecEquipe($org);

        (new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference))
            ->handle(app(ExpoPushNotificationService::class));

        Notification::assertSentTo($livreurUser, CommandeValideeNotification::class);
        Notification::assertSentTo($proprietaireUser, CommandeValideeNotification::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'exp.host'));
    }

    public function test_skips_livreur_who_disabled_activite_preference(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande, $livreurUser, $proprietaireUser] = $this->makeCommandeAvecEquipe(
            $org,
            livreurPrefs: ['activite' => false]
        );

        (new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference))
            ->handle(app(ExpoPushNotificationService::class));

        Notification::assertNotSentTo($livreurUser, CommandeValideeNotification::class);
        Notification::assertSentTo($proprietaireUser, CommandeValideeNotification::class);

        Http::assertSent(function ($request) {
            $tokens = collect($request->data())->pluck('to')->all();

            return ! in_array('ExponentPushToken[livreur]', $tokens, true)
                && in_array('ExponentPushToken[proprietaire]', $tokens, true);
        });
    }

    public function test_skips_proprietaire_who_disabled_activite_preference(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande, $livreurUser, $proprietaireUser] = $this->makeCommandeAvecEquipe(
            $org,
            proprietairePrefs: ['activite' => false]
        );

        (new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference))
            ->handle(app(ExpoPushNotificationService::class));

        Notification::assertSentTo($livreurUser, CommandeValideeNotification::class);
        Notification::assertNotSentTo($proprietaireUser, CommandeValideeNotification::class);
    }

    public function test_sends_no_push_when_both_disabled_activite_preference(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        [$commande] = $this->makeCommandeAvecEquipe(
            $org,
            livreurPrefs: ['activite' => false],
            proprietairePrefs: ['activite' => false]
        );

        (new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference))
            ->handle(app(ExpoPushNotificationService::class));

        Http::assertNothingSent();
    }

    public function test_does_nothing_when_commande_has_no_vehicule(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'vehicule_id' => null,
            'statut' => StatutCommandeVente::BROUILLON->value,
        ]);

        (new NotifierLivreursCommandeVenteJob($commande->id, $commande->reference))
            ->handle(app(ExpoPushNotificationService::class));

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
