<?php

namespace App\Jobs\WebsiteSecurityAuditor;

use App\Models\WebsiteSecurityRemediationProposal;
use App\Services\WebsiteSecurityAuditor\WebsiteSecurityAuditorRealtimeService;
use App\Services\mcp\MCPActionGateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteWebsiteSecurityRemediationProposalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 900;

    public function __construct(public readonly string $proposalId)
    {
        $this->onQueue(config('website-growth-advisor.queue', 'website-growth-advisor'));
    }

    public function uniqueId(): string
    {
        return $this->proposalId;
    }

    public function handle(MCPActionGateService $gate, WebsiteSecurityAuditorRealtimeService $realtime): void
    {
        $proposal = WebsiteSecurityRemediationProposal::query()->whereKey($this->proposalId)->first();
        if (! $proposal || $proposal->status !== 'approved') {
            return;
        }

        $proposal->forceFill(['status' => 'executing'])->save();
        $realtime->publish((string) $proposal->site_id, 'website_security_remediation_updated', [
            'proposal_id' => (string) $proposal->id,
            'audit_id' => (string) $proposal->audit_id,
            'finding_id' => (string) $proposal->finding_id,
            'status' => 'executing',
        ]);
        try {
            $site = $proposal->site()->firstOrFail();
            $conversation = $proposal->conversation()->firstOrFail();
            $result = $gate->executeToolDirectly(
                $site,
                $conversation,
                $proposal->connector_slug.'__'.$proposal->tool_name,
                $proposal->params,
                humanApproved: true,
            );
            if (! $result->success) {
                $proposal->forceFill([
                    'status' => 'failed', 'error_code' => $result->errorCode ?? 'remediation_action_failed',
                    'executed_at' => now(),
                ])->save();
                $realtime->publish((string) $proposal->site_id, 'website_security_remediation_updated', [
                    'proposal_id' => (string) $proposal->id,
                    'audit_id' => (string) $proposal->audit_id,
                    'finding_id' => (string) $proposal->finding_id,
                    'status' => 'failed',
                ]);

                return;
            }

            $proposal->forceFill([
                'status' => 'executed',
                'result' => array_intersect_key($result->data, array_flip(['issue_key', 'issue_id', 'url'])),
                'error_code' => null,
                'executed_at' => now(),
            ])->save();
            $realtime->publish((string) $proposal->site_id, 'website_security_remediation_updated', [
                'proposal_id' => (string) $proposal->id,
                'audit_id' => (string) $proposal->audit_id,
                'finding_id' => (string) $proposal->finding_id,
                'status' => 'executed',
            ]);
        } catch (Throwable $exception) {
            $proposal->forceFill(['status' => 'failed', 'error_code' => 'remediation_action_failed', 'executed_at' => now()])->save();
            $realtime->publish((string) $proposal->site_id, 'website_security_remediation_updated', [
                'proposal_id' => (string) $proposal->id,
                'audit_id' => (string) $proposal->audit_id,
                'finding_id' => (string) $proposal->finding_id,
                'status' => 'failed',
            ]);
            Log::warning('Approved Website Security remediation action failed.', [
                'proposal_id' => $this->proposalId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $updated = WebsiteSecurityRemediationProposal::query()->whereKey($this->proposalId)->whereIn('status', ['approved', 'executing'])->update([
            'status' => 'failed', 'error_code' => 'remediation_job_failed', 'executed_at' => now(),
        ]);
        if ($updated) {
            $proposal = WebsiteSecurityRemediationProposal::query()->find($this->proposalId);
            if ($proposal) {
                app(WebsiteSecurityAuditorRealtimeService::class)->publish((string) $proposal->site_id, 'website_security_remediation_updated', [
                    'proposal_id' => (string) $proposal->id,
                    'audit_id' => (string) $proposal->audit_id,
                    'finding_id' => (string) $proposal->finding_id,
                    'status' => 'failed',
                ]);
            }
        }
        Log::warning('Website Security remediation job failed.', [
            'proposal_id' => $this->proposalId,
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
