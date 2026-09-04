<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\Role;
use App\Models\ExternalAuthOrganization;
use App\Models\ExternalIdentity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GoogleAuthenticationService
{
    public function __construct(
        private readonly UserService $userService,
        private readonly MemberService $memberService,
        private readonly InvitationService $invitationService,
        private readonly OrganizationService $organizationService,
    ) {}

    public function authenticate(
        string $subject,
        string $email,
        string $name,
        ?string $avatarUrl,
    ): User {
        [$user, $organization] = DB::transaction(function () use ($subject, $email, $name): array {
            $authOrganization = ExternalAuthOrganization::query()
                ->whereKey('google')
                ->lockForUpdate()
                ->firstOrFail();
            $identity = ExternalIdentity::query()
                ->where('provider', 'google')
                ->where('provider_user_id', $subject)
                ->lockForUpdate()
                ->first();

            if ($identity !== null) {
                $user = $identity->user;
                $organization = $authOrganization->organization;
                if ($organization === null) {
                    $organization = $this->createOrganizationForOwner($user, $authOrganization);
                }
                $this->ensureOrganizationMembership($user, $organization);

                return [$user, $organization];
            }

            $user = User::query()
                ->where('email', strtolower($email))
                ->where('is_placeholder', false)
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                $user = $this->userService->createPasswordlessUser($name, $email);
            }

            $organization = $authOrganization->organization;
            if ($organization === null) {
                $organization = $this->createOrganizationForOwner($user, $authOrganization);
            } else {
                $this->ensureOrganizationMembership($user, $organization);
            }

            $identity = new ExternalIdentity;
            $identity->user()->associate($user);
            $identity->provider = 'google';
            $identity->provider_user_id = $subject;
            $identity->save();

            return [$user, $organization];
        });

        $this->invitationService->processAcceptedInvitations($user);
        $this->userService->switchCurrentOrganization($user, $organization);
        $this->importAvatar($user, $avatarUrl);

        return $user;
    }

    private function createOrganizationForOwner(
        User $user,
        ExternalAuthOrganization $authOrganization,
    ): Organization {
        $organization = $this->organizationService->createOrganization(
            $this->userService->getOrganizationNameForUserName($user->name),
            $user,
            false,
        );
        $this->userService->switchCurrentOrganization($user, $organization);
        $authOrganization->organization()->associate($organization);
        $authOrganization->save();

        return $organization;
    }

    private function ensureOrganizationMembership(User $user, Organization $organization): void
    {
        if ($user->email_verified_at === null) {
            $user->email_verified_at = Carbon::now();
        }

        if (! $user->isMemberOfOrganization($organization)) {
            $this->memberService->addMember($user, $organization, Role::Employee);

            return;
        }

        $this->userService->switchCurrentOrganization($user, $organization);
    }

    private function importAvatar(User $user, ?string $avatarUrl): void
    {
        if ($avatarUrl === null || $avatarUrl === '' || $user->profile_photo_path !== null) {
            return;
        }

        try {
            $response = Http::timeout(5)->get($avatarUrl);
            $photo = $response->body();
            if (! $response->successful() || $photo === '' || strlen($photo) > 1024 * 1024) {
                return;
            }

            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($photo);
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => null,
            };
            if ($extension === null) {
                return;
            }

            $photoPath = 'profile-photos/'.Str::uuid().'.'.$extension;
            Storage::disk((string) config('filesystems.public'))->put($photoPath, $photo, 'public');
            $user->profile_photo_path = $photoPath;
            $user->save();
        } catch (Throwable $exception) {
            Log::warning('Failed to import Google profile photo', [
                'user_id' => $user->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
