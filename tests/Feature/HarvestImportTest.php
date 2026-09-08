<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Jobs\ProcessHarvestImport;
use App\Models\Client;
use App\Models\ExternalAuthOrganization;
use App\Models\HarvestImportRun;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Service\GoogleAuthenticationService;
use App\Service\Import\Harvest\HarvestClient;
use App\Service\Import\Harvest\HarvestImport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCaseWithDatabase;

class HarvestImportTest extends TestCaseWithDatabase
{
    private object $admin;

    public function test_unique_placeholder_name_is_linked_without_manual_review(): void
    {
        $placeholder = User::factory()->create(['name' => 'New Person', 'is_placeholder' => true]);
        Member::factory()->forOrganization($this->admin->organization)->create(['user_id' => $placeholder->id]);
        $run = $this->snapshot();
        $this->apply($run);
        $this->assertSame($placeholder->id, DB::table('harvest_import_mappings')->where('entity', 'users')->where('source_id', '2')->value('target_id'));
    }

    public function test_duplicate_harvest_names_still_require_placeholder_review(): void
    {
        $placeholder = User::factory()->create(['name' => 'New Person', 'is_placeholder' => true]);
        Member::factory()->forOrganization($this->admin->organization)->create(['user_id' => $placeholder->id]);
        $run = $this->snapshot(withAlias: true);
        app(HarvestImport::class)->prepare($run);
        $this->assertFalse($run->fresh()->summary['can_import']);
        $this->assertContains('users:2', array_column($run->fresh()->summary['plan']['conflicts'], 'key'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockPrivateStorage();
        $this->admin = $this->createUserWithRole(Role::Owner);
        config(['harvest.access_token' => 'test-token', 'harvest.account_id' => '123', 'harvest.organization_id' => $this->admin->organization->id]);
    }

    /** @param array<string, mixed> $timeChanges */
    private function snapshot(array $timeChanges = [], bool $withAlias = false, string $projectName = 'Source Project'): HarvestImportRun
    {
        $run = HarvestImportRun::create(['organization_id' => $this->admin->organization->id, 'requested_by' => $this->admin->user->id,
            'account_id' => '123', 'status' => 'ready', 'disk' => config('filesystems.private')]);
        $rows = array_fill_keys(HarvestClient::COLLECTIONS, []);
        $rows['users'] = [
            ['id' => 1, 'first_name' => 'Owner', 'last_name' => 'Person', 'email' => $this->admin->user->email, 'is_active' => true, 'access_roles' => ['member']],
            ['id' => 2, 'first_name' => 'New', 'last_name' => 'Person', 'email' => 'new@example.com', 'is_active' => false, 'access_roles' => ['member']],
        ];
        $date = '2026-01-01T00:00:00Z';
        $rows['clients'] = [['id' => 10, 'name' => 'Source Client', 'is_active' => true, 'updated_at' => $date]];
        $rows['projects'] = [['id' => 20, 'client' => ['id' => 10], 'name' => $projectName, 'code' => 'P20', 'is_active' => false, 'is_billable' => true,
            'hourly_rate' => 0, 'currency' => 'USD', 'budget_by' => 'project_cost', 'budget' => 5000, 'budget_is_monthly' => false, 'updated_at' => $date]];
        $rows['tasks'] = [['id' => 30, 'name' => 'Source Task', 'is_active' => false]];
        $rows['task_assignments'] = [['id' => 40, 'project' => ['id' => 20], 'task' => ['id' => 30], 'is_active' => true, 'updated_at' => $date]];
        $rows['user_assignments'] = [['id' => 50, 'user' => ['id' => 1, 'name' => 'Owner Person'], 'project' => ['id' => 20, 'name' => 'Source Project'], 'is_active' => true, 'hourly_rate' => 0]];
        $entry = ['id' => 60, 'user' => ['id' => 2, 'name' => 'New Person'], 'project' => ['id' => 20, 'name' => 'Source Project'], 'task' => ['id' => 30],
            'spent_date' => '2026-01-02', 'hours' => 2, 'is_running' => false, 'notes' => '- Work', 'billable' => true, 'billable_rate' => 120.50, 'cost_rate' => 32];
        $rows['time_entries'] = [array_replace($entry, $timeChanges), array_replace($entry, ['id' => 61]), array_replace($entry, ['id' => 62, 'hours' => -1]), array_replace($entry, ['id' => 63, 'hours' => 0])];
        if ($withAlias) {
            $rows['users'][] = array_replace($rows['users'][1], ['id' => 3, 'email' => 'older@example.com']);
            $rows['time_entries'][] = array_replace($entry, ['id' => 64, 'user' => ['id' => 3, 'name' => 'New Person'], 'hours' => 3]);
            $rows['user_assignments'][] = array_replace($rows['user_assignments'][0], ['id' => 51, 'user' => ['id' => 2, 'name' => 'New Person']]);
            $rows['user_assignments'][] = array_replace($rows['user_assignments'][0], ['id' => 52, 'user' => ['id' => 3, 'name' => 'New Person']]);
        }
        $manifest = ['account_id' => '123', 'company' => ['name' => 'Test Company'], 'collections' => []];
        foreach ($rows as $entity => $data) {
            $raw = json_encode([$entity => $data], JSON_THROW_ON_ERROR);
            $path = $run->path($entity.'-1');
            Storage::disk($run->disk)->put($path, $raw);
            $manifest['collections'][$entity] = ['count' => count($data), 'pages' => [['path' => $path, 'sha256' => hash('sha256', $raw)]]];
        }
        Storage::disk($run->disk)->put($run->path('manifest'), json_encode($manifest, JSON_THROW_ON_ERROR));

        return $run;
    }

    private function apply(HarvestImportRun $run): void
    {
        app(HarvestImport::class)->prepare($run);
        $run->refresh();
        $this->assertSame([], $run->summary['plan']['conflicts']);
        $run->update(['status' => 'apply_queued']);
        app(HarvestImport::class)->apply($run);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_renamed_project_requires_review_preserves_name_and_reuses_mapping(): void
    {
        $client = Client::factory()->forOrganization($this->admin->organization)->create(['name' => 'Source Client']);
        $project = Project::factory()->forOrganization($this->admin->organization)->forClient($client)->create(['name' => '[P20] 2026/Q2 Project Source']);
        $run = $this->snapshot();
        app(HarvestImport::class)->prepare($run);
        $conflict = collect($run->fresh()->summary['plan']['conflicts'])->firstWhere('key', 'projects:20');
        $this->assertNotNull($conflict);
        $this->assertSame($project->id, $conflict['candidates'][0]['id']);
        $this->assertNotEmpty($conflict['candidates'][0]['evidence']);
        $run->update(['decisions' => ['projects:20' => $project->id]]);
        $this->apply($run);
        $this->assertSame(1, Project::count());
        $this->assertSame('[P20] 2026/Q2 Project Source', $project->fresh()->name);
        $this->apply($this->snapshot());
        $this->assertSame(1, Project::count());
        $this->assertSame('[P20] 2026/Q2 Project Source', $project->fresh()->name);
    }

    public function test_project_suggestions_exclude_other_clients_and_do_not_resolve_ambiguity(): void
    {
        $client = Client::factory()->forOrganization($this->admin->organization)->create(['name' => 'Source Client']);
        $other = Client::factory()->forOrganization($this->admin->organization)->create(['name' => 'Other Client']);
        $outside = Project::factory()->forOrganization($this->admin->organization)->forClient($other)->create(['name' => '[P20] Other']);
        $one = Project::factory()->forOrganization($this->admin->organization)->forClient($client)->create(['name' => '[P20] One']);
        $two = Project::factory()->forOrganization($this->admin->organization)->forClient($client)->create(['name' => '[P20] Two']);
        $run = $this->snapshot();
        app(HarvestImport::class)->prepare($run);
        $conflict = collect($run->fresh()->summary['plan']['conflicts'])->firstWhere('key', 'projects:20');
        $this->assertNotNull($conflict);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], array_column($conflict['candidates'], 'id'));
        $run->update(['decisions' => ['projects:20' => $outside->id]]);
        app(HarvestImport::class)->prepare($run);
        $this->assertFalse($run->fresh()->summary['can_import']);
    }

    public function test_project_history_suggests_a_renamed_project_without_name_or_code_overlap(): void
    {
        $this->apply($this->snapshot());
        $project = Project::firstOrFail();
        DB::table('projects')->where('id', $project->id)->update(['name' => 'Internal Delivery']);
        DB::table('harvest_import_mappings')->where('entity', 'projects')->delete();
        $run = $this->snapshot();
        app(HarvestImport::class)->prepare($run);
        $conflict = collect($run->fresh()->summary['plan']['conflicts'])->firstWhere('key', 'projects:20');
        $this->assertNotNull($conflict);
        $this->assertSame($project->id, $conflict['candidates'][0]['id']);
        $this->assertStringContainsString('3 matching time-entry occurrences', implode(' ', $conflict['candidates'][0]['evidence']));
        $run->update(['decisions' => ['projects:20' => $project->id]]);
        $this->apply($run);
        $this->assertSame('Internal Delivery', $project->fresh()->name);
        $this->assertSame(4, TimeEntry::count());
    }

    public function test_import_and_repeat_preserve_occurrences_rates_roles_and_status(): void
    {
        $this->apply($this->snapshot());
        $this->assertSame(4, TimeEntry::count());
        $this->assertSame(10800.0, (float) DB::table('time_entries')->selectRaw('sum(extract(epoch from ("end" - start))) as seconds')->value('seconds'));
        $this->assertDatabaseHas('time_entries', ['billable_rate' => 12050, 'cost_rate' => 3200, 'billable_currency' => 'USD']);
        $this->assertDatabaseHas('projects', ['name' => '[P20] Source Project', 'billable_rate' => 0, 'estimated_time' => null, 'is_public' => false]);
        $this->assertNotNull(DB::table('projects')->value('archived_at'));
        $this->assertNotNull(DB::table('tasks')->value('done_at'));
        $this->assertSame('owner', $this->admin->member->fresh()->role);
        $new = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($new->is_placeholder);
        $this->assertNull($new->email_verified_at);
        $this->assertSame(1, DB::table('project_members')->count());
        $ids = TimeEntry::orderBy('id')->pluck('id')->all();
        $again = $this->snapshot();
        $this->apply($again);
        $this->assertSame($ids, TimeEntry::orderBy('id')->pluck('id')->all());
        $this->assertSame(4, $again->fresh()->summary['result']['time_entries']['unchanged']);
    }

    public function test_existing_csv_occurrences_are_adopted_and_formula_escape_is_removed(): void
    {
        $this->apply($this->snapshot());
        DB::table('harvest_import_mappings')->delete();
        DB::table('time_entries')->update(['description' => "'- Work"]);
        $ids = TimeEntry::orderBy('id')->pluck('id')->all();
        $this->apply($this->snapshot());
        $this->assertSame($ids, TimeEntry::orderBy('id')->pluck('id')->all());
        $this->assertSame(['- Work'], TimeEntry::pluck('description')->unique()->values()->all());
    }

    public function test_local_edits_survive_and_both_sides_changed_requires_explicit_resolution(): void
    {
        $this->apply($this->snapshot());
        $id = DB::table('harvest_import_mappings')->where('entity', 'time_entries')->where('source_id', '60')->value('target_id');
        TimeEntry::whereKey($id)->update(['description' => 'Local edit']);
        $this->apply($this->snapshot());
        $this->assertSame('Local edit', TimeEntry::findOrFail($id)->description);
        $changed = $this->snapshot(['notes' => 'Source edit']);
        app(HarvestImport::class)->prepare($changed);
        $this->assertFalse($changed->fresh()->summary['can_import']);
        $this->assertSame('time_entries:60', $changed->fresh()->summary['plan']['conflicts'][0]['key']);
        $changed->update(['decisions' => ['time_entries:60' => 'local']]);
        $this->apply($changed);
        $this->assertSame('Local edit', TimeEntry::findOrFail($id)->description);
    }

    public function test_stale_preview_rolls_back_without_partial_writes(): void
    {
        $run = $this->snapshot();
        app(HarvestImport::class)->prepare($run);
        Client::factory()->create(['organization_id' => $this->admin->organization->id, 'name' => 'Source Client']);
        $run->update(['status' => 'apply_queued']);
        try {
            app(HarvestImport::class)->apply($run);
            $this->fail('A stale plan must not be applied');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('changed after preview', $exception->getMessage());
        }
        $this->assertSame(0, TimeEntry::count());
        $this->assertSame(0, DB::table('harvest_import_mappings')->count());
        $this->assertFalse(User::where('email', 'new@example.com')->exists());
    }

    public function test_imported_placeholder_is_claimed_by_google_without_duplication_on_reimport(): void
    {
        $this->apply($this->snapshot());
        $before = User::where('email', 'new@example.com')->firstOrFail()->id;
        ExternalAuthOrganization::where('provider', 'google')->update(['organization_id' => $this->admin->organization->id]);
        $user = app(GoogleAuthenticationService::class)->authenticate('source-subject', 'new@example.com', 'New Person', null);
        $this->assertSame($before, $user->id);
        $this->assertFalse($user->is_placeholder);
        $this->apply($this->snapshot());
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertSame(4, TimeEntry::where('user_id', $before)->count());
    }

    public function test_account_already_existing_elsewhere_gets_membership_not_a_duplicate_user(): void
    {
        $other = $this->createUserWithRole(Role::Employee);
        $other->user->update(['email' => 'new@example.com']);
        $current = $other->user->fresh()->current_team_id;
        $this->apply($this->snapshot());
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertSame($current, $other->user->fresh()->current_team_id);
        $this->assertDatabaseHas('members', ['user_id' => $other->user->id, 'organization_id' => $this->admin->organization->id, 'role' => 'employee']);
    }

    public function test_confirmation_is_scoped_and_duplicate_confirmation_queues_once(): void
    {
        Queue::fake();
        $run = $this->snapshot();
        app(HarvestImport::class)->prepare($run);
        Passport::actingAs($this->admin->user);
        $url = '/api/v1/organizations/'.$this->admin->organization->id.'/harvest-imports/'.$run->id.'/confirm';
        $body = ['plan_hash' => $run->fresh()->plan_hash, 'acknowledge_limitations' => true];
        $this->postJson($url, array_replace($body, ['plan_hash' => str_repeat('a', 64)]))->assertStatus(409);
        $this->postJson($url, $body)->assertAccepted();
        $this->postJson($url, $body)->assertAccepted();
        Queue::assertPushed(ProcessHarvestImport::class, 1);
        $other = $this->createUserWithRole(Role::Owner);
        Passport::actingAs($other->user);
        $this->postJson($url, $body)->assertForbidden();
    }

    public function test_explicit_person_alias_preserves_both_source_histories_on_reimport(): void
    {
        $run = $this->snapshot(withAlias: true);
        $run->update(['decisions' => ['users:3' => 'alias:2']]);
        $this->apply($run);
        $this->assertSame(2, User::count());
        $this->assertSame(5, TimeEntry::count());
        $this->assertSame(2, DB::table('project_members')->count());
        $this->assertSame(2, DB::table('external_auth_user_mappings')->count());
        $this->apply($this->snapshot(withAlias: true));
        $this->assertSame(2, User::count());
        $this->assertSame(5, TimeEntry::count());
        $this->assertSame(2, DB::table('project_members')->count());
    }

    public function test_changed_source_updates_existing_id_and_keeps_a_zero_rate(): void
    {
        $this->apply($this->snapshot());
        $id = DB::table('harvest_import_mappings')->where('entity', 'time_entries')->where('source_id', '60')->value('target_id');
        $this->apply($this->snapshot(['hours' => 3, 'billable_rate' => 0]));
        $entry = TimeEntry::findOrFail($id);
        $this->assertSame(0, $entry->billable_rate);
        $this->assertSame(10800.0, $entry->start->diffInSeconds($entry->end));
        $this->assertSame(4, TimeEntry::count());
    }

    public function test_wrong_project_adoption_requires_review_and_preserves_the_local_entry_id(): void
    {
        $this->apply($this->snapshot(['notes' => 'Unique note']));
        $id = DB::table('harvest_import_mappings')->where('entity', 'time_entries')->where('source_id', '60')->value('target_id');
        DB::table('harvest_import_mappings')->where('entity', 'time_entries')->where('source_id', '60')->delete();
        $oldId = (string) Str::uuid();
        $wrong = Project::factory()->create(['organization_id' => $this->admin->organization->id]);
        TimeEntry::whereKey($id)->update(['id' => $oldId, 'project_id' => $wrong->id, 'task_id' => null]);
        $run = $this->snapshot(['notes' => 'Unique note']);
        app(HarvestImport::class)->prepare($run);
        $this->assertSame('time_entries:60', $run->fresh()->summary['plan']['conflicts'][0]['key']);
        $run->update(['decisions' => ['time_entries:60' => $oldId]]);
        $this->apply($run);
        $this->assertSame(4, TimeEntry::count());
        $this->assertNotSame($wrong->id, TimeEntry::findOrFail($oldId)->project_id);
    }

    public function test_changed_csv_duration_and_note_require_review_before_adoption(): void
    {
        $this->apply($this->snapshot());
        $id = DB::table('harvest_import_mappings')->where('entity', 'time_entries')->where('source_id', '60')->value('target_id');
        DB::table('harvest_import_mappings')->where('entity', 'time_entries')->where('source_id', '60')->delete();
        $oldId = (string) Str::uuid();
        TimeEntry::whereKey($id)->update(['id' => $oldId]);
        $run = $this->snapshot(['hours' => 3, 'notes' => 'Edited after the CSV export']);
        app(HarvestImport::class)->prepare($run);
        $this->assertFalse($run->fresh()->summary['can_import']);
        $this->assertSame($oldId, $run->fresh()->summary['plan']['conflicts'][0]['candidates'][0]['id']);
        $run->update(['decisions' => ['time_entries:60' => $oldId]]);
        $this->apply($run);
        $this->assertSame(4, TimeEntry::count());
        $this->assertSame(10800.0, TimeEntry::findOrFail($oldId)->start->diffInSeconds(TimeEntry::findOrFail($oldId)->end));
    }

    public function test_older_snapshot_cannot_reverse_a_newer_completed_import(): void
    {
        $older = $this->snapshot();
        $older->update(['created_at' => now()->subDay()]);
        $this->apply($this->snapshot(['hours' => 3]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A newer Harvest snapshot has already been imported');
        app(HarvestImport::class)->prepare($older);
    }

    public function test_project_code_adopts_prefixed_names_and_preserves_manual_edits_on_reimport(): void
    {
        $this->apply($this->snapshot());
        $project = Project::firstOrFail();
        DB::table('harvest_import_mappings')->where('entity', 'projects')->delete();
        $this->apply($this->snapshot(projectName: '[P20] Source Project'));
        $this->assertSame(1, Project::count());
        $this->assertSame('[P20] Source Project', $project->fresh()->name);
        $project->update(['name' => '[P20] September invoice label']);
        $this->apply($this->snapshot(projectName: '[P20] Source Project'));
        $this->assertSame('[P20] September invoice label', $project->fresh()->name);
        $this->assertSame(4, TimeEntry::count());
    }

    public function test_database_failure_rolls_back_every_business_write_and_mapping(): void
    {
        $run = $this->snapshot();
        app(HarvestImport::class)->prepare($run);
        $run->update(['status' => 'apply_queued']);
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT reject_import_task CHECK (name <> 'Source Task')");
        try {
            app(HarvestImport::class)->apply($run);
            $this->fail('The database constraint should reject this import');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('harvest_import_mappings')->count());
            $this->assertSame(0, TimeEntry::count());
            $this->assertSame(0, Client::count());
            $this->assertFalse(User::where('email', 'new@example.com')->exists());
        }
    }
}
