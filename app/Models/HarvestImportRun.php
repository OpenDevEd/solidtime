<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $account_id
 * @property string $status
 * @property string $disk
 * @property array<string, mixed>|null $summary
 * @property string|null $error
 * @property array<string, string>|null $decisions
 * @property string|null $plan_hash
 */
class HarvestImportRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'decisions' => 'array'];
    }

    public function path(string $name): string
    {
        return 'import/harvest/'.$this->organization_id.'/'.$this->id.'/'.$name.'.json';
    }
}
