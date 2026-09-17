<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'visitor_read_at')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->timestamp('visitor_read_at')->nullable()->after('created_at');
            });
        }

        if (! Schema::hasColumn('messages', 'tenant_read_at')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->timestamp('tenant_read_at')->nullable()->after('visitor_read_at');
            });
        }

        if (! Schema::hasIndex('messages', ['conversation_id', 'visitor_read_at'])) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->index(['conversation_id', 'visitor_read_at']);
            });
        }

        if (! Schema::hasIndex('messages', ['conversation_id', 'tenant_read_at'])) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->index(['conversation_id', 'tenant_read_at']);
            });
        }
    }

    public function down(): void
    {
        $this->dropReadIndexIfExists('visitor_read_at');
        $this->dropReadIndexIfExists('tenant_read_at');
    }

    private function dropReadIndexIfExists(string $readColumn): void
    {
        foreach (Schema::getIndexes('messages') as $index) {
            if ($index['columns'] !== ['conversation_id', $readColumn]) {
                continue;
            }

            Schema::table('messages', function (Blueprint $table) use ($index): void {
                $table->dropIndex($index['name']);
            });

            break;
        }
    }
};
