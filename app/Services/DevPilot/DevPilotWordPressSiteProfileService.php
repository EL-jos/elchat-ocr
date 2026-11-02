<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotWordPressSiteProfile;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class DevPilotWordPressSiteProfileService
{
    public function create(Site $site, array $input): DevPilotWordPressSiteProfile
    {
        return DevPilotWordPressSiteProfile::query()->create([
            'id' => (string) Str::uuid(),
            'site_id' => (string) $site->id,
            ...$this->attributes((string) $site->id, $input),
        ]);
    }

    public function update(DevPilotWordPressSiteProfile $profile, array $input): DevPilotWordPressSiteProfile
    {
        return DB::transaction(function () use ($profile, $input): DevPilotWordPressSiteProfile {
            $profile->fill($this->attributes((string) $profile->site_id, $input, $profile));
            $profile->save();
            $primaryChannelId = $profile->file_connection_id ?: $profile->ssh_connection_id;
            $profile->projects()->update(['connection_id' => $primaryChannelId]);

            return $profile->fresh();
        });
    }

    /** @return array<string,mixed> */
    private function attributes(string $siteId, array $input, ?DevPilotWordPressSiteProfile $existing = null): array
    {
        $name = trim((string) ($input['name'] ?? $existing?->name ?? ''));
        if ($name === '') throw new RuntimeException('Le profil WordPress doit avoir un nom.');

        $ids = [];
        foreach (['rest_connection_id', 'file_connection_id', 'ssh_connection_id'] as $field) {
            $ids[$field] = array_key_exists($field, $input)
                ? ($input[$field] !== null && $input[$field] !== '' ? (string) $input[$field] : null)
                : ($existing?->{$field} !== null ? (string) $existing->{$field} : null);
        }

        if ($ids['rest_connection_id'] === null
            && $ids['file_connection_id'] === null
            && $ids['ssh_connection_id'] === null) {
            throw new RuntimeException('Sélectionnez au moins un canal WordPress : REST, fichiers ou SSH.');
        }

        $rest = $this->connectionFor($siteId, $ids['rest_connection_id'], ['wordpress'], 'REST');
        $files = $this->connectionFor($siteId, $ids['file_connection_id'], ['sftp', 'ftp'], 'fichiers');
        $ssh = $this->connectionFor($siteId, $ids['ssh_connection_id'], ['ssh'], 'SSH');

        $rootForm = null;
        $canonicalUrl = null;
        if ($rest) {
            if ($rest->status !== 'connected'
                || ! $rest->can_read
                || ($rest->capabilities['rest_api'] ?? false) !== true) {
                throw new RuntimeException('La connexion REST doit réussir son test de lecture avant d’être liée au profil.');
            }
            $rootForm = $rest->capabilities['rest_root_form'] ?? null;
            if (! in_array($rootForm, ['wp-json', 'rest_route'], true)) {
                throw new RuntimeException('La forme de racine REST doit être détectée par le test de connexion.');
            }
            $canonicalUrl = DevPilotWordPressUrl::normalize((string) $rest->site_url)['site_url'];
            $submittedUrl = $input['canonical_site_url'] ?? null;
            if (is_string($submittedUrl) && trim($submittedUrl) !== ''
                && DevPilotWordPressUrl::normalize($submittedUrl)['site_url'] !== $canonicalUrl) {
                throw new RuntimeException('L’URL canonique du profil doit correspondre à celle de la connexion REST.');
            }
        } else {
            $submittedUrl = array_key_exists('canonical_site_url', $input)
                ? $input['canonical_site_url']
                : $existing?->canonical_site_url;
            if (is_string($submittedUrl) && trim($submittedUrl) !== '') {
                $canonicalUrl = DevPilotWordPressUrl::normalize($submittedUrl)['site_url'];
            }
        }

        if ($files && ($files->status !== 'connected' || ! $files->can_read)) {
            throw new RuntimeException('La connexion fichiers doit être vérifiée et autoriser la lecture.');
        }
        if ($ssh && ($ssh->status !== 'connected'
            || ! $ssh->can_run_commands
            || DevPilotCommandPolicy::executionMode($ssh->command_execution_mode, true) === 'disabled')) {
            throw new RuntimeException('La connexion SSH doit être vérifiée et autoriser les commandes.');
        }

        return [
            'name' => $name,
            'canonical_site_url' => $canonicalUrl,
            ...$ids,
            'rest_root_form' => $rootForm,
        ];
    }

    /** @param list<string> $protocols */
    private function connectionFor(string $siteId, ?string $id, array $protocols, string $channel): ?DevPilotConnection
    {
        if ($id === null) return null;
        $connection = DevPilotConnection::query()->where('site_id', $siteId)->whereKey($id)->first();
        if (! $connection) throw new RuntimeException("La connexion du canal {$channel} n’appartient pas à ce site.");
        if (! in_array($connection->protocol, $protocols, true)) {
            throw new RuntimeException("Le protocole de la connexion sélectionnée ne convient pas au canal {$channel}.");
        }

        return $connection;
    }
}
