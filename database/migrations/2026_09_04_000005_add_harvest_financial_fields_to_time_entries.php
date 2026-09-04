<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->unsignedInteger('cost_rate')->nullable()->after('billable_rate');
            $table->string('billable_currency', 3)->nullable()->after('cost_rate');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropColumn(['cost_rate', 'billable_currency']);
        });
    }
};
