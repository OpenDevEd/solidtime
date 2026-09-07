<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HarvestImportRun;
use App\Service\Import\Harvest\HarvestClient;
use App\Service\Import\Harvest\HarvestPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class PrepareHarvestImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public string $runId)
    {
        $this->onConnection('harvest')->onQueue('harvest');
    }

    public function handle(HarvestClient $client, HarvestPreview $preview): void
    {
        $run = HarvestImportRun::findOrFail($this->runId);
        if ($run->status !== 'queued') {
            return;
        }
        if (! $client->configuredFor($run->organization_id)
            || (string) config('harvest.account_id') !== $run->account_id
            || ! DB::table('members')->where('organization_id', $run->organization_id)
                ->where('user_id', $run->requested_by)->whereIn('role', ['owner', 'admin'])->exists()) {
            throw new RuntimeException('Harvest configuration or administrator access changed.');
        }
        $claimed = HarvestImportRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'fetching']);
        if ($claimed !== 1) {
            return;
        }
        $manifest = ['account_id' => $run->account_id, 'started_at' => now()->toIso8601String(), 'company' => $client->company(), 'collections' => []];
        foreach (HarvestClient::COLLECTIONS as $collection) {
            $pages = [];
            $count = 0;
            foreach ($client->pages($collection) as $index => $page) {
                $path = $run->path($collection.'-'.($index + 1));
                $raw = json_encode($page, JSON_THROW_ON_ERROR);
                Storage::disk($run->disk)->put($path, $raw);
                $pages[] = ['path' => $path, 'sha256' => hash('sha256', $raw)];
                $count += count($page[$collection]);
                $run->update(['summary' => ['progress' => $collection, 'collected' => $count]]);
            }
            $manifest['collections'][$collection] = ['count' => $count, 'pages' => $pages];
        }
        $manifest['finished_at'] = now()->toIso8601String();
        Storage::disk($run->disk)->put($run->path('manifest'), json_encode($manifest, JSON_THROW_ON_ERROR));
        $summary = $preview->build($run, $manifest);
        $run->update(['status' => 'ready', 'summary' => $summary, 'error' => null]);
    }

    public function failed(?Throwable $exception): void
    {
        HarvestImportRun::whereKey($this->runId)->update([
            'status' => 'failed',
            'error' => $exception instanceof RuntimeException && get_class($exception) === RuntimeException::class
                ? $exception->getMessage()
                : 'Preview failed. Check the local worker logs, then start a new preview.',
        ]);
    }
}
