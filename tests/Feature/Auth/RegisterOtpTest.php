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

    public function test_otp_verify_requires_exactly_6_digits(): void
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
            'code' => 'abc123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // ─── Validation OTP ───────────────────────────────────────────────────────

    public function test_otp_verify_fails_without_prior_lookup(): void
    {
        // Aucun OTP en session pour ce numéro
        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000002',
            'code' => '123456',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Code incorrect ou expiré.']);
    }

    public function test_otp_verify_fails_with_wrong_code(): void
    {
        $this->generateOtp('+224620000003');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000003',
            'code' => '999999',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Code incorrect ou expiré.']);
    }

    public function test_otp_verify_succeeds_with_correct_code(): void
    {
        $this->generateOtp('+224620000004');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000004',
            'code' => '123456',
        ])->assertOk()
            ->assertJson(['verified' => true]);
    }

    public function test_otp_verify_locks_out_after_5_wrong_attempts(): void
    {
        $this->generateOtp('+224620000007');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('register.otp.verify'), [
                'telephone' => '+224620000007',
                'code' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000007',
            'code' => '123456',
        ])->assertStatus(429)
            ->assertJsonFragment(['error' => 'Trop de tentatives. Demandez un nouveau code.']);
    }

    // ─── Marquage vérifié en session ──────────────────────────────────────────

    public function test_otp_is_marked_verified_in_session_after_success(): void
    {
        $this->generateOtp('+224620000005');

        $this->postJson(route('register.otp.verify'), [
            'telephone' => '+224620000005',
            'code' => '123456',
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
            'code' => '000000',
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
            'code' => '123456',
        ])->assertStatus(422)
            ->assertJsonFragment(['error' => 'Numéro de téléphone invalide.']);
    }
}
