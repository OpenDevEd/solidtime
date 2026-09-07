<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HarvestImportRun;
use App\Service\Import\Harvest\HarvestImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

class ProcessHarvestImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public string $runId, public bool $apply = false)
    {
        $this->onConnection('harvest')->onQueue('harvest');
    }

    public function handle(HarvestImport $service): void
    {
        $run = HarvestImportRun::findOrFail($this->runId);
        if ($this->apply) {
            if ($run->status !== 'apply_queued') {
                return;
            }
            $service->apply($run);
        } elseif ($run->status === 'planning') {
            $service->prepare($run);
        }
    }

    public function failed(?Throwable $exception): void
    {
        HarvestImportRun::whereKey($this->runId)->where('status', '!=', 'completed')->update([
            'status' => 'failed',
            'error' => $exception instanceof RuntimeException && get_class($exception) === RuntimeException::class
                ? $exception->getMessage() : 'Import failed without applying partial business changes. Check the worker logs and rebuild the plan.',
        ]);
    }
}
