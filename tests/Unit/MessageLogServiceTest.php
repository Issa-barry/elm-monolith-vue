<?php

namespace Tests\Unit;

use App\Enums\OtpPurpose;
use App\Models\Organization;
use App\Models\User;
use App\Services\Communications\MessageLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\Communications\MessageLogService — purement observationnel
 * (cf. docblock de classe) : ces tests ne touchent jamais App\Services\OtpService
 * ni la génération/validation d'un code, seulement le journal de transport.
 */
class MessageLogServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MessageLogService
    {
        return app(MessageLogService::class);
    }

    public function test_logging_an_attempt_creates_a_pending_entry(): void
    {
        $log = $this->service()->logSmsOtpAttempt('+224620000900', OtpPurpose::LOGIN);

        $this->assertSame('pending', $log->status->value);
        $this->assertSame('sms', $log->channel->value);
        $this->assertSame('outbound', $log->direction->value);
        $this->assertSame('login', $log->purpose);
        $this->assertSame('nimba', $log->provider);
        $this->assertNull($log->provider_message_id);
        $this->assertNull($log->messageable_type);
    }

    public function test_organization_id_resolves_from_an_existing_account(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id, 'telephone' => '+224620000901']);

        $log = $this->service()->logSmsOtpAttempt('+224620000901', OtpPurpose::LOGIN);

        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame($user->organization_id, $log->organization_id);
    }

    /**
     * Un OTP de vérification téléphone peut être tenté pendant une inscription,
     * avant qu'un compte/organisation n'existe pour ce numéro — cf. rapport,
     * point 6 : `organization_id` reste `null` plutôt que d'être forcé.
     */
    public function test_organization_id_stays_null_when_no_account_matches_the_phone(): void
    {
        $log = $this->service()->logSmsOtpAttempt('+224699999999', OtpPurpose::PHONE_VERIFICATION);

        $this->assertNull($log->organization_id);
    }

    public function test_mark_sent_records_the_provider_message_id_and_timestamp(): void
    {
        $log = $this->service()->logSmsOtpAttempt('+224620000902', OtpPurpose::LOGIN);

        $this->service()->markSent($log, 'nimba-id-42');

        $log->refresh();
        $this->assertSame('sent', $log->status->value);
        $this->assertSame('nimba-id-42', $log->provider_message_id);
        $this->assertNotNull($log->sent_at);
    }

    public function test_mark_failed_categorizes_a_provider_http_error(): void
    {
        $log = $this->service()->logSmsOtpAttempt('+224620000903', OtpPurpose::LOGIN);

        $this->service()->markFailed($log, new \RuntimeException("Nimba SMS : échec de l'envoi (HTTP 402).", 402));

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertSame('402', $log->provider_status);
        $this->assertSame('402', $log->error_code);
        $this->assertNotNull($log->failed_at);
    }

    public function test_mark_failed_categorizes_a_transport_error_with_no_http_status(): void
    {
        $log = $this->service()->logSmsOtpAttempt('+224620000904', OtpPurpose::LOGIN);

        $this->service()->markFailed($log, new \RuntimeException('Nimba SMS : erreur réseau ou timeout.'));

        $log->refresh();
        $this->assertSame('failed', $log->status->value);
        $this->assertNull($log->provider_status);
        $this->assertSame('transport_error', $log->error_code);
    }

    public function test_message_log_never_persists_a_full_phone_number(): void
    {
        $log = $this->service()->logSmsOtpAttempt('+224620000905', OtpPurpose::LOGIN);

        $this->assertStringNotContainsString('+224620000905', $log->masked_recipient);
    }
}
