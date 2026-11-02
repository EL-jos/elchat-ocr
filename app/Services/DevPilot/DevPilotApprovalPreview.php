<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotTask;

/** Builds the approval-only view after authenticating the decrypted proposal. */
final class DevPilotApprovalPreview
{
    public static function changes(DevPilotTask $task, array $decryptedProposal): ?array
    {
        if (! hash_equals((string) $task->proposal_hash, DevPilotProposalFingerprint::forTask($task, $decryptedProposal))) {
            return null;
        }

        $changes = $decryptedProposal['changes'] ?? [];
        if (! is_array($changes)) return null;
        foreach ($changes as $change) {
            if (! is_array($change)) return null;
            if (($change['action'] ?? null) === 'write'
                && (! is_string($change['path'] ?? null)
                    || ! is_string($change['content'] ?? null)
                    || (array_key_exists('previous_content', $change)
                        && $change['previous_content'] !== null
                        && ! is_string($change['previous_content'])))) {
                return null;
            }
        }

        return array_map(static function (array $change): array {
            $action = (string) ($change['action'] ?? '');
            $preview = array_intersect_key($change, array_flip(['action', 'path', 'content', 'summary', 'expected_sha256', 'risk', 'warnings'])) + [
                'risk' => $action === 'delete' ? 'sensitive' : 'project_change',
            ];

            if ($action === 'write') {
                $isNewFile = ! array_key_exists('previous_content', $change) || $change['previous_content'] === null;
                $preview['diff'] = $isNewFile ? null : DevPilotUnifiedDiff::generate(
                    (string) ($change['path'] ?? ''),
                    (string) $change['previous_content'],
                    (string) ($change['content'] ?? ''),
                );
                $preview['is_new_file'] = $isNewFile;
            } elseif ($action === 'delete') {
                $preview['is_deleted'] = true;
            }

            return $preview;
        }, $changes);
    }

    /** Build a presentation contract that does not require the UI to know operation types. */
    public static function generic(
        DevPilotTask $task,
        DevPilotProject $project,
        array $changes,
        array $operations,
        ?string $confirmationPhrase,
        bool $hasAutomaticOperations,
    ): array {
        $diffs = [];
        $warnings = [];
        foreach ($changes as $index => $change) {
            $action = (string) ($change['action'] ?? '');
            $title = match ($action) {
                'write' => 'Écriture de fichier',
                'mkdir' => 'Création de dossier',
                'delete' => 'Suppression de fichier',
                default => 'Changement',
            };
            $path = is_string($change['path'] ?? null) ? $change['path'] : '';
            $summary = is_string($change['summary'] ?? null) ? $change['summary'] : '';
            $diff = is_string($change['diff'] ?? null) ? $change['diff'] : null;
            $content = is_string($change['content'] ?? null) ? $change['content'] : null;
            $text = $path !== '' ? "Cible : {$path}\n" : '';
            $text .= $diff ?? ($content !== null ? $content : $summary);
            if ($summary !== '' && $diff !== null) $text .= "\n\nRésumé : {$summary}";
            $diffs[] = ['title' => $title.' · élément '.($index + 1), 'text' => $text];
            foreach (is_array($change['warnings'] ?? null) ? $change['warnings'] : [] as $warning) {
                if (is_string($warning) && trim($warning) !== '') $warnings[] = $warning;
            }
        }

        foreach ($operations as $index => $operation) {
            $type = is_string($operation['type'] ?? null) ? $operation['type'] : '';
            $title = match ($type) {
                'run_command' => 'Commande distante',
                'create_database' => 'Création de base de données',
                'wordpress_create_content' => 'Créer un contenu WordPress',
                'wordpress_update_content' => 'Modifier un contenu WordPress',
                'wordpress_trash_content' => 'Mettre un contenu à la corbeille',
                'wordpress_restore_content' => 'Restaurer un contenu WordPress',
                'wordpress_restore_revision' => 'Restaurer une révision WordPress',
                'wordpress_update_media_metadata' => 'Modifier les métadonnées d’une image',
                'wordpress_update_setting' => 'Modifier un réglage WordPress',
                'wordpress_manage_plugin' => 'Gérer un plugin WordPress',
                'connector_action' => 'Action de connecteur MCP',
                default => ucfirst(str_replace('_', ' ', $type ?: 'Opération')),
            };
            if (($operation['approval_required'] ?? true) === false) $title .= ' · diagnostic automatique';
            $lines = [];
            foreach ([
                'summary' => 'Résumé', 'command' => 'Commande', 'working_directory' => 'Dossier',
                'execution_user' => 'Compte', 'database_name' => 'Base', 'database_protocol' => 'Protocole',
                'database_host' => 'Hôte', 'database_port' => 'Port', 'database_connection_name' => 'Connexion',
                'authorization_reason' => 'Décision', 'automatic_rule' => 'Règle automatique',
                'subcommand' => 'Opération WP-CLI', 'argv_display' => 'Tuple exécutable + arguments',
                'option_name' => 'Option', 'post_id' => 'Identifiant du contenu',
                'old_value' => 'Valeur recherchée', 'new_value' => 'Nouvelle valeur',
                'state_action' => 'Lecture d’état',
                'wordpress_type' => 'Type de contenu', 'id' => 'Identifiant',
                'setting_name' => 'Réglage', 'plugin_slug' => 'Slug du plugin', 'plugin_action' => 'Action du plugin',
                'theme_slug' => 'Slug du thème', 'theme_action' => 'Action du thème',
                'cache_command' => 'Commande de purge détectée',
            ] as $key => $label) {
                if (is_scalar($operation[$key] ?? null) && (string) $operation[$key] !== '') {
                    $lines[] = $label.': '.(string) $operation[$key];
                }
            }
            if (isset($operation['risk']) && is_string($operation['risk'])) {
                $lines[] = 'Niveau de risque : '.$operation['risk'];
            }
            foreach (is_array($operation['display_fields'] ?? null) ? $operation['display_fields'] : [] as $field) {
                if (! is_array($field) || ! is_string($field['label'] ?? null) || ! is_string($field['value'] ?? null)) continue;
                $lines[] = mb_strcut($field['label'], 0, 120).': '.mb_strcut($field['value'], 0, 1600);
            }
            foreach (is_array($operation['display_diff'] ?? null) ? $operation['display_diff'] : [] as $field => $change) {
                if (! is_array($change)) continue;
                $before = is_string($change['before'] ?? null) ? $change['before'] : '';
                $after = is_string($change['after'] ?? null) ? $change['after'] : '';
                $lines[] = 'Diff · '.$field.':'."\n".DevPilotUnifiedDiff::generate((string) $field, $before, $after);
            }
            if (($operation['public_verification'] ?? null) === 'not_applicable') $lines[] = 'Vérification publique : non applicable (contenu non publié).';
            $verification = is_array($operation['verification'] ?? null) ? $operation['verification'] : [];
            foreach (['must_contain' => 'Texte qui doit apparaître sur la page publique', 'must_not_contain' => 'Texte qui ne doit pas apparaître sur la page publique'] as $key => $label) {
                $conditions = is_array($verification[$key] ?? null) ? $verification[$key] : [];
                foreach ($conditions as $condition) if (is_string($condition)) $lines[] = $label.' : '.$condition;
            }
            if (($operation['public_verification'] ?? null) === 'required') {
                $hasTextConditions = ($verification['must_contain'] ?? []) !== [] || ($verification['must_not_contain'] ?? []) !== [];
                $lines[] = $hasTextConditions
                    ? 'La page publique sera relue après la relecture WordPress et comparée aux critères de texte ci-dessus.'
                    : 'La page publique sera relue après la relecture WordPress. Les critères de texte sont facultatifs et aucun n’a été fourni.';
            }
            $diffs[] = ['title' => $title.' · élément '.(count($changes) + $index + 1), 'text' => implode("\n", $lines)];
            foreach (is_array($operation['warnings'] ?? null) ? $operation['warnings'] : [] as $warning) {
                if (is_string($warning) && trim($warning) !== '') $warnings[] = $warning;
            }
        }

        $riskOrder = ['diagnostic' => 0, 'project_change' => 1, 'server_admin' => 2, 'sensitive' => 3, 'critical' => 4];
        $risk = 'diagnostic';
        foreach ([...$changes, ...$operations] as $item) {
            $candidate = is_string($item['risk'] ?? null) && isset($riskOrder[$item['risk']]) ? $item['risk'] : 'project_change';
            if ($riskOrder[$candidate] > $riskOrder[$risk]) $risk = $candidate;
        }

        $connection = $project->connection;
        $target = $project->wordpressProfile?->canonical_site_url
            ?: ($connection?->site_url ?: ($connection?->host ?? 'Cible configurée'));
        $channel = $project->wordpressProfile
            ? 'WordPress · '.(string) $project->wordpressProfile->canonical_site_url
            : ($connection?->protocol ?? 'Aucun canal connecté');
        $fields = [
            ['label' => 'Demande', 'value' => (string) $task->instruction],
            ['label' => 'Projet', 'value' => (string) $project->name],
            ['label' => 'Environnement', 'value' => (string) $project->environment],
            ['label' => 'Canal', 'value' => $channel],
            ['label' => 'Cible', 'value' => (string) $target],
            ['label' => 'Racine du projet', 'value' => (string) $project->root_path],
            ['label' => 'Empreinte', 'value' => (string) $task->proposal_hash],
        ];

        $warnings = array_values(array_unique($warnings));
        if ($diffs !== []) $warnings[] = 'Relisez le contenu et les résumés de chaque élément avant de valider.';
        if (in_array($risk, ['server_admin', 'sensitive', 'critical'], true)) $warnings[] = 'Cette proposition comporte une action à risque élevé.';
        if ($hasAutomaticOperations) $warnings[] = 'La proposition comporte également des diagnostics régis par les autorisations configurées.';

        return [
            'title' => 'Proposition DevPilot à approuver',
            'fields' => $fields,
            'diffs' => $diffs,
            'warnings' => $warnings,
            'risk' => $risk,
            'confirmation_phrase' => $confirmationPhrase,
        ];
    }
}
