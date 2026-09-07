<?php

declare(strict_types=1);

namespace App\Service\Import\Harvest;

use App\Models\HarvestImportRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HarvestPreview
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function build(HarvestImportRun $run, array $manifest): array
    {
        $read = fn (string $entity): \Generator => app(HarvestSnapshot::class)->rows($run, $entity, $manifest);
        $members = DB::table('members')->join('users', 'users.id', '=', 'members.user_id')
            ->where('members.organization_id', $run->organization_id)
            ->get(['users.id', 'users.name', 'users.email', 'users.is_placeholder', 'members.role']);
        $authMappings = DB::table('external_auth_user_mappings')
            ->where('organization_id', $run->organization_id)->where('provider', 'google')->get()->keyBy('email');
        $people = [];
        foreach ($read('users') as $user) {
            $email = Str::lower(trim($user['email'] ?? ''));
            $mapping = $authMappings->get($email);
            $matches = $members->filter(fn ($member): bool => $mapping !== null
                ? $member->id === $mapping->user_id
                : Str::lower($member->email) === $email);
            $name = trim($user['first_name'].' '.$user['last_name']);
            $suggestions = $members->filter(fn ($member): bool => $member->is_placeholder
                && Str::lower(Str::squish(Str::ascii($member->name))) === Str::lower(Str::squish(Str::ascii($name))));
            // A name is only a suggestion, never authority to claim an account.
            $people[] = [
                'source_id' => $user['id'], 'name' => $name, 'email' => $email,
                'active' => $user['is_active'],
                'match' => $email === '' ? 'conflict' : ($matches->count() === 1 ? 'linked' : ($matches->isNotEmpty() || $suggestions->isNotEmpty() ? 'review' : 'new')),
                'candidates' => ($matches->isNotEmpty() ? $matches : $suggestions)->map(fn ($member): array => [
                    'id' => $member->id, 'name' => $member->name, 'email' => $member->email, 'role' => $member->role,
                ])->values()->all(),
            ];
        }
        $currencies = [];
        $projects = [];
        foreach ($read('projects') as $project) {
            $projects[$project['id']] = $project;
        }
        $zero = 0;
        $negative = 0;
        $running = 0;
        $orphaned = 0;
        $userIds = array_fill_keys(array_column($people, 'source_id'), true);
        $seconds = 0;
        foreach ($read('time_entries') as $entry) {
            $duration = (int) round($entry['hours'] * 3600);
            $seconds += $duration;
            $zero += (int) ($duration === 0);
            $negative += (int) ($duration < 0);
            $running += (int) $entry['is_running'];
            $project = $projects[$entry['project']['id']] ?? null;
            $orphaned += (int) ($project === null || ! isset($userIds[$entry['user']['id']]));
            $currency = $project['currency'] ?? 'UNKNOWN';
            $currencies[$currency] ??= ['entries' => 0, 'seconds' => 0];
            $currencies[$currency]['entries']++;
            $currencies[$currency]['seconds'] += $duration;
        }
        ksort($currencies);

        return [
            'company' => $manifest['company']['name'],
            'counts' => array_map(fn (array $collection): int => $collection['count'], $manifest['collections']),
            'people' => $people,
            'projects_active' => count(array_filter($projects, fn (array $project): bool => $project['is_active'])),
            'projects_archived' => count(array_filter($projects, fn (array $project): bool => ! $project['is_active'])),
            'time' => ['seconds' => $seconds, 'zero' => $zero, 'negative' => $negative, 'running' => $running, 'missing_relations' => $orphaned, 'currencies' => $currencies],
            'existing_time_entries' => DB::table('time_entries')->where('organization_id', $run->organization_id)->count(),
            'can_import' => false,
            'warnings' => [
                'Build an import plan to review matches and field changes before confirming any writes.',
                'Expense records, project metadata and approval state are retained in the snapshot, not imported into unsupported fields.',
                'Source data is fetched across multiple requests, not an atomic Harvest export.',
            ],
        ];
    }
}
