<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createTableIfMissing('social_intelligence_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->json('sources')->nullable();
            $table->string('period', 20)->default('30d');
            $table->date('custom_from')->nullable();
            $table->date('custom_to')->nullable();
            $table->string('comparison', 32)->default('previous_period');
            $table->json('domains')->nullable();
            $table->string('depth', 20)->default('standard');
            $table->string('frequency', 20)->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->unique('site_id');
            $table->index(['account_id', 'site_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
        });

        $this->createTableIfMissing('social_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('social_account_id');
            $table->string('provider', 50);
            $table->string('external_content_id', 191);
            $table->string('content_type', 50)->nullable();
            $table->string('title')->nullable();
            $table->longText('body')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('availability_status', 32)->default('available');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'provider', 'external_content_id'], 'social_content_identity');
            $table->index(['site_id', 'provider', 'published_at']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->cascadeOnDelete();
        });

        $this->createTableIfMissing('social_content_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('social_account_id');
            $table->uuid('social_content_id')->nullable();
            $table->string('provider', 50);
            $table->string('metric_key', 80);
            $table->string('metric_definition', 500);
            $table->decimal('value', 24, 6)->nullable();
            $table->string('unit', 32)->default('count');
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->timestamp('observed_at');
            $table->string('source', 100);
            $table->string('availability_status', 32)->default('available');
            $table->boolean('comparable')->default(false);
            $table->json('metadata')->nullable();
            $table->string('dedupe_key', 64)->unique();
            $table->timestamps();
            $table->index(['site_id', 'provider', 'metric_key', 'observed_at'], 'si_metrics_site_provider_metric_observed_idx');
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->cascadeOnDelete();
            $table->foreign('social_content_id')->references('id')->on('social_contents')->nullOnDelete();
        });

        // If a MySQL migration stopped after CREATE TABLE but before completing
        // the indexes/foreign keys, finish those constraints on the next run.
        if (Schema::hasTable('social_content_metrics')) {
            if (!Schema::hasIndex('social_content_metrics', 'si_metrics_site_provider_metric_observed_idx')) {
                Schema::table('social_content_metrics', function (Blueprint $table) {
                    $table->index(['site_id', 'provider', 'metric_key', 'observed_at'], 'si_metrics_site_provider_metric_observed_idx');
                });
            }

            $foreignColumns = collect(Schema::getForeignKeys('social_content_metrics'))
                ->flatMap(fn (array $foreignKey) => $foreignKey['columns'])
                ->all();

            Schema::table('social_content_metrics', function (Blueprint $table) use ($foreignColumns) {
                if (!in_array('account_id', $foreignColumns, true)) {
                    $table->foreign('account_id', 'si_metrics_account_fk')->references('id')->on('accounts')->cascadeOnDelete();
                }
                if (!in_array('site_id', $foreignColumns, true)) {
                    $table->foreign('site_id', 'si_metrics_site_fk')->references('id')->on('sites')->cascadeOnDelete();
                }
                if (!in_array('social_account_id', $foreignColumns, true)) {
                    $table->foreign('social_account_id', 'si_metrics_social_account_fk')->references('id')->on('social_accounts')->cascadeOnDelete();
                }
                if (!in_array('social_content_id', $foreignColumns, true)) {
                    $table->foreign('social_content_id', 'si_metrics_content_fk')->references('id')->on('social_contents')->nullOnDelete();
                }
            });
        }

        $this->createTableIfMissing('social_sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('social_account_id')->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('status', 32)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('contents_seen')->default(0);
            $table->unsignedInteger('contents_written')->default(0);
            $table->unsignedInteger('metrics_written')->default(0);
            $table->unsignedInteger('interactions_seen')->default(0);
            $table->json('capabilities')->nullable();
            $table->json('data_quality')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'created_at']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('social_account_id')->references('id')->on('social_accounts')->nullOnDelete();
        });

        $this->createTableIfMissing('social_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('requested_by')->nullable();
            $table->timestamp('period_from');
            $table->timestamp('period_to');
            $table->timestamp('comparison_from')->nullable();
            $table->timestamp('comparison_to')->nullable();
            $table->json('configuration');
            $table->string('depth', 20);
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('phase', 50)->nullable();
            $table->json('result')->nullable();
            $table->json('data_quality')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'created_at']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });

        $this->createTableIfMissing('social_topics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('social_analysis_id');
            $table->string('provider', 50)->nullable();
            $table->string('source_type', 32);
            $table->string('topic_key', 120);
            $table->string('label', 160);
            $table->unsignedInteger('frequency')->default(0);
            $table->unsignedInteger('interaction_count')->default(0);
            $table->decimal('change_percent', 12, 4)->nullable();
            $table->string('status', 32)->default('insufficient_data');
            $table->string('confidence', 16)->default('low');
            $table->json('evidence')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'topic_key']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('social_analysis_id')->references('id')->on('social_analyses')->cascadeOnDelete();
        });

        $this->createTableIfMissing('social_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('social_analysis_id');
            $table->string('type', 40);
            $table->string('title');
            $table->string('importance', 16)->default('medium');
            $table->text('observation')->nullable();
            $table->json('evidence')->nullable();
            $table->text('correlation')->nullable();
            $table->text('interpretation')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('opportunity')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('implementation')->nullable();
            $table->text('measurement')->nullable();
            $table->string('confidence', 16)->default('low');
            $table->json('limitations')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->index(['site_id', 'type', 'importance']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('social_analysis_id')->references('id')->on('social_analyses')->cascadeOnDelete();
        });

        $this->createTableIfMissing('social_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('site_id');
            $table->uuid('social_analysis_id');
            $table->uuid('social_insight_id')->nullable();
            $table->string('title');
            $table->text('problem')->nullable();
            $table->text('why')->nullable();
            $table->text('action');
            $table->text('how')->nullable();
            $table->text('expected_signal')->nullable();
            $table->text('measurement')->nullable();
            $table->json('action_proposal')->nullable();
            $table->string('status', 24)->default('proposed');
            $table->timestamps();
            $table->index(['site_id', 'status']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('sites')->cascadeOnDelete();
            $table->foreign('social_analysis_id')->references('id')->on('social_analyses')->cascadeOnDelete();
            $table->foreign('social_insight_id')->references('id')->on('social_insights')->nullOnDelete();
        });
    }

    private function createTableIfMissing(string $name, \Closure $definition): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_recommendations');
        Schema::dropIfExists('social_insights');
        Schema::dropIfExists('social_topics');
        Schema::dropIfExists('social_analyses');
        Schema::dropIfExists('social_sync_runs');
        Schema::dropIfExists('social_content_metrics');
        Schema::dropIfExists('social_contents');
        Schema::dropIfExists('social_intelligence_settings');
    }
};
