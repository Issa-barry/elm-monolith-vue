<?php

namespace Tests\Feature\Auth;

use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterOtpTest extends TestCase
{
    use RefreshDatabase;

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function generateOtp(string $phone): void
    {
        app(OtpService::class)->generate($phone);
    }

    // ─── Validation de base ───────────────────────────────────────────────────

    public function test_otp_verify_requires_telephone_and_code(): void
    {
        $this->postJson(route('register.otp.verify'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telephone', 'code']);
    }

    public function test_otp_verify_requires_exactly_5_digits(): void
    {
        $this->generateOtp('+224620000001');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000001',
            'code' => '1234', // 4 chiffres seulement
        ])->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_otp_verify_rejects_non_numeric_code(): void
    {
        $this->generateOtp('+224620000001');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000001',
            'code' => 'abc12',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ─── Validation OTP ───────────────────────────────────────────────────────

    public function test_otp_verify_fails_without_prior_lookup(): void
    {
        // Aucun OTP en session pour ce numéro
        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000002',
            'code' => '12345',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Code de vérification incorrect.']);
    }

    public function test_otp_verify_fails_with_wrong_code(): void
    {
        $this->generateOtp('+224620000003');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000003',
            'code' => '99999',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Code de vérification incorrect.']);
    }

    public function test_otp_verify_succeeds_with_correct_code(): void
    {
        $this->generateOtp('+224620000004');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000004',
            'code' => '12345',
        ])->assertOk()
            ->assertJson(['verified' => true]);
    }

    // ─── Marquage vérifié en session ──────────────────────────────────────────

    public function test_otp_is_marked_verified_in_session_after_success(): void
    {
        $this->generateOtp('+224620000005');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000005',
            'code' => '12345',
        ]);

        $this->assertTrue(
            app(OtpService::class)->isVerified('+224620000005'),
            'OTP should be marked verified in session after correct code.',
        );
    }

    public function test_otp_is_not_marked_verified_after_wrong_code(): void
    {
        $this->generateOtp('+224620000006');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000006',
            'code' => '00000',
        ]);

        $this->assertFalse(
            app(OtpService::class)->isVerified('+224620000006'),
            'OTP should NOT be marked verified after wrong code.',
        );
    }

    // ─── Numéro invalide ──────────────────────────────────────────────────────

    public function test_otp_verify_rejects_invalid_phone(): void
    {
        $this->postJson(route('register.otp.verify'), [
            'telephone' => 'pas-un-numero',
            'code' => '12345',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Numéro de téléphone invalide.']);
    }
}
