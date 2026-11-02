<?php

namespace App\Services\DevPilot;

use Illuminate\Support\Str;

/** Server-owned observation IDs, sequence allocation, retention, and goal-proof checks. */
final class DevPilotEvidencePolicy
{
    public const SCHEMA_VERSION = 1;
    public const MAX_EXECUTION_OBSERVATIONS = 64;
    public const MAX_INSPECTION_OBSERVATIONS = 64;

    public static function currentSequence(array $plan): int
    {
        $sequence = max(0, (int) ($plan['evidence_sequence'] ?? 0));
        foreach (is_array($plan['execution_history'] ?? null) ? $plan['execution_history'] : [] as $entry) {
            if (is_array($entry)) $sequence = max($sequence, (int) ($entry['sequence'] ?? 0));
        }
        foreach (is_array($plan['exploration']['observations'] ?? null) ? $plan['exploration']['observations'] : [] as $entry) {
            if (is_array($entry)) $sequence = max($sequence, (int) ($entry['sequence'] ?? 0));
        }

        return $sequence;
    }

    public static function appendHistory(array &$plan, string $source, array $entry, string $targetSnapshotHash): array
    {
        $sequence = self::currentSequence($plan) + 1;
        $entry = self::withIdentity($entry, $sequence, $source, $targetSnapshotHash);
        $history = is_array($plan['execution_history'] ?? null) ? $plan['execution_history'] : [];
        $history[] = $entry;
        $plan['execution_history'] = array_slice($history, -self::MAX_EXECUTION_OBSERVATIONS);
        $plan['evidence_sequence'] = $sequence;

        return $entry;
    }

    public static function appendInspection(array &$plan, array $observation): void
    {
        if (! Str::isUuid((string) ($observation['id'] ?? '')) || ! is_int($observation['sequence'] ?? null)) return;
        $exploration = is_array($plan['exploration'] ?? null) ? $plan['exploration'] : [];
        $observations = is_array($exploration['observations'] ?? null) ? $exploration['observations'] : [];
        $known = array_fill_keys(array_filter(array_map(static fn ($entry) => is_array($entry) ? ($entry['id'] ?? null) : null, $observations), 'is_string'), true);
        if (! isset($known[$observation['id']])) $observations[] = $observation;
        $exploration['observations'] = array_slice($observations, -self::MAX_INSPECTION_OBSERVATIONS);
        $plan['exploration'] = $exploration;
        $plan['evidence_sequence'] = max(self::currentSequence($plan), $observation['sequence']);
    }

    public static function shouldRequestCorrection(string $goalState, array $assessment, bool $hasProposal): bool
    {
        return $goalState === 'achieved' && ($assessment['valid'] ?? false) !== true && ! $hasProposal;
    }

    public static function settleGoalState(string $goalState, array $assessment, bool $hasProposal): string
    {
        if ($goalState !== 'achieved') return $goalState;
        if (($assessment['valid'] ?? false) === true && ! $hasProposal) return 'achieved';

        return $hasProposal ? 'in_progress' : 'blocked';
    }

    public static function observation(
        int $sequence,
        string $kind,
        string $target,
        string $targetSnapshotHash,
        array $facts,
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'sequence' => $sequence,
            'source' => 'inspection',
            'kind' => $kind,
            'target' => $target,
            'target_snapshot_hash' => $targetSnapshotHash,
            'mutating' => false,
            'facts' => self::safeFacts($facts),
        ];
    }

    public static function withIdentity(array $entry, int $sequence, string $source, string $targetSnapshotHash): array
    {
        $entry['id'] = (string) Str::uuid();
        $entry['sequence'] = $sequence;
        $entry['source'] = $source;
        $entry['target_snapshot_hash'] = $targetSnapshotHash;
        $entry['mutating'] = (bool) ($entry['mutating'] ?? false);
        if (array_key_exists('facts', $entry)) $entry['facts'] = self::safeFacts($entry['facts']);

        return $entry;
    }

    public static function safeFacts(mixed $facts): array
    {
        if (! is_array($facts)) return [];
        $safe = [];
        foreach (['sha256', 'size', 'exit_code', 'status', 'complete', 'entry_hashes', 'match_hashes', 'count', 'page', 'total_pages',
            'verified', 'rest_readback_matches', 'public_verified', 'cache_stale_suspected', 'cache_purge_verified', 'created_by_this_task', 'match_ids', 'public_verification', 'must_contain', 'must_not_contain'] as $field) {
            if (! array_key_exists($field, $facts)) continue;
            $value = $facts[$field];
            if ($field === 'sha256') {
                if (is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1) $safe[$field] = strtolower($value);
            } elseif (in_array($field, ['entry_hashes', 'match_hashes'], true)) {
                if (! is_array($value)) continue;
                $hashes = [];
                foreach (array_slice($value, 0, 500) as $hash) {
                    if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/i', $hash) === 1) $hashes[] = strtolower($hash);
                }
                $safe[$field] = array_values(array_unique($hashes));
            } elseif ($field === 'size') {
                if (is_numeric($value)) $safe[$field] = max(0, min(524288, (int) $value));
            } elseif ($field === 'exit_code') {
                $safe[$field] = is_int($value) ? $value : null;
            } elseif ($field === 'complete') {
                $safe[$field] = (bool) $value;
            } elseif (in_array($field, ['verified', 'rest_readback_matches', 'public_verified', 'cache_stale_suspected', 'cache_purge_verified', 'created_by_this_task'], true)) {
                if (is_bool($value)) $safe[$field] = $value;
            } elseif ($field === 'match_ids') {
                if (! is_array($value)) continue;
                $ids = [];
                foreach (array_slice($value, 0, 5) as $id) {
                    if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0 && (int) $id <= 2147483647) {
                        $ids[] = (int) $id;
                    }
                }
                $safe[$field] = array_values(array_unique($ids));
            } elseif (in_array($field, ['must_contain', 'must_not_contain'], true)) {
                if (! is_array($value)) continue;
                $safe[$field] = array_values(array_map(static fn ($condition): bool => $condition === true, array_slice($value, 0, 8)));
            } elseif ($field === 'status' && is_string($value)) {
                $safe[$field] = mb_strcut($value, 0, 80);
            } elseif ($field === 'public_verification' && in_array($value, ['verified', 'failed', 'not_applicable'], true)) {
                $safe[$field] = $value;
            } elseif (in_array($field, ['count', 'page', 'total_pages'], true) && is_numeric($value)) {
                $safe[$field] = max(0, min(1000000, (int) $value));
            }
        }

        return $safe;
    }

    /** @return array{valid:bool,reason:?string,evidence:array,recovery_state_checked:bool,target_state_revalidated:bool,has_mutation:bool} */
    public static function assess(
        mixed $schemaVersion,
        mixed $evidence,
        array $history,
        array $exploration,
        string $currentTargetHash,
        bool $recoveryRequired,
        bool $targetRecheckRequired,
    ): array {
        $index = [];
        foreach ($history as $entry) {
            if (! is_array($entry) || ! self::validIdentity($entry)) continue;
            $index[$entry['id']] = $entry;
        }
        foreach (is_array($exploration['observations'] ?? null) ? $exploration['observations'] : [] as $entry) {
            if (! is_array($entry) || ! self::validIdentity($entry)) continue;
            $index[$entry['id']] = $entry;
        }

        $hasMutation = false;
        $lastMutationSequence = 0;
        $lastMutation = null;
        foreach ($history as $entry) {
            if (! is_array($entry) || ! self::isMutatingHistoryEntry($entry)) continue;
            $hasMutation = true;
            $sequence = is_int($entry['sequence'] ?? null) ? $entry['sequence'] : 0;
            if ($lastMutation === null || $sequence >= $lastMutationSequence) {
                $lastMutationSequence = $sequence;
                $lastMutation = $entry;
            }
        }

        $recoveryChecked = ! $recoveryRequired || self::hasPostUncertaintyCheck($history, $exploration, $currentTargetHash);
        $targetRevalidated = ! $targetRecheckRequired || self::hasPostPauseCheck($history, $exploration, $currentTargetHash);
        $base = [
            'valid' => false, 'reason' => null, 'evidence' => [],
            'recovery_state_checked' => $recoveryChecked, 'target_state_revalidated' => $targetRevalidated,
            'has_mutation' => $hasMutation,
        ];

        if ($schemaVersion !== self::SCHEMA_VERSION || ! is_array($evidence)) return array_replace($base, ['reason' => 'legacy_format']);
        if ($evidence === []) return array_replace($base, [
            'reason' => ! $recoveryChecked && self::hasUncertainShellCommand($history)
                ? 'uncertain_command_scope_unknown' : 'no_evidence',
        ]);

        $accepted = [];
        $resolved = [];
        foreach (array_slice($evidence, 0, 12) as $item) {
            if (! is_array($item) || ! is_string($item['ref'] ?? null) || ! is_string($item['claim'] ?? null)) {
                return array_replace($base, ['reason' => 'legacy_format']);
            }
            if (trim($item['claim']) === '') return array_replace($base, ['reason' => 'legacy_format']);
            $ref = $item['ref'];
            if (! isset($index[$ref])) return array_replace($base, ['reason' => 'unknown_ref']);
            $observation = $index[$ref];
            if (! hash_equals($currentTargetHash, (string) ($observation['target_snapshot_hash'] ?? ''))) {
                return array_replace($base, ['reason' => 'target_mismatch']);
            }
            $resolved[] = $observation;
            $accepted[] = ['ref' => $ref, 'claim' => mb_strcut(trim($item['claim']), 0, 500)];
        }

        if ($hasMutation && ! array_filter($resolved, static fn (array $entry): bool => is_int($entry['sequence'] ?? null) && $entry['sequence'] > $lastMutationSequence)) {
            return array_replace($base, ['reason' => 'stale_evidence']);
        }
        if ($hasMutation && ! array_filter($resolved, static fn (array $entry): bool => ! self::isMutatingHistoryEntry($entry) && is_int($entry['sequence'] ?? null) && $entry['sequence'] > $lastMutationSequence)) {
            return array_replace($base, ['reason' => 'no_post_mutation_observation']);
        }
        if (! array_filter($resolved, static fn (array $entry): bool => self::isPositiveEvidence($entry, $lastMutation, $hasMutation))) {
            return array_replace($base, ['reason' => 'no_positive_result']);
        }
        if (! $recoveryChecked) return array_replace($base, [
            'reason' => self::hasUncertainShellCommand($history) ? 'uncertain_command_scope_unknown' : 'recovery_not_revalidated',
        ]);
        if (! $targetRevalidated) return array_replace($base, ['reason' => 'target_not_revalidated']);

        return [
            'valid' => true, 'reason' => null, 'evidence' => $accepted,
            'recovery_state_checked' => $recoveryChecked, 'target_state_revalidated' => $targetRevalidated,
            'has_mutation' => $hasMutation,
        ];
    }

    public static function rejectionMessage(?string $reason): string
    {
        return match ($reason) {
            'legacy_format' => 'Les anciennes preuves en texte libre ne sont plus acceptées; cite un UUID d’observation serveur avec evidence_schema_version=1.',
            'unknown_ref' => 'Une référence de preuve est inconnue; cite uniquement un id présent dans les observations du serveur.',
            'stale_evidence' => 'La preuve précède la dernière action modificatrice; une observation actuelle est nécessaire.',
            'no_post_mutation_observation' => 'Aucune observation non modificatrice postérieure à la dernière action n’a été fournie.',
            'target_mismatch' => 'La preuve porte sur une autre cible ou une ancienne empreinte; inspecte la cible courante.',
            'recovery_not_revalidated' => 'L’opération incertaine n’a pas été vérifiée après son interruption sur le même chemin.',
            'uncertain_command_scope_unknown' => 'La commande interrompue peut avoir modifié plusieurs chemins inconnus; aucune lecture unique ne peut lever cette incertitude automatiquement. Inspecte manuellement les effets avant de créer une nouvelle tâche; DevPilot ne relancera pas la commande.',
            'target_not_revalidated' => 'La cible n’a pas été inspectée après la pause sur son empreinte actuelle.',
            default => 'Aucun résultat positif objectif ne confirme l’objectif; fournis une lecture, une vérification de présence/absence complète ou une commande diagnostique réussie.',
        };
    }

    private static function validIdentity(array $entry): bool
    {
        return Str::isUuid((string) ($entry['id'] ?? ''))
            && is_int($entry['sequence'] ?? null) && $entry['sequence'] > 0;
    }

    private static function isMutatingHistoryEntry(array $entry): bool
    {
        if (array_key_exists('mutating', $entry)) return $entry['mutating'] === true;
        if (($entry['kind'] ?? null) === 'environment_change') return false;
        if (($entry['kind'] ?? null) === 'file') return true;
        if (($entry['kind'] ?? null) === 'operation') {
            return ! (($entry['action'] ?? null) === 'run_command'
                && ($entry['approval_required'] ?? true) === false
                && DevPilotCommandPolicy::isNonMutatingAutomaticRule(is_string($entry['automatic_rule'] ?? null) ? $entry['automatic_rule'] : null));
        }

        return false;
    }

    private static function isPositiveEvidence(array $entry, ?array $lastMutation, bool $hasMutation): bool
    {
        $facts = is_array($entry['facts'] ?? null) ? $entry['facts'] : [];
        $source = $entry['source'] ?? null;
        $kind = $entry['evidence_kind'] ?? $entry['kind'] ?? null;
        if ($source === 'execution' && $kind === 'operation') {
            return ($entry['action'] ?? null) === 'run_command'
                && ! self::isMutatingHistoryEntry($entry)
                && ($facts['status'] ?? null) === 'completed'
                && ($facts['exit_code'] ?? null) === 0;
        }
        if ($source !== 'inspection') return false;

        if (str_starts_with((string) $kind, 'wordpress_')) {
            if ($kind === 'wordpress_cli_readback') {
                return ($facts['status'] ?? null) === 'read' && ($facts['complete'] ?? false) === true
                    && ($facts['verified'] ?? false) === true && is_string($facts['sha256'] ?? null);
            }
            if ($kind === 'wordpress_public_verification') {
                return ($facts['status'] ?? null) === 'verified' && ($facts['verified'] ?? false) === true;
            }
            $isRead = in_array($kind, ['wordpress_content_read', 'wordpress_media_read', 'wordpress_settings_read'], true);
            if (in_array($kind, ['wordpress_content_readback', 'wordpress_settings_readback', 'wordpress_plugin_readback'], true)) {
                return ($facts['complete'] ?? false) === true
                    && ($facts['status'] ?? null) === 'read'
                    && ($facts['rest_readback_matches'] ?? true) === true
                    && is_string($facts['sha256'] ?? null);
            }
            if (($facts['complete'] ?? false) !== true) return false;
            return $isRead
                ? (($facts['status'] ?? null) === 'read' && is_string($facts['sha256'] ?? null))
                : (($facts['status'] ?? null) === 'listed');
        }

        if ($kind === 'file_read') {
            if (($facts['status'] ?? null) !== 'read' || ! is_string($facts['sha256'] ?? null)) return false;
            if (! $hasMutation || ! is_array($lastMutation)) return true;
            $lastAction = strtolower((string) ($lastMutation['action'] ?? ''));
            $expectedSha = is_array($lastMutation['facts'] ?? null) ? (string) ($lastMutation['facts']['sha256'] ?? '') : '';
            return str_replace('\\', '/', (string) ($entry['target'] ?? '')) === str_replace('\\', '/', (string) ($lastMutation['target'] ?? ''))
                && str_contains($lastAction, 'written')
                && $expectedSha !== '' && hash_equals($expectedSha, $facts['sha256']);
        }
        if ($kind === 'mcp_connector_readback') {
            return ($facts['status'] ?? null) === 'read'
                && ($facts['complete'] ?? false) === true
                && ($facts['verified'] ?? false) === true
                && is_string($facts['sha256'] ?? null)
                && ($facts['rest_readback_matches'] ?? false) === true
                && (($facts['public_verification'] ?? null) === 'not_applicable' || ($facts['public_verified'] ?? false) === true);
        }
        if ($kind === 'directory_listing') {
            if (($facts['status'] ?? null) !== 'listed') return false;
            if (! $hasMutation) return ($facts['complete'] ?? false) === true;
            if (! is_array($lastMutation) || ! in_array(($lastMutation['kind'] ?? null), ['file', 'operation'], true)) return false;
            $mutationTarget = str_replace('\\', '/', (string) ($lastMutation['target'] ?? ''));
            $parent = str_replace('\\', '/', dirname($mutationTarget));
            if ($parent === '') $parent = '.';
            if (str_replace('\\', '/', (string) ($entry['target'] ?? '')) !== $parent) return false;
            $entryHash = hash('sha256', $mutationTarget);
            $present = in_array($entryHash, $facts['entry_hashes'] ?? [], true);
            $lastAction = strtolower((string) ($lastMutation['action'] ?? ''));
            if (str_contains($lastAction, 'deleted')) return ($facts['complete'] ?? false) === true && ! $present;
            return $present;
        }
        if (in_array($kind, ['search_files', 'search_content'], true)) {
            $matches = is_array($facts['match_hashes'] ?? null) ? $facts['match_hashes'] : [];
            if ($matches !== []) {
                if (! $hasMutation || ! is_array($lastMutation)) return true;
                return in_array(hash('sha256', str_replace('\\', '/', (string) ($lastMutation['target'] ?? ''))), $matches, true);
            }
            return ($facts['complete'] ?? false) === true && ! $hasMutation;
        }

        return false;
    }

    private static function hasPostUncertaintyCheck(array $history, array $exploration, string $targetHash): bool
    {
        $uncertain = null;
        foreach ($history as $entry) {
            if (! is_array($entry)) continue;
            $status = $entry['facts']['status'] ?? $entry['status'] ?? null;
            if (! in_array($status, ['uncertain', 'operation_uncertain', 'command_timeout', 'result_unknown_after_worker_interruption'], true)) continue;
            $uncertain = $entry;
        }
        if (! is_array($uncertain) || ($uncertain['action'] ?? null) === 'run_command') return false;
        $target = str_replace('\\', '/', (string) ($uncertain['target'] ?? ''));
        foreach (is_array($exploration['observations'] ?? null) ? $exploration['observations'] : [] as $entry) {
            if (! is_array($entry)) continue;
            if (($entry['sequence'] ?? 0) <= ($uncertain['sequence'] ?? 0) || ($entry['target_snapshot_hash'] ?? null) !== $targetHash) continue;
            if (($entry['kind'] ?? null) === 'file_read') {
                if (($entry['target'] ?? null) === $target && ($entry['facts']['status'] ?? null) === 'read') return true;
                continue;
            }
            $action = (string) ($uncertain['action'] ?? '');
            $kind = (string) ($entry['kind'] ?? '');
            $wpRead = ($action === 'wordpress_cli' && $kind === 'wordpress_cli_readback')
                || (str_starts_with($action, 'wordpress_') && match (true) {
                str_contains($action, 'content') => in_array($kind, ['wordpress_content_read', 'wordpress_content_list'], true),
                str_contains($action, 'revision') => $kind === 'wordpress_content_read',
                str_contains($action, 'media') => in_array($kind, ['wordpress_media_read', 'wordpress_media_list'], true),
                str_contains($action, 'setting') => $kind === 'wordpress_settings_read',
                str_contains($action, 'plugin') => in_array($kind, ['wordpress_plugins_list', 'wordpress_plugin_updates', 'wordpress_plugin_directory', 'wordpress_plugin_metadata', 'wordpress_plugin_integrity', 'wordpress_plugin_settings', 'wordpress_plugin_snapshots'], true),
                default => false,
            });
            $mcpRead = $action === 'connector_action' && $kind === 'mcp_connector_readback';
            $observedTarget = (string) ($entry['target'] ?? '');
            $targetMatches = $action === 'wordpress_cli'
                ? hash_equals($target, $observedTarget)
                : ($observedTarget === $target || ($observedTarget !== '' && str_starts_with($target, rtrim($observedTarget, ':').':')));
            if (str_contains($action, 'plugin') && $kind === 'wordpress_plugins_list' && $observedTarget === 'plugins' && str_starts_with($target, 'plugin:')) $targetMatches = true;
            if ($mcpRead) $targetMatches = hash_equals($target, $observedTarget);
            if (($wpRead || $mcpRead) && $targetMatches && in_array($entry['facts']['status'] ?? null, ['read', 'listed'], true)
                && ($entry['facts']['complete'] ?? false) === true
                && (! $mcpRead || ($entry['facts']['verified'] ?? false) === true)) return true;
        }

        return false;
    }

    private static function hasUncertainShellCommand(array $history): bool
    {
        $uncertain = null;
        foreach ($history as $entry) {
            if (! is_array($entry)) continue;
            $status = $entry['facts']['status'] ?? $entry['status'] ?? null;
            if (! in_array($status, ['uncertain', 'operation_uncertain', 'command_timeout', 'result_unknown_after_worker_interruption'], true)) continue;
            $uncertain = $entry;
        }

        return is_array($uncertain) && ($uncertain['action'] ?? null) === 'run_command';
    }

    private static function hasPostPauseCheck(array $history, array $exploration, string $targetHash): bool
    {
        $pause = null;
        foreach ($history as $entry) {
            if (is_array($entry) && ($entry['evidence_kind'] ?? null) === 'user_pause') $pause = $entry;
        }
        if (! is_array($pause)) return false;
        foreach (is_array($exploration['observations'] ?? null) ? $exploration['observations'] : [] as $entry) {
            if (! is_array($entry) || ($entry['sequence'] ?? 0) <= ($pause['sequence'] ?? 0)) continue;
            if (($entry['target_snapshot_hash'] ?? null) === $targetHash && ($entry['facts']['status'] ?? null) !== 'error') return true;
        }

        return false;
    }
}
