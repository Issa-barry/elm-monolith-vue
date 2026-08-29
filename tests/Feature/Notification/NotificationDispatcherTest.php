<?php

namespace Tests\Feature\Notification;

use App\Jobs\DispatchPushNotificationsJob;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CommissionGenereeNotification;
use App\Services\Notification\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 1 archi notifications (2026-08-27, cf. rapport) : point central de
 * dédoublonnage/préférences/push, factorisé hors des Jobs/Services métier.
 */
class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private function notif(): CommissionGenereeNotification
    {
        return new CommissionGenereeNotification('commande_vente', 'cmd-1', 'CMD-001', 1000.0);
    }

    public function test_dedupes_same_user_passed_twice(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        NotificationDispatcher::send($this->notif(), [$user, $user], 'commissions');

        Notification::assertSentToTimes($user, CommissionGenereeNotification::class, 1);
    }

    public function test_filters_out_null_recipients(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        NotificationDispatcher::send($this->notif(), [null, $user, null], 'commissions');

        Notification::assertSentTo($user, CommissionGenereeNotification::class);
    }

    public function test_respects_disabled_category_preference(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'notification_preferences' => ['commissions' => false],
        ]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions');

        Notification::assertNothingSent();
    }

    public function test_falls_back_to_legacy_activite_when_category_unset(): void
    {
        Notification::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'notification_preferences' => ['activite' => false],
        ]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions');

        Notification::assertNothingSent();
    }

    public function test_sends_no_push_when_no_recipient_left_after_filtering(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'notification_preferences' => ['commissions' => false],
            'expo_push_token' => 'ExponentPushToken[x]',
        ]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions', fn () => [
            'title' => 'Titre',
            'body' => 'Corps',
        ]);

        Http::assertNothingSent();
    }

    public function test_sends_push_to_remaining_recipients_tokens(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'expo_push_token' => 'ExponentPushToken[x]',
        ]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions', fn () => [
            'title' => 'Titre',
            'body' => 'Corps',
        ]);

        Http::assertSent(fn ($request) => in_array('ExponentPushToken[x]', collect($request->data())->pluck('to')->all(), true));
    }

    /**
     * Web Push (2026-08-28) : le fan-out Expo/Web Push passe désormais par un
     * Job asynchrone — preuve qu'ajouter ce canal n'a pas changé les
     * destinataires déterminés par le dispatcher (BeneficiaireUserResolver
     * reste seul juge de qui est concerné).
     */
    public function test_dispatches_push_job_with_correct_recipients_when_payload_provided(): void
    {
        Notification::fake();
        Queue::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions', fn () => [
            'title' => 'Titre',
            'body' => 'Corps',
        ]);

        Queue::assertPushed(DispatchPushNotificationsJob::class, fn (DispatchPushNotificationsJob $job) => $job->userIds === [$user->id]
            && $job->payload['title'] === 'Titre'
            && $job->payload['body'] === 'Corps');
    }

    public function test_does_not_dispatch_push_job_without_payload(): void
    {
        Notification::fake();
        Queue::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions');

        Queue::assertNotPushed(DispatchPushNotificationsJob::class);
    }

    public function test_does_not_dispatch_push_job_when_preference_filters_everyone(): void
    {
        Notification::fake();
        Queue::fake();

        $org = Organization::factory()->create();
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'notification_preferences' => ['commissions' => false],
        ]);

        NotificationDispatcher::send($this->notif(), [$user], 'commissions', fn () => [
            'title' => 'Titre',
            'body' => 'Corps',
        ]);

        Queue::assertNotPushed(DispatchPushNotificationsJob::class);
    }
}
