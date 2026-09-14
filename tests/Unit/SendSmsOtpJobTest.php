<?php

namespace Tests\Unit;

use App\Contracts\SmsGateway;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Jobs\SendSmsOtpJob;
use App\Mail\OtpCodeMail;
use App\Models\MessageLog;
use App\Services\Otp\OtpFallbackTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SendSmsOtpJob — le SEUL endroit où un échec Nimba réel (après que le canal
 * SMS a été jugé "disponible") déclenche un vrai repli vers un autre canal
 * (cf. audit du 31/08/2026, point 2 : avant ce correctif, aucun mécanisme ne
 * retransportait le code si l'envoi Nimba échouait après résolution).
 *
 * Journalise aussi chaque tentative dans `message_logs` (cf.
 * App\Services\Communications\MessageLogService) — RefreshDatabase requis
 * depuis l'intégration du monitoring Communications (07/09/2026).
 */
class SendSmsOtpJobTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGateway(bool $succeeds): SmsGateway
    {
        return new class($succeeds) implements SmsGateway
        {
            public int $calls = 0;

            public function __construct(private readonly bool $succeeds) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $phoneNumber, string $message): ?string
            {
                $this->calls++;

                if (! $this->succeeds) {
                    throw new \RuntimeException('Nimba SMS : échec simulé.');
                }

                return 'fake-provider-message-id';
            }
        };
    }

    public function test_failure_with_a_fallback_retransports_the_same_code_by_email(): void
    {
        Mail::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(false));
        $fallback = new OtpFallbackTarget(OtpChannel::EMAIL, 'client@example.com');

        $job = new SendSmsOtpJob('+224620000601', 'Votre code Eau La Maman est : 654321. Il expire dans 10 minutes.', '654321', OtpPurpose::LOGIN, $fallback);
        app()->call([$job, 'handle']);

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) {
            return $mail->code === '654321'
                && $mail->purpose === OtpPurpose::LOGIN
                && $mail->hasTo('client@example.com');
        });
    }

    public function test_failure_without_a_fallback_sends_no_email_and_does_not_throw(): void
    {
        Mail::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(false));

        $job = new SendSmsOtpJob('+224620000602', 'Votre code Eau La Maman est : 111222. Il expire dans 10 minutes.', '111222', OtpPurpose::LOGIN, null);
        app()->call([$job, 'handle']);

        Mail::assertNothingSent();
        $this->assertTrue(true); // aucune exception propagée hors du job
    }

    public function test_success_calls_the_gateway_exactly_once_and_sends_no_fallback_email(): void
    {
        Mail::fake();
        $gateway = $this->fakeGateway(true);
        $this->app->instance(SmsGateway::class, $gateway);
        $fallback = new OtpFallbackTarget(OtpChannel::EMAIL, 'client@example.com');

        $job = new SendSmsOtpJob('+224620000603', 'Votre code Eau La Maman est : 333444. Il expire dans 10 minutes.', '333444', OtpPurpose::LOGIN, $fallback);
        app()->call([$job, 'handle']);

        $this->assertSame(1, $gateway->calls);
        Mail::assertNothingSent();
    }

    // ── Journal de monitoring (App\Models\MessageLog) ───────────────────────────

    public function test_a_successful_send_creates_a_message_log_marked_sent_with_the_provider_message_id(): void
    {
        Mail::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(true));

        $job = new SendSmsOtpJob('+224620000610', 'Votre code Eau La Maman est : 987654. Il expire dans 10 minutes.', '987654', OtpPurpose::LOGIN, null);
        app()->call([$job, 'handle']);

        $this->assertSame(1, MessageLog::count());
        $log = MessageLog::sole();
        $this->assertSame('sent', $log->status->value);
        $this->assertSame('fake-provider-message-id', $log->provider_message_id);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->failed_at);
    }

    public function test_a_failed_send_creates_a_message_log_marked_failed_with_no_provider_message_id(): void
    {
        Mail::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(false));

        $job = new SendSmsOtpJob('+224620000611', 'Votre code Eau La Maman est : 456789. Il expire dans 10 minutes.', '456789', OtpPurpose::LOGIN, null);
        app()->call([$job, 'handle']);

        $this->assertSame(1, MessageLog::count());
        $log = MessageLog::sole();
        $this->assertSame('failed', $log->status->value);
        $this->assertNull($log->provider_message_id);
        $this->assertNotNull($log->failed_at);
        $this->assertNull($log->sent_at);
        $this->assertNotNull($log->error_message);
    }

    public function test_the_message_log_never_contains_the_otp_code_or_the_sms_body(): void
    {
        Mail::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(false));

        $message = 'Votre code Eau La Maman est : 135790. Il expire dans 10 minutes.';
        $job = new SendSmsOtpJob('+224620000612', $message, '135790', OtpPurpose::LOGIN, null);
        app()->call([$job, 'handle']);

        $log = MessageLog::sole();
        $columns = $log->getAttributes();
        $flat = json_encode($columns);

        $this->assertStringNotContainsString('135790', $flat);
        $this->assertStringNotContainsString($message, $flat);
        // Le numéro complet ne doit jamais apparaître non plus — seul le
        // recipient masqué (cf. App\Services\Otp\OtpDestinationMasker) est
        // persisté.
        $this->assertStringNotContainsString('620000612', $flat);
    }

    public function test_the_recipient_is_stored_masked_never_in_clear(): void
    {
        Mail::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(true));

        $job = new SendSmsOtpJob('+224620000613', 'Votre code Eau La Maman est : 246810. Il expire dans 10 minutes.', '246810', OtpPurpose::LOGIN, null);
        app()->call([$job, 'handle']);

        $log = MessageLog::sole();
        $this->assertStringNotContainsString('+224620000613', $log->masked_recipient);
        $this->assertStringContainsString('•', $log->masked_recipient);
    }
}
