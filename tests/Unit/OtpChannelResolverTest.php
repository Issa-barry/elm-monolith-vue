<?php

namespace Tests\Unit;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Services\Otp\Channels\EmailOtpChannel;
use App\Services\Otp\OtpChannelResolver;
use Tests\TestCase;

/**
 * Correctif du 27/08/2026 : "disponible" ne doit pas signifier seulement
 * "configuré dans otp.channels" — le canal doit aussi être RÉELLEMENT
 * exploitable pour le destinataire concerné (ex: un email configuré
 * globalement mais absent pour ce compte précis n'est pas disponible pour
 * lui).
 */
class OtpChannelResolverTest extends TestCase
{
    private function resolver(): OtpChannelResolver
    {
        return app(OtpChannelResolver::class);
    }

    public function test_email_channel_is_available_when_an_email_is_provided(): void
    {
        $channel = $this->resolver()->firstAvailableFor(OtpPurpose::LOGIN, '+224620000500', 'user@example.com');

        $this->assertInstanceOf(EmailOtpChannel::class, $channel);
    }

    /**
     * LE test du correctif : email configuré (seul canal réellement câblé
     * aujourd'hui) mais ce compte n'a pas d'email → aucun canal disponible,
     * jamais un canal choisi puis un envoi qui échoue silencieusement.
     */
    public function test_email_channel_is_not_available_without_an_email_even_if_configured(): void
    {
        $channel = $this->resolver()->firstAvailableFor(OtpPurpose::LOGIN, '+224620000501', null);

        $this->assertNull($channel);
    }

    public function test_email_channel_is_not_available_with_an_empty_string_email(): void
    {
        $channel = $this->resolver()->firstAvailableFor(OtpPurpose::LOGIN, '+224620000502', '');

        $this->assertNull($channel);
    }

    /**
     * Si un canal en tête de liste n'est pas configuré du tout, la résolution
     * passe au suivant — comportement déjà correct, reconfirmé après le
     * changement de signature.
     */
    public function test_falls_through_to_the_next_configured_channel_in_the_ordered_list(): void
    {
        // PASSWORD_RESET est configuré ['email', 'whatsapp', 'sms'] — whatsapp/sms
        // ne sont pas liés dans otp.channels, seul email doit être retenu.
        $channel = $this->resolver()->firstAvailableFor(OtpPurpose::PASSWORD_RESET, '+224620000503', 'user@example.com');

        $this->assertSame(OtpChannel::EMAIL, $channel->channel());
    }

    public function test_destination_for_email_is_null_when_email_is_blank(): void
    {
        $this->assertNull($this->resolver()->destinationFor(OtpChannel::EMAIL, '+224620000504', ''));
        $this->assertNull($this->resolver()->destinationFor(OtpChannel::EMAIL, '+224620000504', null));
    }

    public function test_destination_for_sms_and_whatsapp_is_the_phone_number(): void
    {
        $this->assertSame('+224620000505', $this->resolver()->destinationFor(OtpChannel::SMS, '+224620000505', null));
        $this->assertSame('+224620000505', $this->resolver()->destinationFor(OtpChannel::WHATSAPP, '+224620000505', null));
    }

    // ── fallbackFor() — audit du 31/08/2026, intégration Nimba SMS point 2 ──

    private function configureNimba(): void
    {
        config([
            'services.nimba_sms.service_id' => 'test-service-id',
            'services.nimba_sms.secret_token' => 'test-secret-token',
            'services.nimba_sms.sender_name' => 'EAULAMAMAN',
        ]);
    }

    public function test_fallback_for_sms_is_email_when_the_account_has_one(): void
    {
        $this->configureNimba();

        $fallback = $this->resolver()->fallbackFor(OtpChannel::SMS, OtpPurpose::LOGIN, '+224620000510', 'client@example.com');

        $this->assertNotNull($fallback);
        $this->assertSame(OtpChannel::EMAIL, $fallback->channel);
        $this->assertSame('client@example.com', $fallback->destination);
    }

    public function test_fallback_for_sms_is_null_when_the_account_has_no_email(): void
    {
        $this->configureNimba();

        $fallback = $this->resolver()->fallbackFor(OtpChannel::SMS, OtpPurpose::LOGIN, '+224620000511', null);

        $this->assertNull($fallback);
    }

    /**
     * whatsapp précède sms dans purpose_channels(LOGIN) mais n'est jamais
     * configuré : fallbackFor() ne doit regarder QU'APRÈS le canal choisi,
     * jamais reconsidérer un canal qui précédait sms dans la liste.
     */
    public function test_fallback_for_sms_never_reconsiders_a_channel_before_it_in_the_list(): void
    {
        $this->configureNimba();

        $fallback = $this->resolver()->fallbackFor(OtpChannel::SMS, OtpPurpose::LOGIN, '+224620000512', 'client@example.com');

        $this->assertSame(OtpChannel::EMAIL, $fallback->channel);
    }

    public function test_fallback_for_email_is_null_when_email_is_last_in_the_list(): void
    {
        $fallback = $this->resolver()->fallbackFor(OtpChannel::EMAIL, OtpPurpose::LOGIN, '+224620000513', 'client@example.com');

        $this->assertNull($fallback);
    }
}
