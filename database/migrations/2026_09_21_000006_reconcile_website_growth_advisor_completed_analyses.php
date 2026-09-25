<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $values = [
            'status' => 'ready',
            'progress' => 100,
            'phase' => 'completed',
            'progress_message' => 'Analyse Growth Advisor terminée.',
        ];

        DB::table('website_growth_advisor_analyses')
            ->whereIn('status', ['queued', 'running'])
            ->whereNull('completed_at')
            ->where(function (Builder $query): void {
                $query->whereNotNull('result')
                    ->orWhere('progress', '>=', 100);
            })
            ->update($values + ['completed_at' => now()]);

        DB::table('website_growth_advisor_analyses')
            ->whereIn('status', ['queued', 'running'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('result')
                    ->orWhereNotNull('completed_at')
                    ->orWhere('progress', '>=', 100);
            })
            ->update($values);
    }

    public function down(): void
    {
        // This data repair is intentionally not reversed.
    }
};
