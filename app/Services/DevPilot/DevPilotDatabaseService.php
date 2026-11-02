<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use PDO;
use Closure;
use RuntimeException;
use Throwable;

final class DevPilotDatabaseService
{
    public function __construct(private readonly ?Closure $connectionFactory = null) {}

    public function test(DevPilotConnection|array $connection, array $secrets): array
    {
        $values = $connection instanceof DevPilotConnection ? $connection->toArray() : $connection;
        $protocol = (string) ($values['protocol'] ?? '');
        $database = (string) ($values['database_name'] ?? '');
        $username = (string) ($values['username'] ?? '');
        if (! in_array($protocol, ['mysql', 'pgsql'], true) || $database === '' || $username === '') throw new RuntimeException('A supported database, name, host and user are required.');
        $pdo = $this->connect($values, $secrets, $database);
        if ($protocol === 'mysql') $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            if ($protocol === 'pgsql') $pdo->exec('SET TRANSACTION READ ONLY');
            if ($protocol === 'mysql') {
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $rows = $pdo->query('SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY TABLE_NAME LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
                $role = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
            } else {
                $version = (string) $pdo->query('SELECT version()')->fetchColumn();
                $rows = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name LIMIT 200")->fetchAll(PDO::FETCH_COLUMN);
                $role = (string) $pdo->query('SELECT current_user')->fetchColumn();
            }
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo = null;
        }

        return [
            'connected' => true, 'protocol' => $protocol, 'database' => $database,
            'server_version' => mb_strcut($version, 0, 160), 'authenticated_role' => $role,
            'tls_verified' => true, 'read_only_transaction' => true,
            'tables' => array_values(array_map('strval', $rows)), 'tables_truncated' => count($rows) >= 200,
            'capabilities' => [
                'schema_read' => true, 'row_read' => false, 'write' => false, 'shell' => false,
                'database_create_configured' => (bool) ($values['can_manage_databases'] ?? false),
            ],
            'restrictions' => array_values(array_filter([
                'La lecture des données et les requêtes SQL libres restent désactivées.',
                ! empty($values['can_manage_databases']) ? 'La création est configurée côté ELChat; les privilèges réels du rôle distant ne sont pas vérifiés par une requête de modification.' : null,
            ])),
        ];
    }

    /** Create exactly one database after the caller has checked the explicit permission and approval. */
    public function createDatabase(DevPilotConnection|array $connection, array $secrets, string $databaseName): array
    {
        $values = $connection instanceof DevPilotConnection ? $connection->toArray() : $connection;
        $protocol = (string) ($values['protocol'] ?? '');
        if (! in_array($protocol, ['mysql', 'pgsql'], true)) throw new RuntimeException('Database creation is available only for a configured MySQL or PostgreSQL connection.');
        if (empty($values['can_manage_databases'])) throw new RuntimeException('Database management permission is disabled for this connection.');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D', $databaseName) !== 1) throw new RuntimeException('Database name must be 1–63 characters, start with a letter, and contain only letters, digits, or underscores.');

        $adminDatabase = (string) ($values['database_name'] ?? '');
        if ($adminDatabase === '' || $adminDatabase === $databaseName) throw new RuntimeException('An existing administrative database, distinct from the new database, must be configured.');
        $pdo = $this->connect($values, $secrets, $adminDatabase);
        try {
            if ($protocol === 'mysql') {
                $check = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
                $check->execute([$databaseName]);
            } else {
                $check = $pdo->prepare('SELECT datname FROM pg_database WHERE datname = ?');
                $check->execute([$databaseName]);
            }
            if ($check->fetchColumn() !== false) throw new RuntimeException('The requested database already exists; no creation was attempted.');

            $identifier = $protocol === 'mysql'
                ? '`'.str_replace('`', '``', $databaseName).'`'
                : '"'.str_replace('"', '""', $databaseName).'"';
            $pdo->exec('CREATE DATABASE '.$identifier);
        } finally {
            $pdo = null;
        }

        // A failed verification is reported as uncertain by the task runner; it is never retried automatically.
        $verified = null;
        try {
            $verified = $this->connect($values, $secrets, $databaseName);
            $actual = (string) $verified->query($protocol === 'mysql' ? 'SELECT DATABASE()' : 'SELECT current_database()')->fetchColumn();
            if (! hash_equals($databaseName, $actual)) throw new RuntimeException('The database creation command returned, but the target database could not be verified.');
        } catch (Throwable $exception) {
            throw new DevPilotOperationUncertainException('The database creation request was sent but post-creation verification failed; check the target before retrying.', $exception);
        } finally {
            $verified = null;
        }

        return ['database' => $databaseName, 'protocol' => $protocol, 'created' => true, 'verified' => true];
    }

    private function connect(array $values, array $secrets, string $database): object
    {
        $protocol = (string) ($values['protocol'] ?? '');
        if ($this->connectionFactory) return ($this->connectionFactory)($values, $secrets, $database);
        $host = (string) ($values['host'] ?? '');
        $address = DevPilotHostResolver::resolve($host, (bool) ($values['private_network_authorized'] ?? false));
        $port = (int) ($values['port'] ?? ($protocol === 'pgsql' ? 5432 : 3306));
        $username = (string) ($values['username'] ?? '');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 6, PDO::ATTR_EMULATE_PREPARES => false];
        $dsnHost = str_contains($address, ':') ? '['.$address.']' : $address;
        if ($protocol === 'mysql') {
            if (! defined('PDO::MYSQL_ATTR_SSL_CA') || ! defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) throw new RuntimeException('MySQL TLS certificate verification is unavailable in this PHP runtime.');
            $ca = $this->mysqlCaFile();
            if (! $ca) throw new RuntimeException('A readable MySQL TLS CA bundle is required.');
            $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $ca;
            // Keep the DSN on the resolver-vetted address. The certificate must identify
            // that IP; never switch to DNS-only validation without a separately pinned socket.
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
            $dsn = "mysql:host={$dsnHost};port={$port};dbname={$database};charset=utf8mb4";
        } elseif ($protocol === 'pgsql') {
            $dsn = "pgsql:host={$host};hostaddr={$address};port={$port};dbname={$database};sslmode=verify-full;connect_timeout=6";
        } else {
            throw new RuntimeException('Unsupported database protocol.');
        }

        return new PDO($dsn, $username, (string) ($secrets['password'] ?? ''), $options);
    }

    private function mysqlCaFile(): ?string
    {
        $options = config('database.connections.mysql.options', []);
        $candidate = is_array($options) && defined('PDO::MYSQL_ATTR_SSL_CA') ? ($options[constant('PDO::MYSQL_ATTR_SSL_CA')] ?? null) : null;
        if (! is_string($candidate) || $candidate === '') $candidate = openssl_get_cert_locations()['default_cert_file'] ?? null;
        if (! is_string($candidate) || $candidate === '') return null;
        $path = realpath($candidate);
        return $path !== false && is_file($path) && is_readable($path) ? $path : null;
    }

}
