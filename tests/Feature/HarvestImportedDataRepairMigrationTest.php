<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Client;
use App\Models\ExternalAuthOrganization;
use App\Models\ExternalAuthUserMapping;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HarvestImportedDataRepairMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_harvest_users_project_access_and_zero_hour_projects(): void
    {
        $owner = User::factory()->create([
            'name' => 'Hassan Mansour',
            'email' => 'hassan@opendeved.net',
        ]);
        $organization = Organization::factory()->withOwner($owner)->create();
        $ownerMember = Member::factory()
            ->forOrganization($organization)
            ->forUser($owner)
            ->role(Role::Owner)
            ->create();
        ExternalAuthOrganization::query()
            ->whereKey('google')
            ->update(['organization_id' => $organization->getKey()]);

        $client = Client::factory()->forOrganization($organization)->create([
            'name' => 'EdTech Hub (R4D) (Project no. 004)',
        ]);
        Client::factory()->forOrganization($organization)->create([
            'name' => 'OBSOLETE - Results for Development',
        ]);
        $existingProject = Project::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->create(['name' => 'Existing Harvest Project', 'is_public' => false]);
        $existingPakistanProject = Project::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->create(['name' => 'Y3O65_Pakistan IA — FCDO Main', 'is_public' => false]);

        $ownerPlaceholder = User::factory()->placeholder()->create([
            'name' => 'Hassan Mansour',
            'email' => 'hassan.mansour@solidtime-import.test',
        ]);
        $ownerPlaceholderMember = Member::factory()
            ->forOrganization($organization)
            ->forUser($ownerPlaceholder)
            ->role(Role::Placeholder)
            ->create();
        $ownerTimeEntry = TimeEntry::factory()->forMember($ownerPlaceholderMember)->create([
            'project_id' => $existingProject->getKey(),
            'client_id' => $client->getKey(),
        ]);
        ProjectMember::factory()
            ->forMember($ownerPlaceholderMember)
            ->forProject($existingProject)
            ->create();

        $mappedPlaceholder = User::factory()->placeholder()->create([
            'name' => 'Mohammed Charrad',
            'email' => 'mohammed.charrad@solidtime-import.test',
        ]);
        $mappedPlaceholderMember = Member::factory()
            ->forOrganization($organization)
            ->forUser($mappedPlaceholder)
            ->role(Role::Placeholder)
            ->create();
        TimeEntry::factory()->forMember($mappedPlaceholderMember)->create([
            'project_id' => $existingProject->getKey(),
            'client_id' => $client->getKey(),
        ]);

        $migration = require database_path('migrations/2026_09_04_000004_repair_harvest_imported_data.php');
        $migration->up();

        $this->assertSame('GBP', $organization->refresh()->currency);
        $this->assertDatabaseMissing(User::class, ['id' => $ownerPlaceholder->getKey()]);
        $this->assertSame($owner->getKey(), $ownerTimeEntry->refresh()->user_id);
        $this->assertSame($ownerMember->getKey(), $ownerTimeEntry->member_id);
        $this->assertSame(Role::Owner->value, $ownerMember->refresh()->role);
        $this->assertDatabaseHas(ExternalAuthUserMapping::class, [
            'email' => 'hassan@opendeved.net',
            'user_id' => $owner->getKey(),
            'role' => Role::Owner->value,
        ]);
        $this->assertDatabaseHas(ExternalAuthUserMapping::class, [
            'email' => 'mohammed@opendeved.net',
            'user_id' => $mappedPlaceholder->getKey(),
            'role' => Role::Admin->value,
        ]);
        $this->assertDatabaseHas(ProjectMember::class, [
            'project_id' => $existingProject->getKey(),
            'member_id' => $mappedPlaceholderMember->getKey(),
            'user_id' => $mappedPlaceholder->getKey(),
        ]);
        $this->assertDatabaseHas(Project::class, [
            'name' => '2025/Q1AMJ ETH-Helpdesk',
            'client_id' => $client->getKey(),
            'organization_id' => $organization->getKey(),
            'is_public' => false,
        ]);
        $this->assertDatabaseHas(Project::class, [
            'name' => 'Y3O65_Pakistan IA — FCDO Main [ETH-PAKISTAN-IA-FCDO]',
            'client_id' => $client->getKey(),
            'organization_id' => $organization->getKey(),
            'is_public' => false,
        ]);
        $this->assertDatabaseHas(Project::class, [
            'id' => $existingPakistanProject->getKey(),
            'name' => 'Y3O65_Pakistan IA — FCDO Main',
        ]);
    }
}
