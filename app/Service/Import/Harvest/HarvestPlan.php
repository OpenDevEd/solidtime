<?php

declare(strict_types=1);

namespace App\Service\Import\Harvest;

use App\Models\HarvestImportRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Builds a deterministic, read-only plan. Only the executor writes business records. */
class HarvestPlan
{
    public const array TABLES = ['users', 'members', 'external_auth_user_mappings', 'clients', 'projects', 'tasks', 'project_members', 'time_entries'];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $local = [];

    /** @var array<string, array<string, mixed>> */
    private array $maps = [];

    /** @var array<string, string> */
    private array $used = [];

    /** @var list<array<string, mixed>> */
    private array $operations = [];

    /** @var list<array<string, mixed>> */
    private array $conflicts = [];

    /** @var array<string, string> */
    private array $decisions = [];

    /** @var array<string, array<string, int>> */
    private array $projectHistory = [];

    private HarvestImportRun $run;

    /**
     * @param  array<string, string>  $decisions
     * @return array<string, mixed>
     */
    public function build(HarvestImportRun $run, array $decisions = [], bool $lock = false): array
    {
        if (HarvestImportRun::where('organization_id', $run->organization_id)->where('account_id', $run->account_id)
            ->where('status', 'completed')->where('created_at', '>', $run->created_at)->exists()) {
            throw new RuntimeException('A newer Harvest snapshot has already been imported. Fetch a fresh snapshot instead of applying older source values.');
        }
        $this->run = $run;
        $this->decisions = $decisions;
        $this->operations = $this->conflicts = $this->used = $this->maps = $this->local = [];
        $this->projectHistory = [];
        $snapshot = app(HarvestSnapshot::class);
        $manifest = $snapshot->manifest($run);
        $read = fn (string $entity): array => iterator_to_array($snapshot->rows($run, $entity, $manifest), false);
        $sourceUsers = $read('users');
        $sourceNameCounts = array_count_values(array_map(fn (array $row): string => $this->normalName($row['first_name'].' '.$row['last_name']), $sourceUsers));
        $emails = array_map(fn (array $row): string => strtolower(trim($row['email'])), $sourceUsers);
        foreach (self::TABLES as $table) {
            $query = DB::table($table);
            if ($table === 'users') {
                $query->whereIn('id', DB::table('members')->select('user_id')->where('organization_id', $run->organization_id))
                    ->orWhereIn(DB::raw('lower(email)'), $emails);
            } elseif ($table === 'project_members') {
                $query->whereIn('project_id', DB::table('projects')->select('id')->where('organization_id', $run->organization_id));
            } else {
                $query->where('organization_id', $run->organization_id);
            }
            if ($lock) {
                $query->lockForUpdate();
            }
            $this->local[$table] = $query->orderBy('id')->get()->mapWithKeys(fn ($row): array => [$row->id => (array) $row])->all();
        }
        foreach (DB::table('harvest_import_mappings')->where('organization_id', $run->organization_id)->where('account_id', $run->account_id)->get() as $mapping) {
            $map = (array) $mapping;
            $map['source_values'] = json_decode($map['source_values'], true, flags: JSON_THROW_ON_ERROR);
            $this->maps[$map['entity'].':'.$map['source_id']] = $map;
            $this->used[$map['entity'].':'.$map['target_id']] = $map['entity'].':'.$map['source_id'];
        }

        $org = ['organization_id' => $run->organization_id];
        $users = $members = $clients = $projects = $tasks = [];
        $sourceUserIndex = array_column($sourceUsers, null, 'id');
        $aliases = [];
        foreach ($sourceUsers as $row) {
            $decision = $decisions['users:'.$row['id']] ?? '';
            $alias = str_starts_with($decision, 'alias:') ? substr($decision, 6) : ($this->maps['users:'.$row['id']]['source_values']['_alias_of'] ?? null);
            if ($alias !== null) {
                $other = $sourceUserIndex[$alias] ?? null;
                if ($other === null || (string) $row['id'] === (string) $alias
                    || $this->normalName($row['first_name'].' '.$row['last_name']) !== $this->normalName($other['first_name'].' '.$other['last_name'])) {
                    throw new RuntimeException('A person alias must reference another Harvest account with the same reviewed name.');
                }
                $aliases[$row['id']] = (string) $alias;
            }
        }
        usort($sourceUsers, fn (array $a, array $b): int => isset($aliases[$a['id']]) <=> isset($aliases[$b['id']]));
        foreach ($sourceUsers as $row) {
            $id = (string) $row['id'];
            $name = trim($row['first_name'].' '.$row['last_name']);
            $email = strtolower(trim($row['email']));
            if (isset($aliases[$id])) {
                $canonical = $aliases[$id];
                if (! isset($users[$canonical], $members[$canonical])) {
                    throw new RuntimeException('Person aliases cannot form chains or cycles.');
                }
                $users[$id] = $users[$canonical];
                $members[$id] = $members[$canonical];
                foreach (['users' => $users[$id], 'members' => $members[$id]] as $entity => $target) {
                    $this->operations[] = ['entity' => $entity, 'source_id' => $id, 'target_id' => $target, 'action' => 'alias', 'label' => $name.' <'.$email.'>',
                        'before' => [], 'after' => [], 'source_values' => ['_alias_of' => $canonical], 'changes' => []];
                }

                continue;
            }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Harvest user '.$id.' has no valid email.');
            }
            $auth = array_values(array_filter($this->local['external_auth_user_mappings'], fn (array $m): bool => $m['provider'] === 'google' && $m['email'] === $email));
            $exact = $this->find('users', fn (array $u): bool => strtolower($u['email']) === $email);
            if (count($auth) === 1) {
                $exact = [$auth[0]['user_id']];
            }
            $suggestions = $this->find('users', fn (array $u): bool => $u['is_placeholder'] && $this->normalName($u['name']) === $this->normalName($name)
                && count($this->find('members', fn (array $m): bool => $m['user_id'] === $u['id'])) > 0);
            $needsNameReview = $exact === [] && $suggestions !== []
                && (count($suggestions) !== 1 || $sourceNameCounts[$this->normalName($name)] !== 1);
            $target = $this->target('users', $id, $exact ?: $suggestions, $name.' <'.$email.'> ['.$id.']', $needsNameReview);
            foreach ($this->conflicts as &$conflict) {
                if ($conflict['key'] !== 'users:'.$id) {
                    continue;
                }
                foreach ($sourceUserIndex as $other) {
                    if ($other['id'] !== $row['id'] && $this->normalName($other['first_name'].' '.$other['last_name']) === $this->normalName($name)) {
                        $conflict['choices'][] = 'alias:'.$other['id'];
                        $conflict['message'] .= ' Other Harvest identity: '.$other['id'].' ('.$other['email'].').';
                    }
                }
            }
            unset($conflict);
            $existing = $this->local['users'][$target] ?? null;
            if ($existing !== null && ! $existing['is_placeholder']) {
                $attrs = [];
            } else {
                $attrs = ['name' => $name, 'email' => $email];
            }
            if ($existing !== null && $existing['email'] !== $email && $exact !== [] && ! $existing['is_placeholder']) {
                $this->conflict('users:'.$id, $name, 'The mapped account email differs from Harvest. Resolve the identity before importing.', []);
            }
            $this->operation('users', $id, $target, $attrs, $name, ['is_placeholder' => true, 'timezone' => 'UTC', 'week_start' => 'monday', 'password' => null, 'email_verified_at' => null]);
            $users[$id] = $target;
            $role = count(array_intersect($row['access_roles'] ?? [], ['administrator', 'manager', 'admin'])) > 0 ? 'admin' : 'employee';
            $memberCandidates = $this->find('members', fn (array $m): bool => $m['user_id'] === $target);
            $memberId = $this->target('members', $id, $memberCandidates, $name);
            $existingMember = $this->local['members'][$memberId] ?? null;
            $memberRole = $existingMember['role'] ?? (($existing['is_placeholder'] ?? true) ? 'placeholder' : $role);
            $this->operation('members', $id, $memberId, $org + ['user_id' => $target, 'role' => $memberRole], $name);
            $members[$id] = $memberId;
            $authId = $this->target('external_auth_user_mappings', $id, array_column($auth, 'id'), $name);
            $otherAuth = DB::table('external_auth_user_mappings')->where('provider', 'google')->where(function ($q) use ($email, $target): void {
                $q->where('email', $email)->orWhere('user_id', $target);
            })->where('organization_id', '!=', $run->organization_id)->exists();
            if ($otherAuth) {
                $this->conflict('external_auth_user_mappings:'.$id, $name, 'A Google mapping already belongs to another organization.', []);
            }
            $authRole = $auth[0]['role'] ?? ($memberRole === 'owner' ? 'owner' : $role);
            $this->operation('external_auth_user_mappings', $id, $authId, $org + ['provider' => 'google', 'email' => $email, 'user_id' => $target, 'role' => $authRole], $name);
        }
        foreach ($read('clients') as $row) {
            $id = (string) $row['id'];
            $target = $this->target('clients', $id, $this->find('clients', fn (array $c): bool => $c['name'] === $row['name']), $row['name']);
            $this->operation('clients', $id, $target, $org + ['name' => $row['name'], 'archived_at' => $this->archived($row)], $row['name']);
            $clients[$id] = $target;
        }
        $sourceProjects = [];
        $projectRows = $read('projects');
        $sourceTasks = array_column($read('tasks'), null, 'id');
        // Histories are evidence for a human-reviewed match, never identity authority.
        if (array_filter($projectRows, fn (array $p): bool => ! isset($this->maps['projects:'.$p['id']])) !== []) {
            $localHistory = [];
            foreach ($this->local['time_entries'] as $entry) {
                if (! $entry['is_imported'] || $entry['end'] === null || $entry['start'] === $entry['end']) {
                    continue;
                }
                $key = $this->timeKey($entry, true, true).':'.$this->normalName($this->local['tasks'][$entry['task_id']]['name'] ?? '');
                $localHistory[$key][$entry['project_id']] = ($localHistory[$key][$entry['project_id']] ?? 0) + 1;
            }
            $sourceHistory = [];
            foreach ($snapshot->rows($run, 'time_entries', $manifest) as $entry) {
                if ($entry['is_running'] || ! $entry['hours'] || ! isset($members[$entry['user']['id']])) {
                    continue;
                }
                $start = Carbon::parse($entry['spent_date'], 'UTC')->startOfDay();
                $key = $this->timeKey(['member_id' => $members[$entry['user']['id']], 'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $start->copy()->addSeconds((int) round($entry['hours'] * 3600))->format('Y-m-d H:i:s'), 'description' => $entry['notes'] ?? ''], true)
                    .':'.$this->normalName($sourceTasks[$entry['task']['id']]['name'] ?? '');
                $sourceHistory[$entry['project']['id']][$key] = ($sourceHistory[$entry['project']['id']][$key] ?? 0) + 1;
            }
            foreach ($sourceHistory as $projectId => $history) {
                foreach ($history as $key => $count) {
                    foreach ($localHistory[$key] ?? [] as $localId => $localCount) {
                        $this->projectHistory[$projectId][$localId] = ($this->projectHistory[$projectId][$localId] ?? 0) + min($count, $localCount);
                    }
                }
            }
            unset($sourceHistory, $localHistory);
        }
        $projectNameCounts = array_count_values(array_map(fn (array $p): string => $p['client']['id'].':'.Str::squish($p['name']), $projectRows));
        foreach ($projectRows as $row) {
            $id = (string) $row['id'];
            $clientId = $clients[$row['client']['id']] ?? throw new RuntimeException('Project references a missing client.');
            $code = trim($row['code'] ?? '');
            $displayName = trim($row['name']);
            if ($code !== '') {
                // Harvest displays its separate code before the name. Avoid adding
                // it twice when the source name already includes the same code.
                if (! str_starts_with($displayName, '['.$code.']')) {
                    $displayName = preg_replace('/\s*\['.preg_quote($code, '/').'\]$/u', '', $displayName) ?? $displayName;
                    $displayName = '['.$code.'] '.$displayName;
                }
            } elseif ($projectNameCounts[$row['client']['id'].':'.Str::squish($row['name'])] > 1) {
                $displayName = '[Harvest '.$id.'] '.$displayName;
            }
            $names = [$row['name'], $row['name'].' ['.$code.']', $displayName];
            $candidates = $this->find('projects', fn (array $p): bool => $p['client_id'] === $clientId && in_array($p['name'], $names, true));
            $review = false;
            $evidence = [];
            if ($candidates === []) {
                $normalNames = array_map(fn (string $name): string => Str::squish($name), $names);
                $candidates = $this->find('projects', fn (array $p): bool => $p['client_id'] === $clientId && in_array(Str::squish($p['name']), $normalNames, true));
                $review = $candidates !== [];
            }
            if ($candidates === [] && ! isset($this->maps['projects:'.$id])) {
                $evidence = $this->projectSuggestions($id, $clientId, $row['name'], $code);
                $candidates = array_keys($evidence);
                $review = $candidates !== [];
            }
            $target = $this->target('projects', $id, $candidates, $displayName, $review);
            foreach ($this->conflicts as &$conflict) {
                if ($conflict['key'] === 'projects:'.$id) {
                    $conflict['message'] .= ' Reviewed matches keep the existing Solidtime project name.';
                    foreach ($conflict['candidates'] as &$candidate) {
                        $candidate['evidence'] = $evidence[$candidate['id']] ?? ['Same client', 'Name matches after whitespace normalization'];
                    }
                    unset($candidate);
                }
            }
            unset($conflict);
            $attrs = $org + ['client_id' => $clientId, 'name' => $displayName, 'is_billable' => $row['is_billable'], 'billable_rate' => $this->cents($row['hourly_rate']),
                'archived_at' => $this->archived($row), 'estimated_time' => $row['budget_by'] === 'project' && ! $row['budget_is_monthly'] && $row['budget'] !== null ? (int) round($row['budget'] * 3600) : null];
            $this->operation('projects', $id, $target, $attrs, $row['name'], ['is_public' => false, 'color' => '#42a5f5']);
            if ($review && ! isset($this->maps['projects:'.$id]) && isset($this->local['projects'][$target])) {
                // Keep the source name as the baseline, but preserve the reviewed local label.
                $op = array_pop($this->operations);
                $op['after']['name'] = $this->local['projects'][$target]['name'];
                unset($op['changes']['name']);
                $op['action'] = $op['changes'] === [] ? 'link' : 'update';
                $this->operations[] = $op;
            }
            $projects[$id] = $target;
            $sourceProjects[$id] = $row;
        }
        foreach ($read('task_assignments') as $row) {
            $id = (string) $row['id'];
            $projectId = $projects[$row['project']['id']] ?? throw new RuntimeException('Task references a missing project.');
            $task = $sourceTasks[$row['task']['id']] ?? throw new RuntimeException('Task assignment references a missing task.');
            $target = $this->target('tasks', $id, $this->find('tasks', fn (array $t): bool => $t['project_id'] === $projectId && $t['name'] === $task['name']), $task['name']);
            $this->operation('tasks', $id, $target, $org + ['project_id' => $projectId, 'name' => $task['name'], 'done_at' => $row['is_active'] && $task['is_active'] ? null : $this->sourceDate($row['updated_at'])], $task['name']);
            $tasks[$row['project']['id'].':'.$row['task']['id']] = $target;
        }
        $sourceAssignments = $read('user_assignments');
        usort($sourceAssignments, fn (array $a, array $b): int => isset($aliases[$a['user']['id']]) <=> isset($aliases[$b['user']['id']]));
        $assignedMembers = [];
        foreach ($sourceAssignments as $row) {
            // Archived source assignments do not grant fresh project access.
            if (! $row['is_active']) {
                continue;
            }
            $id = (string) $row['id'];
            $memberId = $members[$row['user']['id']] ?? throw new RuntimeException('Assignment references a missing person.');
            $projectId = $projects[$row['project']['id']] ?? throw new RuntimeException('Assignment references a missing project.');
            $pair = $projectId.':'.$memberId;
            if (isset($assignedMembers[$pair])) {
                $this->operations[] = ['entity' => 'project_members', 'source_id' => $id, 'target_id' => $assignedMembers[$pair], 'action' => 'alias',
                    'label' => $row['user']['name'].' / '.$row['project']['name'], 'before' => [], 'after' => [], 'source_values' => [], 'changes' => []];

                continue;
            }
            $target = $this->target('project_members', $id, $this->find('project_members', fn (array $m): bool => $m['project_id'] === $projectId && $m['member_id'] === $memberId), $row['user']['name'].' / '.$row['project']['name']);
            $assignedMembers[$pair] = $target;
            $this->operation('project_members', $id, $target, ['project_id' => $projectId, 'member_id' => $memberId, 'user_id' => $users[$row['user']['id']], 'billable_rate' => $this->cents($row['hourly_rate'])], $row['user']['name'].' / '.$row['project']['name']);
        }

        // Index adoption candidates once, then consume one local row per source occurrence.
        $timeIndex = [];
        $coarseIndex = [];
        $dayIndex = [];
        foreach ($this->local['time_entries'] as $local) {
            if ($local['is_imported'] && ! isset($this->used['time_entries:'.$local['id']])) {
                $timeIndex[$this->timeKey($local, legacyCsv: true)][] = $local['id'];
                $coarseIndex[$this->timeKey($local, true, true)][] = $local['id'];
                $dayIndex[$local['member_id'].':'.substr($local['start'], 0, 10)][] = $local['id'];
            }
        }
        $skippedRunning = 0;
        foreach ($snapshot->rows($run, 'time_entries', $manifest) as $row) {
            if ($row['is_running']) {
                $skippedRunning++;

                continue;
            }
            $id = (string) $row['id'];
            $project = $sourceProjects[$row['project']['id']] ?? throw new RuntimeException('Time entry references a missing project.');
            $taskId = $tasks[$row['project']['id'].':'.$row['task']['id']] ?? null;
            if ($taskId === null) {
                throw new RuntimeException('Time entry references a task without a project assignment.');
            }
            $start = Carbon::createFromFormat('!Y-m-d', $row['spent_date'], 'UTC');
            if ($start === null || $start->format('Y-m-d') !== $row['spent_date']) {
                throw new RuntimeException('Invalid Harvest time entry date.');
            }
            $duration = (int) round($row['hours'] * 3600);
            $attrs = $org + ['user_id' => $users[$row['user']['id']], 'member_id' => $members[$row['user']['id']], 'project_id' => $projects[$row['project']['id']],
                'client_id' => $clients[$project['client']['id']], 'task_id' => $taskId, 'description' => $row['notes'] ?? '',
                'start' => $start->format('Y-m-d H:i:s'), 'end' => $start->copy()->addSeconds($duration)->format('Y-m-d H:i:s'),
                'billable' => $row['billable'], 'billable_rate' => $this->cents($row['billable_rate']), 'cost_rate' => $this->cents($row['cost_rate']),
                'billable_currency' => $project['currency'], 'is_imported' => true];
            if (mb_strlen($attrs['description']) > 5000) {
                throw new RuntimeException('Harvest time entry '.$id.' exceeds the note length limit.');
            }
            $key = $this->timeKey($attrs);
            $candidates = $timeIndex[$key] ?? [];
            $candidates = array_values(array_filter($candidates, fn (string $candidate): bool => ! isset($this->used['time_entries:'.$candidate])));
            // Multiple byte-equivalent imported occurrences are interchangeable, not duplicates to discard.
            if (count($candidates) > 1) {
                $financials = array_unique(array_map(fn (string $candidate): string => json_encode(array_intersect_key($this->local['time_entries'][$candidate], array_flip(['billable', 'billable_rate', 'cost_rate', 'billable_currency'])), JSON_THROW_ON_ERROR), $candidates));
                if (count($financials) === 1) {
                    $candidates = [$candidates[0]];
                }
            }
            $target = $this->target('time_entries', $id, $candidates, $row['spent_date'].' / '.$row['user']['name'].' / '.$row['project']['name']);
            $this->operation('time_entries', $id, $target, $attrs, $row['spent_date'].' / '.$row['user']['name'], ['tags' => '[]', 'type' => 'work']);
        }
        // Only consider project/task corrections after every exact source occurrence
        // has had its chance to claim a local entry. These matches require review.
        foreach (array_keys($this->operations) as $index) {
            $op = $this->operations[$index];
            if ($op['entity'] !== 'time_entries' || $op['action'] !== 'create') {
                continue;
            }
            $candidates = array_values(array_filter($coarseIndex[$this->timeKey($op['after'], true)] ?? [], fn (string $id): bool => ! isset($this->used['time_entries:'.$id])));
            if ($candidates === []) {
                // An old CSV has no stable entry IDs. Changed hours or notes cannot
                // be matched automatically; offer remaining same-person/day rows.
                $candidates = array_values(array_filter($dayIndex[$op['after']['member_id'].':'.substr($op['after']['start'], 0, 10)] ?? [], fn (string $id): bool => ! isset($this->used['time_entries:'.$id])));
            }
            $candidates = array_values(array_filter($candidates, fn (string $id): bool => ! in_array($id, array_diff_key($decisions, ['time_entries:'.$op['source_id'] => true]), true)));
            if ($candidates === []) {
                continue;
            }
            $target = $this->target('time_entries', $op['source_id'], $candidates, $op['label'].' / '.$op['source_id'].' / '.$op['after']['description'].' / ends '.$op['after']['end'], true);
            if (isset($this->local['time_entries'][$target])) {
                $attrs = $op['after'];
                unset($attrs['tags'], $attrs['type']);
                $this->operation('time_entries', $op['source_id'], $target, $attrs, $op['label']);
                $this->operations[$index] = array_pop($this->operations);
            }
        }
        $counts = [];
        foreach ($this->operations as $op) {
            $counts[$op['entity']][$op['action']] = ($counts[$op['entity']][$op['action']] ?? 0) + 1;
        }
        $unmapped = count(array_filter($this->local['time_entries'], fn (array $t): bool => $t['is_imported'] && ! isset($this->used['time_entries:'.$t['id']])));
        if ($unmapped > 0 && ($decisions['unmatched_existing'] ?? null) !== 'keep') {
            $this->conflict('unmatched_existing', 'Existing imported time entries', $unmapped.' imported local entries have no source match. Keep them explicitly before proceeding; they will not be deleted.', [], ['keep']);
        }
        $plan = ['operations' => $this->operations, 'conflicts' => $this->conflicts, 'counts' => $counts, 'unmatched_existing' => $unmapped, 'skipped_running' => $skippedRunning];
        $plan['hash'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    /** @return list<string> */
    private function find(string $table, callable $predicate): array
    {
        return array_keys(array_filter($this->local[$table], $predicate));
    }

    /** @param list<string> $candidates */
    private function target(string $entity, string $sourceId, array $candidates, string $label, bool $review = false): string
    {
        $key = $entity.':'.$sourceId;
        $mapping = $this->maps[$key] ?? null;
        $decision = $this->decisions[$key] ?? null;
        if ($mapping !== null) {
            if (! isset($this->local[$entity][$mapping['target_id']])) {
                $this->conflict($key, $label, 'Previously imported record was deleted. Restore it before reimporting.', []);
            }

            return $mapping['target_id'];
        }
        $available = array_values(array_filter($candidates, fn (string $id): bool => ! isset($this->used[$entity.':'.$id]) || $this->used[$entity.':'.$id] === $key));
        $chosen = null;
        if ($decision !== null && in_array($decision, $available, true)) {
            $chosen = $decision;
        } elseif ($decision === 'create') {
            $chosen = $this->id($key);
        } elseif (count($available) === 1 && ! $review) {
            $chosen = $available[0];
        } elseif ($candidates !== []) {
            $details = array_map(function (string $id) use ($entity): array {
                $row = $this->local[$entity][$id];
                $name = $row['name'] ?? $row['description'] ?? $id;
                if ($entity === 'time_entries') {
                    $name = ($this->local['projects'][$row['project_id']]['name'] ?? 'No project').' / '.$row['start'].' to '.$row['end'].' / '.($row['description'] ?: 'No note');
                }

                return ['id' => $id, 'name' => $name, 'email' => $row['email'] ?? null];
            }, $available);
            $this->conflict($key, $label, $review ? 'Confirm this suggested match; it is not an exact source-ID match.' : 'Matching is ambiguous or the candidate is already assigned to another source record.', $details);
        }
        $chosen ??= $this->id($key);
        $this->used[$entity.':'.$chosen] = $key;

        return $chosen;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $defaults
     */
    private function operation(string $entity, string $sourceId, string $target, array $attrs, string $label, array $defaults = []): void
    {
        $key = $entity.':'.$sourceId;
        $local = $this->local[$entity][$target] ?? null;
        $mapping = $this->maps[$key] ?? null;
        $before = [];
        $after = $attrs;
        $sourceValues = $attrs;
        $changed = [];
        if ($local !== null) {
            foreach ($attrs as $field => $value) {
                $current = $local[$field] ?? null;
                if (in_array($field, ['archived_at', 'done_at'], true) && $value !== null && $current !== null) {
                    $value = $current;
                    $sourceValues[$field] = $value;
                }
                $before[$field] = $current;
                if ($mapping !== null) {
                    $previous = $mapping['source_values'][$field] ?? null;
                    if ($value === $previous) {
                        $after[$field] = $current;

                        continue;
                    }
                    if ($current !== $previous && $current !== $value) {
                        $decision = $this->decisions[$key] ?? null;
                        if ($decision === 'local') {
                            $after[$field] = $current;

                            continue;
                        }
                        if ($decision !== 'source') {
                            $this->conflict($key, $label, 'Both Harvest and Solidtime changed '.$field.'.', [], ['local', 'source']);
                        }
                    }
                }
                $after[$field] = $value;
                if ($current !== $value) {
                    $changed[$field] = ['before' => $current, 'after' => $value];
                }
            }
        }
        $action = $local === null ? 'create' : ($changed !== [] ? 'update' : ($mapping === null ? 'link' : 'unchanged'));
        $this->operations[] = ['entity' => $entity, 'source_id' => $sourceId, 'target_id' => $target, 'action' => $action, 'label' => $label,
            'before' => $before, 'after' => $local === null ? $after + $defaults : $after, 'source_values' => $sourceValues, 'changes' => $changed];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $choices
     */
    private function conflict(string $key, string $label, string $message, array $candidates, array $choices = ['create']): void
    {
        $this->conflicts[] = ['key' => $key, 'label' => $label, 'message' => $message, 'candidates' => $candidates, 'choices' => $choices];
    }

    private function id(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'harvest:'.$this->run->organization_id.':'.$this->run->account_id.':'.$key)->toString();
    }

    private function normalName(string $name): string
    {
        return Str::lower(Str::squish(Str::ascii($name)));
    }

    /** @return array<string, list<string>> */
    private function projectSuggestions(string $sourceId, string $clientId, string $name, string $code): array
    {
        $tokens = function (string $value): array {
            $value = preg_replace('/\[[^\]]*\]|\b\d{4}\s*\/\s*Q[1-4]\b/ui', ' ', $value) ?? $value;
            $words = preg_split('/[^a-z0-9]+/', $this->normalName($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return array_values(array_unique($words));
        };
        $sourceWords = $tokens($name);
        $matches = [];
        foreach ($this->local['projects'] as $project) {
            if ($project['client_id'] !== $clientId || isset($this->used['projects:'.$project['id']])) {
                continue;
            }
            $words = $tokens($project['name']);
            $union = array_unique(array_merge($sourceWords, $words));
            $similarity = $union === [] ? 0 : count(array_intersect($sourceWords, $words)) / count($union);
            preg_match_all('/\[([^\]]+)\]/u', $project['name'], $codes);
            $sameCode = $code !== '' && in_array($this->normalName($code), array_map($this->normalName(...), $codes[1]), true);
            $overlap = $this->projectHistory[$sourceId][$project['id']] ?? 0;
            if (! $sameCode && $similarity < 0.65 && $overlap < 2) {
                continue;
            }
            $reasons = ['Same client'];
            if ($sameCode) {
                $reasons[] = 'Same project code (not necessarily unique)';
            }
            if ($similarity >= 0.65) {
                $reasons[] = 'Normalized name overlap: '.(int) round($similarity * 100).'%';
            }
            if ($overlap > 0) {
                $reasons[] = $overlap.' matching time-entry occurrences (person, date, duration, task and notes)';
            }
            $matches[$project['id']] = $reasons;
        }
        ksort($matches);

        return $matches;
    }

    /** @param array<string, mixed> $row */
    private function timeKey(array $row, bool $ignoreProject = false, bool $legacyCsv = false): string
    {
        $note = $row['description'] ?? '';
        // Harvest CSV's spreadsheet-formula escape is not part of the source note.
        if ($legacyCsv) {
            $note = preg_replace("/^'(?=[=+@-])/u", '', $note) ?? $note;
        }
        $note = Str::squish($note);

        return hash('sha256', json_encode([$row['member_id'], $ignoreProject ? null : $row['project_id'], $ignoreProject ? null : $row['task_id'], substr($row['start'], 0, 10),
            Carbon::parse($row['start'], 'UTC')->diffInSeconds(Carbon::parse($row['end'], 'UTC'), false), $note], JSON_THROW_ON_ERROR));
    }

    private function cents(int|float|null $value): ?int
    {
        return $value === null ? null : (int) round($value * 100);
    }

    /** @param array<string, mixed> $row */
    private function archived(array $row): ?string
    {
        return $row['is_active'] ? null : $this->sourceDate($row['updated_at']);
    }

    private function sourceDate(string $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
