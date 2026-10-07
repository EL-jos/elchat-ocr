<?php

namespace App\Console\Commands;

use App\Jobs\SocialIntelligence\SyncSocialIntelligenceJob;
use App\Models\Site;
use Illuminate\Console\Command;

class SocialIntelligenceSyncCommand extends Command
{
    protected $signature = 'social-intelligence:sync {--site=}';
    protected $description = 'Synchronise les données sociales disponibles pour Social Intelligence';

    public function handle(): int
    {
        $query = Site::query()->whereHas('socialAccounts', fn ($accounts) => $accounts->where('is_active', true)->whereIn('provider', config('social-intelligence.supported_providers')))->when($this->option('site'), fn ($sites, $site) => $sites->whereKey($site));
        $count = 0;
        $query->pluck('id')->each(function (string $siteId) use (&$count) { SyncSocialIntelligenceJob::dispatch($siteId); $count++; });
        $this->components->info("{$count} synchronisation(s) sociale(s) placée(s) dans la file.");
        return self::SUCCESS;
    }
}
