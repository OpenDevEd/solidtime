<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE harvest_import_mappings DROP CONSTRAINT harvest_target_unique');
        DB::statement("CREATE UNIQUE INDEX harvest_target_unique ON harvest_import_mappings (organization_id, account_id, entity, target_id) WHERE entity NOT IN ('users', 'members', 'project_members')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX harvest_target_unique');
        DB::statement('ALTER TABLE harvest_import_mappings ADD CONSTRAINT harvest_target_unique UNIQUE (organization_id, account_id, entity, target_id)');
    }
};
