<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\DevPilot\ApplyDevPilotChangesJob;
use App\Jobs\DevPilot\RunDevPilotTaskJob;
use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotTask;
use App\Models\DevPilot\DevPilotTerminalSession;
use App\Models\DevPilot\DevPilotWordPressSiteProfile;
use App\Models\Mcp\McpAgent;
use App\Models\Site;
use App\Services\DevPilot\DevPilotConnectionService;
use App\Services\DevPilot\DevPilotApprovalPolicy;
use App\Services\DevPilot\DevPilotCommandPolicy;
use App\Services\DevPilot\DevPilotApprovalPreview;
use App\Services\DevPilot\DevPilotEvidencePolicy;
use App\Services\DevPilot\DevPilotProposalFingerprint;
use App\Services\DevPilot\DevPilotQueueConfiguration;
use App\Services\DevPilot\DevPilotRealtimeService;
use App\Services\DevPilot\DevPilotSecretRedactor;
use App\Services\DevPilot\DevPilotTargetFingerprint;
use App\Services\DevPilot\DevPilotTaskRunner;
use App\Services\DevPilot\DevPilotTaskAttachmentService;
use App\Services\DevPilot\DevPilotWordPressSiteProfileService;
use App\Services\DevPilot\DevPilotWordPressUrl;
use App\Services\DevPilot\DevPilotWordPressBridgePairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

final class DevPilotController extends Controller
{
    use AuthorizesSiteAccess;

    private const DELETABLE_TASK_STATUSES = ['completed', 'partial', 'failed', 'cancelled', 'rejected', 'expired'];

    public function __construct(
        private readonly DevPilotConnectionService $connections,
        private readonly DevPilotSecretRedactor $redactor,
        private readonly DevPilotRealtimeService $realtime,
        private readonly DevPilotWordPressSiteProfileService $wordpressProfiles,
    ) {}

    public function projects(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $projects = DevPilotProject::query()->where('site_id', $site->id)->with([
            'connection:id,name,protocol,status,capabilities,last_tested_at',
            'databaseConnection:id,name,protocol,status',
            'wordpressProfile:id,site_id,name,canonical_site_url,rest_connection_id,file_connection_id,ssh_connection_id,rest_root_form',
        ])->withCount('tasks')->orderBy('name')->get();
        return response()->json(['data' => $projects]);
    }

    public function wordpressProfiles(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => DevPilotWordPressSiteProfile::query()
            ->where('site_id', $site->id)->orderBy('name')->get()]);
    }

    public function storeWordPressProfile(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $this->validatedWordPressProfile($request);
        try {
            return response()->json(['data' => $this->wordpressProfiles->create($site, $data)], 201);
        } catch (Throwable $exception) {
            return response()->json(['message' => $this->safeProfileError($exception)], 422);
        }
    }

    public function updateWordPressProfile(Request $request, Site $site, DevPilotWordPressSiteProfile $profile): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($profile->site_id === $site->id, 404);
        $data = $this->validatedWordPressProfile($request);
        try {
            return response()->json(['data' => $this->wordpressProfiles->update($profile, $data)]);
        } catch (Throwable $exception) {
            return response()->json(['message' => $this->safeProfileError($exception)], 422);
        }
    }

    public function destroyWordPressProfile(Request $request, Site $site, DevPilotWordPressSiteProfile $profile): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($profile->site_id === $site->id, 404);
        if ($profile->projects()->exists()) {
            return response()->json(['message' => 'Retirez ce profil des projets avant de le supprimer.'], 409);
        }
        $profile->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function storeProject(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $this->validatedProject($request);
        $project = DevPilotProject::create(['id' => (string) Str::uuid(), 'site_id' => $site->id, ...$data]);
        return response()->json(['data' => $project->load([
            'connection:id,name,protocol,status,capabilities', 'databaseConnection:id,name,protocol,status',
            'wordpressProfile:id,site_id,name,canonical_site_url,rest_connection_id,file_connection_id,ssh_connection_id,rest_root_form',
        ])], 201);
    }

    public function updateProject(Request $request, Site $site, DevPilotProject $project): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id, 404);
        $project->update($this->validatedProject($request));
        return response()->json(['data' => $project->fresh()->load([
            'connection:id,name,protocol,status,capabilities', 'databaseConnection:id,name,protocol,status',
            'wordpressProfile:id,site_id,name,canonical_site_url,rest_connection_id,file_connection_id,ssh_connection_id,rest_root_form',
        ])]);
    }

    public function destroyProject(Request $request, Site $site, DevPilotProject $project): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id, 404);
        $project->tasks()->pluck('id')->each(fn ($taskId) => app(DevPilotTaskAttachmentService::class)->deleteForTask((string) $taskId));
        $project->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function connectionIndex(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => DevPilotConnection::query()->where('site_id', $site->id)->withCount('projects')->orderBy('name')->get()]);
    }

    public function pairWordPressBridge(Request $request, Site $site, DevPilotWordPressBridgePairingService $pairing): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'site_url' => ['required', 'string', 'max:2048'],
            'username' => ['required', 'string', 'max:128'],
            'application_password' => ['required', 'string', 'max:4096'],
            'pairing_code' => ['required', 'string', 'max:256'],
            'capabilities' => ['required', 'array', 'min:1', 'max:50'],
            'capabilities.*' => ['required', 'string', Rule::in(['content.read', 'content.search', 'content.create', 'content.update', 'content.update.title', 'content.update.slug', 'content.update.excerpt', 'content.update.body', 'content.status.change', 'content.publish', 'content.featured_media', 'content.trash', 'media.upload', 'media.read', 'content.featured_media.remove', 'content.restore', 'content.taxonomy.assign', 'content.taxonomy.create', 'content.schedule', 'content.search.unpublished', 'media.search', 'media.update_metadata', 'content.visibility.private', 'content.password', 'content.author.change', 'content.discussion.manage', 'content.page.structure', 'content.seo.manage', 'content.revisions.read', 'content.revisions.restore', 'plugins.read', 'plugins.updates.read', 'plugins.directory.read', 'plugins.integrity.read', 'site.health.read', 'plugins.activate', 'plugins.deactivate', 'plugins.network_toggle', 'plugins.install_directory', 'plugins.update', 'plugins.update_bulk', 'plugins.rollback', 'plugins.autoupdate', 'plugins.delete', 'plugins.install_zip', 'plugins.settings.read', 'plugins.settings.write', 'plugins.maintenance', 'plugins.snapshot.create', 'plugins.snapshot.restore'])],
            'private_network_authorized' => ['required', 'boolean'],
        ]);
        try {
            $connection = $pairing->pair($site, $data);
            return response()->json([
                'data' => $this->connections->safeConnection($connection),
                'message' => $connection->last_test_result['error'] ?? null,
            ], $connection->status === 'pairing_uncertain' ? 202 : 201);
        } catch (Throwable $exception) {
            $message = $this->connections->failureMessage($exception);
            $this->connections->logFailure('pair_wordpress_bridge', 'wordpress', $exception, $message);
            return response()->json(['message' => $message], 422);
        }
    }

    public function storeConnection(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $this->validatedConnection($request, false, allowInsecureWordPressProbe: true);
        try {
            $connection = $this->connections->create($site, $data);
            return response()->json(['data' => $connection], 201);
        } catch (Throwable $exception) {
            $message = $this->connections->failureMessage($exception);
            $this->connections->logFailure('store_connection', $data['protocol'] ?? null, $exception, $message);
            return response()->json(['message' => $message], 422);
        }
    }

    public function updateConnection(Request $request, Site $site, DevPilotConnection $connection): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($connection->site_id === $site->id, 404);
        if ($connection->auth_method === 'devpilot_bridge_hmac') {
            return response()->json(['message' => 'Un canal ELChat-DevPilot jumelé ne peut pas être modifié comme une connexion WordPress REST classique.'], 409);
        }
        $data = $this->validatedConnection($request, true, $connection);
        try {
            $saved = $this->connections->update($connection, $data);
            return response()->json(['data' => $saved]);
        } catch (Throwable $exception) {
            $message = $this->connections->failureMessage($exception);
            $this->connections->logFailure('update_connection', $data['protocol'] ?? null, $exception, $message);
            return response()->json(['message' => $message], 422);
        }
    }

    public function testConnectionInput(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $this->validatedConnection($request, false);
        try {
            return response()->json(['data' => $this->connections->testInput($data)]);
        } catch (Throwable $exception) {
            $message = $this->connections->failureMessage($exception);
            $this->connections->logFailure('test_connection_input', $data['protocol'] ?? null, $exception, $message);
            return response()->json(['data' => ['connected' => false, 'protocol' => $data['protocol'] ?? null, 'error' => $message]], 422);
        }
    }

    public function testConnection(Request $request, Site $site, DevPilotConnection $connection): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($connection->site_id === $site->id, 404);
        try {
            return response()->json(['data' => $this->connections->test($connection)]);
        } catch (Throwable $exception) {
            $message = $this->connections->failureMessage($exception);
            $result = $connection->fresh()->last_test_result;
            return response()->json(['data' => $result, 'message' => $result['error'] ?? $message], 422);
        }
    }

    public function destroyConnection(Request $request, Site $site, DevPilotConnection $connection): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($connection->site_id === $site->id, 404);
        $usedByProfile = DevPilotWordPressSiteProfile::query()->where(function ($query) use ($connection): void {
            $query->where('rest_connection_id', $connection->id)
                ->orWhere('file_connection_id', $connection->id)
                ->orWhere('ssh_connection_id', $connection->id);
        })->exists();
        if ($usedByProfile) {
            return response()->json(['message' => 'Retirez cette connexion des profils WordPress avant de la supprimer.'], 409);
        }
        $connection->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function tasks(Request $request, Site $site, DevPilotProject $project): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id, 404);
        $tasks = $project->tasks()->latest()->limit(100)->get()->map(fn (DevPilotTask $task) => $this->publicTask($task));
        return response()->json(['data' => $tasks]);
    }

    public function destroyTask(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id, 404);
        $deleted = $this->deleteHistoryTasks($site, $project, [(string) $task->id]);

        return response()->json(['status' => 'deleted', 'deleted' => $deleted]);
    }

    public function destroyTasks(Request $request, Site $site, DevPilotProject $project): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id, 404);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $ids = array_map('strval', $data['ids']);

        return response()->json([
            'status' => 'deleted',
            'deleted' => $this->deleteHistoryTasks($site, $project, $ids),
        ]);
    }

    /** Delete only completed history, serialized with task creation and execution for this project. */
    private function deleteHistoryTasks(Site $site, DevPilotProject $project, array $ids): int
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids, SORT_STRING);

        return DB::transaction(function () use ($site, $project, $ids): int {
            // Keep the same lock order as storeTask: tenant, project, then tasks.
            Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            DevPilotProject::query()->where('site_id', $site->id)->lockForUpdate()->findOrFail($project->id);

            $tasks = DevPilotTask::query()
                ->where('site_id', $site->id)
                ->where('project_id', $project->id)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            abort_unless($tasks->count() === count($ids), 404, 'Une ou plusieurs tâches ne font pas partie de ce projet.');
            abort_if(
                $tasks->contains(fn (DevPilotTask $task): bool => ! in_array($task->status, self::DELETABLE_TASK_STATUSES, true)),
                409,
                'Les tâches en cours ou en attente ne peuvent pas être supprimées de l’historique.',
            );

            $tasks->each(function (DevPilotTask $task): void {
                app(DevPilotTaskAttachmentService::class)->deleteForTask((string) $task->id);
                $task->delete();
            });

            return $tasks->count();
        }, 5);
    }

    public function storeTask(Request $request, Site $site, DevPilotProject $project): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id, 404);
        $data = $request->validate([
            'instruction' => ['required', 'string', 'min:3', 'max:12000'],
            'agent_id' => ['required', 'uuid'],
            'attachments' => ['sometimes', 'array', 'max:8'],
            'attachments.*' => ['file', 'max:10240'],
        ]);
        $uploads = array_values(array_filter($request->file('attachments', [])));
        if (config('queue.default') === 'sync') return response()->json(['message' => 'DevPilot a besoin d’un worker de queue asynchrone. Configurez QUEUE_CONNECTION=database ou redis et démarrez un worker DevPilot.'], 503);
        $queueConnection = (string) config('queue.default');
        $commandMaximumSeconds = (int) config('devpilot.command_max_seconds', 1800);
        $minimumRetryAfter = DevPilotQueueConfiguration::minimumRetryAfter($commandMaximumSeconds);
        $retryAfter = config('queue.connections.'.$queueConnection.'.retry_after');
        if (! DevPilotQueueConfiguration::hasSufficientRetryAfter($retryAfter, $commandMaximumSeconds)) {
            return response()->json([
                'message' => 'La connexion de file « '.$queueConnection.' » doit définir retry_after à au moins '.$minimumRetryAfter.' secondes pour DevPilot (durée maximale d’une commande et marge de sécurité).',
            ], 503);
        }
        $wordpressProfile = $project->wordpress_profile_id !== null
            ? $project->wordpressProfile()->with('restConnection')->first()
            : null;
        $restConnection = $wordpressProfile?->restConnection;
        $restRootForm = $wordpressProfile?->rest_root_form;
        $hasVerifiedRest = $restConnection !== null
            && $restConnection->site_id === $site->id
            && $restConnection->protocol === 'wordpress'
            && $restConnection->status === 'connected'
            && $restConnection->can_read
            && ($restConnection->capabilities['rest_api'] ?? false) === true
            && in_array($restRootForm, ['wp-json', 'rest_route'], true)
            && ($restConnection->capabilities['rest_root_form'] ?? null) === $restRootForm;
        abort_unless(
            ($project->connection_id && $project->connection?->status === 'connected') || $hasVerifiedRest,
            422,
            'Associez une connexion distante vérifiée ou un canal REST WordPress vérifié à ce projet.',
        );
        $agent = McpAgent::query()->where('site_id', $site->id)->whereKey($data['agent_id'])->where('template_key', 'devpilot')->firstOrFail();
        abort_unless($agent->is_active, 409, 'Activez ELChat DevPilot avant de lancer une tâche.');
        $instruction = $this->redactor->redact(trim($data['instruction']));
        $task = null;
        $eventId = null;
        $storedAttachmentIds = [];
        try {
        DB::transaction(function () use ($request, $site, $project, $agent, $instruction, $uploads, &$task, &$eventId, &$storedAttachmentIds): void {
            // Serialize tenant quota checks first; the locked project row serializes same-project creation.
            Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $lockedProject = DevPilotProject::query()->where('site_id', $site->id)->lockForUpdate()->findOrFail($project->id);
            $projectActiveStatuses = ['queued', 'running', 'applying', 'executing', 'watchdog_recovering'];
            abort_if(
                DevPilotTask::query()->where('site_id', $site->id)->where('project_id', $lockedProject->id)
                    ->whereIn('status', $projectActiveStatuses)->exists(),
                409,
                'Une tâche est déjà active sur ce projet. Attendez sa fin ou son approbation avant d’en créer une autre.',
            );

            $siteActiveStatuses = [...$projectActiveStatuses, 'awaiting_input', 'awaiting_approval'];
            $siteTaskCount = DevPilotTask::query()->where('site_id', $site->id)->whereIn('status', $siteActiveStatuses)->count();
            abort_if($siteTaskCount >= (int) config('devpilot.concurrent_tasks_per_site', 3), 429, 'La limite de tâches actives de ce site est atteinte.');

            $currentProject = DevPilotProject::query()->where('site_id', $site->id)
                ->with(['connection', 'databaseConnection', 'wordpressProfile.restConnection'])->findOrFail($lockedProject->id);
            $verifiedRest = $currentProject->wordpressProfile?->restConnection;
            $hasCurrentRest = $verifiedRest
                && $verifiedRest->site_id === $site->id
                && $verifiedRest->protocol === 'wordpress'
                && $verifiedRest->status === 'connected'
                && $verifiedRest->can_read
                && ($verifiedRest->capabilities['rest_api'] ?? false) === true
                && in_array($currentProject->wordpressProfile?->rest_root_form, ['wp-json', 'rest_route'], true)
                && ($verifiedRest->capabilities['rest_root_form'] ?? null) === $currentProject->wordpressProfile?->rest_root_form;
            abort_unless(
                ($currentProject->connection_id && $currentProject->connection?->status === 'connected') || $hasCurrentRest,
                422,
                'Associez une connexion distante vérifiée ou un canal REST WordPress vérifié à ce projet.',
            );
            $task = DevPilotTask::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id, 'project_id' => $currentProject->id,
                'agent_id' => $agent->id, 'connection_id' => $currentProject->connection_id,
                'connection_protocol' => $currentProject->connection?->protocol, 'connection_name' => $currentProject->connection?->name,
                'target_snapshot_hash' => DevPilotTargetFingerprint::forProject($currentProject),
                'project_environment' => $currentProject->environment, 'project_root' => $currentProject->root_path,
                'requested_by' => (string) $request->user()?->id,
                'instruction' => $instruction, 'status' => 'queued', 'progress' => 0,
            ]);
            $attachments = app(DevPilotTaskAttachmentService::class)->storeForTask($task, $uploads);
            $storedAttachmentIds = array_map(static fn ($attachment): string => (string) $attachment->id, $attachments);
            $eventId = $task->events()->create([
                'kind' => 'queued', 'message' => 'Tâche mise en file d’attente.',
                'details' => ['environment' => $currentProject->environment], 'created_at' => now(),
            ])->getKey();
            RunDevPilotTaskJob::dispatch((string) $task->id)->afterCommit();
        }, 5);
        } catch (Throwable $exception) {
            app(DevPilotTaskAttachmentService::class)->deleteByIds($storedAttachmentIds);
            if ($task?->id) app(DevPilotTaskAttachmentService::class)->deleteForTask((string) $task->id);
            throw $exception;
        }

        $task->refresh();
        $this->realtime->publish($task, 'queued', 'Tâche mise en file d’attente.', [], $eventId);
        return response()->json(['data' => $this->publicTask($task->fresh()->load('attachments'))], 202);
    }

    public function showTask(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id && $task->site_id === $site->id && $task->project_id === $project->id, 404);
        $payload = $this->publicTask($task->load(['events', 'messages', 'attachments']));
        $terminalSession = DevPilotTerminalSession::query()
            ->where('site_id', $site->id)->where('project_id', $project->id)->where('task_id', $task->id)
            ->latest('created_at')->first();
        if ($terminalSession) {
            $terminalOutput = '';
            if ($terminalSession->output_encrypted) {
                try { $terminalOutput = Crypt::decryptString($terminalSession->output_encrypted); } catch (Throwable) { $terminalOutput = '[Sortie du terminal indisponible]'; }
            }
            $payload['terminal_session'] = [
                'id' => (string) $terminalSession->id,
                'status' => (string) $terminalSession->status,
                'active' => $task->status === 'executing' && in_array($terminalSession->status, ['starting', 'running'], true),
                'output' => mb_strcut($terminalOutput, 0, (int) config('devpilot.command_output_limit_bytes', 65536)),
                'updated_at' => $terminalSession->updated_at?->toISOString(),
            ];
        }
        if ($task->status === 'awaiting_approval' && $task->proposed_changes_encrypted) {
            $decrypted = app(\App\Domain\MCP\Security\CredentialVault::class)->open($task->proposed_changes_encrypted);
            abort_unless(is_array($decrypted), 409, 'La proposition ne peut pas être déchiffrée. Relancez la tâche avant toute approbation.');
            $project->loadMissing(['connection', 'databaseConnection', 'wordpressProfile']);
            abort_unless(hash_equals((string) $task->target_snapshot_hash, DevPilotTargetFingerprint::forProject($project)),
                409, 'Le projet, la connexion ou les permissions ont changé depuis la préparation; relancez la tâche.');
            $proposedChanges = DevPilotApprovalPreview::changes($task, $decrypted);
            abort_unless($proposedChanges !== null,
                409, 'L’empreinte des actions ne correspond plus au contenu de la proposition. Relancez la tâche.');
            $payload['proposed_changes'] = $proposedChanges;
            $commandMode = DevPilotCommandPolicy::executionMode($project->connection?->command_execution_mode, (bool) $project->connection?->can_run_commands);
            $payload['proposed_operations'] = array_map(static function ($operation) use ($project, $commandMode): array {
                $authorization = ($operation['type'] ?? null) === 'run_command'
                    ? DevPilotCommandPolicy::decide((string) ($operation['command'] ?? ''), $commandMode, $project->connection?->allowed_commands ?? [])
                    : ['critical_confirmation_required' => ($operation['critical_confirmation_required'] ?? true) === true];
                $keys = ['type', 'command', 'argv_display', 'subcommand', 'working_directory', 'database_name', 'database_protocol', 'database_connection_id', 'database_connection_name', 'database_host', 'database_port', 'risk', 'execution_user', 'execution_user_verified', 'summary', 'authorization_reason', 'automatic_rule', 'wordpress_type', 'id', 'setting_name', 'option_name', 'post_id', 'old_value', 'new_value', 'plugin_slug', 'plugin_action', 'theme_slug', 'theme_action', 'state_action', 'cache_command', 'display_diff', 'warnings', 'verification', 'public_verification', 'connector_slug', 'tool_name', 'effect', 'display_fields', 'media_previews'];
                if (($operation['type'] ?? null) === 'connector_action') $keys = array_diff($keys, ['verification']);
                return array_intersect_key($operation, array_flip($keys))
                    + ['summary_origin' => 'ai_unverified', 'approval_required' => true, 'critical_confirmation_required' => (bool) ($authorization['critical_confirmation_required'] ?? false)];
            }, $decrypted['operations'] ?? []);
            $payload['proposed_operations'] = [...$payload['proposed_operations'], ...array_map(static function ($operation) use ($project, $commandMode): array {
                $authorization = ($operation['type'] ?? null) === 'connector_action'
                    ? ['critical_confirmation_required' => ($operation['critical_confirmation_required'] ?? true) === true]
                    : DevPilotCommandPolicy::decide((string) ($operation['command'] ?? ''), $commandMode, $project->connection?->allowed_commands ?? []);
                $keys = ['type', 'command', 'working_directory', 'database_name', 'database_protocol', 'database_connection_id', 'database_connection_name', 'database_host', 'database_port', 'risk', 'execution_user', 'execution_user_verified', 'summary', 'authorization_reason', 'automatic_rule', 'connector_slug', 'tool_name', 'effect', 'display_fields'];
                return array_intersect_key($operation, array_flip($keys))
                    + ['summary_origin' => 'ai_unverified', 'approval_required' => false, 'critical_confirmation_required' => (bool) ($authorization['critical_confirmation_required'] ?? false)];
            }, $decrypted['automatic_operations'] ?? [])];
            $confirmationRequired = collect($payload['proposed_operations'])->contains(static fn ($operation): bool => ($operation['critical_confirmation_required'] ?? false) === true)
                || collect($payload['proposed_changes'])->contains(static fn ($change): bool => ($change['action'] ?? '') === 'delete')
                || DevPilotApprovalPolicy::requiresReinforcedConfirmation(
                    (string) $project->environment,
                    $decrypted['changes'] ?? [],
                    $decrypted['operations'] ?? [],
                    $decrypted['automatic_operations'] ?? [],
                    $project,
                );
            $confirmationPhrase = $confirmationRequired
                ? DevPilotProposalFingerprint::confirmationPhrase((string) $task->proposal_hash)
                : null;
            if ($confirmationPhrase !== null) $payload['approval_confirmation_phrase'] = $confirmationPhrase;
            $payload['approval_preview'] = DevPilotApprovalPreview::generic(
                $task,
                $project,
                $payload['proposed_changes'],
                $payload['proposed_operations'],
                $confirmationPhrase,
                is_array($decrypted['automatic_operations'] ?? null) && $decrypted['automatic_operations'] !== [],
            );
            $payload['approval_context'] = [
                'site_id' => (string) $site->id, 'project_id' => (string) $project->id, 'task_id' => (string) $task->id,
                'connection_id' => (string) $project->connection_id,
                'connection_name' => $project->connection?->name,
                'host' => $project->connection?->host,
                'execution_user' => $project->connection?->username,
                'project_root' => (string) $project->root_path,
                'environment' => (string) $project->environment,
                'target_snapshot_hash' => (string) $task->target_snapshot_hash,
            ];
        }
        return response()->json(['data' => $payload]);
    }

    /** Queue exactly one encrypted input line for the active SSH PTY bound to this task. */
    public function terminalInput(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task, string $session): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id && $task->site_id === $site->id && $task->project_id === $project->id, 404);
        $data = $request->validate([
            'input' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n\x00-\x1F\x7F]/u'],
        ]);
        abort_if(trim($data['input']) === '', 422, 'Saisissez une réponse terminale non vide sur une seule ligne.');

        $sequence = null;
        DB::transaction(function () use ($site, $project, $task, $session, $data, &$sequence): void {
            $lockedTask = DevPilotTask::query()->where('site_id', $site->id)->where('project_id', $project->id)
                ->lockForUpdate()->findOrFail($task->id);
            abort_unless($lockedTask->status === 'executing' && ! $lockedTask->cancel_requested, 409, 'La tâche n’attend plus une réponse de terminal.');
            abort_unless($lockedTask->agent()->where('site_id', $site->id)->where('is_active', true)->exists(), 409, 'Activez ELChat DevPilot avant de répondre à la session active.');
            $currentProject = DevPilotProject::query()->where('site_id', $site->id)
                ->with(['connection', 'databaseConnection'])->lockForUpdate()->findOrFail($project->id);
            abort_unless(hash_equals((string) $lockedTask->target_snapshot_hash, DevPilotTargetFingerprint::forProject($currentProject)), 409, 'Le projet, la connexion ou ses permissions ont changé pendant la session. Annulez-la et relancez la tâche après vérification.');
            $locked = DevPilotTerminalSession::query()
                ->where('site_id', $site->id)->where('project_id', $project->id)->where('task_id', $task->id)
                ->lockForUpdate()->findOrFail($session);
            abort_unless($locked->status === 'running', 409, 'Cette session de terminal n’est plus active.');
            abort_if($locked->input_sequence > $locked->consumed_input_sequence, 409, 'La réponse précédente est encore en cours de transmission.');

            $sequence = (int) $locked->input_sequence + 1;
            $locked->forceFill([
                'pending_input_encrypted' => Crypt::encryptString($data['input']),
                'input_sequence' => $sequence,
            ])->save();
        }, 3);

        try {
            $this->realtime->publish($task, 'terminal_input_queued', 'Réponse reçue; transmission à la session de terminal en cours.', [
                'terminal_session_id' => $session, 'terminal_state' => 'running',
            ]);
        } catch (Throwable) {
            // The encrypted input is already queued; live updates are best-effort.
        }

        return response()->json(['data' => ['session_id' => $session, 'accepted' => true, 'sequence' => $sequence]], 202);
    }

    public function replyTask(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id && $task->site_id === $site->id && $task->project_id === $project->id, 404);
        if (config('queue.default') === 'sync') return response()->json(['message' => 'DevPilot a besoin d’un worker de queue asynchrone.'], 503);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:12000'],
            'attachments' => ['sometimes', 'array', 'max:8'],
            'attachments.*' => ['file', 'max:10240'],
        ]);
        $uploads = array_values(array_filter($request->file('attachments', [])));
        $message = $this->redactor->redact(trim($data['message']));
        abort_if($message === '', 422, 'La réponse ne peut pas être vide après le masquage des données sensibles.');
        $eventId = null;
        $storedAttachmentIds = [];

        try {
        DB::transaction(function () use ($request, $site, $project, $task, $message, $uploads, &$eventId, &$storedAttachmentIds): void {
            $locked = DevPilotTask::query()->where('site_id', $site->id)->where('project_id', $project->id)
                ->lockForUpdate()->findOrFail($task->id);
            abort_unless($locked->status === 'awaiting_input', 409, 'Cette tâche n’attend pas de réponse. Actualisez la conversation.');
            abort_unless($locked->agent()->where('site_id', $site->id)->where('is_active', true)->exists(), 409, 'Activez ELChat DevPilot avant de reprendre cette tâche.');
            $currentProject = DevPilotProject::query()->where('site_id', $site->id)
                ->with(['connection', 'databaseConnection'])->findOrFail($project->id);
            abort_unless(! $currentProject->connection_id || $currentProject->connection?->status === 'connected', 409, 'Vérifiez la connexion distante avant de reprendre cette tâche.');
            $currentTargetHash = DevPilotTargetFingerprint::forProject($currentProject);
            $targetChanged = ! hash_equals((string) $locked->target_snapshot_hash, $currentTargetHash);
            $plan = is_array($locked->plan) ? $locked->plan : [];
            $plan['clarification_pending'] = false;
            $plan['target_changed_after_user_pause'] = $targetChanged;
            if ($targetChanged) {
                $plan['execution_history'] = is_array($plan['execution_history'] ?? null) ? $plan['execution_history'] : [];
                DevPilotEvidencePolicy::appendHistory($plan, 'event', [
                    'kind' => 'environment_change', 'cycle' => max(1, (int) ($plan['goal_cycle'] ?? 1)),
                    'target' => 'Projet ou connexion reconfiguré pendant l’attente utilisateur.',
                    'status' => 'reconfigured_after_user_input',
                    'evidence_kind' => 'user_pause', 'mutating' => false,
                    'facts' => ['status' => 'target_changed', 'complete' => true],
                    'message' => 'Les preuves et opérations antérieures peuvent viser l’ancienne configuration. Vérifier l’état courant avant de poursuivre.',
                ], $currentTargetHash);
            }

            $locked->messages()->create([
                'role' => 'user', 'created_by' => (string) $request->user()?->id,
                'content' => $message, 'created_at' => now(),
            ]);
            $attachments = app(DevPilotTaskAttachmentService::class)->storeForTask($locked, $uploads);
            $storedAttachmentIds = array_map(static fn ($attachment): string => (string) $attachment->id, $attachments);
            $locked->forceFill([
                'status' => 'queued', 'progress' => 0, 'proposed_changes_encrypted' => null,
                'proposal_hash' => null, 'proposal_expires_at' => null, 'error_code' => null,
                'cancel_requested' => false, 'approved_by' => null, 'approved_at' => null,
                'started_at' => null, 'completed_at' => null,
                'plan' => $plan,
                'connection_id' => $currentProject->connection_id,
                'connection_protocol' => $currentProject->connection?->protocol,
                'connection_name' => $currentProject->connection?->name,
                'project_environment' => $currentProject->environment,
                'project_root' => $currentProject->root_path,
                'target_snapshot_hash' => $currentTargetHash,
            ])->save();
            $eventId = $locked->events()->create([
                'kind' => 'user_reply', 'message' => 'Réponse reçue; DevPilot reprend la tâche.',
                'created_at' => now(),
            ])->getKey();

            RunDevPilotTaskJob::dispatch((string) $locked->id)->afterCommit();
        }, 5);
        } catch (Throwable $exception) {
            app(DevPilotTaskAttachmentService::class)->deleteByIds($storedAttachmentIds);
            throw $exception;
        }

        $task->refresh();
        $this->realtime->publish($task, 'user_reply', 'Réponse reçue; DevPilot reprend la tâche.', [], $eventId);
        return response()->json(['data' => $this->publicTask($task->load(['messages', 'attachments']))], 202);
    }

    public function approveTask(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id && $task->site_id === $site->id && $task->project_id === $project->id, 404);
        $data = $request->validate([
            'proposal_hash' => ['required', 'string', 'size:64'],
            'confirmation_phrase' => ['sometimes', 'string', 'max:64'],
        ]);
        abort_unless($task->agent()->where('site_id', $site->id)->where('is_active', true)->exists(), 409, 'Activez ELChat DevPilot avant d’approuver une opération distante.');
        $eventId = null;
        $expired = false;
        DB::transaction(function () use ($request, $site, $project, $task, $data, &$eventId, &$expired): void {
            $lockedProject = DevPilotProject::query()->where('site_id', $site->id)
                ->with(['connection', 'databaseConnection'])->lockForUpdate()->findOrFail($project->id);
            $locked = DevPilotTask::query()->where('site_id', $site->id)->where('project_id', $project->id)
                ->lockForUpdate()->findOrFail($task->id);
            if ($locked->status !== 'awaiting_approval' || ! hash_equals((string) $locked->proposal_hash, $data['proposal_hash'])) abort(409, 'La proposition a changé ou n’attend plus de validation.');
            $expiresAt = $locked->proposal_expires_at ?? $locked->created_at?->copy()->addHours((int) config('devpilot.proposal_expiration_hours', 24));
            if ($expiresAt && $expiresAt->isPast()) {
                $message = 'La proposition a expiré; aucune opération n’a été exécutée. Relancez la tâche pour obtenir un plan à jour.';
                $locked->forceFill([
                    'status' => 'expired', 'proposal_expires_at' => null,
                    'proposed_changes_encrypted' => null, 'proposal_hash' => null,
                    'completed_at' => now(), 'result' => array_merge($locked->result ?? [], ['summary' => $message]),
                ])->save();
                $eventId = $locked->events()->create(['kind' => 'expired', 'message' => $message, 'created_at' => now()])->getKey();
                $expired = true;
                return;
            }
            $decrypted = app(\App\Domain\MCP\Security\CredentialVault::class)->open((string) $locked->proposed_changes_encrypted);
            abort_unless(is_array($decrypted) && is_array($decrypted['changes'] ?? null)
                && is_array($decrypted['operations'] ?? null) && is_array($decrypted['automatic_operations'] ?? null),
                409, 'La proposition est illisible et ne peut pas être approuvée.');
            abort_unless(hash_equals((string) $locked->target_snapshot_hash, DevPilotTargetFingerprint::forProject($lockedProject)),
                409, 'Le projet, la connexion ou les permissions ont changé depuis la préparation; relancez la tâche.');
            abort_unless(hash_equals((string) $locked->proposal_hash, DevPilotProposalFingerprint::forTask($locked, $decrypted)),
                409, 'L’empreinte de l’action proposée ne correspond plus; aucune opération ne peut être approuvée.');

            $commandMode = DevPilotCommandPolicy::executionMode($lockedProject->connection?->command_execution_mode, (bool) $lockedProject->connection?->can_run_commands);
            $requiresAdminConfirmation = collect($decrypted['operations'])->contains(function ($operation) use ($lockedProject, $commandMode): bool {
                if (! is_array($operation)) return true;
                if (($operation['type'] ?? null) === 'create_database') return true;
                if (str_starts_with((string) ($operation['type'] ?? ''), 'wordpress_')) return ($operation['critical_confirmation_required'] ?? false) === true;
                if (($operation['type'] ?? null) !== 'run_command') return true;
                $decision = DevPilotCommandPolicy::decide((string) ($operation['command'] ?? ''), $commandMode, $lockedProject->connection?->allowed_commands ?? []);
                abort_if($decision['decision'] === 'blocked', 409, 'La politique d’exécution a changé; la commande doit être replifiée.');
                return $decision['critical_confirmation_required'];
            }) || collect($decrypted['automatic_operations'])->contains(function ($operation) use ($lockedProject, $commandMode): bool {
                if (! is_array($operation)) return true;
                if (($operation['type'] ?? null) === 'connector_action') return ($operation['critical_confirmation_required'] ?? true) === true;
                if (($operation['type'] ?? null) !== 'run_command') return true;
                $decision = DevPilotCommandPolicy::decide((string) ($operation['command'] ?? ''), $commandMode, $lockedProject->connection?->allowed_commands ?? []);
                abort_if($decision['decision'] !== 'automatic', 409, 'Une commande automatique n’est plus couverte par une règle d’exécution automatique.');
                return $decision['critical_confirmation_required'];
            }) || collect($decrypted['changes'])->contains(static fn ($change): bool => is_array($change) && ($change['action'] ?? '') === 'delete');
            $requiresAdminConfirmation = $requiresAdminConfirmation || DevPilotApprovalPolicy::requiresReinforcedConfirmation(
                (string) $lockedProject->environment,
                $decrypted['changes'],
                $decrypted['operations'],
                $decrypted['automatic_operations'],
                $lockedProject,
            );
            $expectedPhrase = DevPilotProposalFingerprint::confirmationPhrase((string) $locked->proposal_hash);
            abort_if($requiresAdminConfirmation && ! hash_equals($expectedPhrase, (string) ($data['confirmation_phrase'] ?? '')),
                422, 'Confirmation renforcée requise : saisissez la phrase affichée pour cette proposition précise.');
            $locked->forceFill(['status' => 'applying', 'approved_by' => (string) $request->user()?->id, 'approved_at' => now(), 'proposal_expires_at' => null, 'progress' => 5])->save();
            $eventId = $locked->events()->create([
                'kind' => 'approved', 'message' => 'La proposition exacte a été approuvée par un membre autorisé.',
                'details' => ['proposal_hash' => $data['proposal_hash'], 'approved_by' => (string) $request->user()?->id],
                'created_at' => now(),
            ])->getKey();
            ApplyDevPilotChangesJob::dispatch((string) $locked->id, $data['proposal_hash'])->afterCommit();
        }, 5);
        $task->refresh();
        if ($expired) {
            $message = 'La proposition a expiré; aucune opération n’a été exécutée. Relancez la tâche pour obtenir un plan à jour.';
            $this->realtime->publish($task, 'expired', $message, [], $eventId);
            return response()->json(['data' => $this->publicTask($task->fresh()), 'message' => $message], 410);
        }
        $this->realtime->publish($task, 'approved', 'La proposition a été approuvée; préparation de son application.', [], $eventId);
        return response()->json(['data' => $this->publicTask($task->fresh())], 202);
    }

    public function rejectTask(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id && $task->site_id === $site->id && $task->project_id === $project->id, 404);
        $eventId = null;
        DB::transaction(function () use ($site, $project, $task, &$eventId): void {
            $locked = DevPilotTask::query()->where('site_id', $site->id)->where('project_id', $project->id)
                ->lockForUpdate()->findOrFail($task->id);
            abort_unless($locked->status === 'awaiting_approval', 409);
            $message = 'Proposition refusée; aucun fichier ni opération distante n’a été exécuté.';
            $locked->forceFill(['status' => 'rejected', 'proposed_changes_encrypted' => null, 'proposal_hash' => null, 'proposal_expires_at' => null, 'completed_at' => now(), 'result' => array_merge($locked->result ?? [], ['summary' => $message])])->save();
            $eventId = $locked->events()->create(['kind' => 'rejected', 'message' => $message, 'created_at' => now()])->getKey();
        }, 5);
        $task->refresh();
        $this->realtime->publish($task, 'rejected', 'Proposition refusée; aucun fichier ni opération distante n’a été exécuté.', [], $eventId);
        return response()->json(['data' => $this->publicTask($task)]);
    }

    public function cancelTask(Request $request, Site $site, DevPilotProject $project, DevPilotTask $task): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        abort_unless($project->site_id === $site->id && $task->site_id === $site->id && $task->project_id === $project->id, 404);
        $eventId = null;
        $cancelled = false;
        $eventKind = 'cancel_requested';
        $cancelMessage = 'Annulation demandée.';
        DB::transaction(function () use ($site, $project, $task, &$eventId, &$cancelled, &$eventKind, &$cancelMessage): void {
            $locked = DevPilotTask::query()->where('site_id', $site->id)->where('project_id', $project->id)
                ->lockForUpdate()->findOrFail($task->id);
            if (! in_array($locked->status, ['queued', 'running', 'applying', 'executing', 'awaiting_input', 'awaiting_approval'], true)) return;
            $waiting = in_array($locked->status, ['awaiting_input', 'awaiting_approval'], true);
            $immediate = $waiting || $locked->status === 'queued';
            $locked->forceFill(['cancel_requested' => true]);
            if ($immediate) {
                $locked->forceFill([
                    'status' => 'cancelled', 'completed_at' => now(), 'proposal_expires_at' => null,
                    'proposed_changes_encrypted' => null, 'proposal_hash' => null,
                ]);
                $eventKind = 'cancelled';
                $cancelMessage = $waiting ? 'Tâche annulée; toute proposition en attente a été supprimée.' : 'Tâche annulée.';
                if ($waiting) $locked->forceFill(['result' => array_merge($locked->result ?? [], ['summary' => $cancelMessage])]);
            }
            $locked->save();
            $cancelMessage = $waiting ? 'Tâche annulée; toute proposition en attente a été supprimée.' : ($immediate ? 'Tâche annulée.' : 'Annulation demandée.');
            $eventId = $locked->events()->create(['kind' => $eventKind, 'message' => $cancelMessage, 'created_at' => now()])->getKey();
            $cancelled = true;
        }, 5);
        $task->refresh();
        if ($cancelled) $this->realtime->publish($task, $eventKind, $cancelMessage, [], $eventId);
        return response()->json(['data' => $this->publicTask($task->fresh())]);
    }

    private function validatedProject(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'connection_id' => ['nullable', 'uuid'],
            'wordpress_profile_id' => ['nullable', 'uuid'],
            'database_connection_id' => ['nullable', 'uuid'],
            'environment' => ['required', Rule::in(['development', 'staging', 'production'])],
            'root_path' => ['required', 'string', 'max:1024', 'regex:/^(?:\.|[A-Za-z0-9._\/-]+)$/'],
        ]);
        foreach (explode('/', str_replace('\\', '/', $data['root_path'])) as $segment) {
            if ((in_array($segment, ['', '..', '.env', '.ssh', '.aws'], true) && $data['root_path'] !== '.') || ($segment === '.' && $data['root_path'] !== '.')) abort(422, 'Le chemin du projet contient un segment interdit.');
        }
        if (! empty($data['wordpress_profile_id'])) {
            $profile = DevPilotWordPressSiteProfile::query()
                ->where('site_id', $request->route('site')->id)->findOrFail($data['wordpress_profile_id']);
            abort_if(! empty($data['database_connection_id']), 422, 'Un projet de profil WordPress ne peut pas associer une connexion SQL.');
            $primaryChannelId = $profile->file_connection_id ?: $profile->ssh_connection_id;
            if (! empty($data['connection_id']) && (string) $data['connection_id'] !== (string) $primaryChannelId) {
                abort(422, 'La connexion principale doit correspondre au canal fichiers ou SSH du profil sélectionné.');
            }
            $data['connection_id'] = $primaryChannelId;
            $data['database_connection_id'] = null;
        } elseif (! empty($data['connection_id'])) {
            $connection = DevPilotConnection::query()->where('site_id', $request->route('site')->id)->findOrFail($data['connection_id']);
            abort_unless(in_array($connection->protocol, ['ssh', 'sftp', 'ftp'], true), 422, 'La connexion sélectionnée ne gère pas de fichiers.');
        }
        if (! empty($data['database_connection_id'])) {
            $connection = DevPilotConnection::query()->where('site_id', $request->route('site')->id)->findOrFail($data['database_connection_id']);
            abort_unless(in_array($connection->protocol, ['mysql', 'pgsql'], true), 422, 'La connexion de base doit utiliser MySQL ou PostgreSQL.');
        }
        return $data;
    }

    private function validatedWordPressProfile(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'canonical_site_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'rest_connection_id' => ['sometimes', 'nullable', 'uuid'],
            'file_connection_id' => ['sometimes', 'nullable', 'uuid'],
            'ssh_connection_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
    }

    private function safeProfileError(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $safePrefixes = [
            'Le profil WordPress doit avoir un nom.',
            'Sélectionnez au moins un canal WordPress : REST, fichiers ou SSH.',
            'La connexion du canal ',
            'Le protocole de la connexion sélectionnée ne convient pas au canal ',
            'La connexion REST doit réussir son test de lecture avant d’être liée au profil.',
            'La forme de racine REST doit être détectée par le test de connexion.',
            'L’URL canonique du profil doit correspondre à celle de la connexion REST.',
            'La connexion fichiers doit être vérifiée et autoriser la lecture.',
            'La connexion SSH doit être vérifiée et autoriser les commandes.',
            'WordPress exige une URL HTTPS',
            'Une URL HTTPS WordPress valide est obligatoire.',
            'Le port de l’URL WordPress est invalide.',
            'L’hôte de l’URL WordPress est invalide.',
            'Le chemin de l’URL WordPress',
        ];
        foreach ($safePrefixes as $prefix) {
            if (str_starts_with($message, $prefix)) return mb_strcut($message, 0, 2300);
        }

        return 'Le profil WordPress n’a pas pu être enregistré. Vérifiez les canaux sélectionnés.';
    }

    private function validatedConnection(
        Request $request,
        bool $updating,
        ?DevPilotConnection $existingConnection = null,
        bool $allowInsecureWordPressProbe = false,
    ): array
    {
        $wordpress = $request->input('protocol') === 'wordpress';
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'protocol' => ['required', Rule::in(['ssh', 'sftp', 'ftp', 'mysql', 'pgsql', 'wordpress'])],
            'site_url' => [$wordpress ? 'required' : 'prohibited', 'string', 'max:2048'],
            'host' => [$wordpress ? 'nullable' : 'required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.:-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:128'],
            'database_name' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_$.-]+$/'],
            'auth_method' => [$wordpress ? 'prohibited' : 'nullable', Rule::in(['password', 'private_key'])],
            'password' => [$wordpress ? 'prohibited' : ($updating ? 'nullable' : 'required_without:private_key'), 'string', 'max:4096'],
            'application_password' => [$wordpress ? ($updating ? 'nullable' : 'required') : 'prohibited', 'string', 'max:4096'],
            'private_key' => [$wordpress ? 'prohibited' : 'nullable', 'string', 'max:32768'],
            'private_key_passphrase' => ['nullable', 'string', 'max:4096'],
            'host_key_sha256' => ['nullable', 'string', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
            'root_path' => ['nullable', 'string', 'max:1024'],
            'private_network_authorized' => ['required', 'boolean'],
            'can_read' => ['required', 'boolean'], 'can_write' => ['required', 'boolean'],
            'can_delete' => ['required', 'boolean'], 'can_run_commands' => ['required', 'boolean'],
            'probe_sudo' => ['sometimes', 'boolean'],
            'command_execution_mode' => ['sometimes', Rule::in(['unrestricted', 'approval', 'allowlist', 'disabled'])],
            'confirm_unattended_commands' => ['exclude_unless:command_execution_mode,unrestricted', 'required', 'accepted'],
            'can_manage_databases' => ['sometimes', 'boolean'],
            'allowed_commands' => ['array', 'max:12'], 'allowed_commands.*' => ['string', 'max:200'],
            'insecure_ftp_confirmed' => ['nullable', 'boolean'],
            'tls_verify' => ['nullable', 'accepted'],
        ]);
        if ($wordpress) {
            try {
                $canonical = DevPilotWordPressUrl::normalize((string) $data['site_url'], $allowInsecureWordPressProbe);
            } catch (Throwable $exception) {
                abort(422, $this->connections->failureMessage($exception));
            }
            $data['site_url'] = $canonical['site_url'];
            $data['host'] = $canonical['host'];
            $data['port'] = $canonical['port'];
            $data['database_name'] = null;
            $data['root_path'] = null;
            $data['auth_method'] = 'application_password';
            $data['can_run_commands'] = false;
            $data['command_execution_mode'] = 'disabled';
            $data['probe_sudo'] = false;
            $data['can_manage_databases'] = false;
            $data['allowed_commands'] = [];
        }
        if (in_array($data['protocol'], ['ssh', 'sftp'], true)) {
            if (empty($data['root_path']) || ! str_starts_with($data['root_path'], '/') || $data['root_path'] === '/' || preg_match('#(?:^|/)\.\.(?:/|$)#', $data['root_path'])) abort(422, 'SSH/SFTP exige une racine absolue précise, sans traversée.');
            if (empty($data['host_key_sha256'])) abort(422, 'L’empreinte SHA-256 du serveur SSH doit être confirmée avant l’enregistrement.');
            if (($data['auth_method'] ?? 'password') === 'private_key' && empty($data['private_key']) && ! $updating) abort(422, 'La clé privée est requise.');
        }
        if ($data['protocol'] === 'ftp' && empty($data['root_path'])) $data['root_path'] = '.';
        if ($data['protocol'] === 'ftp') {
            $ftpRoot = str_replace('\\', '/', $data['root_path']);
            if (str_starts_with($ftpRoot, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $ftpRoot)) abort(422, 'FTP utilise un chemin relatif au dossier d’accueil, sans traversée.');
        }
        if (in_array($data['protocol'], ['mysql', 'pgsql'], true)) {
            if (empty($data['database_name']) || empty($data['tls_verify'])) abort(422, 'Une base et une vérification TLS obligatoire sont requises.');
            $data['root_path'] = null;
        }
        if ($data['protocol'] === 'ftp' && empty($data['insecure_ftp_confirmed'])) abort(422, 'FTP non chiffré exige une confirmation explicite du risque.');
        $executionMode = $data['command_execution_mode'] ?? ((bool) $data['can_run_commands'] ? 'allowlist' : 'disabled');
        if ($data['protocol'] !== 'ssh') $executionMode = 'disabled';
        $data['command_execution_mode'] = $executionMode;
        $data['can_run_commands'] = $data['protocol'] === 'ssh' && $executionMode !== 'disabled';
        $data['probe_sudo'] = $data['protocol'] === 'ssh'
            && (bool) ($data['probe_sudo'] ?? $existingConnection?->probe_sudo ?? false);
        $allowedCommands = [];
        $credentialSets = [];
        $submittedCredentials = [];
        foreach (['password', 'private_key', 'private_key_passphrase'] as $credentialField) {
            if (is_string($data[$credentialField] ?? null) && $data[$credentialField] !== '') {
                $submittedCredentials[$credentialField] = $data[$credentialField];
            }
        }
        if ($submittedCredentials !== []) $credentialSets[] = $submittedCredentials;
        if (($data['allowed_commands'] ?? []) !== []
            && $existingConnection
            && is_string($existingConnection->credentials_encrypted)
            && $existingConnection->credentials_encrypted !== '') {
            try {
                $credentialSets[] = $this->connections->credentials($existingConnection);
            } catch (Throwable) {
                abort(422, 'Impossible de vérifier les commandes autorisées sans lire les identifiants enregistrés pour cette connexion.');
            }
        }
        foreach ($executionMode === 'allowlist' ? ($data['allowed_commands'] ?? []) : [] as $command) {
            $command = trim($command);
            if ($command === '') continue;
            if (! DevPilotCommandPolicy::allows($command)) abort(422, 'La liste contient une commande ou une syntaxe bloquée.');
            if ($this->redactor->commandContainsSecret($command, $credentialSets)) abort(422, 'Une commande contient un identifiant ou une chaîne reconnue comme secrète. Supprimez les secrets des arguments.');
            if (! in_array($command, $allowedCommands, true)) $allowedCommands[] = $command;
        }
        $data['allowed_commands'] = $allowedCommands;
        return $data;
    }

    private function publicTask(DevPilotTask $task): array
    {
        $task->makeHidden(['proposed_changes_encrypted']);
        $payload = $task->toArray();
        if ($task->relationLoaded('events')) $payload['events'] = $task->events;
        if ($task->relationLoaded('messages')) {
            $payload['messages'] = $task->messages->map(static fn ($message): array => [
                'id' => $message->id, 'role' => $message->role,
                'content' => $message->content, 'created_at' => $message->created_at,
            ])->all();
        }
        if ($task->relationLoaded('attachments')) {
            $payload['attachments'] = $task->attachments->map(static function ($attachment): array {
                $metadata = is_array($attachment->metadata) ? $attachment->metadata : [];
                return [
                    'id' => (string) $attachment->id, 'parent_attachment_id' => $attachment->parent_attachment_id,
                    'kind' => (string) $attachment->kind, 'name' => (string) $attachment->original_name,
                    'mime_type' => (string) $attachment->mime_type, 'size_bytes' => (int) $attachment->size_bytes,
                    'sha256' => (string) $attachment->sha256,
                    'alt' => is_string($metadata['alt'] ?? null) ? $metadata['alt'] : null,
                    'secret_detected' => (bool) ($metadata['secret_detected'] ?? false),
                ];
            })->all();
        }
        return $payload;
    }
}
