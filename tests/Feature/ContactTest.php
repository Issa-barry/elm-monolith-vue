<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_read_works_for_staff(): void
    {
        // needs staff user with organization
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $site = Site::create([
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
