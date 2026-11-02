<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotTask;
use App\Domain\MCP\Security\CredentialVault;
use RuntimeException;

final class RemoteFileSystemFactory
{
    public function __construct(private readonly CredentialVault $vault) {}

    public function forProject(DevPilotProject $project, ?DevPilotTask $task = null): RemoteFileSystem
    {
        if ($project->wordpress_profile_id !== null) {
            $connection = null;
            foreach (['files', 'ssh'] as $channel) {
                try {
                    $connection = (new DevPilotChannelResolver())->resolve($project, $channel, ['read']);
                    break;
                } catch (RuntimeException) {
                    // Try only another channel explicitly selected in this WordPress profile.
                }
            }
            if ($connection) return $this->filesystemForConnection($connection, $project, $task);
            $restConnection = $project->wordpressProfile?->restConnection;
            if ($restConnection
                && $restConnection->site_id === $project->site_id
                && $restConnection->protocol === 'wordpress'
                && $restConnection->status === 'connected'
                && $restConnection->can_read) {
                return new DevPilotNoFileSystem();
            }
            throw new RuntimeException('Aucun canal fichiers/SSH autorisé ni canal REST lisible n’est configuré pour ce profil WordPress.');
        }
        $connection = $project->connection;
        if (! $connection || $connection->site_id !== $project->site_id || $connection->status !== 'connected') {
            throw new RuntimeException('Project has no verified active remote connection.');
        }
        if (! $connection->can_read) throw new RuntimeException('Read permission is disabled for this connection.');

        return $this->filesystemForConnection($connection, $project, $task);
    }

    /** Resolve exactly one explicitly selected filesystem or SSH profile channel. */
    public function forProjectChannel(DevPilotProject $project, string $channel, ?DevPilotTask $task = null, array $requiredCapabilities = ['read']): RemoteFileSystem
    {
        if (! in_array($channel, ['files', 'ssh'], true)) throw new RuntimeException('Ce canal ne possède pas d’adaptateur de système de fichiers.');
        $connection = $project->wordpress_profile_id !== null
            ? (new DevPilotChannelResolver())->resolve($project, $channel, $requiredCapabilities)
            : $project->connection;
        if (! $connection || $connection->site_id !== $project->site_id || $connection->status !== 'connected'
            || ($channel === 'ssh' && $connection->protocol !== 'ssh')
            || ($channel === 'files' && ! in_array($connection->protocol, ['sftp', 'ftp'], true))) {
            throw new RuntimeException('La connexion du canal demandé n’est pas disponible pour ce projet.');
        }
        if ($project->wordpress_profile_id === null) {
            foreach ($requiredCapabilities as $capability) {
                $allowed = match ($capability) {
                    'read' => (bool) $connection->can_read,
                    'write' => (bool) $connection->can_write,
                    'delete' => (bool) $connection->can_delete,
                    'commands' => $connection->protocol === 'ssh' && (bool) $connection->can_run_commands,
                    default => false,
                };
                if (! $allowed) throw new RuntimeException('La connexion ne possède pas la capacité requise.');
            }
        }

        return $this->filesystemForConnection($connection, $project, $task);
    }

    private function filesystemForConnection(DevPilotConnection $connection, DevPilotProject $project, ?DevPilotTask $task): RemoteFileSystem
    {
        $secrets = $this->vault->open((string) $connection->credentials_encrypted);
        if ($secrets === null) throw new RuntimeException('Stored connection credentials could not be decrypted.');
        $secrets = DevPilotConnectionCredentials::verify($connection, $secrets);
        $options = [
            'connection' => $connection->only([
                'protocol', 'host', 'port', 'username', 'auth_method', 'root_path', 'host_key_sha256',
                'private_network_authorized', 'can_read', 'can_write', 'can_delete', 'can_run_commands', 'probe_sudo', 'command_execution_mode', 'can_manage_databases',
                'allowed_commands', 'database_name',
            ]),
            'secrets' => $secrets,
            'project_root' => (string) $project->root_path,
            'task' => $task,
            'cancellation_check' => $task ? static function () use ($task): void {
                $current = $task->fresh();
                if (! $current || $current->cancel_requested) {
                    throw new RuntimeException('Task cancelled by user.');
                }
                if (! in_array($current->status, ['running', 'executing'], true)) {
                    throw new RuntimeException('Task execution ownership changed; stop remote activity.');
                }
            } : null,
        ];

        return match ($connection->protocol) {
            'ssh', 'sftp' => new PhpseclibSftpFileSystem($options),
            'ftp' => new NativeFtpFileSystem($options),
            'ftps' => throw new RuntimeException('FTPS is disabled until the remote certificate can be verified securely.'),
            default => throw new RuntimeException('This connection does not expose a remote file system.'),
        };
    }

    public function forConnection(array $connection, array $secrets, string $projectRoot = '.'): RemoteFileSystem
    {
        $options = ['connection' => $connection, 'secrets' => $secrets, 'project_root' => $projectRoot];

        return match ($connection['protocol'] ?? '') {
            'ssh', 'sftp' => new PhpseclibSftpFileSystem($options),
            'ftp' => new NativeFtpFileSystem($options),
            'ftps' => throw new RuntimeException('FTPS is disabled until the remote certificate can be verified securely.'),
            default => throw new RuntimeException('This connection protocol is not available for remote files.'),
        };
    }
}
