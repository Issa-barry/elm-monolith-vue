<?php

namespace Tests\Feature\Api;

use App\Mail\ContactMessageReceived;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ces routes (api/public/*) sont appelées server-to-server par l'app vitrine,
 * jamais par un navigateur — d'où l'absence de assertRedirect/assertSessionHas*
 * (contrairement aux équivalents web) : tout est JSON, sans session.
 */
class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    private function vitrineHeaders(): array
    {
        return ['X-Vitrine-Key' => config('services.vitrine.token')];
    }

    private function verifyOtp(string $phone): void
    {
        $otp = app(OtpService::class);
        $otp->generate($phone);
        $otp->markVerified($phone);
    }

    private function livreurPayload(array $overrides = []): array
    {
        return array_merge([
            'prenom' => 'Alpha',
            'nom' => 'Diallo',
            'telephone' => '+224622111001',
            'telephone_country' => 'GN',
            'telephone_local' => '622111001',
            'password' => 'Password123!',
        ], $overrides);
    }

    // ── Protection par token partagé ─────────────────────────────────────────

    public function test_requests_without_vitrine_token_are_rejected(): void
    {
        $this->postJson(route('api.public.contact'), ['phone' => '+224620000000', 'message' => 'x'])
            ->assertForbidden();
    }

    public function test_requests_with_wrong_vitrine_token_are_rejected(): void
    {
        $this->postJson(route('api.public.contact'), ['phone' => '+224620000000', 'message' => 'x'], [
            'X-Vitrine-Key' => 'wrong-token',
        ])->assertForbidden();
    }

    // ── contact ───────────────────────────────────────────────────────────────

    public function test_contact_creates_message_with_organization_id_and_sends_mail(): void
    {
        Mail::fake();
        $org = Organization::factory()->create();
        // publicOrganization() prend la première organisation (id le plus bas) :
        // s'assurer qu'aucune autre n'existe pour un test déterministe.
        Organization::query()->where('id', '!=', $org->id)->delete();

        $this->postJson(route('api.public.contact'), [
            'phone' => '+224620001122',
            'message' => 'Bonjour, je voudrais un partenariat.',
        ], $this->vitrineHeaders())->assertOk();

        $this->assertDatabaseHas('contact_messages', [
            'phone' => '+224620001122',
            'organization_id' => $org->id,
        ]);
        Mail::assertSent(ContactMessageReceived::class);
    }

    public function test_contact_requires_phone_and_message(): void
    {
        $this->postJson(route('api.public.contact'), [], $this->vitrineHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'message']);
    }

    // ── register/lookup ───────────────────────────────────────────────────────

    public function test_register_lookup_returns_not_found_for_unknown_phone(): void
    {
        $this->postJson(route('api.public.register.lookup'), ['telephone' => '+224699999999'], $this->vitrineHeaders())
            ->assertOk()
            ->assertJson(['status' => 'not_found', 'prefill' => null]);
    }

    public function test_register_lookup_returns_user_exists(): void
    {
        User::factory()->create(['telephone' => '+224620000200']);

        $this->postJson(route('api.public.register.lookup'), ['telephone' => '+224620000200'], $this->vitrineHeaders())
            ->assertOk()
            ->assertJson(['status' => 'user_exists']);
    }

    // ── register/otp/verify ───────────────────────────────────────────────────

    public function test_register_otp_verify_succeeds_with_correct_code(): void
    {
        app(OtpService::class)->generate('+224622111001');

        $this->postJson(route('api.public.register.otp.verify'), [
            'telephone' => '+224622111001',
            'code' => '123456',
        ], $this->vitrineHeaders())->assertOk()->assertJson(['verified' => true]);
    }

    public function test_register_otp_verify_fails_with_wrong_code(): void
    {
        app(OtpService::class)->generate('+224622111001');

        $this->postJson(route('api.public.register.otp.verify'), [
            'telephone' => '+224622111001',
            'code' => '000000',
        ], $this->vitrineHeaders())->assertUnprocessable();
    }

    // ── register/livreur ──────────────────────────────────────────────────────

    public function test_livreur_registration_succeeds_without_auth_login_or_session(): void
    {
        Organization::factory()->create();
        $this->verifyOtp('+224622111001');

        $response = $this->postJson(route('api.public.register.livreur'), $this->livreurPayload(), $this->vitrineHeaders())
            ->assertOk()
            ->assertJson(['status' => 'pending_validation']);

        // Jamais de Set-Cookie de session : Auth::login() connecterait le serveur
        // appelant (la vitrine), pas le visiteur — voir le commentaire du contrôleur.
        $response->assertHeaderMissing('set-cookie');
        $this->assertGuest();

        $user = User::where('telephone', '+224622111001')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('livreur'));
        $this->assertDatabaseHas('livreurs', ['telephone' => '+224622111001', 'is_active' => false]);
    }

    public function test_livreur_registration_links_existing_admin_created_livreur(): void
    {
        $org = Organization::factory()->create();
        $livreur = Livreur::factory()->create([
            'organization_id' => $org->id,
            'telephone' => '+224622111001',
            'user_id' => null,
        ]);
        $this->verifyOtp('+224622111001');

        $this->postJson(route('api.public.register.livreur'), $this->livreurPayload(), $this->vitrineHeaders())
            ->assertOk();

        $user = User::where('telephone', '+224622111001')->first();
        $this->assertSame($user->id, $livreur->fresh()->user_id);
        $this->assertDatabaseCount('livreurs', 1);
    }

    public function test_livreur_registration_fails_without_otp_verification(): void
    {
        Organization::factory()->create();

        $this->postJson(route('api.public.register.livreur'), $this->livreurPayload(), $this->vitrineHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telephone']);
    }

    public function test_livreur_registration_fails_if_phone_already_has_account(): void
    {
        Organization::factory()->create();
        User::factory()->create(['telephone' => '+224622111001']);
        $this->verifyOtp('+224622111001');

        $this->postJson(route('api.public.register.livreur'), $this->livreurPayload(), $this->vitrineHeaders())
            ->assertUnprocessable();
    }

    // ── modules ───────────────────────────────────────────────────────────────

    public function test_modules_endpoint_returns_can_register_flag(): void
    {
        $this->getJson(route('api.public.modules'), $this->vitrineHeaders())
            ->assertOk()
            ->assertJsonStructure(['can_register']);
    }
}
