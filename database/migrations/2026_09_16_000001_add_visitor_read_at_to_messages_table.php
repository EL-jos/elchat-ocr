<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('visitor_read_at')->nullable()->after('created_at');
            $table->timestamp('tenant_read_at')->nullable()->after('visitor_read_at');
            $table->index(['conversation_id', 'visitor_read_at']);
            $table->index(['conversation_id', 'tenant_read_at']);
        });
    }

    public function down(): void
    {
        $this->dropReadIndexIfExists('visitor_read_at');
        $this->dropReadIndexIfExists('tenant_read_at');

        foreach (['visitor_read_at', 'tenant_read_at'] as $column) {
            if (Schema::hasColumn('messages', $column)) {
                Schema::table('messages', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
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
