<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\PrepareHarvestImport;
use App\Models\HarvestImportRun;
use App\Models\Organization;
use App\Models\TimeEntry;
use App\Service\Import\Harvest\HarvestClient;
use App\Service\Import\Harvest\HarvestPreview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCaseWithDatabase;

class HarvestApiPreviewTest extends TestCaseWithDatabase
{
    private function configure(string $organizationId): void
    {
        config(['harvest.access_token' => 'test-token', 'harvest.account_id' => '123', 'harvest.organization_id' => $organizationId]);
    }

    public function test_only_admin_of_configured_organization_can_queue_a_preview(): void
    {
        Queue::fake();
        $admin = $this->createUserWithRole(Role::Admin);
        $this->configure($admin->organization->id);
        Passport::actingAs($admin->user);
        $url = '/api/v1/organizations/'.$admin->organization->id.'/harvest-imports';
        $this->postJson($url)->assertAccepted()->assertJsonPath('run.status', 'queued');
        $this->postJson($url)->assertStatus(409);
        Queue::assertPushed(PrepareHarvestImport::class, 1);
        $this->getJson($url)->assertOk()->assertDontSee('test-token');

        $other = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($other->user);
        $otherUrl = '/api/v1/organizations/'.$other->organization->id.'/harvest-imports';
        $this->getJson($otherUrl)->assertOk()->assertJsonPath('configured', false)->assertJsonPath('run', null);
        $this->postJson($otherUrl)->assertForbidden();
        $this->getJson($url)->assertForbidden();

        $employee = $this->createUserWithRole(Role::Employee);
        $this->configure($employee->organization->id);
        Passport::actingAs($employee->user);
        $employeeUrl = '/api/v1/organizations/'.$employee->organization->id.'/harvest-imports';
        $this->getJson($employeeUrl)->assertForbidden();
        $this->postJson($employeeUrl)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_run_ids_are_scoped_even_for_another_authorized_owner(): void
    {
        $admin = $this->createUserWithRole(Role::Owner);
        $this->configure($admin->organization->id);
        Passport::actingAs($admin->user);
        $foreign = HarvestImportRun::create([
            'organization_id' => Organization::factory()->create()->id,
            'account_id' => '123', 'status' => 'ready', 'disk' => 'local',
        ]);
        $this->getJson('/api/v1/organizations/'.$admin->organization->id.'/harvest-imports/'.$foreign->id)->assertNotFound();
    }

    public function test_preview_preserves_source_occurrences_and_does_not_write_business_records(): void
    {
        $this->mockPrivateStorage();
        $admin = $this->createUserWithRole(Role::Owner);
        $this->configure($admin->organization->id);
        TimeEntry::factory()->create([
            'organization_id' => $admin->organization->id,
            'member_id' => $admin->member->id,
            'user_id' => $admin->user->id,
            'description' => 'Existing local work must remain untouched',
        ]);
        $before = [];
        foreach (['users', 'members', 'projects', 'tasks', 'time_entries', 'external_auth_user_mappings'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $sourceUser = ['id' => 1, 'first_name' => 'Archived', 'last_name' => 'Person', 'email' => 'archived@example.com', 'is_active' => false];
        $time = ['id' => 1, 'hours' => 2, 'is_running' => false, 'user' => ['id' => 1], 'project' => ['id' => 2]];
        $collections = array_fill_keys(HarvestClient::COLLECTIONS, []);
        $collections['users'] = [$sourceUser];
        $collections['projects'] = [['id' => 2, 'currency' => 'GBP', 'is_active' => false]];
        $collections['time_entries'] = [$time, array_replace($time, ['id' => 2]), array_replace($time, ['id' => 3, 'hours' => 0]), array_replace($time, ['id' => 4, 'hours' => -1])];
        $responses = ['api.harvestapp.com/v2/company' => Http::response(['name' => 'Test Company'])];
        foreach ($collections as $entity => $rows) {
            $responses['api.harvestapp.com/v2/'.$entity.'?*'] = Http::response([$entity => $rows, 'total_entries' => count($rows), 'links' => ['next' => null]]);
        }
        Http::fake($responses);
        $run = HarvestImportRun::create([
            'organization_id' => $admin->organization->id, 'requested_by' => $admin->user->id,
            'account_id' => '123', 'status' => 'queued', 'disk' => config('filesystems.private'),
        ]);
        (new PrepareHarvestImport($run->id))->handle(app(HarvestClient::class), app(HarvestPreview::class));
        $run->refresh();
        $this->assertSame('ready', $run->status);
        $this->assertSame(4, $run->summary['counts']['time_entries']);
        $this->assertSame(10800, $run->summary['time']['seconds']);
        $this->assertSame(1, $run->summary['time']['zero']);
        $this->assertSame(1, $run->summary['time']['negative']);
        $this->assertSame('archived@example.com', $run->summary['people'][0]['email']);
        $this->assertFalse($run->summary['can_import']);
        foreach ($before as $table => $records) {
            $this->assertSame($records, DB::table($table)->orderBy('id')->get()->toJson(), $table.' must remain unchanged');
        }
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && $request->hasHeader('Harvest-Account-Id', '123'));
    }

    public function test_client_follows_cursor_links_and_rejects_duplicate_source_ids(): void
    {
        $this->configure('test');
        Http::fake([
            'api.harvestapp.com/v2/users?per_page=2000' => Http::response(['users' => [['id' => 1]], 'total_entries' => 2, 'links' => ['next' => 'https://api.harvestapp.com/v2/users?cursor=next']]),
            'api.harvestapp.com/v2/users?cursor=next' => Http::response(['users' => [['id' => 1]], 'total_entries' => 2, 'links' => ['next' => null]]),
        ]);
        $this->expectExceptionMessage('missing or duplicate source IDs');
        iterator_to_array(app(HarvestClient::class)->pages('users'));
    }

    public function test_client_never_sends_the_token_to_a_pagination_redirect_host(): void
    {
        $this->configure('test');
        Http::fake(['api.harvestapp.com/*' => Http::response(['users' => [['id' => 1]], 'total_entries' => 2, 'links' => ['next' => 'https://example.com/v2/users']])]);
        try {
            iterator_to_array(app(HarvestClient::class)->pages('users'));
            $this->fail('Unsafe pagination must be rejected');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('unsafe pagination', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_upstream_errors_are_not_exposed_to_the_browser(): void
    {
        $this->configure('test');
        Http::fake(['api.harvestapp.com/*' => Http::response('private upstream detail', 401)]);
        $this->expectExceptionMessage('Harvest denied access. Check the server token and account permissions.');
        app(HarvestClient::class)->company();
    }

    public function test_client_completes_cursor_pagination_without_losing_identical_occurrences(): void
    {
        $this->configure('test');
        Http::fake([
            'api.harvestapp.com/v2/users?per_page=2000' => Http::response(['users' => [['id' => 1, 'name' => 'Same name']], 'total_entries' => 2, 'links' => ['next' => 'https://api.harvestapp.com/v2/users?cursor=next']]),
            'api.harvestapp.com/v2/users?cursor=next' => Http::response(['users' => [['id' => 2, 'name' => 'Same name']], 'total_entries' => 2, 'links' => ['next' => null]]),
        ]);
        $pages = iterator_to_array(app(HarvestClient::class)->pages('users'));
        $this->assertSame([1, 2], array_column(array_merge(...array_column($pages, 'users')), 'id'));
        Http::assertSentCount(2);
    }

    public function test_incomplete_collection_cannot_be_presented_as_a_complete_preview(): void
    {
        $this->configure('test');
        Http::fake(['api.harvestapp.com/*' => Http::response(['users' => [['id' => 1]], 'total_entries' => 2, 'links' => ['next' => null]])]);
        $this->expectExceptionMessage('collection count does not match');
        iterator_to_array(app(HarvestClient::class)->pages('users'));
    }

    public function test_revoked_admin_cannot_fetch_data_from_a_previously_queued_job(): void
    {
        $admin = $this->createUserWithRole(Role::Admin);
        $this->configure($admin->organization->id);
        $run = HarvestImportRun::create([
            'organization_id' => $admin->organization->id, 'requested_by' => $admin->user->id,
            'account_id' => '123', 'status' => 'queued', 'disk' => 'local',
        ]);
        $admin->member->update(['role' => Role::Employee->value]);
        try {
            (new PrepareHarvestImport($run->id))->handle(app(HarvestClient::class), app(HarvestPreview::class));
            $this->fail('Revoked access must be rejected');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('access changed', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
