<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devpilot_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->string('name', 120);
            $table->string('protocol', 16);
            $table->string('host', 253)->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('username', 128)->nullable();
            $table->string('database_name', 128)->nullable();
            $table->string('auth_method', 24)->nullable();
            $table->text('credentials_encrypted')->nullable();
            $table->string('host_key_sha256', 64)->nullable();
            $table->string('root_path', 1024)->nullable();
            $table->boolean('private_network_authorized')->default(false);
            $table->boolean('can_read')->default(true);
            $table->boolean('can_write')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_run_commands')->default(false);
            $table->json('allowed_commands')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('last_test_result')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('status', 24)->default('unverified');
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->index(['site_id', 'status']);
        });

        Schema::create('devpilot_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('connection_id')->nullable();
            $table->string('name', 160);
            $table->string('environment', 16)->default('development');
            $table->string('root_path', 1024)->default('.');
            $table->uuid('database_connection_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('connection_id')->references('id')->on('devpilot_connections')->nullOnDelete();
            $table->foreign('database_connection_id')->references('id')->on('devpilot_connections')->nullOnDelete();
            $table->index(['site_id', 'name']);
        });

        Schema::create('devpilot_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id');
            $table->uuid('project_id');
            $table->uuid('agent_id')->nullable();
            $table->uuid('connection_id')->nullable();
            $table->string('connection_protocol', 16)->nullable();
            $table->string('connection_name', 120)->nullable();
            $table->char('target_snapshot_hash', 64)->nullable();
            $table->string('project_environment', 16)->nullable();
            $table->string('project_root', 1024)->nullable();
            $table->string('requested_by', 64)->nullable();
            $table->text('instruction');
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('plan')->nullable();
            $table->longText('proposed_changes_encrypted')->nullable();
            $table->string('proposal_hash', 64)->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->string('approved_by', 64)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('devpilot_projects')->cascadeOnDelete();
            $table->foreign('agent_id')->references('id')->on('mcp_agents')->nullOnDelete();
            $table->index(['site_id', 'project_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('devpilot_task_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('task_id');
            $table->string('kind', 32);
            $table->string('message', 500);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('task_id')->references('id')->on('devpilot_tasks')->cascadeOnDelete();
            $table->index(['task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devpilot_task_events');
        Schema::dropIfExists('devpilot_tasks');
        Schema::dropIfExists('devpilot_projects');
        Schema::dropIfExists('devpilot_connections');
    }
};
