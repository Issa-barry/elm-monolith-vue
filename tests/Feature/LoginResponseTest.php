<?php

namespace Tests\Feature;

use App\Http\Responses\LoginResponse;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LoginResponseTest extends TestCase
{
    use RefreshDatabase;

    private function makeRequest(User $user, bool $wantsJson = false): Request
    {
        $server = $wantsJson ? ['HTTP_ACCEPT' => 'application/json'] : [];
        $request = Request::create('/', 'GET', [], [], [], $server);
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_non_client_user_is_redirected_to_dashboard(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $request = $this->makeRequest($user);
        $loginResponse = new LoginResponse;
        $response = $loginResponse->toResponse($request);

        $this->assertStringContainsString('dashboard', $response->getTargetUrl());
    }

    public function test_client_user_is_redirected_to_client_dashboard(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('client');

        $request = $this->makeRequest($user);
        $loginResponse = new LoginResponse;
        $response = $loginResponse->toResponse($request);

        $this->assertStringContainsString('client', $response->getTargetUrl());
    }

    public function test_login_response_returns_json_when_request_wants_json(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('admin_entreprise');

        $request = $this->makeRequest($user, true);
        $loginResponse = new LoginResponse;
        $response = $loginResponse->toResponse($request);

        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['two_factor']);
    }
}
