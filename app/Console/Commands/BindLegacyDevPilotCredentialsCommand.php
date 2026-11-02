<?php

namespace App\Console\Commands;

use App\Domain\MCP\Security\CredentialVault;
use App\Models\DevPilot\DevPilotConnection;
use App\Services\DevPilot\DevPilotConnectionCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BindLegacyDevPilotCredentialsCommand extends Command
{
    protected $signature = 'devpilot:bind-legacy-credentials {--dry-run : Compter les changements sans modifier les connexions}';

    protected $description = 'Lie les identifiants DevPilot hérités à leur cible distante.';

    public function handle(CredentialVault $vault): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'bound' => 0,
            'would_bind' => 0,
            'already_bound' => 0,
            'unverified' => 0,
            'would_mark_unverified' => 0,
            'skipped' => 0,
        ];

        DevPilotConnection::query()
            ->whereNotNull('credentials_encrypted')
            ->orderBy('id')
            ->chunkById(100, function ($connections) use ($vault, $dryRun, &$counts): void {
                foreach ($connections as $candidate) {
                    $result = DB::transaction(function () use ($candidate, $vault, $dryRun): string {
                        $connection = DevPilotConnection::query()->lockForUpdate()->find($candidate->id);
                        if (! $connection || ! is_string($connection->credentials_encrypted) || $connection->credentials_encrypted === '') {
                            return 'skipped';
                        }

                        try {
                            $credentials = $vault->open($connection->credentials_encrypted);
                        } catch (Throwable) {
                            $credentials = null;
                        }

                        if (! is_array($credentials) || ! $this->hasAuthenticationSecret($connection, $credentials)) {
                            return $this->markUnverified($connection, $dryRun);
                        }

                        if (array_key_exists('bound_to', $credentials)) {
                            try {
                                DevPilotConnectionCredentials::verify($connection, $credentials);

                                return 'already_bound';
                            } catch (Throwable) {
                                return $this->markUnverified($connection, $dryRun);
                            }
                        }

                        if ($dryRun) {
                            return 'would_bind';
                        }

                        $connection->credentials_encrypted = $vault->seal(DevPilotConnectionCredentials::bind(
                            (string) $connection->site_id,
                            $connection->getAttributes(),
                            $credentials,
                        ));
                        $connection->save();

                        return 'bound';
                    });

                    $counts[$result]++;
                }
            }, 'id');

        $this->line(sprintf(
            '%s %d liée(s), %d déjà liée(s), %d marquée(s) non vérifiée(s), %d ignorée(s).',
            $dryRun ? 'Simulation :' : 'Résultat :',
            $counts['bound'] + $counts['would_bind'],
            $counts['already_bound'],
            $counts['unverified'] + $counts['would_mark_unverified'],
            $counts['skipped'],
        ));
        if ($dryRun) {
            $this->comment('Aucune connexion n’a été modifiée.');
        }

        return self::SUCCESS;
    }

    private function hasAuthenticationSecret(DevPilotConnection $connection, array $credentials): bool
    {
        $field = in_array($connection->protocol, ['ssh', 'sftp'], true) && $connection->auth_method === 'private_key'
            ? 'private_key'
            : 'password';

        return is_string($credentials[$field] ?? null) && trim($credentials[$field]) !== '';
    }

    private function markUnverified(DevPilotConnection $connection, bool $dryRun): string
    {
        if ($dryRun) {
            return 'would_mark_unverified';
        }

        $connection->forceFill([
            'status' => 'unverified',
            'capabilities' => null,
            'last_test_result' => null,
            'last_tested_at' => null,
        ])->save();

        return 'unverified';
    }
}
