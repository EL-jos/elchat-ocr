<?php

namespace App\Jobs;

use App\Domain\MCP\Connectors\Microsoft365Connector;
use App\Domain\MCP\Security\CredentialVault;
use App\Models\Site;
use App\Services\Microsoft365SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class Microsoft365SyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 900;

    public function __construct(
        protected Site $site,
        protected ?string $driveId = null,
        protected ?string $providerSiteId = null,
    ) {
    }

    public function middleware(): array
    {
        $scope = $this->driveId ?: ($this->providerSiteId ? 'site:' . $this->providerSiteId : 'me');

        return [new WithoutOverlapping('microsoft365:' . $this->site->id . ':' . $scope)];
    }

    public function handle(CredentialVault $vault, Microsoft365Connector $connector, Microsoft365SyncService $sync): void
    {
        $credentials = $vault->retrieve($this->site, 'microsoft_365');
        if (!$credentials) return;
        $fresh = $connector->authenticate($credentials);
        if ($fresh !== $credentials) $vault->refresh($this->site, 'microsoft_365', $fresh);
        $sync->sync($this->site, $fresh, $this->driveId, $this->providerSiteId);
    }
}
