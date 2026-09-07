<?php

declare(strict_types=1);

namespace App\Service\Import\Harvest;

use App\Models\HarvestImportRun;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class HarvestSnapshot
{
    /** @return array<string, mixed> */
    public function manifest(HarvestImportRun $run): array
    {
        $data = json_decode(Storage::disk($run->disk)->get($run->path('manifest')) ?? '', true, flags: JSON_THROW_ON_ERROR);
        if ((string) $data['account_id'] !== $run->account_id) {
            throw new RuntimeException('The saved snapshot belongs to a different Harvest account.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>|null  $manifest
     * @return \Generator<int, array<string, mixed>>
     */
    public function rows(HarvestImportRun $run, string $entity, ?array $manifest = null): \Generator
    {
        $manifest ??= $this->manifest($run);
        foreach ($manifest['collections'][$entity]['pages'] as $page) {
            $raw = Storage::disk($run->disk)->get($page['path']);
            if ($raw === null || ! hash_equals($page['sha256'], hash('sha256', $raw))) {
                throw new RuntimeException('Saved Harvest snapshot failed its integrity check.');
            }
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            yield from $data[$entity];
        }
    }
}
