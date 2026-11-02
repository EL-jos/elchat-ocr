<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotWordPressSiteProfile;
use RuntimeException;

/** Resolves only the explicitly selected profile channel; it never falls back to another channel. */
final class DevPilotChannelResolver
{
    private const CAPABILITIES = ['read', 'write', 'delete', 'commands'];

    /** @param list<string> $requiredCapabilities */
    public function resolve(DevPilotProject $project, string $channel, array $requiredCapabilities = []): DevPilotConnection
    {
        if (! in_array($channel, ['rest', 'files', 'ssh'], true)) {
            throw new RuntimeException('Canal WordPress inconnu.');
        }
        foreach ($requiredCapabilities as $capability) {
            if (! is_string($capability) || ! in_array($capability, self::CAPABILITIES, true)) {
                throw new RuntimeException('La capacité demandée au résolveur est inconnue.');
            }
        }

        $profile = $project->wordpressProfile;
        if (! $profile instanceof DevPilotWordPressSiteProfile
            || (string) $profile->site_id !== (string) $project->site_id
            || (string) $project->wordpress_profile_id !== (string) $profile->id) {
            throw new RuntimeException('Le projet ne possède pas de profil WordPress valide pour ce site.');
        }

        [$connectionField, $relation, $protocols] = match ($channel) {
            'rest' => ['rest_connection_id', 'restConnection', ['wordpress']],
            'files' => ['file_connection_id', 'fileConnection', ['sftp', 'ftp']],
            'ssh' => ['ssh_connection_id', 'sshConnection', ['ssh']],
        };
        $connectionId = $profile->{$connectionField};
        $connection = $profile->{$relation};
        if (! is_string($connectionId) || $connectionId === '' || ! $connection instanceof DevPilotConnection
            || (string) $connection->id !== (string) $connectionId) {
            throw new RuntimeException("Le profil ne configure pas le canal {$channel}.");
        }
        if ((string) $connection->site_id !== (string) $project->site_id
            || ! in_array($connection->protocol, $protocols, true)) {
            throw new RuntimeException("La connexion du canal {$channel} ne correspond pas au site ou au protocole attendu.");
        }
        if ($connection->status !== 'connected') {
            throw new RuntimeException("La connexion du canal {$channel} n’est pas vérifiée et connectée.");
        }

        $capabilities = $connection->capabilities ?? [];
        if ($channel === 'rest') {
            try {
                $profileUrl = DevPilotWordPressUrl::normalize((string) $profile->canonical_site_url)['site_url'];
                $connectionUrl = DevPilotWordPressUrl::normalize((string) $connection->site_url)['site_url'];
            } catch (RuntimeException) {
                throw new RuntimeException('Le canal REST ne possède pas d’URL canonique valide.');
            }
            if (! $connection->can_read
                || ($capabilities['rest_api'] ?? false) !== true
                || $profileUrl !== $connectionUrl
                || ! in_array($profile->rest_root_form, ['wp-json', 'rest_route'], true)
                || ($capabilities['rest_root_form'] ?? null) !== $profile->rest_root_form) {
                throw new RuntimeException('Le canal REST n’a pas de racine détectée cohérente et de lecture autorisée.');
            }
        }

        foreach (array_unique($requiredCapabilities) as $capability) {
            $allowed = match ($capability) {
                'read' => (bool) $connection->can_read,
                'write' => (bool) $connection->can_write,
                'delete' => (bool) $connection->can_delete,
                'commands' => $channel === 'ssh'
                    && (bool) $connection->can_run_commands
                    && DevPilotCommandPolicy::executionMode($connection->command_execution_mode, true) !== 'disabled',
            };
            if (! $allowed) {
                throw new RuntimeException("La connexion du canal {$channel} n’autorise pas la capacité requise {$capability}.");
            }
        }

        return $connection;
    }
}
