<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotWordPressSiteProfile;

final class DevPilotTargetFingerprint
{
    public static function forProject(DevPilotProject $project): string
    {
        $connection = $project->connection;
        $encryptedCredentials = (string) $connection?->credentials_encrypted;

        $connectionTarget = $connection ? [
                'id' => (string) $connection->id,
                'site_id' => (string) $connection->site_id,
                'protocol' => (string) $connection->protocol,
                'host' => (string) $connection->host,
                'port' => (int) $connection->port,
                'username' => (string) $connection->username,
                'auth_method' => (string) $connection->auth_method,
                'root_path' => (string) $connection->root_path,
                'host_key_sha256' => (string) $connection->host_key_sha256,
                'private_network_authorized' => (bool) $connection->private_network_authorized,
                'can_read' => (bool) $connection->can_read,
                'can_write' => (bool) $connection->can_write,
                'can_delete' => (bool) $connection->can_delete,
                'can_run_commands' => (bool) $connection->can_run_commands,
                'probe_sudo' => (bool) $connection->probe_sudo,
                'command_execution_mode' => DevPilotCommandPolicy::executionMode($connection->command_execution_mode, (bool) $connection->can_run_commands),
                'can_manage_databases' => (bool) $connection->can_manage_databases,
                'allowed_commands' => $connection->allowed_commands ?? [],
                'credentials_sha256' => hash('sha256', $encryptedCredentials),
            ] : null;
        if ($connection && $connection->protocol === 'wordpress') {
            $connectionTarget['site_url'] = (string) $connection->site_url;
        }

        $target = [
            'site_id' => (string) $project->site_id,
            'project_connection_id' => (string) $project->connection_id,
            'project_root' => (string) $project->root_path,
            'project_environment' => (string) $project->environment,
            'connection' => $connectionTarget,
            'database_connection' => $project->databaseConnection ? [
                'id' => (string) $project->databaseConnection->id,
                'site_id' => (string) $project->databaseConnection->site_id,
                'protocol' => (string) $project->databaseConnection->protocol,
                'host' => (string) $project->databaseConnection->host,
                'port' => (int) $project->databaseConnection->port,
                'username' => (string) $project->databaseConnection->username,
                'database_name' => (string) $project->databaseConnection->database_name,
                'can_manage_databases' => (bool) $project->databaseConnection->can_manage_databases,
                'status' => (string) $project->databaseConnection->status,
                'credentials_sha256' => hash('sha256', (string) $project->databaseConnection->credentials_encrypted),
            ] : null,
        ];

        if ($project->wordpress_profile_id !== null) {
            $profile = $project->wordpressProfile;
            $target['wordpress_profile'] = $profile instanceof DevPilotWordPressSiteProfile ? [
                'id' => (string) $profile->id,
                'site_id' => (string) $profile->site_id,
                'canonical_site_url' => (string) $profile->canonical_site_url,
                'rest_root_form' => (string) $profile->rest_root_form,
                'rest_connection' => self::profileConnection($profile->restConnection),
                'file_connection' => self::profileConnection($profile->fileConnection),
                'ssh_connection' => self::profileConnection($profile->sshConnection),
            ] : [
                'id' => (string) $project->wordpress_profile_id,
                'missing' => true,
            ];
        }

        return hash('sha256', json_encode($target, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function profileConnection(?DevPilotConnection $connection): ?array
    {
        if (! $connection) return null;

        $snapshot = [
            'id' => (string) $connection->id,
            'site_id' => (string) $connection->site_id,
            'protocol' => (string) $connection->protocol,
            'host' => (string) $connection->host,
            'port' => (int) $connection->port,
            'username' => (string) $connection->username,
            'auth_method' => (string) $connection->auth_method,
            'root_path' => (string) $connection->root_path,
            'host_key_sha256' => (string) $connection->host_key_sha256,
            'private_network_authorized' => (bool) $connection->private_network_authorized,
            'can_read' => (bool) $connection->can_read,
            'can_write' => (bool) $connection->can_write,
            'can_delete' => (bool) $connection->can_delete,
            'can_run_commands' => (bool) $connection->can_run_commands,
            'probe_sudo' => (bool) $connection->probe_sudo,
            'can_manage_databases' => (bool) $connection->can_manage_databases,
            'command_execution_mode' => DevPilotCommandPolicy::executionMode($connection->command_execution_mode, (bool) $connection->can_run_commands),
            'allowed_commands' => $connection->allowed_commands ?? [],
            'credentials_sha256' => hash('sha256', (string) $connection->credentials_encrypted),
        ];
        if ($connection->protocol === 'wordpress') {
            $snapshot['site_url'] = (string) $connection->site_url;
            if ($connection->auth_method === 'devpilot_bridge_hmac') {
                $bridge = is_array($connection->capabilities['bridge'] ?? null) ? $connection->capabilities['bridge'] : [];
                $snapshot['bridge_identity'] = [
                    'protocol' => (string) ($bridge['protocol'] ?? ''),
                    'installation_id' => (string) ($bridge['installation_id'] ?? ''),
                    'blog_id' => (int) ($bridge['blog_id'] ?? 0),
                    'network_id' => $bridge['network_id'] ?? null,
                    'canonical_site_url' => (string) ($bridge['canonical_site_url'] ?? ''),
                    'key_id' => (string) ($bridge['key_id'] ?? ''),
                    'key_fingerprint' => (string) ($bridge['key_fingerprint'] ?? ''),
                ];
            }
        }

        return $snapshot;
    }
}
