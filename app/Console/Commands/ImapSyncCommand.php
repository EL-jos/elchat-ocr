<?php

namespace App\Console\Commands;

use App\Models\Social\SocialAccount;
use App\Services\Social\Email\ImapSyncService;
use Illuminate\Console\Command;
use Throwable;

class ImapSyncCommand extends Command
{
    protected $signature   = 'email:sync-imap';
    protected $description = 'Synchronise les emails IMAP de tous les comptes actifs';

    public function handle(ImapSyncService $service): int
    {
        $accounts = SocialAccount::where('provider', 'imap')
            ->where('is_active', true)
            ->get();

        $this->info("Comptes IMAP trouvés : {$accounts->count()}");

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

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
