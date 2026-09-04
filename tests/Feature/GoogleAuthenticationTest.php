<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ExternalAuthOrganization;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_authentication_redirects_to_google_when_enabled(): void
    {
        config()->set('services.google.enabled', true);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('redirect')
            ->once()
            ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));
        Socialite::shouldReceive('driver')
            ->once()
            ->with('google')
            ->andReturn($provider);

        $response = $this->get('/auth/google');

        $response->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    public function test_login_page_exposes_google_authentication_when_enabled(): void
    {
        $this->withoutVite();
        config()->set('services.google.enabled', true);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Login')
            ->where('googleAuthEnabled', true)
        );
    }

    public function test_google_authentication_rejects_accounts_outside_the_allowed_domains(): void
    {
        config()->set('services.google.enabled', true);
        config()->set('services.google.allowed_domains', ['opendeved.net', 'ekitabu.com']);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-user-1',
            'name' => 'Outside User',
            'email' => 'outside@example.com',
            'email_verified' => true,
            'hd' => 'example.com',
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHas('message', 'You are not authorized.');
    }

    public function test_google_authentication_returns_to_login_when_google_rejects_the_callback(): void
    {
        config()->set('services.google.enabled', true);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHas('message', 'Google sign-in could not be completed. Please try again.');
    }

    public function test_first_google_login_creates_the_organization_and_becomes_its_owner(): void
    {
        $photo = file_get_contents(resource_path('testfiles/test.png'));
        $this->assertIsString($photo);
        $photoDisk = (string) config('filesystems.public');
        Storage::fake($photoDisk);
        Http::fake([
            'https://lh3.googleusercontent.com/google-avatar' => Http::response($photo, 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);
        config()->set('services.google.enabled', true);
        config()->set('services.google.allowed_domains', ['opendeved.net', 'ekitabu.com']);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-user-2',
            'name' => 'OpenDevEd User',
            'email' => 'person@opendeved.net',
            'email_verified' => true,
            'hd' => 'opendeved.net',
            'avatar' => 'https://lh3.googleusercontent.com/google-avatar',
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get('/auth/google/callback');

        $response->assertRedirect(RouteServiceProvider::HOME);
        $this->assertAuthenticated();
        $user = User::query()->where('email', 'person@opendeved.net')->firstOrFail();
        $organization = Organization::query()->sole();
        $this->assertSame($user->getKey(), auth()->id());
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue($organization->owner->is($user));
        $this->assertSame($organization->getKey(), $user->current_team_id);
        $this->assertNotNull($user->profile_photo_path);
        $this->assertStringStartsWith('profile-photos/', $user->profile_photo_path);
        $this->assertStringEndsWith('.png', $user->profile_photo_path);
        Storage::disk($photoDisk)->assertExists($user->profile_photo_path);
        $this->assertSame($photo, Storage::disk($photoDisk)->get($user->profile_photo_path));
        $this->assertDatabaseHas('members', [
            'organization_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'role' => Role::Owner->value,
        ]);
        $this->assertDatabaseHas('external_identities', [
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => 'google-user-2',
        ]);
        $this->assertFalse($organization->personal_team);
    }

    public function test_google_login_links_an_existing_account_by_verified_email(): void
    {
        $organization = $this->createGoogleOrganization();
        $existingUser = User::factory()->unverified()->create([
            'email' => 'person@ekitabu.com',
        ]);
        config()->set('services.google.enabled', true);
        config()->set('services.google.allowed_domains', ['opendeved.net', 'ekitabu.com']);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-user-3',
            'name' => 'Existing User',
            'email' => 'person@ekitabu.com',
            'email_verified' => true,
            'hd' => 'ekitabu.com',
            'avatar' => null,
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect(RouteServiceProvider::HOME);

        $this->assertAuthenticatedAs($existingUser);
        $this->assertTrue($existingUser->refresh()->hasVerifiedEmail());
        $this->assertSame($organization->getKey(), $existingUser->current_team_id);
        $this->assertDatabaseHas('members', [
            'organization_id' => $organization->getKey(),
            'user_id' => $existingUser->getKey(),
            'role' => Role::Employee->value,
        ]);
        $this->assertDatabaseHas('external_identities', [
            'user_id' => $existingUser->getKey(),
            'provider' => 'google',
            'provider_user_id' => 'google-user-3',
        ]);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_later_google_logins_join_the_existing_organization_as_employees(): void
    {
        config()->set('services.google.enabled', true);
        config()->set('services.google.allowed_domains', ['opendeved.net', 'ekitabu.com']);

        $firstGoogleUser = SocialiteUser::fake([
            'id' => 'google-owner',
            'name' => 'First User',
            'email' => 'first@opendeved.net',
            'email_verified' => true,
            'hd' => 'opendeved.net',
            'avatar' => null,
        ]);
        $firstProvider = Mockery::mock(Provider::class);
        $firstProvider->shouldReceive('user')->once()->andReturn($firstGoogleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($firstProvider);
        $this->get('/auth/google/callback')->assertRedirect(RouteServiceProvider::HOME);
        $organization = Organization::query()->sole();
        auth()->logout();

        $secondGoogleUser = SocialiteUser::fake([
            'id' => 'google-employee',
            'name' => 'Second User',
            'email' => 'second@ekitabu.com',
            'email_verified' => true,
            'hd' => 'ekitabu.com',
            'avatar' => null,
        ]);
        $secondProvider = Mockery::mock(Provider::class);
        $secondProvider->shouldReceive('user')->once()->andReturn($secondGoogleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($secondProvider);

        $this->get('/auth/google/callback')->assertRedirect(RouteServiceProvider::HOME);

        $employee = User::query()->where('email', 'second@ekitabu.com')->firstOrFail();
        $this->assertSame($organization->getKey(), $employee->current_team_id);
        $this->assertTrue($organization->owner->isNot($employee));
        $this->assertDatabaseHas('members', [
            'organization_id' => $organization->getKey(),
            'user_id' => $employee->getKey(),
            'role' => Role::Employee->value,
        ]);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_repeat_google_login_uses_the_linked_subject_without_creating_another_account(): void
    {
        $this->createGoogleOrganization();
        config()->set('services.google.enabled', true);
        config()->set('services.google.allowed_domains', ['opendeved.net', 'ekitabu.com']);

        $firstGoogleUser = SocialiteUser::fake([
            'id' => 'google-user-4',
            'name' => 'Linked User',
            'email' => 'linked@opendeved.net',
            'email_verified' => true,
            'hd' => 'opendeved.net',
            'avatar' => null,
        ]);
        $firstProvider = Mockery::mock(Provider::class);
        $firstProvider->shouldReceive('user')->once()->andReturn($firstGoogleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($firstProvider);
        $this->get('/auth/google/callback')->assertRedirect(RouteServiceProvider::HOME);
        $linkedUserId = auth()->id();
        auth()->logout();

        $repeatGoogleUser = SocialiteUser::fake([
            'id' => 'google-user-4',
            'name' => 'Linked User',
            'email' => 'linked@ekitabu.com',
            'email_verified' => true,
            'hd' => 'ekitabu.com',
            'avatar' => null,
        ]);
        $repeatProvider = Mockery::mock(Provider::class);
        $repeatProvider->shouldReceive('user')->once()->andReturn($repeatGoogleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($repeatProvider);

        $this->get('/auth/google/callback')->assertRedirect(RouteServiceProvider::HOME);

        $this->assertSame($linkedUserId, auth()->id());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('external_identities', 1);
    }

    public function test_google_login_finishes_accepted_invitations_and_keeps_the_google_organization_current(): void
    {
        $configuredOrganization = $this->createGoogleOrganization();
        $invitedOwner = User::factory()->create();
        $invitedOrganization = Organization::factory()->withOwner($invitedOwner)->create();
        $invitedOrganization->users()->attach($invitedOwner, ['role' => Role::Owner->value]);
        $invitation = OrganizationInvitation::factory()
            ->forOrganization($invitedOrganization)
            ->create([
                'email' => 'invited@opendeved.net',
                'role' => Role::Employee->value,
                'accepted_at' => now(),
            ]);
        config()->set('services.google.enabled', true);
        config()->set('services.google.allowed_domains', ['opendeved.net', 'ekitabu.com']);

        $googleUser = SocialiteUser::fake([
            'id' => 'google-user-5',
            'name' => 'Invited User',
            'email' => 'invited@opendeved.net',
            'email_verified' => true,
            'hd' => 'opendeved.net',
            'avatar' => null,
        ]);
        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect(RouteServiceProvider::HOME);

        $user = User::query()->where('email', 'invited@opendeved.net')->firstOrFail();
        $this->assertTrue($user->isMemberOfOrganization($configuredOrganization));
        $this->assertTrue($user->isMemberOfOrganization($invitedOrganization));
        $this->assertSame($configuredOrganization->getKey(), $user->current_team_id);
        $this->assertDatabaseMissing('organization_invitations', ['id' => $invitation->getKey()]);
    }

    private function createGoogleOrganization(): Organization
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->withOwner($owner)->create();
        $organization->users()->attach($owner, ['role' => Role::Owner->value]);
        ExternalAuthOrganization::query()
            ->whereKey('google')
            ->update(['organization_id' => $organization->getKey()]);

        return $organization;
    }
}
