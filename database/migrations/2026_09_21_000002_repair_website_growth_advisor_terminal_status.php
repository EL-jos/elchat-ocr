<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('website_growth_advisor_analyses')
            ->where('status', 'running')
            ->whereNotNull('result')
            ->whereNotNull('completed_at')
            ->update([
                'status' => 'ready',
                'progress' => 100,
                'phase' => 'completed',
                'progress_message' => 'Analyse Growth Advisor terminée.',
            ]);
    }

    public function down(): void
    {
        // This data repair is intentionally not reversed: reverting the
        // migration must not put completed analyses back into `running`.
    }
};
