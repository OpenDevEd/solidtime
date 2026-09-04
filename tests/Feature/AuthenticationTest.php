<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_password_authentication_is_disabled(): void
    {
        $response = $this->post('/login', [
            'email' => 'person@opendeved.net',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertMethodNotAllowed();
    }

    public function test_registration_routes_are_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_e2e_authentication_is_disabled_without_its_token(): void
    {
        $this->postJson('/__e2e/login', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'test-password',
            'timezone' => 'UTC',
        ])->assertNotFound();

        $this->assertGuest();
    }

    public function test_e2e_authentication_creates_and_logs_in_a_test_user(): void
    {
        config()->set('app.e2e_auth_token', 'test-token');

        $this->withHeader('X-E2E-Auth-Token', 'test-token')
            ->postJson('/__e2e/login', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'test-password',
                'timezone' => 'UTC',
            ])
            ->assertNoContent();

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
