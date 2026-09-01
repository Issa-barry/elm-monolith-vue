<?php

namespace Tests\Unit;

use App\Contracts\SmsGateway;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Jobs\SendSmsOtpJob;
use App\Mail\OtpCodeMail;
use App\Services\Otp\OtpFallbackTarget;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SendSmsOtpJob — le SEUL endroit où un échec Nimba réel (après que le canal
 * SMS a été jugé "disponible") déclenche un vrai repli vers un autre canal
 * (cf. audit du 31/08/2026, point 2 : avant ce correctif, aucun mécanisme ne
 * retransportait le code si l'envoi Nimba échouait après résolution).
 */
class SendSmsOtpJobTest extends TestCase
{
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

            public function send(string $phoneNumber, string $message): void
            {
                $this->calls++;

                if (! $this->succeeds) {
                    throw new \RuntimeException('Nimba SMS : échec simulé.');
                }
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
}
