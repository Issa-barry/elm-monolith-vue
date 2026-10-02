<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivreurRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'prenom' => 'Mamadou',
            'nom' => 'Diallo',
            'telephone' => '+224620000001',
            'telephone_country' => 'GN',
            'telephone_local' => '620000001',
            'password' => 'Password123!',
        ], $overrides);
    }

    private function verifyOtp(string $phone): void
    {
        $otp = app(OtpService::class);
        $code = $otp->generate($phone, OtpPurpose::PHONE_VERIFICATION);
        $otp->verify($phone, $code, OtpPurpose::PHONE_VERIFICATION);
        $otp->markVerified($phone, OtpPurpose::PHONE_VERIFICATION);
    }

    public function test_store_creates_user_and_livreur_and_logs_in(): void
    {
        Organization::factory()->create();
        $this->verifyOtp('+224620000001');

        $this->post(route('livreur.register.store'), $this->validData())
            ->assertRedirect(route('client.pending'));

        $user = User::whereHas('personne', fn ($q) => $q->where('telephone', '+224620000001'))->firstOrFail();
        $this->assertTrue($user->hasRole('livreur'));
        $this->assertAuthenticatedAs($user);

        $livreur = Livreur::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Mamadou DIALLO', $livreur->nom_complet);
        $this->assertFalse($livreur->is_active);
    }

    public function test_store_requires_otp_verification(): void
    {
        Organization::factory()->create();

        $this->post(route('livreur.register.store'), $this->validData())
            ->assertSessionHasErrors('telephone');

        $this->assertGuest();
        $this->assertDatabaseMissing('personnes', ['telephone' => '+224620000001']);
    }

    public function test_store_rejects_duplicate_phone(): void
    {
        $org = Organization::factory()->create();
        $existingUser = User::factory()->create(['organization_id' => $org->id, 'telephone' => '+224620000001']);
        $this->verifyOtp('+224620000001');

        $this->post(route('livreur.register.store'), $this->validData())
            ->assertSessionHasErrors('telephone');

        $this->assertGuest();
        $this->assertSame(1, User::whereHas('personne', fn ($q) => $q->where('telephone', '+224620000001'))->count());
    }

    public function test_store_rejects_invalid_phone(): void
    {
        Organization::factory()->create();

        $this->post(route('livreur.register.store'), $this->validData(['telephone' => 'pas-un-numero']))
            ->assertSessionHasErrors('telephone');

        $this->assertGuest();
    }

    public function test_store_links_to_existing_livreur_without_user(): void
    {
        $org = Organization::factory()->create();
        $personne = Personne::create([
            'organization_id' => $org->id,
            'nom' => 'DIALLO',
            'prenom' => 'Mamadou',
            'telephone' => '+224620000001',
            'telephone_normalise' => '224620000001',
        ]);
        $existingLivreur = Livreur::create([
            'organization_id' => $org->id,
            'user_id' => null,
            'personne_id' => $personne->id,
            'is_active' => false,
        ]);
        $this->verifyOtp('+224620000001');

        $this->post(route('livreur.register.store'), $this->validData())
            ->assertRedirect(route('client.pending'));

        $existingLivreur->refresh();
        $this->assertNotNull($existingLivreur->user_id);
        $this->assertSame(1, Livreur::count());
    }

    public function test_store_validates_required_fields(): void
    {
        $this->post(route('livreur.register.store'), [])
            ->assertSessionHasErrors(['prenom', 'nom', 'telephone', 'telephone_country', 'telephone_local', 'password']);
    }
}
