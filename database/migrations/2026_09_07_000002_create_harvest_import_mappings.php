<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvest_import_runs', function (Blueprint $table): void {
            $table->jsonb('decisions')->nullable();
            $table->string('plan_hash', 64)->nullable();
        });
        Schema::create('harvest_import_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('account_id');
            $table->string('entity');
            $table->string('source_id');
            $table->uuid('target_id');
            $table->jsonb('source_values');
            $table->timestamps();
            $table->unique(['organization_id', 'account_id', 'entity', 'source_id'], 'harvest_source_unique');
            $table->unique(['organization_id', 'account_id', 'entity', 'target_id'], 'harvest_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvest_import_mappings');
        Schema::table('harvest_import_runs', function (Blueprint $table): void {
            $table->dropColumn(['decisions', 'plan_hash']);
        });
    }
};
