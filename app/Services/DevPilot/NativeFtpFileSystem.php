<?php

namespace App\Services\DevPilot;

use RuntimeException;

final class NativeFtpFileSystem implements RemoteFileSystem
{
    private array $connection;
    private array $secrets;
    private string $projectRoot;
    private $cancellationCheck;

    public function __construct(array $options)
    {
        $this->connection = $options['connection'];
        $this->secrets = $options['secrets'];
        $this->projectRoot = $this->relativePath((string) ($options['project_root'] ?? '.'));
        $this->cancellationCheck = $options['cancellation_check'] ?? null;
    }

    public function test(bool $probeCommands = true): array
    {
        return $this->withFtp(function ($ftp): array {
            $entries = $this->listAt($ftp, '.');
            return [
                'connected' => true,
                'protocol' => $this->connection['protocol'],
                'root_path' => $this->projectRoot,
                'capabilities' => [
                    'list_files' => true, 'read_files' => true,
                    'write_files_configured' => (bool) ($this->connection['can_write'] ?? false),
                    'delete_files_configured' => (bool) ($this->connection['can_delete'] ?? false),
                    'shell' => false, 'commands_detected' => [], 'database' => false,
                    'symlink_metadata' => function_exists('ftp_mlsd') || function_exists('ftp_rawlist'),
                ],
                'root_entries' => count($entries),
                'restrictions' => ($this->connection['protocol'] ?? '') === 'ftp'
                    ? ['FTP classique ne chiffre pas les identifiants ni les fichiers transférés.']
                    : ['FTPS utilise une connexion TLS; les capacités dépendent des extensions FTP/OpenSSL disponibles sur le serveur ELChat.'],
            ];
        });
    }

    public function refreshDetectedTools(bool $timeoutAvailable = false): array
    {
        return ['commands_detected' => [], 'complete' => false, 'observations' => []];
    }

    public function list(string $path = '.'): array
    {
        return $this->withFtp(fn ($ftp) => $this->listAt($ftp, $this->relativePath($path)));
    }

    public function read(string $path): string
    {
        $path = $this->relativePath($path, false);
        return $this->withFtp(function ($ftp) use ($path): string {
            $this->assertSafePath($ftp, $path);
            $size = @ftp_size($ftp, $path);
            if (! is_int($size) || $size < 0 || $size > 262144) throw new RuntimeException('File is missing, unreadable, or larger than the 256 KB limit.');
            $stream = fopen('php://temp', 'w+b');
            if (! $stream || ! @ftp_fget($ftp, $stream, $path, FTP_BINARY)) throw new RuntimeException('FTP download failed.');
            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);
            if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) throw new RuntimeException('Binary or non-UTF-8 files cannot be sent to the language model.');
            return $content;
        });
    }

    public function write(string $path, string $content, ?string $expectedSha256): array
    {
        if (empty($this->connection['can_write'])) throw new RuntimeException('Write permission is disabled for this connection.');
        if (strlen($content) > 262144 || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) throw new RuntimeException('Only UTF-8 text files up to 256 KB can be written.');
        $path = $this->relativePath($path, false);
        return $this->withFtp(function ($ftp) use ($path, $content, $expectedSha256): array {
            $this->assertSafePath($ftp, $path, true);
            $exists = @ftp_size($ftp, $path) >= 0;
            $actual = $exists ? hash('sha256', $this->download($ftp, $path)) : null;
            if ($actual !== $expectedSha256) throw new RuntimeException('File changed since the task read it; the patch was not applied.');
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $content);
            rewind($stream);
            $ok = @ftp_fput($ftp, $path, $stream, FTP_BINARY);
            fclose($stream);
            if (! $ok) throw new RuntimeException('FTP upload failed.');
            $saved = $this->download($ftp, $path);
            $hash = hash('sha256', $saved);
            if (! hash_equals(hash('sha256', $content), $hash)) throw new RuntimeException('FTP transfer integrity check failed.');
            return ['path' => $path, 'sha256' => $hash, 'bytes' => strlen($content), 'verified' => true];
        });
    }

    public function delete(string $path, ?string $expectedSha256): array
    {
        if (empty($this->connection['can_delete'])) throw new RuntimeException('Delete permission is disabled for this connection.');
        $path = $this->relativePath($path, false);
        return $this->withFtp(function ($ftp) use ($path, $expectedSha256): array {
            $this->assertSafePath($ftp, $path);
            if (! hash_equals((string) $expectedSha256, hash('sha256', $this->download($ftp, $path)))) throw new RuntimeException('File changed since it was inspected; it was not deleted.');
            if (! @ftp_delete($ftp, $path)) throw new RuntimeException('FTP delete failed.');
            return ['path' => $path, 'deleted' => @ftp_size($ftp, $path) < 0, 'verified' => @ftp_size($ftp, $path) < 0];
        });
    }

    public function rename(string $path, string $newPath, ?string $expectedSha256): array
    {
        if (empty($this->connection['can_write'])) throw new RuntimeException('Write permission is disabled for this connection.');
        $path = $this->relativePath($path, false);
        $newPath = $this->relativePath($newPath, false);
        return $this->withFtp(function ($ftp) use ($path, $newPath, $expectedSha256): array {
            $this->assertSafePath($ftp, $path);
            $this->assertSafePath($ftp, $newPath, true);
            $hash = hash('sha256', $this->download($ftp, $path));
            if (! hash_equals((string) $expectedSha256, $hash) || @ftp_size($ftp, $newPath) >= 0) throw new RuntimeException('Rename source changed or the destination already exists.');
            if (! @ftp_rename($ftp, $path, $newPath)) throw new RuntimeException('FTP rename failed.');
            return ['path' => $path, 'new_path' => $newPath, 'sha256' => hash('sha256', $this->download($ftp, $newPath)), 'verified' => true];
        });
    }

    public function mkdir(string $path): array
    {
        if (empty($this->connection['can_write'])) throw new RuntimeException('Write permission is disabled for this connection.');
        $path = $this->relativePath($path, false);
        return $this->withFtp(function ($ftp) use ($path): array {
            $this->assertSafePath($ftp, $path, true);
            if (! @ftp_mkdir($ftp, $path)) throw new RuntimeException('FTP directory creation failed.');
            return ['path' => $path, 'created' => true, 'verified' => true];
        });
    }

    public function search(string $query, string $path = '.'): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 160) throw new RuntimeException('Search query must contain 1 to 160 characters.');
        return $this->withFtp(function ($ftp) use ($query, $path): array {
            $queue = [[$this->relativePath($path), 0]];
            $matches = [];
            $entries = 0;
            while ($queue && $entries < 500) {
                [$directory, $depth] = array_shift($queue);
                foreach ($this->listAt($ftp, $directory) as $item) {
                    if (++$entries > 500) break;
                    $child = $directory === '.' ? $item['name'] : $directory.'/'.$item['name'];
                    if ($item['type'] === 'directory' && $depth < 5) $queue[] = [$child, $depth + 1];
                    if (stripos($item['name'], $query) !== false) $matches[] = ['path' => $child, 'type' => $item['type']];
                    if (count($matches) >= 100) break 2;
                }
            }
            return ['matches' => $matches, 'entries_scanned' => $entries, 'limited' => $entries >= 500 || count($matches) >= 100];
        });
    }

    public function runAllowedCommand(string $command, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false): array
    {
        throw new RuntimeException('FTP and FTPS do not provide remote command execution.');
    }

    public function runAllowedArgv(array $arguments, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false, bool $privateUmask = false): array
    {
        throw new RuntimeException('FTP and FTPS do not provide remote command execution.');
    }

    private function withFtp(callable $callback): mixed
    {
        $this->checkCancelled();
        $protocol = (string) ($this->connection['protocol'] ?? '');
        if ($protocol === 'ftps') throw new RuntimeException('FTPS is disabled until the remote certificate can be verified securely.');
        if (! extension_loaded('ftp') || ! function_exists('ftp_connect')) throw new RuntimeException('The FTP extension is not installed in the ELChat runtime.');
        $host = DevPilotHostResolver::resolve((string) $this->connection['host'], (bool) ($this->connection['private_network_authorized'] ?? false));
        $port = (int) ($this->connection['port'] ?? 21);
        $timeout = 8;
        if (empty($this->secrets['insecure_ftp_confirmed'])) throw new RuntimeException('Plain FTP requires explicit acknowledgement of its unencrypted transport.');
        $ftp = @ftp_connect($host, $port, $timeout);
        if (! $ftp) throw new RuntimeException('Could not connect to the configured FTP endpoint.');
        try {
            if (! @ftp_login($ftp, (string) $this->connection['username'], (string) ($this->secrets['password'] ?? ''))) throw new RuntimeException('FTP authentication failed.');
            $this->checkCancelled();
            @ftp_pasv($ftp, true);
            $configuredRoot = trim((string) ($this->connection['root_path'] ?? ''));
            if ($configuredRoot !== '' && $configuredRoot !== '.' && ! @ftp_chdir($ftp, $configuredRoot)) throw new RuntimeException('Configured FTP root is inaccessible.');
            if ($this->projectRoot !== '.' && ! @ftp_chdir($ftp, $this->projectRoot)) throw new RuntimeException('Configured project directory is inaccessible.');
            return $callback($ftp);
        } finally {
            @ftp_close($ftp);
        }
    }

    private function checkCancelled(): void
    {
        if ($this->cancellationCheck) ($this->cancellationCheck)();
    }

    private function listAt($ftp, string $path): array
    {
        $path = $this->relativePath($path);
        $names = @ftp_nlist($ftp, $path === '.' ? '.' : $path);
        if (! is_array($names)) throw new RuntimeException('FTP directory listing failed.');
        $result = [];
        foreach ($names as $listed) {
            $name = basename(str_replace('\\', '/', (string) $listed));
            if ($name === '' || in_array($name, ['.', '..'], true) || PhpseclibSftpFileSystem::isSensitiveName($name)) continue;
            $child = $path === '.' ? $name : $path.'/'.$name;
            $facts = $this->facts($ftp, $path, $name);
            if (($facts['type'] ?? '') === 'symlink') continue;
            $directory = ($facts['type'] ?? '') === 'directory';
            $size = @ftp_size($ftp, $child);
            $result[] = ['name' => $name, 'type' => $directory ? 'directory' : 'file', 'size' => max(0, (int) $size)];
        }
        return array_slice($result, 0, 500);
    }

    private function facts($ftp, string $parent, string $name): array
    {
        $path = $parent === '.' ? $name : $parent.'/'.$name;
        if (function_exists('ftp_mlsd')) {
            $facts = @ftp_mlsd($ftp, $parent);
            if (is_array($facts)) foreach ($facts as $entry) if (($entry['name'] ?? '') === $name) {
                $type = strtolower((string) ($entry['type'] ?? ''));
                return ['type' => str_contains($type, 'slink') || str_contains($type, 'symlink') ? 'symlink' : ($type === 'dir' ? 'directory' : 'file')];
            }
        }
        $lines = @ftp_rawlist($ftp, $parent);
        if (is_array($lines)) foreach ($lines as $line) {
            if (preg_match('/^([bcdlps-])[rwxStTs-]{9}\s+\S+\s+\S+\s+\S+\s+\d+\s+\S+\s+\d+\s+[\d:]+\s+(.+)$/', $line, $m) && trim(explode(' -> ', $m[2])[0]) === $name) {
                return ['type' => $m[1] === 'l' ? 'symlink' : ($m[1] === 'd' ? 'directory' : 'file')];
            }
        }
        return [];
    }

    private function assertSafePath($ftp, string $path, bool $allowMissing = false): void
    {
        $parts = explode('/', $path);
        $current = '.';
        foreach ($parts as $index => $part) {
            if ($part === '') continue;
            $last = $index === array_key_last($parts);
            $facts = $this->facts($ftp, $current, $part);
            if (($facts['type'] ?? null) === 'symlink') throw new RuntimeException('FTP symbolic-link paths are blocked.');
            if (! isset($facts['type']) && ! $allowMissing) throw new RuntimeException('The FTP server did not provide enough path metadata to verify this file.');
            if (! isset($facts['type']) && $allowMissing && ! $last) throw new RuntimeException('Parent directories must already exist.');
            if (($facts['type'] ?? '') === 'directory' && $last) throw new RuntimeException('Directory operations are limited to explicit mkdir; file operation rejected.');
            $current = $current === '.' ? $part : $current.'/'.$part;
        }
    }

    private function download($ftp, string $path): string
    {
        $size = @ftp_size($ftp, $path);
        if (! is_int($size) || $size < 0 || $size > 262144) throw new RuntimeException('File is missing or exceeds the 256 KB verification limit.');
        $stream = fopen('php://temp', 'w+b');
        if (! $stream || ! @ftp_fget($ftp, $stream, $path, FTP_BINARY)) throw new RuntimeException('FTP download failed.');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if (! is_string($content)) throw new RuntimeException('FTP download failed.');
        return $content;
    }

    private function relativePath(string $path, bool $allowDot = true): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if (str_starts_with($path, '/') || str_contains($path, "\0") || preg_match('/^[A-Za-z]:/', $path)) throw new RuntimeException('Absolute or malformed paths are not allowed.');
        if ($path === '' || $path === '.') {
            if (! $allowDot) throw new RuntimeException('A file path is required.');
            return '.';
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if (in_array($part, ['', '.', '..'], true) || preg_match('/[\x00-\x1F]/', $part) || PhpseclibSftpFileSystem::isSensitiveName($part)) throw new RuntimeException('Path traversal, credentials, and malformed paths are blocked.');
        }
        return implode('/', $parts);
    }

}
