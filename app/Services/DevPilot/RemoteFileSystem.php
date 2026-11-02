<?php

namespace App\Services\DevPilot;

interface RemoteFileSystem
{
    public function test(bool $probeCommands = true): array;
    public function refreshDetectedTools(bool $timeoutAvailable = false): array;
    public function list(string $path = '.'): array;
    public function read(string $path): string;
    public function write(string $path, string $content, ?string $expectedSha256): array;
    public function delete(string $path, ?string $expectedSha256): array;
    public function rename(string $path, string $newPath, ?string $expectedSha256): array;
    public function mkdir(string $path): array;
    public function search(string $query, string $path = '.'): array;
    public function runAllowedCommand(string $command, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false): array;
    /** Execute only a server-built WP-CLI argv tuple through the SSH command policy. */
    public function runAllowedArgv(array $arguments, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false, bool $privateUmask = false): array;
}
