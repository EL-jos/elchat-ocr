<?php

namespace App\Jobs\WebsiteSecurityAuditor;

use App\Models\WebsiteSecurityAudit;
use App\Services\WebsiteSecurityAuditor\WebsiteSecurityAuditorService;
use App\Services\WebsiteSecurityAuditor\WebsiteSecurityAuditorRealtimeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunWebsiteSecurityAuditJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;
    public int $uniqueFor = 600;
    public array $backoff = [60, 300];

    public function __construct(public readonly string $auditId)
    {
        $this->onQueue(config('website-growth-advisor.queue', 'website-growth-advisor'));
    }

    public function uniqueId(): string
    {
        return $this->auditId;
    }

    public function handle(WebsiteSecurityAuditorService $auditor, WebsiteSecurityAuditorRealtimeService $realtime): void
    {
        $audit = WebsiteSecurityAudit::query()->find($this->auditId);
        if (! $audit || in_array($audit->status, ['completed', 'partial'], true)) return;

        $audit->forceFill([
            'status' => 'running', 'progress' => 5, 'phase' => 'preparation',
            'progress_message' => 'Préparation de l’audit read-only.', 'started_at' => now(),
        ])->save();
        $realtime->publish((string) $audit->site_id, 'website_security_audit_started', [
            'audit_id' => (string) $audit->id,
            'status' => 'running',
            'progress' => 5,
            'phase' => 'preparation',
            'progress_message' => 'Préparation de l’audit read-only.',
        ]);

        $auditor->analyze($audit, function (array $progress) use ($audit, $realtime): void {
            $progressValue = max(0, min(100, (int) ($progress['progress'] ?? 0)));
            $audit->forceFill([
                'progress' => $progressValue,
                'phase' => (string) ($progress['phase'] ?? 'processing'),
                'progress_message' => (string) ($progress['message'] ?? 'Analyse en cours.'),
            ])->save();

            $realtime->publish((string) $audit->site_id, 'website_security_audit_progress', [
                'audit_id' => (string) $audit->id,
                'status' => $progressValue >= 100 ? (string) $audit->status : 'running',
                'progress' => (int) $audit->progress,
                'phase' => (string) $audit->phase,
                'progress_message' => (string) $audit->progress_message,
            ]);
        });

        $audit->refresh();
        $realtime->publish((string) $audit->site_id, 'website_security_audit_completed', [
            'audit_id' => (string) $audit->id,
            'status' => (string) $audit->status,
            'progress' => (int) $audit->progress,
            'phase' => (string) $audit->phase,
            'progress_message' => (string) $audit->progress_message,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $audit = WebsiteSecurityAudit::query()->whereKey($this->auditId)->first();
        if (! $audit) return;
        $audit->forceFill([
            'status' => 'failed', 'phase' => 'failed', 'progress_message' => 'L’audit a échoué.',
            'error_message' => 'website_security_audit_failed', 'completed_at' => now(),
        ])->save();
        app(WebsiteSecurityAuditorRealtimeService::class)->publish((string) $audit->site_id, 'website_security_audit_failed', [
            'audit_id' => (string) $audit->id,
            'status' => 'failed',
            'progress' => (int) $audit->progress,
            'phase' => 'failed',
            'progress_message' => (string) $audit->progress_message,
        ]);
        Log::error('Website Security Auditor job failed.', [
            'audit_id' => $this->auditId,
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
