<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\WebsiteSecurityAuditor\ExecuteWebsiteSecurityRemediationProposalJob;
use App\Jobs\WebsiteSecurityAuditor\RunWebsiteSecurityAuditJob;
use App\Models\Conversation;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpPermission;
use App\Models\Site;
use App\Models\WebsiteSecurityAudit;
use App\Models\WebsiteSecurityBaseline;
use App\Models\WebsiteSecurityManifest;
use App\Models\WebsiteSecurityRemediationProposal;
use App\Services\WebsiteSecurityAuditor\WebsiteSecurityConfigurationService;
use App\Services\WebsiteSecurityAuditor\WebsiteSecurityManifestImportService;
use App\Services\WebsiteSecurityAuditor\WebsiteSecurityAuditorRealtimeService;
use App\Services\WebsiteSecurityAuditor\WebsiteSecuritySbomComparisonService;
use App\Services\WebsiteSecurityAuditor\WebsiteSecuritySourceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebsiteSecurityAuditorController extends Controller
{
    use AuthorizesSiteAccess;

    public function __construct(
        private readonly WebsiteSecurityConfigurationService $configurations,
        private readonly WebsiteSecurityManifestImportService $manifests,
        private readonly WebsiteSecuritySbomComparisonService $sbomComparison,
        private readonly WebsiteSecuritySourceAccessService $sources,
        private readonly WebsiteSecurityAuditorRealtimeService $realtime,
    ) {}

    public function configuration(Request $request, Site $site, string $agent): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = $this->securityAgent($site, $agent);
        $configuration = $this->configurations->forAgent($site, $model);

        return response()->json([
            'data' => $configuration,
            'meta' => ['configuration_version' => (int) $configuration->version, 'supported_modes' => ['audit', 'investigation']],
        ]);
    }

    public function updateConfiguration(Request $request, Site $site, string $agent): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = $this->securityAgent($site, $agent);
        $configuration = $this->configurations->forAgent($site, $model);
        $validated = $request->validate([
            'audit_mode' => ['required', Rule::in(['audit', 'investigation'])],
            'analysis_depth' => ['required', Rule::in(['quick', 'standard', 'deep'])],
            'ai_interpretation' => ['required', 'boolean'],
            'checks' => ['required', 'array'],
            'checks.security_headers' => ['required', 'boolean'],
            'checks.tls_transport' => ['required', 'boolean'],
            'checks.cookie_flags' => ['required', 'boolean'],
            'checks.discovered_surface' => ['required', 'boolean'],
            'checks.vulnerability_intelligence' => ['required', 'boolean'],
            'checks.network_surface_scan' => ['required', 'boolean'],
            'checks.dns_discovery' => ['required', 'boolean'],
            'checks.repository_analysis' => ['required', 'boolean'],
            'checks.server_configuration' => ['required', 'boolean'],
            'checks.database_posture' => ['required', 'boolean'],
            'authorized_scope' => ['required', 'array'],
            'authorized_scope.hosts' => ['present', 'array', 'max:20'],
            'authorized_scope.hosts.*' => ['string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i'],
            'authorized_scope.ports' => ['present', 'array', 'max:32'],
            'authorized_scope.ports.*' => ['integer', 'between:1,65535'],
            'authorized_scope.authorization_confirmed' => ['required', 'boolean'],
            'execution_mode' => ['required', Rule::in(['manual', 'scheduled'])],
            'schedule_frequency' => ['nullable', Rule::in(['daily', 'weekly', 'monthly'])],
            'schedule_time' => ['required', 'date_format:H:i'],
            'is_active' => ['required', 'boolean'],
        ]);

        $values = $this->configurations->normalize($validated, $configuration);
        $scopeChanged = $values['authorized_scope'] !== ($configuration->authorized_scope ?? ['hosts' => [], 'ports' => [], 'authorization_confirmed' => false]);
        $networkChecksRequested = ! empty($values['checks']['network_surface_scan']) || ! empty($values['checks']['dns_discovery']);
        if ($networkChecksRequested && ($values['authorized_scope']['hosts'] === [] || ! $values['authorized_scope']['authorization_confirmed'])) {
            abort(422, 'Déclarez les hôtes autorisés et confirmez explicitement le périmètre avant d’activer les contrôles réseau.');
        }
        if (! empty($values['checks']['network_surface_scan']) && $values['authorized_scope']['ports'] === []) {
            abort(422, 'Ajoutez au moins un port explicitement autorisé avant d’activer la vérification TCP.');
        }
        if ($scopeChanged && $networkChecksRequested && empty($validated['authorized_scope']['authorization_confirmed'])) {
            abort(422, 'Toute modification du périmètre réseau requiert une nouvelle confirmation.');
        }
        $values['version'] = ((int) $configuration->version) + 1;
        if ($scopeChanged && ! empty($values['authorized_scope']['authorization_confirmed'])) {
            $configuration->scope_authorized_by = (string) $request->user()->id;
            $configuration->scope_authorized_at = now();
        }
        $configuration->fill($values)->save();

        return response()->json(['data' => $configuration->fresh(), 'meta' => ['configuration_version' => (int) $configuration->version]]);
    }

    public function sources(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);

        return response()->json(['data' => $this->sources->statuses($site)]);
    }

    public function connectSource(Request $request, Site $site, string $source): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $validated = match ($source) {
            'repository' => $request->validate([
                'provider' => ['required', Rule::in(['github', 'gitlab'])],
                'repository' => ['required', 'string', 'max:512'], 'access_token' => ['required', 'string', 'min:8', 'max:4096'],
                'branch' => ['nullable', 'string', 'max:255'],
            ]),
            'server' => $request->validate([
                'host' => ['required', 'string', 'max:253'], 'port' => ['nullable', 'integer', 'between:1,65535'],
                'username' => ['required', 'string', 'max:128'], 'password' => ['nullable', 'string', 'max:4096'],
                'private_key' => ['nullable', 'string', 'max:32768'], 'private_key_passphrase' => ['nullable', 'string', 'max:4096'],
                'host_key_sha256' => ['required', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
                'root_path' => ['required', 'string', 'max:255'], 'private_network_authorized' => ['required', 'boolean'],
            ]),
            'database' => $request->validate([
                'driver' => ['required', Rule::in(['mysql', 'pgsql'])], 'host' => ['required', 'string', 'max:253'],
                'port' => ['nullable', 'integer', 'between:1,65535'], 'database' => ['required', 'string', 'max:128'],
                'username' => ['required', 'string', 'max:128'], 'password' => ['required', 'string', 'max:4096'],
                'read_only_confirmed' => ['required', 'accepted'], 'tls_verify' => ['required', 'accepted'],
                'private_network_authorized' => ['required', 'boolean'],
            ]),
            default => abort(404),
        };

        $result = match ($source) {
            'repository' => $this->sources->connectRepository($site, $validated),
            'server' => $this->sources->connectServer($site, $validated),
            'database' => $this->sources->connectDatabase($site, $validated),
        };

        return response()->json(['data' => $result], 201);
    }

    public function disconnectSource(Request $request, Site $site, string $source): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $this->sources->disconnect($site, $source);

        return response()->json(['data' => $this->sources->statuses($site)]);
    }

    public function remediationProposals(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $proposals = WebsiteSecurityRemediationProposal::query()->where('account_id', $site->account_id)
            ->where('site_id', $site->id)->latest()->limit(100)->get();

        return response()->json(['data' => $proposals]);
    }

    public function proposeRemediation(Request $request, Site $site, string $audit): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $validated = $request->validate([
            'finding_id' => ['required', 'string', 'max:96'],
            'project_key' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{0,9}$/'],
            'issue_type' => ['required', Rule::in(['Task', 'Bug'])],
        ]);
        $auditRecord = WebsiteSecurityAudit::query()->where('site_id', $site->id)->whereKey($audit)
            ->whereIn('status', ['completed', 'partial'])->firstOrFail();
        $finding = collect($auditRecord->findings ?? [])->first(fn ($item) => is_array($item) && ($item['id'] ?? null) === $validated['finding_id']);
        abort_unless(is_array($finding), 404);

        $existing = WebsiteSecurityRemediationProposal::query()->where('site_id', $site->id)
            ->where('audit_id', $auditRecord->id)->where('finding_id', $validated['finding_id'])
            ->whereIn('status', ['proposed', 'approved', 'executing', 'executed'])->latest()->first();
        if ($existing) {
            return response()->json(['data' => $existing], 200);
        }

        $title = mb_substr(trim((string) ($finding['title'] ?? 'Security finding')), 0, 220);
        $recommendation = trim((string) data_get($finding, 'remediation.recommendation', 'Review this security finding and determine an approved remediation.'));
        $evidenceIds = collect($finding['evidence'] ?? [])->pluck('id')->filter(fn ($id) => is_string($id))->take(10)->values()->all();
        $description = implode("\n", array_filter([
            'Created from an explicitly approved Website Security Auditor remediation proposal.',
            'Finding: '.$title,
            'Severity: '.(string) ($finding['severity'] ?? 'unknown').' · Validation: '.(string) ($finding['validation_state'] ?? 'unknown'),
            'Asset: '.mb_substr((string) ($finding['asset'] ?? 'unknown'), 0, 240),
            'Observation: '.mb_substr((string) ($finding['description'] ?? ''), 0, 1400),
            'Recommended action: '.mb_substr($recommendation, 0, 1200),
            'Evidence IDs: '.implode(', ', $evidenceIds),
            'Automated change: not performed. Verify the proposed fix and rerun the security audit after implementation.',
        ]));
        $proposal = WebsiteSecurityRemediationProposal::query()->create([
            'id' => (string) Str::uuid(), 'account_id' => $site->account_id, 'site_id' => $site->id,
            'audit_id' => $auditRecord->id, 'finding_id' => $validated['finding_id'],
            'finding_fingerprint' => (string) ($finding['fingerprint'] ?? hash('sha256', $validated['finding_id'])),
            'status' => 'proposed', 'connector_slug' => 'jira', 'tool_name' => 'create_issue',
            'params' => [
                'project_key' => $validated['project_key'], 'issue_type' => $validated['issue_type'],
                'summary' => '[Website Security] '.$title, 'description' => $description,
                'labels' => ['website-security', 'elchat-remediation'],
            ],
            'proposed_by' => (string) $request->user()->id,
        ]);
        $this->realtime->publish((string) $site->id, 'website_security_remediation_updated', [
            'proposal_id' => (string) $proposal->id,
            'audit_id' => (string) $proposal->audit_id,
            'finding_id' => (string) $proposal->finding_id,
            'status' => (string) $proposal->status,
        ]);

        return response()->json(['data' => $proposal], 201);
    }

    public function approveRemediation(Request $request, Site $site, string $proposal): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($request->user()?->isAdmin(), 403, 'Une validation administrateur est requise pour créer le ticket de remédiation.');
        $record = WebsiteSecurityRemediationProposal::query()->where('site_id', $site->id)->findOrFail($proposal);
        abort_unless($record->status === 'proposed', 409, 'Cette proposition a déjà été examinée.');
        $permission = McpPermission::query()->where('site_id', $site->id)->where('connector_slug', 'jira')->where('tool_name', 'create_issue')->first();
        abort_unless($permission && $permission->mode !== 'deny', 422, 'L’action jira.create_issue est bloquée par les permissions MCP du site.');
        $jiraConnected = $site->mcpSiteConnectors()->whereHas('mcpConnector', fn ($query) => $query->where('slug', 'jira'))
            ->where('status', 'connected')->exists();
        abort_unless($jiraConnected, 422, 'Connectez Jira avant d’approuver la création du ticket.');

        $approved = DB::transaction(function () use ($site, $record, $request): WebsiteSecurityRemediationProposal {
            $locked = WebsiteSecurityRemediationProposal::query()->where('site_id', $site->id)->whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'proposed', 409, 'Cette proposition a déjà été examinée.');
            $conversation = Conversation::query()->create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id, 'user_id' => (string) $request->user()->id,
            ]);
            $locked->forceFill([
                'status' => 'approved', 'reviewed_by' => (string) $request->user()->id,
                'reviewed_at' => now(), 'conversation_id' => $conversation->id,
            ])->save();

            return $locked;
        });
        $this->realtime->publish((string) $site->id, 'website_security_remediation_updated', [
            'proposal_id' => (string) $approved->id,
            'audit_id' => (string) $approved->audit_id,
            'finding_id' => (string) $approved->finding_id,
            'status' => 'approved',
        ]);
        ExecuteWebsiteSecurityRemediationProposalJob::dispatch((string) $approved->id)->afterCommit();

        return response()->json(['data' => $approved->fresh()], 202);
    }

    public function rejectRemediation(Request $request, Site $site, string $proposal): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($request->user()?->isAdmin(), 403, 'Une validation administrateur est requise.');
        $updated = WebsiteSecurityRemediationProposal::query()->where('site_id', $site->id)->whereKey($proposal)
            ->where('status', 'proposed')->update([
                'status' => 'rejected', 'reviewed_by' => (string) $request->user()->id, 'reviewed_at' => now(),
            ]);
        abort_unless($updated === 1, 409, 'Cette proposition a déjà été examinée.');

        $record = WebsiteSecurityRemediationProposal::query()->where('site_id', $site->id)->findOrFail($proposal);
        $this->realtime->publish((string) $site->id, 'website_security_remediation_updated', [
            'proposal_id' => (string) $record->id,
            'audit_id' => (string) $record->audit_id,
            'finding_id' => (string) $record->finding_id,
            'status' => (string) $record->status,
        ]);

        return response()->json(['data' => $record]);
    }

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        return response()->json(['data' => WebsiteSecurityAudit::query()
            ->where('site_id', $site->id)->latest('created_at')->limit(50)->get()]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $agent = McpAgent::query()->where('site_id', $site->id)
            ->whereIn('template_key', ['website_security_auditor', 'website-security-auditor'])->first();
        abort_unless($agent, 422, 'Installez Website Security Auditor depuis la Banque d’agents avant de lancer un audit.');
        $configuration = $this->configurations->forAgent($site, $agent);
        $running = WebsiteSecurityAudit::query()->where('site_id', $site->id)->whereIn('status', ['queued', 'running'])->latest()->first();
        if ($running) {
            return response()->json(['data' => $running, 'message' => 'Un audit est déjà en cours pour ce site.'], 202);
        }

        $audit = WebsiteSecurityAudit::query()->create([
            'id' => (string) Str::uuid(),
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'agent_id' => $agent->id,
            'trigger' => 'manual',
            'status' => 'queued',
            'progress' => 0,
            'phase' => 'queued',
            'progress_message' => 'Audit placé dans la file d’attente.',
            'configuration_snapshot' => $this->configurations->effective($configuration),
        ]);
        $this->realtime->publish((string) $site->id, 'website_security_audit_queued', [
            'audit_id' => (string) $audit->id,
            'status' => 'queued',
            'progress' => 0,
            'phase' => 'queued',
            'progress_message' => (string) $audit->progress_message,
        ]);
        RunWebsiteSecurityAuditJob::dispatch((string) $audit->id);

        return response()->json(['data' => $audit], 202);
    }

    public function show(Request $request, Site $site, string $audit): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        return response()->json(['data' => WebsiteSecurityAudit::query()->where('site_id', $site->id)->findOrFail($audit)]);
    }

    public function destroy(Request $request, Site $site, string $audit): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $deletedAuditId = DB::transaction(function () use ($site, $audit): string {
            $record = WebsiteSecurityAudit::query()->where('site_id', $site->id)->whereKey($audit)->lockForUpdate()->firstOrFail();
            abort_unless(! in_array($record->status, ['queued', 'running'], true), 409, 'Un audit en cours ne peut pas être supprimé.');

            $isActiveBaseline = WebsiteSecurityBaseline::query()->where('site_id', $site->id)
                ->where('audit_id', $record->id)->where('is_active', true)->exists();
            abort_unless(! $isActiveBaseline, 409, 'Cet audit alimente la baseline active. Définissez une nouvelle baseline avant de le supprimer.');

            $record->delete();

            return (string) $record->id;
        });

        $this->realtime->publish((string) $site->id, 'website_security_audit_deleted', [
            'audit_id' => $deletedAuditId,
        ]);

        return response()->json(['data' => ['id' => $deletedAuditId]]);
    }

    public function currentBaseline(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $baseline = WebsiteSecurityBaseline::query()->where('site_id', $site->id)->where('is_active', true)->latest()->first();

        return response()->json(['data' => $baseline]);
    }

    public function establishBaseline(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $validated = $request->validate(['audit_id' => ['required', 'uuid']]);
        $baseline = DB::transaction(function () use ($site, $validated): WebsiteSecurityBaseline {
            $audit = WebsiteSecurityAudit::query()->where('site_id', $site->id)->whereKey($validated['audit_id'])
                ->lockForUpdate()->firstOrFail();
            abort_unless(in_array($audit->status, ['completed', 'partial'], true), 422, 'Une baseline nécessite un audit terminé.');
            abort_unless(count($audit->inventory ?? []) > 0, 422, 'Une baseline nécessite au moins une page observée.');
            $snapshot = [
                'findings' => $audit->findings ?? [],
                'inventory' => $audit->inventory ?? [],
                'scope_report' => $audit->scope_report ?? [],
            ];
            $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            WebsiteSecurityBaseline::query()->where('site_id', $site->id)->where('is_active', true)->update(['is_active' => false]);

            return WebsiteSecurityBaseline::query()->create([
                'id' => (string) Str::uuid(),
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'audit_id' => $audit->id,
                'snapshot' => $snapshot,
                'snapshot_hash' => hash('sha256', $encoded ?: '{}'),
                'is_active' => true,
            ]);
        });

        return response()->json(['data' => $baseline], 201);
    }

    public function sbomIndex(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $history = WebsiteSecurityManifest::query()->where('account_id', $site->account_id)->where('site_id', $site->id)
            ->latest('created_at')->limit(50)->get([
                'id', 'ecosystem', 'package_manager', 'lockfile_name', 'manifest_name', 'fingerprint', 'component_count', 'created_at',
            ]);

        return response()->json(['data' => $history]);
    }

    public function showSbom(Request $request, Site $site, string $manifest): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $record = WebsiteSecurityManifest::query()->where('account_id', $site->account_id)->where('site_id', $site->id)->findOrFail($manifest);

        return response()->json(['data' => $record]);
    }

    public function importSbom(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $agent = $this->securityAgentForSite($site);
        $validated = $request->validate([
            'lockfile' => ['required', 'file', 'max:5120'],
            'manifest' => ['nullable', 'file', 'max:1024'],
        ]);
        $lockfile = $validated['lockfile'];
        $manifest = $validated['manifest'] ?? null;
        $result = $this->manifests->import(
            $lockfile->getClientOriginalName(),
            $lockfile->get(),
            $manifest?->getClientOriginalName(),
            $manifest?->get(),
        );
        $record = WebsiteSecurityManifest::query()->create([
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'agent_id' => $agent->id,
            'ecosystem' => $result['ecosystem'],
            'package_manager' => $result['package_manager'],
            'lockfile_name' => $result['lockfile_name'],
            'manifest_name' => $result['manifest_name'],
            'fingerprint' => $result['fingerprint'],
            'component_count' => $result['component_count'],
            'components' => $result['components'],
            'cyclonedx' => $result['cyclonedx'],
        ]);

        return response()->json(['data' => $record], 201);
    }

    public function compareSbomSnapshots(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $this->securityAgentForSite($site);
        $validated = $request->validate(['from_id' => ['required', 'uuid'], 'to_id' => ['required', 'uuid', 'different:from_id']]);
        $baseQuery = WebsiteSecurityManifest::query()->where('account_id', $site->account_id)->where('site_id', $site->id);
        $from = (clone $baseQuery)->findOrFail($validated['from_id']);
        $to = (clone $baseQuery)->findOrFail($validated['to_id']);
        abort_unless($from->ecosystem === $to->ecosystem && $from->package_manager === $to->package_manager, 422, 'Comparez deux snapshots du même écosystème et package manager.');

        return response()->json([
            'data' => $this->sbomComparison->compare($to->components ?? [], $from->components ?? []),
            'meta' => ['from' => $from->only(['id', 'ecosystem', 'fingerprint', 'created_at']), 'to' => $to->only(['id', 'ecosystem', 'fingerprint', 'created_at'])],
        ]);
    }

    private function securityAgent(Site $site, string $agentId): McpAgent
    {
        return McpAgent::query()->where('site_id', $site->id)->whereKey($agentId)
            ->whereIn('template_key', ['website_security_auditor', 'website-security-auditor'])->firstOrFail();
    }

    private function securityAgentForSite(Site $site): McpAgent
    {
        return McpAgent::query()->where('site_id', $site->id)
            ->whereIn('template_key', ['website_security_auditor', 'website-security-auditor'])->firstOrFail();
    }
}
