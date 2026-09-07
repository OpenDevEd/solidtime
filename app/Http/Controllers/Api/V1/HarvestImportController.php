<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Jobs\PrepareHarvestImport;
use App\Jobs\ProcessHarvestImport;
use App\Models\HarvestImportRun;
use App\Models\Organization;
use App\Service\Import\Harvest\HarvestClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HarvestImportController extends Controller
{
    public function index(Organization $organization, HarvestClient $client): JsonResponse
    {
        $this->authorizeAdmin($organization);
        $configured = $client->configuredFor($organization->id);

        return response()->json([
            'configured' => $configured,
            'run' => $configured ? HarvestImportRun::where('organization_id', $organization->id)
                ->where('account_id', (string) config('harvest.account_id'))->latest()->first() : null,
        ]);
    }

    public function store(Organization $organization, HarvestClient $client): JsonResponse
    {
        $this->authorizeAdmin($organization);
        abort_unless($client->configuredFor($organization->id), 403);
        $run = DB::transaction(function () use ($organization): HarvestImportRun {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $active = HarvestImportRun::where('organization_id', $organization->id)
                ->whereIn('status', ['queued', 'fetching', 'planning', 'apply_queued', 'applying'])->exists();
            abort_if($active, 409, 'A Harvest preview is already in progress.');
            $run = HarvestImportRun::create([
                'organization_id' => $organization->id,
                'requested_by' => $this->user()->id,
                'account_id' => (string) config('harvest.account_id'),
                'status' => 'queued',
                'disk' => config('filesystems.private'),
            ]);
            PrepareHarvestImport::dispatch($run->id);

            return $run;
        });

        return response()->json(['run' => $run], 202);
    }

    public function show(Organization $organization, string $run, HarvestClient $client): JsonResponse
    {
        $this->authorizeAdmin($organization);
        abort_unless($client->configuredFor($organization->id), 403);

        return response()->json(['run' => HarvestImportRun::where('organization_id', $organization->id)
            ->where('account_id', (string) config('harvest.account_id'))->findOrFail($run)]);
    }

    private function authorizeAdmin(Organization $organization): void
    {
        $this->checkPermission($organization, 'import');
        abort_unless(DB::table('members')->where('organization_id', $organization->id)
            ->where('user_id', $this->user()->id)->whereIn('role', ['owner', 'admin'])->exists(), 403);
    }

    public function plan(Organization $organization, string $run, Request $request, HarvestClient $client): JsonResponse
    {
        $this->authorizeAdmin($organization);
        abort_unless($client->configuredFor($organization->id), 403);
        $data = $request->validate(['decisions' => ['sometimes', 'array', 'max:100000'], 'decisions.*' => ['required', 'string', 'max:64']]);
        $record = DB::transaction(function () use ($organization, $run, $data): HarvestImportRun {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $record = $this->record($organization, $run)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($record->status, ['ready', 'planned', 'failed'], true), 409, 'This run cannot be replanned now.');
            abort_unless(Storage::disk($record->disk)->exists($record->path('manifest')), 409, 'Fetch a complete snapshot first.');
            abort_if(HarvestImportRun::where('organization_id', $organization->id)->where('id', '!=', $record->id)
                ->whereIn('status', ['planning', 'apply_queued', 'applying'])->exists(), 409, 'Another import is in progress.');
            $record->update(['status' => 'planning', 'requested_by' => $this->user()->id, 'decisions' => $data['decisions'] ?? [], 'plan_hash' => null, 'error' => null]);
            ProcessHarvestImport::dispatch($record->id);

            return $record;
        });

        return response()->json(['run' => $record], 202);
    }

    public function confirm(Organization $organization, string $run, Request $request, HarvestClient $client): JsonResponse
    {
        $this->authorizeAdmin($organization);
        abort_unless($client->configuredFor($organization->id), 403);
        $data = $request->validate(['plan_hash' => ['required', 'string', 'size:64'], 'acknowledge_limitations' => ['required', 'accepted']]);
        $record = DB::transaction(function () use ($organization, $run, $data): HarvestImportRun {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $record = $this->record($organization, $run)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($record->plan_hash ?? '', $data['plan_hash']), 409, 'The reviewed plan has changed.');
            if (in_array($record->status, ['completed', 'apply_queued', 'applying'], true)) {
                return $record;
            }
            abort_unless($record->status === 'planned' && ($record->summary['can_import'] ?? false), 409, 'Resolve conflicts before confirming.');
            abort_if(HarvestImportRun::where('organization_id', $organization->id)->where('id', '!=', $record->id)
                ->whereIn('status', ['planning', 'apply_queued', 'applying'])->exists(), 409, 'Another import is in progress.');
            $record->update(['status' => 'apply_queued', 'requested_by' => $this->user()->id]);
            ProcessHarvestImport::dispatch($record->id, true);

            return $record;
        });

        return response()->json(['run' => $record], 202);
    }

    public function changes(Organization $organization, string $run, Request $request, HarvestClient $client): JsonResponse
    {
        $this->authorizeAdmin($organization);
        abort_unless($client->configuredFor($organization->id), 403);
        $record = $this->record($organization, $run)->firstOrFail();
        abort_unless($record->plan_hash !== null, 409, 'Build a plan first.');
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = (int) ($data['page'] ?? 1);
        $plan = json_decode(Storage::disk($record->disk)->get($record->path('plan-'.$record->plan_hash)) ?? '', true, flags: JSON_THROW_ON_ERROR);
        $operations = array_values(array_filter($plan['operations'], fn (array $op): bool => $op['action'] !== 'unchanged'));
        $items = array_map(fn (array $op): array => array_intersect_key($op, array_flip(['entity', 'source_id', 'target_id', 'action', 'label', 'changes', 'after'])), array_slice($operations, ($page - 1) * 100, 100));

        return response()->json(['data' => $items, 'page' => $page, 'total' => count($operations), 'plan_hash' => $record->plan_hash]);
    }

    /** @return Builder<HarvestImportRun> */
    private function record(Organization $organization, string $id): Builder
    {
        return HarvestImportRun::where('organization_id', $organization->id)
            ->where('account_id', (string) config('harvest.account_id'))->whereKey($id);
    }
}
