<?php

declare(strict_types=1);

namespace App\Service\Import\Harvest;

use App\Models\HarvestImportRun;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HarvestImport
{
    public function prepare(HarvestImportRun $run): void
    {
        $this->authorizeRun($run);
        $plan = app(HarvestPlan::class)->build($run, $run->decisions ?? []);
        Storage::disk($run->disk)->put($run->path('plan-'.$plan['hash']), json_encode($plan, JSON_THROW_ON_ERROR));
        $summary = $run->summary ?? [];
        $summary['can_import'] = $plan['conflicts'] === [];
        $summary['plan'] = [
            'counts' => $plan['counts'], 'conflicts' => $plan['conflicts'], 'hash' => $plan['hash'],
            'unmatched_existing' => $plan['unmatched_existing'], 'skipped_running' => $plan['skipped_running'],
        ];
        $summary['warnings'] = [
            'Source data is fetched across multiple requests, not an atomic Harvest export.',
            'Review field changes before confirming. Local edits are preserved unless explicitly resolved in favor of Harvest.',
            'Project codes are included in project names. Expenses, project notes/dates, monetary budgets and approval/invoice state remain in the retained source snapshot; Solidtime has no equivalent fields for them.',
            'Running timers and inactive project-user assignments are skipped. Nothing is deleted when absent from the snapshot.',
            'Date-only time entries use midnight UTC; original API values remain in the snapshot. Currencies are not converted.',
        ];
        $run->update(['status' => 'planned', 'plan_hash' => $plan['hash'], 'summary' => $summary, 'error' => null]);
    }

    public function apply(HarvestImportRun $run): void
    {
        DB::transaction(function () use ($run): void {
            Organization::whereKey($run->organization_id)->lockForUpdate()->firstOrFail();
            $run = HarvestImportRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status === 'completed') {
                return;
            }
            if (! in_array($run->status, ['apply_queued', 'applying'], true)) {
                throw new RuntimeException('This import has not been confirmed.');
            }
            $this->authorizeRun($run);
            $plan = app(HarvestPlan::class)->build($run, $run->decisions ?? [], true);
            $this->authorizeRun($run);
            if ($plan['conflicts'] !== [] || ! hash_equals($run->plan_hash ?? '', $plan['hash'])) {
                throw new RuntimeException('The local data changed after preview. Rebuild and review the plan before confirming again.');
            }
            $run->update(['status' => 'applying']);
            $now = now()->format('Y-m-d H:i:s');
            // FK order is explicit. Bulk inserts keep full-account imports bounded.
            foreach (HarvestPlan::TABLES as $table) {
                $inserts = [];
                foreach ($plan['operations'] as $op) {
                    if ($op['entity'] !== $table) {
                        continue;
                    }
                    if ($op['action'] === 'create') {
                        $inserts[] = ['id' => $op['target_id']] + $op['after'] + ['created_at' => $now, 'updated_at' => $now];
                        if (count($inserts) === 250) {
                            DB::table($table)->insert($inserts);
                            $inserts = [];
                        }
                    } elseif ($op['action'] === 'update') {
                        DB::table($table)->where('id', $op['target_id'])->update($op['after'] + ['updated_at' => $now]);
                    }
                }
                if ($inserts !== []) {
                    DB::table($table)->insert($inserts);
                }
            }
            $mappings = [];
            foreach ($plan['operations'] as $op) {
                $mappings[] = ['organization_id' => $run->organization_id, 'account_id' => $run->account_id,
                    'entity' => $op['entity'], 'source_id' => $op['source_id'], 'target_id' => $op['target_id'],
                    'source_values' => json_encode($op['source_values'], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now];
                if (count($mappings) === 250) {
                    $this->saveMappings($mappings);
                    $mappings = [];
                }
            }
            if ($mappings !== []) {
                $this->saveMappings($mappings);
            }
            foreach (['projects' => 'project_id', 'tasks' => 'task_id'] as $table => $foreign) {
                DB::statement('UPDATE '.$table.' SET spent_time = COALESCE((SELECT SUM(EXTRACT(EPOCH FROM ("end" - start))) FROM time_entries WHERE time_entries.'.$foreign.' = '.$table.'.id AND time_entries.organization_id = ? AND "end" IS NOT NULL), 0) WHERE organization_id = ?', [$run->organization_id, $run->organization_id]);
            }
            $summary = $run->summary ?? [];
            $summary['result'] = $plan['counts'];
            $summary['can_import'] = false;
            $run->update(['status' => 'completed', 'summary' => $summary, 'error' => null]);
        });
    }

    /** @param list<array<string, mixed>> $mappings */
    private function saveMappings(array $mappings): void
    {
        DB::table('harvest_import_mappings')->upsert($mappings,
            ['organization_id', 'account_id', 'entity', 'source_id'], ['target_id', 'source_values', 'updated_at']);
    }

    public function authorizeRun(HarvestImportRun $run): void
    {
        if (! app(HarvestClient::class)->configuredFor($run->organization_id)
            || (string) config('harvest.account_id') !== $run->account_id
            || ! DB::table('members')->where('organization_id', $run->organization_id)
                ->where('user_id', $run->requested_by)->whereIn('role', ['owner', 'admin'])->exists()) {
            throw new RuntimeException('Harvest configuration or administrator access changed.');
        }
    }
}
