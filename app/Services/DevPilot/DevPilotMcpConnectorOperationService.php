<?php

namespace App\Services\DevPilot;

use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Domain\MCP\Exceptions\ToolExecutionUncertainException;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\ActorContext;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Security\PermissionEngine;
use App\Domain\MCP\Security\ToolArgumentsValidator;
use App\Domain\MCP\Security\ToolEffectResolver;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotTask;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Site;
use App\Models\User;
use App\Models\Mcp\McpConnector;
use App\Services\mcp\MCPActionGateService;
use RuntimeException;
use Throwable;

/** Generic, server-typed operation bridge to existing MCP connectors. */
final class DevPilotMcpConnectorOperationService
{
    private const PROTOCOL_VERSION = 1;
    private const MAX_RESULT_BYTES = 24000;
    private const MAX_RESULT_ITEMS = 512;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly CredentialVault $vault,
        private readonly PermissionEngine $permissions,
        private readonly MCPActionGateService $gate,
        private readonly DevPilotSecretRedactor $redactor,
        private readonly ToolArgumentsValidator $argumentsValidator = new ToolArgumentsValidator(),
        private readonly ToolEffectResolver $effectResolver = new ToolEffectResolver(),
        private readonly DevPilotWordPressClient $wordpressClient = new DevPilotWordPressClient(),
    ) {
    }

    public function prepare(DevPilotProject $project, DevPilotTask $task, array $input): array
    {
        if (array_diff(array_keys($input), ['type', 'connector_slug', 'tool_name', 'arguments', 'verification', 'duplicate_check', 'summary']) !== []) {
            throw new RuntimeException('Une action MCP contient des champs non autorisés.');
        }
        if ((string) $project->site_id !== (string) $task->site_id) {
            throw new RuntimeException('Le projet et la tâche MCP ne correspondent pas au même site.');
        }
        $connectorSlug = $this->identifier($input['connector_slug'] ?? null, 'connecteur');
        $toolName = $this->identifier($input['tool_name'] ?? null, 'outil');
        $site = Site::query()->findOrFail($project->site_id);
        $actor = $this->actorForTask($task, approved: false);
        [$schema, $siteConnector, $credentials] = $this->resolveTool($site, $connectorSlug, $toolName);
        $arguments = $input['arguments'] ?? [];
        if (! is_array($arguments)) throw new RuntimeException('Les paramètres de l’action MCP doivent être un objet structuré.');
        $arguments = $this->argumentsValidator->validate($this->objectSchema($schema), $arguments);
        $this->assertNoSecrets($input, $credentials);

        $permission = $this->permissions->authorize($site, $actor, $connectorSlug, $toolName, skipConfirmation: true, consumeDailyLimit: false);
        $effect = $this->effectResolver->resolve($schema);
        if ($connectorSlug === 'wordpress' && $effect !== 'read') {
            throw new RuntimeException('Les modifications WordPress doivent utiliser les opérations REST typées DevPilot, pas un outil MCP générique.');
        }
        $verification = $this->prepareVerification($project, $site, $task, $connectorSlug, $input['verification'] ?? null, $credentials, $effect);
        $duplicateCheck = $this->prepareDuplicateCheck($task, $site, $connectorSlug, $toolName, $arguments, $input['duplicate_check'] ?? null, $credentials, $effect);
        $requiresHumanApproval = $effect !== 'read' || $permission->mode === 'confirm';
        $critical = $effect !== 'read';
        $schemaHash = $this->schemaFingerprint($schema);
        $connectionHash = $this->connectionFingerprint($siteConnector);
        $summary = mb_strcut($this->redactor->redact(trim((string) ($input['summary'] ?? 'Action du connecteur'))), 0, 500);
        if ($summary === '') $summary = 'Action du connecteur MCP';

        $warnings = [];
        if ($effect === 'unknown') $warnings[] = 'L’effet de cet outil MCP n’est pas certifié en lecture seule; une confirmation renforcée est obligatoire.';
        elseif ($effect === 'write') $warnings[] = 'Cet outil MCP est déclaré comme une écriture et exige une confirmation renforcée.';
        if ($permission->mode === 'confirm' && ! $critical) $warnings[] = 'La permission MCP actuelle exige une approbation humaine pour cette inspection.';
        if ($verification['public_permalink_path'] === null && $effect !== 'read') $warnings[] = 'Vérification publique : non applicable pour cette opération; la relecture MCP reste obligatoire.';

        $displayFields = [
            ['label' => 'Connecteur', 'value' => $connectorSlug],
            ['label' => 'Outil', 'value' => $toolName],
            ['label' => 'Effet déclaré', 'value' => $effect],
        ];
        foreach ($this->displayArguments($this->objectSchema($schema), $arguments, $credentials) as $field) $displayFields[] = $field;
        if ($verification['readback_tool_name'] !== null) {
            $displayFields[] = ['label' => 'Outil de relecture', 'value' => $connectorSlug.'.'.$verification['readback_tool_name']];
            foreach ($this->displayArguments($verification['readback_schema'], $verification['readback_arguments'], $credentials, 'Relecture') as $field) $displayFields[] = $field;
            foreach ($verification['must_equal'] as $path => $expected) {
                $displayFields[] = ['label' => 'État attendu · '.$path, 'value' => $this->displayValue($expected)];
            }
        }
        if ($verification['public_permalink_path'] !== null) {
            $displayFields[] = ['label' => 'Adresse publique issue de la relecture', 'value' => $verification['public_permalink_path']];
            foreach ($verification['must_contain'] as $condition) $displayFields[] = ['label' => 'Texte public attendu', 'value' => $condition];
            foreach ($verification['must_not_contain'] as $condition) $displayFields[] = ['label' => 'Texte public interdit', 'value' => $condition];
        }
        if ($duplicateCheck !== null) {
            $displayFields[] = ['label' => 'Recherche préalable de doublon', 'value' => $connectorSlug.'.'.$duplicateCheck['tool_name']];
            foreach ($this->displayArguments($duplicateCheck['schema'], $duplicateCheck['arguments'], $credentials, 'Recherche de doublon') as $field) $displayFields[] = $field;
            foreach ($duplicateCheck['must_equal'] as $path => $expected) {
                $displayFields[] = ['label' => 'Aucun doublon · '.$path, 'value' => $this->displayValue($expected)];
            }
        }

        $actionFingerprint = self::fingerprint($connectorSlug, $toolName, $arguments, $schemaHash);
        $operation = [
            'type' => 'connector_action', 'channel' => 'mcp', 'protocol_version' => self::PROTOCOL_VERSION,
            'connector_slug' => $connectorSlug, 'tool_name' => $toolName,
            'arguments' => $arguments, 'tool_schema_sha256' => $schemaHash,
            'connection_fingerprint' => $connectionHash,
            'required_capabilities' => [$schema->capability ?: 'mcp:'.$connectorSlug.'.'.$toolName],
            'effect' => $effect, 'action_fingerprint' => $actionFingerprint,
            'mcp_permission_mode' => $permission->mode,
            'mcp_confirm_actor' => $permission->confirm_actor,
            'verification' => $this->publicVerificationContract($verification),
            'duplicate_check' => $duplicateCheck === null ? null : $this->duplicateCheckContract($duplicateCheck),
            'target' => 'mcp:'.$connectorSlug.'.'.$toolName.':'.$actionFingerprint,
            'recovery_fingerprint' => $verification['readback_action_fingerprint'],
            'recovery_target' => $verification['readback_action_fingerprint'] === null
                ? null : 'mcp:'.$connectorSlug.'.'.$verification['readback_tool_name'].':'.$verification['readback_action_fingerprint'],
            'display_fields' => $displayFields, 'warnings' => $warnings,
            'approval_required' => $requiresHumanApproval,
            'critical_confirmation_required' => $critical,
            'execution_decision' => $requiresHumanApproval ? 'approval' : 'automatic',
            'risk' => $critical ? 'critical' : 'diagnostic',
            'authorization_reason' => $critical
                ? 'Toute écriture ou tout effet inconnu d’un connecteur exige une confirmation DevPilot.'
                : 'Outil déclaré en lecture seule et permission MCP automatique; son exécution reste soumise à la porte MCP.',
            'summary' => $summary,
        ];

        return $operation;
    }

    /** Describe explicitly permitted connector schemas as data; MCP tools are never exposed as model-callable tools. */
    public function availableActions(DevPilotTask $task): array
    {
        try {
            $site = Site::query()->findOrFail($task->site_id);
            $actor = $this->actorForTask($task, approved: false);
        } catch (Throwable) {
            return [];
        }
        $available = [];
        $connections = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get();
        foreach ($connections as $connection) {
            $slug = (string) ($connection->mcpConnector?->slug ?? '');
            if ($slug === '' || ! $this->registry->has($slug)) continue;
            try {
                $credentials = $this->vault->retrieve($site, $slug);
                if (! is_array($credentials)) continue;
                $connector = $this->registry->get($slug);
                $tools = $connector instanceof ProvidesSiteScopedTools
                    ? $connector->toolsAvailableFor($credentials)
                    : $connector->listTools();
                foreach ($tools as $schema) {
                    if (! $schema instanceof ToolSchema || $schema->connectorSlug !== $slug) continue;
                    try {
                        $permission = $this->permissions->authorize($site, $actor, $slug, $schema->name, skipConfirmation: true, consumeDailyLimit: false);
                        $effect = $this->effectResolver->resolve($schema);
                        $available[] = [
                            'connector_slug' => $slug,
                            'tool_name' => $schema->name,
                            'effect' => $effect,
                            'permission_mode' => $permission->mode,
                            'parameters' => $this->describeSchema($schema->parameters),
                        ];
                    } catch (Throwable) {
                        // Missing, denied, or actor-incompatible permissions never appear in model context.
                    }
                    if (count($available) >= 80) return $available;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $available;
    }

    /** Revalidate and execute the approved action, its typed re-read and optional same-origin public check. */
    public function apply(DevPilotProject $project, DevPilotTask $task, array $operation, bool $automatic): array
    {
        try {
            return $this->applyValidated($project, $task, $operation, $automatic);
        } catch (DevPilotAuthorizationChangedException|DevPilotOperationUncertainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DevPilotAuthorizationChangedException('L’action MCP a été bloquée avant son envoi, car une validation ou permission n’a pas abouti.', previous: $exception);
        }
    }

    private function applyValidated(DevPilotProject $project, DevPilotTask $task, array $operation, bool $automatic): array
    {
        if (($operation['type'] ?? null) !== 'connector_action' || ($operation['channel'] ?? null) !== 'mcp'
            || ($operation['protocol_version'] ?? null) !== self::PROTOCOL_VERSION) {
            throw new DevPilotAuthorizationChangedException('Le canal ou la version de l’opération MCP ne correspond plus à la proposition approuvée.');
        }
        $site = Site::query()->findOrFail($project->site_id);
        $connectorSlug = $this->identifier($operation['connector_slug'] ?? null, 'connecteur');
        $toolName = $this->identifier($operation['tool_name'] ?? null, 'outil');
        [$schema, $siteConnector, $credentials] = $this->resolveTool($site, $connectorSlug, $toolName);
        $arguments = $operation['arguments'] ?? null;
        if (! is_array($arguments)) throw new DevPilotAuthorizationChangedException('Les paramètres MCP approuvés sont mal formés.');
        $arguments = $this->argumentsValidator->validate($this->objectSchema($schema), $arguments);
        $effect = $this->effectResolver->resolve($schema);
        $schemaHash = $this->schemaFingerprint($schema);
        $connectionHash = $this->connectionFingerprint($siteConnector);
        $actor = $this->actorForTask($task, approved: ! $automatic);
        $permission = $this->permissions->authorize(
            $site,
            $actor,
            $connectorSlug,
            $toolName,
            skipConfirmation: true,
            consumeDailyLimit: false,
        );
        $requiresHumanApproval = $effect !== 'read' || $permission->mode === 'confirm';
        $humanApproved = ! $automatic;
        if ($automatic && ($effect !== 'read' || $permission->mode !== 'auto')) {
            throw new DevPilotAuthorizationChangedException('L’opération MCP ne peut plus être exécutée automatiquement par la permission actuelle.');
        }
        if ($requiresHumanApproval && ! $humanApproved) {
            throw new DevPilotAuthorizationChangedException('L’opération MCP exige une approbation humaine qui ne figure pas dans cette tâche.');
        }
        if (! hash_equals((string) ($operation['tool_schema_sha256'] ?? ''), $schemaHash)
            || ! hash_equals((string) ($operation['connection_fingerprint'] ?? ''), $connectionHash)
            || ! hash_equals((string) ($operation['action_fingerprint'] ?? ''), self::fingerprint($connectorSlug, $toolName, $arguments, $schemaHash))
            || ($operation['effect'] ?? null) !== $effect
            || ($operation['mcp_permission_mode'] ?? null) !== $permission->mode
            || ($operation['mcp_confirm_actor'] ?? null) !== $permission->confirm_actor
            || ($operation['required_capabilities'] ?? null) !== [$schema->capability ?: 'mcp:'.$connectorSlug.'.'.$toolName]) {
            throw new DevPilotAuthorizationChangedException('Le connecteur, son schéma, ses paramètres ou ses permissions MCP ont changé depuis l’approbation.');
        }

        $duplicateCheck = is_array($operation['duplicate_check'] ?? null) ? $operation['duplicate_check'] : null;
        if ($this->requiresDuplicateCheck($task, $connectorSlug, $toolName) && $duplicateCheck === null) {
            throw new DevPilotAuthorizationChangedException('Après une écriture incertaine sur cette cible, une recherche approuvée de doublon est obligatoire avant toute nouvelle tentative.');
        }

        $verification = is_array($operation['verification'] ?? null) ? $operation['verification'] : [];
        $publicConnectionAttributes = null;
        if (is_string($verification['public_permalink_path'] ?? null) && $verification['public_permalink_path'] !== '') {
            try {
                $publicConnectionAttributes = $this->publicVerificationConnection($project);
            } catch (Throwable $exception) {
                throw new DevPilotAuthorizationChangedException('Le canal REST requis pour vérifier la page publique n’est plus disponible; aucune écriture n’a été lancée.', previous: $exception);
            }
        }
        $readbackSchema = null;
        $readbackCredentials = [];
        $readbackArguments = [];
        $readbackTool = null;
        if ($effect !== 'read') {
            try {
                $readbackTool = $this->identifier($verification['readback_tool_name'] ?? null, 'outil de relecture');
                if (! is_array($verification['readback_arguments'] ?? null)) throw new RuntimeException('Le plan de relecture MCP approuvé est invalide.');
                [$readbackSchema, $readbackConnection, $readbackCredentials] = $this->resolveTool($site, $connectorSlug, $readbackTool);
                if ($this->effectResolver->resolve($readbackSchema) !== 'read') throw new RuntimeException('L’outil de relecture n’est plus certifié en lecture seule.');
                $readbackArguments = $this->argumentsValidator->validate($this->objectSchema($readbackSchema), $verification['readback_arguments']);
                $readbackPermission = $this->permissions->authorize($site, $actor, $connectorSlug, $readbackTool, skipConfirmation: true, consumeDailyLimit: false);
                if ($this->schemaFingerprint($readbackSchema) !== ($verification['readback_tool_schema_sha256'] ?? null)
                    || $this->connectionFingerprint($readbackConnection) !== ($verification['readback_connection_fingerprint'] ?? null)
                    || $readbackPermission->mode !== ($verification['readback_permission_mode'] ?? null)
                    || $readbackPermission->confirm_actor !== ($verification['readback_confirm_actor'] ?? null)
                    || ! hash_equals((string) ($verification['readback_action_fingerprint'] ?? ''), self::fingerprint(
                        $connectorSlug, $readbackTool, $readbackArguments, $this->schemaFingerprint($readbackSchema),
                    ))) throw new RuntimeException('Le schéma, les paramètres ou la permission MCP de relecture ont changé.');
            } catch (Throwable $exception) {
                throw new DevPilotAuthorizationChangedException('La relecture MCP exacte ne peut plus être exécutée; aucune écriture n’a été lancée.', previous: $exception);
            }
        }

        if ($duplicateCheck !== null) {
            try {
                $checkTool = $this->identifier($duplicateCheck['tool_name'] ?? null, 'outil de recherche de doublon');
                if (! is_array($duplicateCheck['arguments'] ?? null) || ! is_array($duplicateCheck['must_equal'] ?? null)) {
                    throw new RuntimeException('Le contrat de recherche de doublon MCP est invalide.');
                }
                [$checkSchema, $checkConnection, $checkCredentials] = $this->resolveTool($site, $connectorSlug, $checkTool);
                if ($this->effectResolver->resolve($checkSchema) !== 'read') throw new RuntimeException('La recherche de doublon doit être en lecture seule.');
                $checkArguments = $this->argumentsValidator->validate($this->objectSchema($checkSchema), $duplicateCheck['arguments']);
                $checkPermission = $this->permissions->authorize($site, $actor, $connectorSlug, $checkTool, skipConfirmation: true, consumeDailyLimit: false);
                if ($this->schemaFingerprint($checkSchema) !== ($duplicateCheck['tool_schema_sha256'] ?? null)
                    || $this->connectionFingerprint($checkConnection) !== ($duplicateCheck['connection_fingerprint'] ?? null)
                    || $checkPermission->mode !== ($duplicateCheck['permission_mode'] ?? null)
                    || $checkPermission->confirm_actor !== ($duplicateCheck['confirm_actor'] ?? null)
                    || ! hash_equals((string) ($duplicateCheck['action_fingerprint'] ?? ''), self::fingerprint(
                        $connectorSlug, $checkTool, $checkArguments, $this->schemaFingerprint($checkSchema),
                    )) || ! $this->hasConclusiveAbsenceCondition($duplicateCheck['must_equal'])
                    || ! $this->hasMatchingDuplicateIdentity($arguments, $checkArguments)) {
                    throw new RuntimeException('Le schéma, les paramètres ou les conditions d’absence de doublon ont changé.');
                }
            } catch (Throwable $exception) {
                throw new DevPilotAuthorizationChangedException('La recherche de doublon approuvée ne peut plus être validée; aucune écriture n’a été lancée.', previous: $exception);
            }

            try {
                $duplicateResult = $this->gate->executeToolForDevPilot(
                    $site, $actor, $task, $connectorSlug, $checkTool, $checkArguments,
                    automatic: false, approvedActionFingerprint: (string) $operation['action_fingerprint'], approvedSubAction: 'duplicate_check',
                );
            } catch (Throwable $exception) {
                throw new DevPilotAuthorizationChangedException('La recherche de doublon a échoué; aucune écriture n’a été lancée.', previous: $exception);
            }
            if (! $duplicateResult->success || ! $this->matchesExpectedValues($duplicateResult->data ?? [], $duplicateCheck['must_equal'])) {
                return $this->resultBase($operation, 'failed', false, 'La recherche approuvée n’a pas confirmé l’absence de doublon; la nouvelle écriture a été bloquée.', [
                    'status' => 'duplicate_found_or_unconfirmed', 'complete' => true, 'verified' => false,
                ], $this->sanitizeResult($duplicateResult->data ?? [], $checkCredentials), readbackMatches: false);
            }
        }

        try {
            $result = $this->gate->executeToolForDevPilot($site, $actor, $task, $connectorSlug, $toolName, $arguments, $automatic);
        } catch (ToolExecutionUncertainException $exception) {
            throw new DevPilotOperationUncertainException('Le connecteur a peut-être appliqué l’action; une relecture est obligatoire avant toute suite.');
        }
        if (! $result->success && $effect === 'read') {
            return $this->resultBase($operation, 'failed', false, $this->redactResultText($result->errorMessage ?? $result->errorCode ?? 'Échec du connecteur.', $credentials), [
                'status' => 'failed', 'complete' => true, 'verified' => false,
            ]);
        }

        $actionData = $result->success ? $result->data : [];
        $safeActionData = $this->sanitizeResult($actionData, $credentials);
        if ($effect === 'read') {
            return $this->resultBase($operation, 'completed', true, $this->redactResultText($result->humanSummary ?? 'Lecture MCP terminée.', $credentials), [
                'status' => 'read', 'complete' => true, 'verified' => true,
                'sha256' => $this->dataHash($actionData), 'count' => is_array($actionData) && array_is_list($actionData) ? count($actionData) : null,
            ], $safeActionData, readbackMatches: true);
        }

        if (! $readbackSchema instanceof ToolSchema || ! is_string($readbackTool)) {
            throw new DevPilotOperationUncertainException('L’action MCP a été lancée, mais son plan de relecture est absent.');
        }
        try {
            $readback = $this->gate->executeToolForDevPilot(
                $site, $actor, $task, $connectorSlug, $readbackTool, $readbackArguments,
                automatic: false, approvedActionFingerprint: (string) $operation['action_fingerprint'], approvedSubAction: 'readback',
            );
        } catch (Throwable $exception) {
            throw new DevPilotOperationUncertainException('L’action MCP a été lancée, mais sa relecture a échoué; son état reste incertain.');
        }
        if (! $readback->success) throw new DevPilotOperationUncertainException('L’action MCP a été lancée, mais le connecteur n’a pas confirmé la relecture.');
        $readbackData = $readback->data;
        $readbackMatches = $this->matchesExpectedValues($readbackData, is_array($verification['must_equal'] ?? null) ? $verification['must_equal'] : []);
        $publicVerification = 'not_applicable';
        $publicVerified = false;
        $publicConditions = ['must_contain' => [], 'must_not_contain' => []];
        if (is_string($verification['public_permalink_path'] ?? null) && $verification['public_permalink_path'] !== '') {
            $publicConditions = [
                'must_contain' => is_array($verification['must_contain'] ?? null) ? $verification['must_contain'] : [],
                'must_not_contain' => is_array($verification['must_not_contain'] ?? null) ? $verification['must_not_contain'] : [],
            ];
            $permalink = $this->valueAtPath($readbackData, $verification['public_permalink_path']);
            if (! is_string($permalink) || $permalink === '') {
                $publicVerification = 'failed';
            } else {
                $public = $this->wordpressClient->verifyPublicPage(
                    $publicConnectionAttributes ?? [], $permalink,
                    $publicConditions['must_contain'], $publicConditions['must_not_contain'],
                );
                $publicVerification = ($public['verified'] ?? false) ? 'verified' : 'failed';
                $publicVerified = ($public['verified'] ?? false) === true;
            }
        }
        $verified = $readbackMatches && ($publicVerification === 'not_applicable' || $publicVerified);
        $safeReadbackData = $this->sanitizeResult($readbackData, $readbackCredentials);
        return $this->resultBase(
            $operation,
            $verified ? 'verified' : 'verification_failed',
            $verified,
            $verified ? 'L’action MCP a été relue et les conditions approuvées sont satisfaites.' : 'La relecture MCP ou la vérification publique ne satisfait pas toutes les conditions approuvées.',
            [
                'status' => $verified ? 'verified' : 'verification_failed', 'complete' => true,
                'verified' => $verified, 'sha256' => $this->dataHash($readbackData),
                'readback_matches' => $readbackMatches, 'public_verified' => $publicVerified,
            ],
            $safeReadbackData,
            $readbackMatches,
            $publicVerification,
            $publicVerified,
            $publicConditions,
        );
    }

    public static function fingerprint(string $connector, string $tool, array $arguments, string $schemaHash): string
    {
        return hash('sha256', json_encode([
            'channel' => 'mcp', 'protocol_version' => self::PROTOCOL_VERSION,
            'connector' => $connector, 'tool' => $tool, 'schema_sha256' => $schemaHash, 'arguments' => $arguments,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function canRunAutomatically(DevPilotProject $project, DevPilotTask $task, array $operation): bool
    {
        try {
            $site = Site::query()->findOrFail($project->site_id);
            $connector = $this->identifier($operation['connector_slug'] ?? null, 'connecteur');
            $tool = $this->identifier($operation['tool_name'] ?? null, 'outil');
            [$schema, $siteConnector, $credentials] = $this->resolveTool($site, $connector, $tool);
            $arguments = $this->argumentsValidator->validate($this->objectSchema($schema), is_array($operation['arguments'] ?? null) ? $operation['arguments'] : []);
            $actor = $this->actorForTask($task, approved: false);
            $permission = $this->permissions->authorize($site, $actor, $connector, $tool, skipConfirmation: true, consumeDailyLimit: false);
            return $this->effectResolver->resolve($schema) === 'read'
                && $permission->mode === 'auto'
                && ($operation['approval_required'] ?? true) === false
                && hash_equals((string) ($operation['tool_schema_sha256'] ?? ''), $this->schemaFingerprint($schema))
                && hash_equals((string) ($operation['connection_fingerprint'] ?? ''), $this->connectionFingerprint($siteConnector))
                && hash_equals((string) ($operation['action_fingerprint'] ?? ''), self::fingerprint($connector, $tool, $arguments, $this->schemaFingerprint($schema)));
        } catch (Throwable) {
            return false;
        }
    }

    private function prepareDuplicateCheck(DevPilotTask $task, Site $site, string $connectorSlug, string $toolName, array $mainArguments, mixed $input, array $credentials, string $effect): ?array
    {
        $required = $effect !== 'read' && $this->requiresDuplicateCheck($task, $connectorSlug, $toolName);
        if ($input === null) {
            if ($required) throw new RuntimeException('Après une écriture incertaine sur cette cible, indique une recherche en lecture seule du slug ou du titre avant toute nouvelle tentative.');
            return null;
        }
        if ($effect === 'read' || ! is_array($input)
            || array_diff(array_keys($input), ['tool', 'arguments', 'must_equal']) !== []) {
            throw new RuntimeException('Le contrôle de doublon MCP doit être un contrat de lecture typé sur une écriture.');
        }
        $tool = $this->identifier($input['tool'] ?? null, 'outil de recherche de doublon');
        [$schema, $connection, $checkCredentials] = $this->resolveTool($site, $connectorSlug, $tool);
        if ($this->effectResolver->resolve($schema) !== 'read') throw new RuntimeException('L’outil de recherche de doublon doit être déclaré en lecture seule.');
        $arguments = $input['arguments'] ?? null;
        if (! is_array($arguments)) throw new RuntimeException('Les paramètres de recherche de doublon doivent être un objet structuré.');
        $arguments = $this->argumentsValidator->validate($this->objectSchema($schema), $arguments);
        $mustEqual = $input['must_equal'] ?? null;
        if (! is_array($mustEqual) || $mustEqual === [] || count($mustEqual) > 8) {
            throw new RuntimeException('La recherche de doublon exige des conditions explicites confirmant son absence.');
        }
        foreach ($mustEqual as $path => $expected) {
            if (! is_string($path) || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,7}$/D', $path) !== 1
                || (! is_scalar($expected) && $expected !== null)
                || (is_string($expected) && mb_strlen($expected) > 512)) {
                throw new RuntimeException('Une condition d’absence de doublon est invalide.');
            }
        }
        if (! $this->hasConclusiveAbsenceCondition($mustEqual)) {
            throw new RuntimeException('Utilise une condition vérifiable count=0, total=0, exists=false, found=false ou id=null pour prouver l’absence de doublon.');
        }
        if (! $this->hasMatchingDuplicateIdentity($mainArguments, $arguments)) {
            throw new RuntimeException('La recherche de doublon doit porter sur le même slug ou titre que la nouvelle écriture; si aucun n’est disponible, la relance reste bloquée.');
        }
        $permission = $this->permissions->authorize($site, $this->actorForTask($task, approved: false), $connectorSlug, $tool, skipConfirmation: true, consumeDailyLimit: false);
        $this->assertNoSecrets($input, $credentials);
        $this->assertNoSecrets($input, $checkCredentials);

        return [
            'tool_name' => $tool, 'arguments' => $arguments, 'schema' => $this->objectSchema($schema), 'must_equal' => $mustEqual,
            'tool_schema_sha256' => $this->schemaFingerprint($schema),
            'connection_fingerprint' => $this->connectionFingerprint($connection),
            'action_fingerprint' => self::fingerprint($connectorSlug, $tool, $arguments, $this->schemaFingerprint($schema)),
            'permission_mode' => $permission->mode, 'confirm_actor' => $permission->confirm_actor,
        ];
    }

    private function duplicateCheckContract(array $check): array
    {
        return [
            'tool_name' => $check['tool_name'], 'arguments' => $check['arguments'], 'must_equal' => $check['must_equal'],
            'tool_schema_sha256' => $check['tool_schema_sha256'], 'connection_fingerprint' => $check['connection_fingerprint'],
            'action_fingerprint' => $check['action_fingerprint'], 'permission_mode' => $check['permission_mode'],
            'confirm_actor' => $check['confirm_actor'],
        ];
    }

    private function hasConclusiveAbsenceCondition(array $conditions): bool
    {
        foreach ($conditions as $path => $expected) {
            $leaf = strtolower(ltrim((string) (strrchr('.'.$path, '.') ?: $path), '.'));
            if (in_array($leaf, ['count', 'total'], true) && $expected === 0) return true;
            if (in_array($leaf, ['exists', 'found'], true) && $expected === false) return true;
            if ($leaf === 'id' && $expected === null) return true;
        }
        return false;
    }

    private function hasMatchingDuplicateIdentity(array $mainArguments, array $checkArguments): bool
    {
        $identifiers = [];
        foreach (['slug', 'title'] as $key) {
            if (is_string($mainArguments[$key] ?? null) && trim($mainArguments[$key]) !== '') {
                $identifiers[$key] = $mainArguments[$key];
            }
        }
        if ($identifiers === []) return false;
        foreach ($identifiers as $key => $value) {
            if (! is_string($checkArguments[$key] ?? null) || $checkArguments[$key] !== $value) return false;
        }

        return true;
    }

    private function requiresDuplicateCheck(DevPilotTask $task, string $connectorSlug, string $toolName): bool
    {
        $history = is_array($task->plan) && is_array($task->plan['execution_history'] ?? null)
            ? $task->plan['execution_history'] : [];
        foreach (array_reverse($history) as $entry) {
            if (! is_array($entry) || ($entry['action'] ?? null) !== 'connector_action'
                || ($entry['connector_slug'] ?? null) !== $connectorSlug || ($entry['tool_name'] ?? null) !== $toolName
                || ! in_array($entry['effect'] ?? null, ['write', 'unknown'], true)) continue;
            $status = $entry['execution_state'] ?? $entry['status'] ?? $entry['facts']['status'] ?? null;
            if (in_array($status, ['uncertain', 'operation_uncertain', 'result_unknown_after_worker_interruption'], true)) return true;
        }
        return false;
    }

    private function prepareVerification(DevPilotProject $project, Site $site, DevPilotTask $task, string $connectorSlug, mixed $input, array $credentials, string $effect): array
    {
        $empty = [
            'readback_tool_name' => null, 'readback_arguments' => [], 'readback_schema' => [],
            'readback_tool_schema_sha256' => null, 'readback_connection_fingerprint' => null,
            'readback_action_fingerprint' => null, 'readback_permission_mode' => null, 'readback_confirm_actor' => null,
            'must_equal' => [], 'public_permalink_path' => null, 'must_contain' => [], 'must_not_contain' => [],
        ];
        if ($effect === 'read') {
            if ($input !== null) throw new RuntimeException('Une lecture MCP ne doit pas contenir de plan de vérification d’écriture.');
            return $empty;
        }
        if (! is_array($input) || array_diff(array_keys($input), ['readback_tool', 'readback_arguments', 'must_equal', 'public_permalink_path', 'must_contain', 'must_not_contain']) !== []) {
            throw new RuntimeException('Toute écriture ou tout effet inconnu exige un plan de relecture MCP approuvé.');
        }
        $readbackTool = $this->identifier($input['readback_tool'] ?? null, 'outil de relecture');
        [$readbackSchema, $readbackConnection, $readbackCredentials] = $this->resolveTool($site, $connectorSlug, $readbackTool);
        if ($this->effectResolver->resolve($readbackSchema) !== 'read') throw new RuntimeException('L’outil de relecture doit être déclaré en lecture seule dans le registre MCP.');
        $readbackArguments = $input['readback_arguments'] ?? null;
        if (! is_array($readbackArguments)) throw new RuntimeException('Les paramètres de relecture doivent être un objet structuré.');
        $readbackArguments = $this->argumentsValidator->validate($this->objectSchema($readbackSchema), $readbackArguments);
        $readbackPermission = $this->permissions->authorize(
            $site, $this->actorForTask($task, approved: false), $connectorSlug, $readbackTool,
            skipConfirmation: true, consumeDailyLimit: false,
        );
        $this->assertNoSecrets($input, $credentials);
        $this->assertNoSecrets($input, $readbackCredentials);
        $mustEqual = $input['must_equal'] ?? null;
        if (! is_array($mustEqual) || $mustEqual === [] || count($mustEqual) > 8) throw new RuntimeException('Une relecture MCP doit vérifier au moins un champ attendu et au plus huit.');
        foreach ($mustEqual as $path => $expected) {
            if (! is_string($path) || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,7}$/D', $path) !== 1
                || (! is_scalar($expected) && $expected !== null)
                || (is_string($expected) && mb_strlen($expected) > 512)) throw new RuntimeException('Une condition de relecture MCP est invalide.');
        }
        $publicPath = $input['public_permalink_path'] ?? null;
        if ($publicPath !== null && (! is_string($publicPath) || preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+){0,7}$/D', $publicPath) !== 1)) {
            throw new RuntimeException('Le chemin du lien public de relecture est invalide.');
        }
        if ($publicPath !== null) $this->publicVerificationConnection($project);
        $mustContain = $this->conditions($input['must_contain'] ?? [], 'must_contain');
        $mustNotContain = $this->conditions($input['must_not_contain'] ?? [], 'must_not_contain');
        if (($mustContain !== [] || $mustNotContain !== []) && $publicPath === null) throw new RuntimeException('Les conditions publiques exigent un chemin de permalien dans le résultat de relecture.');
        return [
            'readback_tool_name' => $readbackTool, 'readback_arguments' => $readbackArguments,
            'readback_schema' => $this->objectSchema($readbackSchema), 'must_equal' => $mustEqual,
            'readback_tool_schema_sha256' => $this->schemaFingerprint($readbackSchema),
            'readback_connection_fingerprint' => $this->connectionFingerprint($readbackConnection),
            'readback_action_fingerprint' => self::fingerprint($connectorSlug, $readbackTool, $readbackArguments, $this->schemaFingerprint($readbackSchema)),
            'readback_permission_mode' => $readbackPermission->mode, 'readback_confirm_actor' => $readbackPermission->confirm_actor,
            'public_permalink_path' => $publicPath, 'must_contain' => $mustContain, 'must_not_contain' => $mustNotContain,
        ];
    }

    private function publicVerificationContract(array $verification): array
    {
        return [
            'readback_tool_name' => $verification['readback_tool_name'],
            'readback_arguments' => $verification['readback_arguments'],
            'readback_tool_schema_sha256' => $verification['readback_tool_schema_sha256'],
            'readback_connection_fingerprint' => $verification['readback_connection_fingerprint'],
            'readback_action_fingerprint' => $verification['readback_action_fingerprint'],
            'readback_permission_mode' => $verification['readback_permission_mode'],
            'readback_confirm_actor' => $verification['readback_confirm_actor'],
            'must_equal' => $verification['must_equal'],
            'public_permalink_path' => $verification['public_permalink_path'],
            'must_contain' => $verification['must_contain'],
            'must_not_contain' => $verification['must_not_contain'],
        ];
    }

    private function publicVerificationConnection(DevPilotProject $project): array
    {
        if ($project->wordpress_profile_id === null) throw new RuntimeException('La vérification publique exige un profil WordPress connecté au même projet.');
        $project->loadMissing('wordpressProfile.restConnection');
        $profile = $project->wordpressProfile;
        $connection = (new DevPilotChannelResolver())->resolve($project, 'rest', ['read']);
        if ($connection->protocol !== 'wordpress' || $connection->status !== 'connected'
            || ! is_string($profile?->canonical_site_url) || $profile->canonical_site_url === '') {
            throw new RuntimeException('Le canal REST WordPress n’est pas prêt pour une vérification publique.');
        }

        return [
            'site_url' => $profile->canonical_site_url,
            'host' => $connection->host,
            'private_network_authorized' => (bool) $connection->private_network_authorized,
        ];
    }

    private function resolveTool(Site $site, string $connectorSlug, string $toolName): array
    {
        $siteConnector = $site->mcpSiteConnectors()
            ->where('status', 'connected')
            ->whereHas('mcpConnector', static fn ($query) => $query->where('slug', $connectorSlug))
            ->first();
        if (! $siteConnector instanceof McpSiteConnector || ! $this->registry->has($connectorSlug)) {
            throw new RuntimeException('Le connecteur MCP n’est pas connecté et actif pour ce site.');
        }
        $credentials = $this->vault->retrieve($site, $connectorSlug);
        if (! is_array($credentials)) throw new RuntimeException('Les identifiants du connecteur MCP ne sont pas disponibles.');
        $connector = $this->registry->get($connectorSlug);
        $tools = $connector instanceof ProvidesSiteScopedTools
            ? $connector->toolsAvailableFor($credentials)
            : $connector->listTools();
        foreach ($tools as $tool) {
            if ($tool instanceof ToolSchema && $tool->connectorSlug === $connectorSlug && $tool->name === $toolName) {
                return [$tool, $siteConnector, $credentials];
            }
        }
        throw new RuntimeException('Cet outil MCP n’est pas exposé par le schéma actuellement connecté.');
    }

    private function objectSchema(ToolSchema $tool): array
    {
        $schema = $tool->parameters;
        if (($schema['type'] ?? null) !== 'object' || ! is_array($schema['properties'] ?? null)) {
            throw new RuntimeException('Le schéma des paramètres du connecteur doit être un objet JSON déclaré côté serveur.');
        }

        return $schema;
    }

    private function describeSchema(array $schema, int $depth = 0): array
    {
        if ($depth > 6) return [];
        $safe = [];
        foreach (['type', 'required', 'enum', 'format', 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems', 'pattern'] as $key) {
            if (array_key_exists($key, $schema) && (is_scalar($schema[$key]) || is_array($schema[$key]))) $safe[$key] = $schema[$key];
        }
        if (is_array($schema['properties'] ?? null)) {
            $safe['properties'] = [];
            foreach (array_slice($schema['properties'], 0, 64, true) as $name => $property) {
                if (! is_string($name) || ! is_array($property)) continue;
                $safe['properties'][$name] = $this->describeSchema($property, $depth + 1);
            }
        }
        if (is_array($schema['items'] ?? null)) $safe['items'] = $this->describeSchema($schema['items'], $depth + 1);
        if (is_array($schema['additionalProperties'] ?? null)) $safe['additionalProperties'] = $this->describeSchema($schema['additionalProperties'], $depth + 1);

        return $safe;
    }

    private function schemaFingerprint(ToolSchema $schema): string
    {
        return hash('sha256', json_encode([
            'connector' => $schema->connectorSlug, 'tool' => $schema->name,
            'description' => $schema->description, 'parameters' => $schema->parameters,
            'is_write_action' => $schema->isWriteAction, 'capability' => $schema->capability,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function connectionFingerprint(McpSiteConnector $connection): string
    {
        return hash('sha256', json_encode([
            'id' => (string) $connection->id, 'status' => (string) $connection->status,
            'credentials' => hash('sha256', (string) $connection->credentials_encrypted),
            'settings' => $connection->settings ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function actorForTask(DevPilotTask $task, bool $approved): ActorContext
    {
        $userId = $approved ? $task->approved_by : $task->requested_by;
        $user = is_string($userId) && $userId !== '' ? User::query()->find($userId) : null;
        if (! $user) throw new PermissionDeniedException('L’acteur DevPilot n’est plus disponible pour appliquer l’action MCP.');

        return new ActorContext('user', (string) $user->id, (bool) $user->isAdmin());
    }

    private function identifier(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value) !== 1) {
            throw new RuntimeException("Le {$label} MCP n’est pas un identifiant valide.");
        }
        return $value;
    }

    private function assertNoSecrets(array $data, array $credentials): void
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        if (str_contains($encoded, '[REDACTED') || $this->redactor->redact($encoded) !== $encoded
            || $this->redactor->containsKnownSecret($encoded, [$credentials])) {
            throw new RuntimeException('Les paramètres MCP contiennent une valeur sensible ou un secret enregistré; action bloquée.');
        }
    }

    private function displayArguments(array $schema, array $arguments, array $credentials, string $prefix = 'Paramètre'): array
    {
        $fields = [];
        foreach ($arguments as $key => $value) {
            $property = is_array($schema['properties'][$key] ?? null) ? $schema['properties'][$key] : [];
            $sensitive = ($property['writeOnly'] ?? false) === true || ($property['format'] ?? null) === 'password'
                || preg_match('/password|secret|token|authorization|api[_-]?key|consumer_key/i', (string) $key) === 1;
            $shown = $sensitive ? '[masqué]' : $this->displayValue($value);
            $shown = $this->redactor->redactKnownSecrets($shown, [$credentials]);
            $fields[] = ['label' => $prefix.' · '.$key, 'value' => mb_strcut($shown, 0, 1400)];
        }
        return $fields;
    }

    private function displayValue(mixed $value): string
    {
        return is_string($value) ? $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function conditions(mixed $conditions, string $name): array
    {
        if (! is_array($conditions) || count($conditions) > 8) throw new RuntimeException("La liste {$name} est invalide.");
        $result = [];
        foreach ($conditions as $condition) {
            if (! is_string($condition) || trim($condition) === '' || mb_strlen($condition) > 256) throw new RuntimeException("Une condition {$name} est invalide.");
            $result[] = trim($condition);
        }
        return array_values($result);
    }

    private function matchesExpectedValues(array $data, array $expected): bool
    {
        if ($expected === []) return false;
        foreach ($expected as $path => $value) {
            if (! $this->pathExists($data, (string) $path)) return false;
            $actual = $this->valueAtPath($data, (string) $path);
            if ($actual !== $value) return false;
        }
        return true;
    }

    private function valueAtPath(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) return null;
            $value = $value[$segment];
        }
        return $value;
    }

    private function pathExists(array $data, string $path): bool
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) return false;
            $value = $value[$segment];
        }

        return true;
    }

    private function sanitizeResult(mixed $value, array $credentials, int $depth = 0, ?int &$remaining = null, ?string $key = null, ?int &$remainingItems = null): mixed
    {
        $remaining ??= self::MAX_RESULT_BYTES;
        $remainingItems ??= self::MAX_RESULT_ITEMS;
        if ($depth > 8 || $remaining <= 0 || $remainingItems <= 0) return '[résultat tronqué]';
        if ($key !== null && preg_match('/password|secret|token|authorization|consumer_key|api[_-]?key/i', $key) === 1) return '[masqué]';
        if (is_array($value)) {
            $result = [];
            $items = array_slice($value, 0, min(80, $remainingItems), true);
            foreach ($items as $childKey => $child) {
                $remainingItems--;
                $safeKey = is_string($childKey) ? mb_strcut($childKey, 0, 100) : (int) $childKey;
                $result[$safeKey] = $this->sanitizeResult($child, $credentials, $depth + 1, $remaining, is_string($childKey) ? $childKey : null, $remainingItems);
                if ($remaining <= 0 || $remainingItems <= 0) break;
            }
            return $result;
        }
        if (is_string($value)) {
            $safe = $this->redactor->redactKnownSecrets($value, [$credentials]);
            $safe = mb_strcut($safe, 0, min(3000, $remaining));
            $remaining -= strlen($safe);
            return $safe;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) return $value;
        return '[type non pris en charge]';
    }

    private function dataHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
    }

    private function redactResultText(string $text, array $credentials): string
    {
        return mb_strcut($this->redactor->redactKnownSecrets($this->redactor->redact($text), [$credentials]), 0, 1000);
    }

    private function resultBase(
        array $operation,
        string $status,
        bool $verified,
        string $message,
        array $facts,
        mixed $resultData = null,
        bool $readbackMatches = false,
        string $publicVerification = 'not_applicable',
        bool $publicVerified = false,
        array $publicConditions = ['must_contain' => [], 'must_not_contain' => []],
    ): array {
        return array_filter([
            'type' => 'connector_action', 'status' => $status, 'execution_state' => in_array($status, ['failed'], true) ? 'failed' : 'completed',
            'verified' => $verified, 'risk' => $operation['risk'] ?? 'critical',
            'approval_required' => $operation['approval_required'] ?? true,
            'critical_confirmation_required' => $operation['critical_confirmation_required'] ?? true,
            'summary' => $operation['summary'] ?? '', 'target' => $operation['target'] ?? ('mcp:'.$operation['connector_slug'].'.'.$operation['tool_name'].':'.$operation['action_fingerprint']),
            'recovery_target' => $operation['recovery_target'] ?? null,
            'recovery_fingerprint' => $operation['recovery_fingerprint'] ?? null,
            'connector_slug' => $operation['connector_slug'], 'tool_name' => $operation['tool_name'],
            'effect' => $operation['effect'] ?? 'unknown',
            'message' => $message, 'facts' => $facts, 'result_untrusted_data' => $resultData,
            'rest_readback_matches' => $readbackMatches, 'public_verification' => $publicVerification,
            'public_verified' => $publicVerified, 'public_conditions' => $publicConditions,
            'mutating' => ($operation['effect'] ?? 'unknown') !== 'read',
        ], static fn ($value): bool => $value !== null);
    }
}
