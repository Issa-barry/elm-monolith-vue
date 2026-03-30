<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateNewUserTest extends TestCase
{
    use RefreshDatabase;

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'prenom' => 'Mamadou',
            'nom' => 'Diallo',
            'email' => 'mamadou@example.com',
            'telephone_country' => 'GN',
            'telephone_local' => '622000001',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ], $overrides);
    }

    public function test_create_user_assigns_client_role(): void
    {
        $action = new CreateNewUser;
        $user = $action->create($this->validInput());

        $this->assertTrue($user->hasRole('client'));
    }

    public function test_create_user_formats_prenom_with_title_case(): void
    {
        $action = new CreateNewUser;
        $user = $action->create($this->validInput(['prenom' => 'jean-paul']));

        $this->assertSame('Jean-Paul', $user->prenom);
    }

    public function test_create_user_uppercases_nom(): void
    {
        $action = new CreateNewUser;
        $user = $action->create($this->validInput(['nom' => 'diallo']));

        $this->assertSame('DIALLO', $user->nom);
    }

    public function test_create_user_builds_e164_telephone(): void
    {
        $action = new CreateNewUser;
        $user = $action->create($this->validInput([
            'telephone_country' => 'GN',
            'telephone_local' => '622000002',
        ]));

        $this->assertSame('+224622000002', $user->telephone);
    }

    public function test_create_user_without_telephone(): void
    {
        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Test',
            'nom' => 'User',
            'email' => 'test.user@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertNull($user->telephone);
    }

    public function test_create_user_fails_with_invalid_country(): void
    {
        $this->expectException(ValidationException::class);

        $action = new CreateNewUser;
        $action->create($this->validInput([
            'telephone_country' => 'XX',
            'telephone_local' => '123456789',
        ]));
    }

    public function test_create_user_fails_with_wrong_local_length(): void
    {
        $this->expectException(ValidationException::class);

        $action = new CreateNewUser;
        $action->create($this->validInput([
            'telephone_country' => 'GN',
            'telephone_local' => '12345', // too short for GN (needs 9)
        ]));
    }

    public function test_create_user_accepts_legacy_telephone_e164(): void
    {
        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Legacy',
            'nom' => 'User',
            'email' => 'legacy@example.com',
            'telephone' => '+224622000003',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertSame('+224622000003', $user->telephone);
    }

    public function test_create_user_accepts_legacy_telephone_with_00_prefix(): void
    {
        $action = new CreateNewUser;
        $user = $action->create([
            'prenom' => 'Legacy',
            'nom' => 'User2',
            'email' => 'legacy2@example.com',
            'telephone' => '00224622000004',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertSame('+224622000004', $user->telephone);
    }

    public function test_create_user_fails_with_invalid_legacy_telephone(): void
    {
        $this->expectException(ValidationException::class);

        $action = new CreateNewUser;
        $action->create([
            'prenom' => 'Bad',
            'nom' => 'Phone',
            'email' => 'badphone@example.com',
            'telephone' => 'not-a-phone',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
    }

    public function test_create_user_lowercases_email(): void
    {
        $action = new CreateNewUser;
        $user = $action->create($this->validInput([
            'email' => 'USER@EXAMPLE.COM',
        ]));

        $this->assertSame('user@example.com', $user->email);
    }
}
