<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\Role;
use App\Events\MemberAdded;
use App\Events\MemberAdding;
use App\Events\MemberRemoved;
use App\Exceptions\Api\CanNotRemoveOwnerFromOrganization;
use App\Exceptions\Api\ChangingRoleOfPlaceholderIsNotAllowed;
use App\Exceptions\Api\ChangingRoleToPlaceholderIsNotAllowed;
use App\Exceptions\Api\EntityStillInUseApiException;
use App\Exceptions\Api\OnlyOwnerCanChangeOwnership;
use App\Exceptions\Api\OrganizationNeedsAtLeastOneOwner;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MemberService
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function addMember(User $user, Organization $organization, Role $role, bool $asSuperAdmin = false): Member
    {
        if (! $asSuperAdmin) {
            MemberAdding::dispatch($user, $organization, $role);
        }

        $member = new Member;
        DB::transaction(function () use ($organization, $user, $role, &$member): void {
            $member->user()->associate($user);
            $member->organization()->associate($organization);
            $member->role = $role->value;
            $member->save();

            $user->currentOrganization()->associate($organization);
            $user->save();
        });
        $this->mergePlaceholderMembersIntoExistingMember($member, $organization, $user);

        if (! $asSuperAdmin) {
            MemberAdded::dispatch($member, $organization, $user);
        }

        return $member;
    }

    public function claimUniquePlaceholderMember(
        Organization $organization,
        string $name,
        string $email,
        Role $role = Role::Employee,
    ): ?User {
        $placeholderMembers = Member::query()
            ->whereBelongsTo($organization, 'organization')
            ->where('role', Role::Placeholder->value)
            ->whereHas('user', function (Builder $query): void {
                /** @var Builder<User> $query */
                $query->where('is_placeholder', true);
            })
            ->with('user')
            ->lockForUpdate()
            ->get();

        $normalizedEmail = Str::lower($email);
        $matchingMembers = $placeholderMembers->filter(
            fn (Member $member): bool => Str::lower($member->user->email) === $normalizedEmail
        );

        if ($matchingMembers->isEmpty()) {
            $normalizedName = $this->normalizeName($name);
            $matchingMembers = $placeholderMembers->filter(
                fn (Member $member): bool => $this->normalizeName($member->user->name) === $normalizedName
            );
        }

        if ($matchingMembers->count() !== 1) {
            return null;
        }

        /** @var Member $member */
        $member = $matchingMembers->first();
        $user = $this->claimPlaceholderMember($member->user, $organization, $name, $email, $role);

        return $user;
    }

    public function claimPlaceholderMember(
        User $placeholder,
        Organization $organization,
        string $name,
        string $email,
        Role $role,
    ): ?User {
        $member = Member::query()
            ->whereBelongsTo($organization, 'organization')
            ->whereBelongsTo($placeholder, 'user')
            ->where('role', Role::Placeholder->value)
            ->lockForUpdate()
            ->first();
        if ($member === null) {
            return null;
        }

        /** @var User $user */
        $user = User::query()
            ->whereKey($placeholder->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        if (! $user->is_placeholder) {
            return null;
        }

        $user->name = $name;
        $user->email = Str::lower($email);
        $user->pending_email = null;
        $user->password = null;
        $user->email_verified_at = now();
        $user->is_placeholder = false;
        $user->save();

        $member->role = $role->value;
        $member->save();

        return $user;
    }

    private function normalizeName(string $name): string
    {
        return Str::lower(Str::squish(Str::ascii($name)));
    }

    private function mergePlaceholderMembersIntoExistingMember(Member $member, Organization $organization, User $user): void
    {
        $placeholders = Member::query()
            ->whereHas('user', function (Builder $query) use ($user): void {
                /** @var Builder<User> $query */
                $query->where('is_placeholder', '=', true)
                    ->where('email', '=', $user->email);
            })
            ->whereBelongsTo($organization, 'organization')
            ->with(['user'])
            ->get();

        foreach ($placeholders as $placeholder) {
            /** @var Member $placeholder */
            $placeholderUser = $placeholder->user;
            $this->assignOrganizationEntitiesToDifferentMember($organization, $placeholder, $member);
            $placeholder->delete();
            $placeholderUser->delete();
        }
    }

    /**
     * @throws CanNotRemoveOwnerFromOrganization
     * @throws EntityStillInUseApiException
     */
    public function removeMember(Member $member, Organization $organization, bool $withRelations = false): void
    {
        if ($member->role === Role::Owner->value) {
            throw new CanNotRemoveOwnerFromOrganization;
        }

        $user = $member->user;
        $isPlaceholder = $user->is_placeholder;

        if (! $isPlaceholder && $user->current_team_id === $member->organization_id) {
            $user->currentOrganization()->disassociate();
            $user->save();
        }

        if ($withRelations) {
            TimeEntry::query()->where('user_id', $member->user_id)->whereBelongsTo($organization, 'organization')->delete();
            ProjectMember::query()->whereBelongsToOrganization($organization)->where('user_id', $member->user_id)->delete();
        } else {
            if (TimeEntry::query()->where('user_id', $member->user_id)->whereBelongsTo($organization, 'organization')->exists()) {
                throw new EntityStillInUseApiException('member', 'time_entry');
            }
            if (ProjectMember::query()->whereBelongsToOrganization($organization)->where('user_id', $member->user_id)->exists()) {
                throw new EntityStillInUseApiException('member', 'project_member');
            }
        }

        $member->delete();

        if ($isPlaceholder) {
            $user->delete();
        } else {
            $this->userService->makeSureUserHasAtLeastOneOrganization($user);
            $this->userService->makeSureUserHasCurrentOrganization($user);
        }

        MemberRemoved::dispatch($member, $organization);
    }

    /**
     * @throws ChangingRoleToPlaceholderIsNotAllowed
     * @throws OnlyOwnerCanChangeOwnership
     * @throws OrganizationNeedsAtLeastOneOwner
     * @throws ChangingRoleOfPlaceholderIsNotAllowed
     */
    public function changeRole(Member $member, Organization $organization, Role $newRole, bool $allowOwnerChange): void
    {
        $oldRole = Role::from($member->role);
        if ($oldRole === Role::Owner) {
            throw new OrganizationNeedsAtLeastOneOwner;
        }
        if ($oldRole === Role::Placeholder) {
            throw new ChangingRoleOfPlaceholderIsNotAllowed;
        }
        if ($newRole === Role::Placeholder) {
            throw new ChangingRoleToPlaceholderIsNotAllowed;
        }
        if ($newRole === Role::Owner) {
            if ($allowOwnerChange) {
                $this->changeOwnership($organization, $member);
            } else {
                throw new OnlyOwnerCanChangeOwnership;
            }
        } else {
            $member->role = $newRole->value;
        }
    }

    public function assignOrganizationEntitiesToDifferentMember(Organization $organization, Member $fromMember, Member $toMember): void
    {
        // Time entries
        TimeEntry::query()
            ->whereBelongsTo($organization, 'organization')
            ->whereBelongsTo($fromMember, 'member')
            ->update([
                'user_id' => $toMember->user_id,
                'member_id' => $toMember->getKey(),
            ]);

        // Project members
        ProjectMember::query()
            ->whereBelongsToOrganization($organization)
            ->whereBelongsTo($fromMember, 'member')
            ->whereDoesntHave('project', function (Builder $builder) use ($toMember): void {
                /** @var Builder<Project> $builder */
                $builder->whereHas('members', function (Builder $builder) use ($toMember): void {
                    /** @var Builder<ProjectMember> $builder */
                    $builder->where('member_id', $toMember->getKey());
                });
            })
            ->update([
                'user_id' => $toMember->user_id,
                'member_id' => $toMember->getKey(),
            ]);

        ProjectMember::query()
            ->whereBelongsToOrganization($organization)
            ->whereBelongsTo($fromMember, 'member')
            ->delete();
    }

    /**
     * Change the ownership of an organization to a new user.
     * The previous owner will be demoted to an admin.
     */
    public function changeOwnership(Organization $organization, Member $newOwner): void
    {
        $organization->update([
            'user_id' => $newOwner->user_id,
        ]);
        if ($newOwner->organization_id !== $organization->getKey()) {
            throw new InvalidArgumentException('Member is not part of the organization');
        }
        $newOwner->role = Role::Owner->value;
        $newOwner->save();
        $oldOwners = Member::query()
            ->whereBelongsTo($organization, 'organization')
            ->where('role', '=', Role::Owner->value)
            ->where('id', '!=', $newOwner->getKey())
            ->get();
        foreach ($oldOwners as $oldOwner) {
            $oldOwner->role = Role::Admin->value;
            $oldOwner->save();
        }
    }

    public function makeMemberToPlaceholder(Member $member, bool $makeSureUserHasAtLeastOneOrganization = true): void
    {
        $user = $member->user;
        if ($user->current_team_id === $member->organization_id) {
            $user->currentOrganization()->disassociate();
            $user->save();
        }

        $placeholderUser = $user->replicate();
        $placeholderUser->is_placeholder = true;
        $placeholderUser->current_team_id = $member->organization_id;
        $placeholderUser->save();

        $member->user()->associate($placeholderUser);
        $member->role = Role::Placeholder->value;
        $member->save();

        $this->userService->assignOrganizationEntitiesToDifferentUser($member->organization, $user, $placeholderUser);
        if ($makeSureUserHasAtLeastOneOrganization) {
            $this->userService->makeSureUserHasAtLeastOneOrganization($user);
            $this->userService->makeSureUserHasCurrentOrganization($user);
        }
    }

    public function isEmailAlreadyMember(Organization $organization, string $email): bool
    {
        return Member::query()
            ->whereBelongsTo($organization, 'organization')
            ->whereRelation('user', 'email', '=', $email)
            ->where('role', '!=', Role::Placeholder->value)
            ->exists();
    }
}
