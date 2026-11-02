<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** Placeholder for REST-only WordPress profiles; every file operation fails closed. */
final class DevPilotNoFileSystem implements RemoteFileSystem
{
    public function test(bool $probeCommands = true): array
    {
        return [
            'connected' => true,
            'protocol' => 'wordpress',
            'capabilities' => [
                'list_files' => false, 'read_files' => false, 'write_files_configured' => false,
                'delete_files_configured' => false, 'shell' => false, 'shell_configured' => false,
                'command_execution_mode' => 'disabled',
            ],
        ];
    }

    public function refreshDetectedTools(bool $timeoutAvailable = false): array { return ['complete' => false, 'commands' => []]; }
    public function list(string $path = '.'): array { throw $this->unavailable(); }
    public function read(string $path): string { throw $this->unavailable(); }
    public function write(string $path, string $content, ?string $expectedSha256): array { throw $this->unavailable(); }
    public function delete(string $path, ?string $expectedSha256): array { throw $this->unavailable(); }
    public function rename(string $path, string $newPath, ?string $expectedSha256): array { throw $this->unavailable(); }
    public function mkdir(string $path): array { throw $this->unavailable(); }
    public function search(string $query, string $path = '.'): array { throw $this->unavailable(); }
    public function runAllowedCommand(string $command, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false): array { throw $this->unavailable(); }
    public function runAllowedArgv(array $arguments, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false, bool $privateUmask = false): array { throw $this->unavailable(); }

    private function unavailable(): RuntimeException
    {
        return new RuntimeException('Ce profil WordPress ne possède pas de canal fichiers ou SSH.');
    }
}
