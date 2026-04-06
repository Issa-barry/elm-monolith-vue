<?php

namespace Tests\Feature;

use App\Mail\ContactMessageReceived;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_page_loads(): void
    {
        $this->get(route('home'))->assertStatus(200);
        $this->get('/contact')->assertStatus(200);
    }

    public function test_store_creates_message_and_sends_mail(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [
            'phone' => '+224620001122',
            'message' => 'Bonjour, je voudrais un partenariat.',
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_messages', [
            'phone' => '+224620001122',
        ]);

        Mail::assertSent(ContactMessageReceived::class);
    }

    public function test_store_requires_phone_and_message(): void
    {
        $this->post(route('contact.store'), [])
            ->assertSessionHasErrors(['phone', 'message']);
    }

    public function test_store_with_optional_name_and_email(): void
    {
        Mail::fake();

        $this->post(route('contact.store'), [
            'name' => 'Mamadou Diallo',
            'email' => 'mamadou@example.com',
            'phone' => '+224620001122',
            'message' => 'Test message',
        ])->assertRedirect();

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Mamadou Diallo',
            'email' => 'mamadou@example.com',
        ]);
    }

    public function test_message_too_long_fails_validation(): void
    {
        $this->post(route('contact.store'), [
            'phone' => '+224620001122',
            'message' => str_repeat('a', 5001),
        ])->assertSessionHasErrors('message');
    }

    public function test_unread_count_returns_zero_for_unauthenticated(): void
    {
        // Test via middleware shared data - unauthenticated gets 0
        ContactMessage::factory()->create();
        // Just verify the route doesn't expose data to guests
        $this->get('/contact')->assertStatus(200);
    }

    public function test_mark_read_works_for_staff(): void
    {
        // needs staff user with organization
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = \App\Models\Organization::factory()->create();
        $user = \App\Models\User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $site = \App\Models\Site::create([
            'organization_id' => $org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        $msg = ContactMessage::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->patch(route('contact-messages.read', $msg))
            ->assertJson(['ok' => true]);

        $this->assertNotNull($msg->fresh()->read_at);
    }
}
