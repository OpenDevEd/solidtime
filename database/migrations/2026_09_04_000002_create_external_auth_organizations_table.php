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

        DB::table('external_auth_organizations')->insert([
            'provider' => 'google',
            'organization_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('external_auth_organizations');
    }
};
