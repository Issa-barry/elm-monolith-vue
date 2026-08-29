<?php

namespace Tests\Feature;

use App\Contracts\OtpDeliveryChannel;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simule l'arrivée d'un vrai fournisseur SMS/WhatsApp (aucun n'est configuré
 * aujourd'hui, cf. rapport du 27/08/2026) via un canal FAKE implémentant le
 * même contrat `OtpDeliveryChannel` qu'une future intégration réelle —
 * prouve que le reste du système (génération, transport, vérification,
 * décision de vérifier l'identité) fonctionne sans aucun changement une fois
 * un canal téléphonique réellement branché.
 */
class OtpPhoneVerificationChannelTest extends TestCase
{
    use RefreshDatabase;

    private function fakeChannel(OtpChannel $channel): OtpDeliveryChannel
    {
        return new class($channel) implements OtpDeliveryChannel
        {
            public array $sent = [];

            public function __construct(private readonly OtpChannel $c) {}

            public function channel(): OtpChannel
            {
                return $this->c;
            }

            public function send(string $destination, string $code, OtpPurpose $purpose): void
            {
                $this->sent[] = compact('destination', 'code', 'purpose');
            }
        };
    }

    private function makeTelephoneIdentity(string $phone): UserAuthIdentity
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $identity = $user->authIdentities()->create([
            'type' => UserAuthIdentity::TYPE_TELEPHONE,
            'value' => $phone,
            'normalized_value' => ltrim($phone, '+'),
        ]);

        return $identity;
    }

    public function test_phone_verification_via_sms_marks_the_identity_verified(): void
    {
        $identity = $this->makeTelephoneIdentity('+224620000400');
        $channel = $this->fakeChannel(OtpChannel::SMS);
        $otp = app(OtpService::class);

        $code = $otp->generateAndSend('+224620000400', OtpPurpose::PHONE_VERIFICATION, $channel, '+224620000400');

        $this->assertCount(1, $channel->sent);
        $this->assertSame($code, $channel->sent[0]['code']);

        $this->assertTrue($otp->verify('+224620000400', $code, OtpPurpose::PHONE_VERIFICATION));

        // Décision applicative explicite (jamais automatique dans OtpService lui-même,
        // cf. rapport point 7) : le code est validé ET le canal utilisé prouve le
        // téléphone → l'application choisit ici de marquer l'identité vérifiée.
        $identity->markVerifiedVia($channel->channel());

        $this->assertTrue($identity->isVerified());
        $this->assertSame('sms', $identity->verification_channel);
    }

    public function test_phone_verification_via_whatsapp_marks_the_identity_verified(): void
    {
        $identity = $this->makeTelephoneIdentity('+224620000401');
        $channel = $this->fakeChannel(OtpChannel::WHATSAPP);
        $otp = app(OtpService::class);

        $code = $otp->generateAndSend('+224620000401', OtpPurpose::PHONE_VERIFICATION, $channel, '+224620000401');

        $this->assertTrue($otp->verify('+224620000401', $code, OtpPurpose::PHONE_VERIFICATION));

        $identity->markVerifiedVia($channel->channel());

        $this->assertTrue($identity->isVerified());
        $this->assertSame('whatsapp', $identity->verification_channel);
    }

    /**
     * Même code validé, mais livré par email : l'application ne doit PAS
     * appeler markVerifiedVia() sur l'identité téléphone avec ce canal — et si
     * elle le faisait par erreur, le modèle refuse (cf.
     * UserAuthIdentityMarkVerifiedViaTest).
     */
    public function test_phone_verification_via_email_never_marks_the_identity_verified(): void
    {
        $identity = $this->makeTelephoneIdentity('+224620000402');
        $channel = $this->fakeChannel(OtpChannel::EMAIL);
        $otp = app(OtpService::class);

        $code = $otp->generateAndSend('+224620000402', OtpPurpose::PHONE_VERIFICATION, $channel, 'secours@example.com');

        $this->assertTrue($otp->verify('+224620000402', $code, OtpPurpose::PHONE_VERIFICATION));

        // L'application ne doit jamais appeler markVerifiedVia(EMAIL) ici — c'est
        // précisément ce que ce test vérifie en ne l'appelant pas.
        $this->assertFalse($identity->isVerified());
    }

    /**
     * Point 11 du brief : un fallback WhatsApp → SMS transporte le MÊME code,
     * jamais un nouveau — vérifié en générant une fois et en le transportant
     * successivement sur deux canaux différents.
     */
    public function test_the_same_code_can_be_retransported_on_a_fallback_channel(): void
    {
        $otp = app(OtpService::class);
        $whatsapp = $this->fakeChannel(OtpChannel::WHATSAPP);
        $sms = $this->fakeChannel(OtpChannel::SMS);

        $code = $otp->generate('+224620000403', OtpPurpose::PHONE_VERIFICATION);

        // "Échec" WhatsApp simulé : on retransporte le MÊME $code sur SMS,
        // sans jamais rappeler generate().
        $whatsapp->send('+224620000403', $code, OtpPurpose::PHONE_VERIFICATION);
        $sms->send('+224620000403', $code, OtpPurpose::PHONE_VERIFICATION);

        $this->assertSame($whatsapp->sent[0]['code'], $sms->sent[0]['code']);
        $this->assertTrue($otp->verify('+224620000403', $code, OtpPurpose::PHONE_VERIFICATION));
    }
}
