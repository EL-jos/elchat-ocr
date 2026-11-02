<?php

namespace App\Domain\MCP\Contracts;

/** Optional connector hook used before an API-key connection is marked active. */
interface VerifiesConnectorConnection
{
    /** @return array<string, mixed> Safe connection details; never include credentials. */
    public function verifyConnection(array $credentials): array;
}
