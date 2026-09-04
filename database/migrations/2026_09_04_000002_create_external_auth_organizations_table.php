<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_auth_organizations', function (Blueprint $table): void {
            $table->string('provider')->primary();
            $table->foreignUuid('organization_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamps();
        });

        $existingOrganizations = DB::table('organizations')
            ->select('id')
            ->limit(2)
            ->get();
        $organizationId = $existingOrganizations->count() === 1
            ? (string) $existingOrganizations->first()->id
            : null;

        DB::table('external_auth_organizations')->insert([
            'provider' => 'google',
            'organization_id' => $organizationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('external_auth_organizations');
    }
};
