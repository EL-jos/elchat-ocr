<?php

namespace App\Services\DevPilot;

use App\Domain\MCP\Security\CredentialVault;
use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotWordPressSiteProfile;
use App\Models\Site;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class DevPilotConnectionService
{
    private readonly DevPilotSecretRedactor $redactor;
    private readonly DevPilotWordPressClient $wordpress;
    private readonly DevPilotWordPressBridgePairingService $wordpressBridge;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly RemoteFileSystemFactory $fileSystems,
        private readonly DevPilotDatabaseService $databases,
        ?DevPilotSecretRedactor $redactor = null,
        ?DevPilotWordPressClient $wordpress = null,
        ?DevPilotWordPressBridgePairingService $wordpressBridge = null,
    ) {
        $this->redactor = $redactor ?? new DevPilotSecretRedactor();
        $this->wordpress = $wordpress ?? new DevPilotWordPressClient();
        $this->wordpressBridge = $wordpressBridge ?? new DevPilotWordPressBridgePairingService(
            $vault, $this->wordpress, new DevPilotWordPressBridgeChannel($this->wordpress),
        );
    }

    public function create(Site $site, array $input): DevPilotConnection
    {
        $attributes = $this->connectionAttributes($input);
        $credentials = $this->secretsFor($input);
        return DevPilotConnection::create([
            'id' => (string) Str::uuid(), 'site_id' => $site->id,
            ...$attributes,
            'credentials_encrypted' => $this->vault->seal(DevPilotConnectionCredentials::bind((string) $site->id, $attributes, $credentials)),
            'status' => 'unverified',
        ]);
    }

    public function update(DevPilotConnection $connection, array $input): DevPilotConnection
    {
        if ($connection->auth_method === 'devpilot_bridge_hmac') {
            throw new RuntimeException('Un canal ELChat-DevPilot jumelé ne peut pas être converti en connexion WordPress REST classique. Supprimez-le puis jumelez le plugin à nouveau.');
        }
        $attributes = $this->connectionAttributes($input, $connection, requireSshFingerprint: false);
        $targetChanged = $this->targetChanged($connection, $attributes);
        if ($connection->protocol !== ($attributes['protocol'] ?? null)
            && DevPilotWordPressSiteProfile::query()->where(function ($query) use ($connection): void {
                $query->where('rest_connection_id', $connection->id)
                    ->orWhere('file_connection_id', $connection->id)
                    ->orWhere('ssh_connection_id', $connection->id);
            })->exists()) {
            throw new RuntimeException('Retirez la connexion de son profil WordPress avant d’en changer le protocole.');
        }
        $hasNewSecret = trim((string) ($input['password'] ?? '')) !== ''
            || trim((string) ($input['private_key'] ?? '')) !== ''
            || DevPilotWordPressClient::normalizeApplicationPassword((string) ($input['application_password'] ?? '')) !== '';
        if ($targetChanged && ! $hasNewSecret) {
            if (($connection->protocol === 'wordpress' || ($input['protocol'] ?? null) === 'wordpress')) {
                throw new RuntimeException('Ressaisissez le mot de passe d’application quand la cible change.');
            }
            throw new RuntimeException('Ressaisissez le mot de passe ou la clé privée quand la cible change.');
        }
        $this->assertSshFingerprint($attributes);

        $credentials = $this->secretsFor($input, $connection, ! $targetChanged);
        $connection->fill($attributes);
        if ($credentials !== null) $connection->credentials_encrypted = $this->vault->seal(DevPilotConnectionCredentials::bind((string) $connection->site_id, $attributes, $credentials));
        $connection->status = 'unverified';
        $connection->capabilities = null;
        $connection->last_test_result = null;
        $connection->save();
        if ($connection->protocol === 'wordpress' && $targetChanged) {
            DevPilotWordPressSiteProfile::query()->where('rest_connection_id', $connection->id)->update([
                'canonical_site_url' => $attributes['site_url'],
                'rest_root_form' => null,
            ]);
        }
        return $connection->fresh();
    }

    public function test(DevPilotConnection $connection): array
    {
        try {
            $credentials = $this->credentials($connection);
            $result = $connection->protocol === 'wordpress' && $connection->auth_method === 'devpilot_bridge_hmac'
                ? $this->wordpressBridge->test($connection, $credentials)
                : $this->runTest($connection->toArray(), $credentials);
            $connection->forceFill([
                'status' => 'connected', 'capabilities' => $result['capabilities'] ?? [],
                'last_test_result' => $result, 'last_tested_at' => now(),
            ])->save();
            if ($connection->protocol === 'wordpress'
                && in_array($result['capabilities']['rest_root_form'] ?? null, ['wp-json', 'rest_route'], true)) {
                DevPilotWordPressSiteProfile::query()->where('rest_connection_id', $connection->id)->update([
                    'canonical_site_url' => $connection->site_url,
                    'rest_root_form' => $result['capabilities']['rest_root_form'],
                ]);
            }
            return $result;
        } catch (Throwable $exception) {
            $safeMessage = $this->failureMessage($exception);
            $this->logFailure('test_connection', $connection->protocol, $exception, $safeMessage);
            $connection->forceFill([
                'status' => $connection->status === 'pairing_uncertain' ? 'pairing_uncertain' : 'error',
                'capabilities' => $connection->auth_method === 'devpilot_bridge_hmac' ? $connection->capabilities : null,
                'last_test_result' => ['connected' => false, 'protocol' => $connection->protocol,
                    ...($connection->auth_method === 'devpilot_bridge_hmac' ? ['channel' => 'bridge'] : []), 'error' => $safeMessage],
                'last_tested_at' => now(),
            ])->save();
            throw new RuntimeException($safeMessage, 0, $exception);
        }
    }

    public function testInput(array $input): array
    {
        return $this->runTest(
            $this->connectionAttributes($input, allowInsecureWordPressProbe: true),
            $this->secretsFor($input),
        );
    }

    public function credentials(DevPilotConnection $connection): array
    {
        $credentials = $connection->credentials_encrypted ? $this->vault->open($connection->credentials_encrypted) : null;
        if (! is_array($credentials)) throw new RuntimeException('Connection credentials are unavailable or cannot be decrypted.');
        return DevPilotConnectionCredentials::verify($connection, $credentials);
    }

    private function targetChanged(DevPilotConnection $connection, array $newAttributes): bool
    {
        foreach (['host', 'port', 'username', 'protocol', 'auth_method', 'site_url'] as $field) {
            if ((string) $connection->getAttribute($field) !== (string) ($newAttributes[$field] ?? '')) return true;
        }
        return false;
    }

    public function safeConnection(DevPilotConnection $connection): array
    {
        return $connection->makeHidden(['credentials_encrypted'])->toArray();
    }

    public function failureMessage(Throwable $exception): string
    {
        $original = $exception->getMessage();
        foreach ([
            'Une URL HTTPS WordPress valide est obligatoire.',
            'WordPress exige une URL HTTPS sans identifiants, paramètres ni fragment.',
            'L’hôte de l’URL WordPress est invalide.',
            'Le port de l’URL WordPress est invalide.',
            'Le chemin de l’URL WordPress contient une séquence invalide.',
            'Le chemin de l’URL WordPress ne peut pas traverser un répertoire.',
            'La prise en charge IDN du serveur est indisponible.',
            'L’hôte IDN de l’URL WordPress est invalide.',
            'Un utilisateur WordPress et un mot de passe d’application sont obligatoires.',
            'L’hôte ne correspond pas à l’URL canonique de la connexion WordPress.',
            'La racine REST WordPress ne correspond pas à l’origine canonique du site.',
            'La REST API WordPress est introuvable ou bloquée. Vérifiez les permaliens et les règles de sécurité du site.',
            'Identifiants WordPress refusés. Vérifiez l’utilisateur et le mot de passe d’application; l’hébergement peut aussi retirer l’en-tête Authorization.',
            'Le jumelage exige un mot de passe d’application valide associé à un administrateur WordPress disposant de manage_options. Vérifiez le compte et son rôle dans WordPress.',
            'Le code temporaire WordPress est invalide, expiré ou a atteint sa limite d’essais. Générez un nouveau code et recommencez.',
            'Le plugin WordPress n’a pas approuvé toutes les capacités demandées. Approuvez les mêmes capacités dans ses réglages, puis générez un nouveau code.',
            'WordPress ne reconnaît pas la requête de jumelage comme HTTPS. Vérifiez la configuration HTTPS du proxy ou de l’hébergement WordPress.',
            'WordPress a refusé le jumelage (HTTP 403). Vérifiez le rôle administrateur, le code temporaire et les règles de sécurité qui filtrent le POST du pont.',
            'WordPress a refusé l’authentification du POST de jumelage (HTTP 401). Vérifiez le login, le mot de passe d’application actif et la transmission de l’en-tête Authorization pour les requêtes POST.',
            'Le jumelage reste incertain et ne peut pas être testé automatiquement, car WordPress n’a pas confirmé l’identité du blog. Vérifiez Réglages → ELChat DevPilot dans WordPress, puis révoquez le jumelage si l’installation y apparaît comme jumelée.',
            'La REST API WordPress est refusée. Un plugin de sécurité ou une règle d’hébergement peut bloquer l’accès.',
            'La REST API ou le compte WordPress refuse le test authentifié. Un plugin de sécurité peut bloquer l’accès.',
            'La REST API WordPress ne répond pas correctement. Vérifiez l’URL et les règles d’accès du site.',
            'La connexion HTTPS WordPress a échoué. Vérifiez le certificat TLS, l’hôte et le réseau.',
            'Le site répond en HTTP sans redirection vers HTTPS. Utilisez une URL HTTPS; aucun identifiant n’a été envoyé.',
            'La sonde HTTP sans identifiants n’a pas obtenu de redirection HTTPS. Vérifiez l’URL; aucun identifiant n’a été envoyé.',
            'La réponse WordPress dépasse la taille maximale autorisée.',
            'Le site WordPress limite les requêtes (HTTP 429). Réessayez plus tard; aucune nouvelle tentative automatique ne sera faite.',
            'Le site redirige la requête. Les redirections sont désactivées; vérifiez et mettez à jour l’URL HTTPS canonique.',
            'Le transport WordPress sécurisé exige l’extension cURL pour épingler l’adresse réseau.',
            'La route REST WordPress construite par le serveur est invalide.',
            'Hôte refusé par la politique réseau DevPilot.',
        ] as $safeMessage) {
            if ($original === $safeMessage || str_starts_with($original, $safeMessage)) return $original;
        }
        if (str_starts_with($original, 'Le site redirige la requête. L’URL canonique est ')
            && str_ends_with($original, '; mettez-la à jour puis retestez.')) {
            return mb_strcut($original, 0, 2200);
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'ressaisissez le mot de passe')) return 'Ressaisissez le mot de passe ou la clé privée quand la cible change.';
        if (str_contains($message, 'ftps is disabled')) return 'FTPS est désactivé tant que la validation du certificat distant n’est pas sécurisée.';
        if (str_contains($message, 'hôte refusé par la politique réseau')) return 'Hôte refusé par la politique réseau DevPilot.';
        if (str_contains($message, 'empreinte du serveur')) return 'L’empreinte du serveur ne correspond pas à celle confirmée pour cette connexion.';
        if (str_contains($message, 'authentification auprès du serveur distant')) return 'L’authentification auprès du serveur distant a échoué.';
        if (str_contains($message, 'dépassé le délai autorisé')) return 'La connexion au serveur distant a dépassé le délai autorisé.';
        if (str_contains($message, 'reserved address') || str_contains($message, 'private address') || str_contains($message, 'did not resolve') || str_contains($message, 'invalid ip address')) {
            return 'Hôte refusé par la politique réseau DevPilot.';
        }
        if (str_contains($message, 'fingerprint')) return 'L’empreinte du serveur ne correspond pas à celle confirmée pour cette connexion.';
        if (str_contains($message, 'authenticat') || str_contains($message, 'login failed') || str_contains($message, 'access denied')) {
            return 'L’authentification auprès du serveur distant a échoué.';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) return 'La connexion au serveur distant a dépassé le délai autorisé.';

        return 'Connexion au serveur distant impossible. Vérifiez l’hôte, le port, les identifiants et les capacités du service.';
    }

    public function logFailure(string $operation, ?string $protocol, Throwable $exception, ?string $safeMessage = null): void
    {
        Log::warning('DevPilot connection operation failed.', [
            'operation' => $operation,
            'protocol' => $protocol,
            'category' => $safeMessage,
            'exception' => $exception::class,
            'message' => mb_strcut($this->redactor->redact($exception->getMessage()), 0, 1000),
        ]);
    }

    private function runTest(array $attributes, array $credentials): array
    {
        $protocol = (string) ($attributes['protocol'] ?? '');
        if ($protocol === 'wordpress') return $this->wordpress->testConnection($attributes, $credentials);
        if (in_array($protocol, ['mysql', 'pgsql'], true)) return $this->databases->test($attributes, $credentials);
        return $this->fileSystems->forConnection($attributes, $credentials)->test();
    }

    private function connectionAttributes(
        array $input,
        ?DevPilotConnection $existing = null,
        bool $requireSshFingerprint = true,
        bool $allowInsecureWordPressProbe = false,
    ): array
    {
        $protocol = (string) $input['protocol'];
        if ($protocol === 'ftps') throw new RuntimeException('FTPS is disabled until the remote certificate can be verified securely.');
        if ($protocol === 'wordpress') {
            $site = DevPilotWordPressUrl::normalize((string) ($input['site_url'] ?? ''), $allowInsecureWordPressProbe);

            return [
                'name' => trim((string) $input['name']),
                'protocol' => 'wordpress',
                'site_url' => $site['site_url'],
                'host' => $site['host'],
                'port' => $site['port'],
                'username' => trim((string) ($input['username'] ?? '')),
                'database_name' => null,
                'auth_method' => 'application_password',
                'host_key_sha256' => null,
                'root_path' => null,
                'private_network_authorized' => (bool) ($input['private_network_authorized'] ?? false),
                'can_read' => (bool) ($input['can_read'] ?? true),
                'can_write' => (bool) ($input['can_write'] ?? false),
                'can_delete' => (bool) ($input['can_delete'] ?? false),
                'probe_sudo' => false,
                'command_execution_mode' => 'disabled',
                'can_run_commands' => false,
                'can_manage_databases' => false,
                'allowed_commands' => [],
            ];
        }
        $port = (int) ($input['port'] ?? match ($protocol) { 'ssh', 'sftp' => 22, 'mysql' => 3306, 'pgsql' => 5432, default => 21 });
        $attributes = [
            'name' => trim((string) $input['name']), 'protocol' => $protocol,
            'site_url' => null,
            'host' => $input['host'] ?? null, 'port' => $port,
            'username' => $input['username'] ?? null, 'database_name' => $input['database_name'] ?? null,
            'auth_method' => $input['auth_method'] ?? ($protocol === 'ssh' || $protocol === 'sftp' ? 'password' : 'password'),
            'host_key_sha256' => array_key_exists('host_key_sha256', $input)
                ? $input['host_key_sha256']
                : $existing?->host_key_sha256,
            'root_path' => $input['root_path'] ?? null,
            'private_network_authorized' => (bool) ($input['private_network_authorized'] ?? false),
            'can_read' => (bool) ($input['can_read'] ?? true), 'can_write' => (bool) ($input['can_write'] ?? false),
            'can_delete' => (bool) ($input['can_delete'] ?? false),
            'probe_sudo' => (bool) ($input['probe_sudo'] ?? $existing?->probe_sudo ?? false),
            'command_execution_mode' => $input['command_execution_mode'] ?? ((bool) ($input['can_run_commands'] ?? false) ? 'allowlist' : 'disabled'),
            'can_run_commands' => (bool) ($input['can_run_commands'] ?? false),
            'can_manage_databases' => (bool) ($input['can_manage_databases'] ?? false),
            'allowed_commands' => $input['allowed_commands'] ?? [],
        ];
        if ($protocol !== 'ssh') {
            $attributes['command_execution_mode'] = 'disabled';
            $attributes['can_run_commands'] = false;
            $attributes['probe_sudo'] = false;
        } else {
            $attributes['command_execution_mode'] = DevPilotCommandPolicy::executionMode($attributes['command_execution_mode'], true);
            $attributes['can_run_commands'] = $attributes['command_execution_mode'] !== 'disabled';
        }
        if ($attributes['command_execution_mode'] !== 'allowlist') $attributes['allowed_commands'] = [];
        if (in_array($protocol, ['ftp', 'mysql', 'pgsql'], true)) $attributes['can_delete'] = (bool) ($input['can_delete'] ?? false);
        if (in_array($protocol, ['mysql', 'pgsql'], true)) {
            $attributes['can_write'] = false;
            $attributes['can_delete'] = false;
            $attributes['can_run_commands'] = false;
        } else {
            $attributes['can_manage_databases'] = false;
        }
        if ($requireSshFingerprint) $this->assertSshFingerprint($attributes);

        return $attributes;
    }

    private function assertSshFingerprint(array $attributes): void
    {
        if (in_array($attributes['protocol'] ?? null, ['ssh', 'sftp'], true)
            && trim((string) ($attributes['host_key_sha256'] ?? '')) === '') {
            throw new RuntimeException('L’empreinte SHA-256 du serveur SSH est obligatoire pour les connexions SSH/SFTP.');
        }
    }

    private function secretsFor(array $input, ?DevPilotConnection $existing = null, bool $allowExisting = true): ?array
    {
        $protocol = (string) ($input['protocol'] ?? $existing?->protocol);
        if ($protocol === 'wordpress') {
            $password = DevPilotWordPressClient::normalizeApplicationPassword((string) ($input['application_password'] ?? ''));
            if ($password !== '') return ['application_password' => $password];
            if ($existing && $allowExisting) return $this->credentials($existing);
            if ($existing) throw new RuntimeException('Ressaisissez le mot de passe d’application quand la cible change.');
            throw new RuntimeException('Un utilisateur WordPress et un mot de passe d’application sont obligatoires.');
        }

        if (in_array($protocol, ['ssh', 'sftp'], true) && ($input['auth_method'] ?? $existing?->auth_method) === 'private_key') {
            $key = (string) ($input['private_key'] ?? '');
            $passphrase = (string) ($input['private_key_passphrase'] ?? '');
            if ($key !== '') return ['private_key' => $key, 'private_key_passphrase' => $passphrase];
            if ($existing && $allowExisting) return $this->credentials($existing);
            if ($existing) throw new RuntimeException('Ressaisissez le mot de passe ou la clé privée quand la cible change.');
            throw new RuntimeException('A private key is required for this SSH authentication method.');
        }
        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            return ['password' => $password, 'insecure_ftp_confirmed' => (bool) ($input['insecure_ftp_confirmed'] ?? false)];
        }
        if ($existing && $allowExisting) return $this->credentials($existing);
        if ($existing) throw new RuntimeException('Ressaisissez le mot de passe ou la clé privée quand la cible change.');
        throw new RuntimeException('A password or private key is required.');
    }
}
