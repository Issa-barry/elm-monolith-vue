<?php

namespace Tests\Unit;

use App\Contracts\SmsGateway;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Jobs\SendSmsOtpJob;
use App\Services\Otp\Channels\SmsOtpChannel;
use App\Services\Otp\OtpFallbackTarget;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SmsOtpChannel : pure plomberie entre OtpService et le SmsGateway lié
 * (aujourd'hui NimbaSmsGateway) — ne doit jamais appeler le fournisseur
 * directement (cf. audit du 31/08/2026 : l'appel réseau réel est délégué à
 * SendSmsOtpJob, jamais exécuté depuis la requête HTTP).
 */
class SmsOtpChannelTest extends TestCase
{
    private function fakeGateway(bool $configured): SmsGateway
    {
        return new class($configured) implements SmsGateway
        {
            public function __construct(private readonly bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(string $phoneNumber, string $message): void
            {
                throw new \RuntimeException('Ne doit jamais être appelé directement par SmsOtpChannel::send().');
            }
        };
    }

    public function test_channel_is_sms(): void
    {
        $this->app->instance(SmsGateway::class, $this->fakeGateway(true));

        $this->assertSame(OtpChannel::SMS, app(SmsOtpChannel::class)->channel());
    }

    public function test_is_available_delegates_to_the_gateway(): void
    {
        $this->app->instance(SmsGateway::class, $this->fakeGateway(true));
        $this->assertTrue(app(SmsOtpChannel::class)->isAvailable());

        $this->app->instance(SmsGateway::class, $this->fakeGateway(false));
        $this->assertFalse(app(SmsOtpChannel::class)->isAvailable());
    }

    public function test_send_dispatches_a_queued_job_instead_of_calling_the_gateway_directly(): void
    {
        Queue::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(true));

        app(SmsOtpChannel::class)->send('+224620000710', '123456', OtpPurpose::LOGIN);

        Queue::assertPushed(SendSmsOtpJob::class, function (SendSmsOtpJob $job) {
            $phone = (fn () => $this->phoneNumber)->call($job);
            $message = (fn () => $this->message)->call($job);
            $code = (fn () => $this->code)->call($job);
            $purpose = (fn () => $this->purpose)->call($job);
            $fallback = (fn () => $this->fallback)->call($job);

            return $phone === '+224620000710'
                && str_contains($message, '123456')
                && str_contains($message, '10 minutes')
                && $code === '123456'
                && $purpose === OtpPurpose::LOGIN
                && $fallback === null;
        });
    }

    public function test_send_forwards_the_fallback_target_to_the_job(): void
    {
        Queue::fake();
        $this->app->instance(SmsGateway::class, $this->fakeGateway(true));
        $fallback = new OtpFallbackTarget(OtpChannel::EMAIL, 'client@example.com');

        app(SmsOtpChannel::class)->send('+224620000711', '654321', OtpPurpose::LOGIN, $fallback);

        Queue::assertPushed(SendSmsOtpJob::class, function (SendSmsOtpJob $job) use ($fallback) {
            $forwarded = (fn () => $this->fallback)->call($job);

            return $forwarded === $fallback;
        });
    }
}
