<?php

namespace Tests\Unit;

use App\Enums\OtpChannel;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserAuthIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Règle de sécurité centrale du chantier OTP agnostique du canal (cf. rapport
 * du 27/08/2026) : `UserAuthIdentity::markVerifiedVia()` est le SEUL point qui
 * doit écrire `verified_at`/`verification_channel` — il refuse structurellement
 * de marquer un téléphone vérifié via un canal qui ne prouve pas sa possession
 * (email), et symétriquement pour un email via un canal téléphonique.
 */
class UserAuthIdentityMarkVerifiedViaTest extends TestCase
{
    use RefreshDatabase;

    private function makeIdentity(string $type): UserAuthIdentity
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        return $user->authIdentities()->create([
            'type' => $type,
            'value' => $type === UserAuthIdentity::TYPE_TELEPHONE ? '+224620000099' : 'test@example.com',
            'normalized_value' => $type === UserAuthIdentity::TYPE_TELEPHONE ? '224620000099' : 'test@example.com',
        ]);
    }

    // ── Cas autorisés ─────────────────────────────────────────────────────────

    public function test_sms_verifie_une_identite_telephone(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_TELEPHONE);

        $identity->markVerifiedVia(OtpChannel::SMS);

        $this->assertTrue($identity->isVerified());
        $this->assertSame('sms', $identity->verification_channel);
    }

    public function test_whatsapp_verifie_une_identite_telephone(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_TELEPHONE);

        $identity->markVerifiedVia(OtpChannel::WHATSAPP);

        $this->assertTrue($identity->isVerified());
        $this->assertSame('whatsapp', $identity->verification_channel);
    }

    public function test_email_verifie_une_identite_email(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_EMAIL);

        $identity->markVerifiedVia(OtpChannel::EMAIL);

        $this->assertTrue($identity->isVerified());
        $this->assertSame('email', $identity->verification_channel);
    }

    // ── Cas interdits : la règle de sécurité centrale ────────────────────────

    /**
     * LE test qui garantit la règle demandée : un OTP livré par email ne doit
     * JAMAIS pouvoir marquer un téléphone comme vérifié.
     */
    public function test_email_ne_peut_jamais_verifier_une_identite_telephone(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_TELEPHONE);

        $this->expectException(LogicException::class);

        $identity->markVerifiedVia(OtpChannel::EMAIL);
    }

    public function test_sms_ne_peut_jamais_verifier_une_identite_email(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_EMAIL);

        $this->expectException(LogicException::class);

        $identity->markVerifiedVia(OtpChannel::SMS);
    }

    public function test_whatsapp_ne_peut_jamais_verifier_une_identite_email(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_EMAIL);

        $this->expectException(LogicException::class);

        $identity->markVerifiedVia(OtpChannel::WHATSAPP);
    }

    /**
     * Une tentative refusée ne doit laisser AUCUNE trace : `verified_at` reste
     * NULL après l'exception, pas d'écriture partielle.
     */
    public function test_une_tentative_refusee_ne_modifie_rien(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_TELEPHONE);

        try {
            $identity->markVerifiedVia(OtpChannel::EMAIL);
        } catch (LogicException) {
            // attendu
        }

        $identity->refresh();
        $this->assertFalse($identity->isVerified());
        $this->assertNull($identity->verification_channel);
    }

    // ── isVerified() garde son sens strict ───────────────────────────────────

    public function test_is_verified_reste_faux_pour_une_identite_jamais_marquee(): void
    {
        $identity = $this->makeIdentity(UserAuthIdentity::TYPE_TELEPHONE);

        $this->assertFalse($identity->isVerified());
    }
}
