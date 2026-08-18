<?php

namespace App\Console\Commands;

use App\Models\Social\SocialAccount;
use App\Services\Social\YoutTube\YouTubeChannelSyncService;
use Illuminate\Console\Command;
use Throwable;

class YouTubeSyncCommentsCommand extends Command
{
    protected $signature = 'youtube:sync-comments';

    protected $description = 'Synchronise les commentaires YouTube';

    public function handle(YouTubeChannelSyncService $service): int
    {
        $accounts = SocialAccount::query()
            ->where('provider', 'youtube')
            ->where('is_active', true)
            ->get();

        $this->info("Channels found: {$accounts->count()}");

        $errors = 0;

        foreach ($accounts as $account) {
            try {
                $service->sync($account);
                $this->info("✓ {$account->account_name}");
            } catch (Throwable $e) {
                $errors++;
                report($e);
                $this->error("✗ {$account->account_name}: {$e->getMessage()}");
            }
        }

        // ✅ FAILURE si au moins un compte a échoué → détectable par
        // un monitoring externe (ex: Laravel Pulse, healthchecks.io)
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
